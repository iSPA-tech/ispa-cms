<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IspaAuditSeeders extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $signature = 'ispa:audit-seeders {--module=* : Limit to specific modules (e.g. RealEstate, Education)} {--json : Output JSON only}';

    protected $description = 'Audit module seeders against the current database schema and report mismatched columns.';

    /** @var array<string, array{columns: array<int, string>, required: array<int, string>}> */
    protected array $tableSchemaCache = [];

    public function handle(): int
    {
        $modules = $this->option('module');
        $seeders = $this->discoverSeeders($modules);

        if ($seeders->isEmpty()) {
            $this->warn('No seeders found.');

            return self::SUCCESS;
        }

        $report = [];
        $errors = [];

        foreach ($seeders as $seederClass) {
            $this->line("Auditing {$seederClass}");

            try {
                $queries = $this->captureSeederQueries($seederClass);
                $report[$seederClass] = $this->analyzeQueries($queries);
            } catch (\Throwable $e) {
                $errors[$seederClass] = $e->getMessage();
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        $flatIssues = $this->flattenIssues($report);
        $topIssues = array_slice($flatIssues, 0, 10);

        if (! $this->option('json')) {
            $this->newline();
            $this->info('=== Seeder Schema Audit ===');
            foreach ($report as $seeder => $tables) {
                $seederIssues = $this->flattenIssues([$seeder => $tables]);
                if (empty($seederIssues)) {
                    $this->line("✔ {$seeder}: no schema mismatches detected");
                    continue;
                }

                $this->line("✖ {$seeder}:");
                foreach ($seederIssues as $issue) {
                    $this->line(sprintf(
                        "  - [%s] missing_in_db=%s | missing_required=%s | sql=%s",
                        $issue['table'],
                        empty($issue['missing_columns']) ? '[]' : implode(',', $issue['missing_columns']),
                        empty($issue['missing_required']) ? '[]' : implode(',', $issue['missing_required']),
                        Str::limit($issue['sql'], 120)
                    ));
                }
            }

            if (! empty($errors)) {
                $this->newline();
                $this->warn('Seeders that failed to audit:');
                foreach ($errors as $seeder => $message) {
                    $this->line("  - {$seeder}: {$message}");
                }
            }

            $this->newline();
            $this->info('Top issues:');
            foreach ($topIssues as $issue) {
                $this->line(sprintf(
                    "  - %s | table=%s | missing_in_db=%s | missing_required=%s",
                    $issue['seeder'],
                    $issue['table'],
                    empty($issue['missing_columns']) ? '[]' : implode(',', $issue['missing_columns']),
                    empty($issue['missing_required']) ? '[]' : implode(',', $issue['missing_required'])
                ));
            }
        }

        $jsonOutput = [
            'seeders' => $report,
            'errors' => $errors,
            'top_issues' => $topIssues,
        ];

        $this->output->writeln(json_encode($jsonOutput, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $modules
     * @return Collection<int, string>
     */
    protected function discoverSeeders(array $modules): Collection
    {
        $base = base_path('cms');
        $dirs = collect(File::directories($base))
            ->filter(fn (string $dir) => empty($modules) || in_array(basename($dir), $modules, true));

        $seeders = collect();
        foreach ($dirs as $dir) {
            $files = File::glob("{$dir}/database/seeders/*.php");
            foreach ($files as $file) {
                $class = $this->extractSeederClass($file);
                if ($class && class_exists($class)) {
                    $seeders->push($class);
                }
            }
        }

        return $seeders->unique()->values();
    }

    protected function extractSeederClass(string $file): ?string
    {
        $contents = File::get($file);
        $namespace = null;

        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $m)) {
            $namespace = trim($m[1]);
        }

        if (! preg_match('/class\s+([A-Za-z0-9_\\\\]+)\s+extends\s+Seeder/', $contents, $m)) {
            return null;
        }

        $class = $m[1];

        return $namespace ? "{$namespace}\\{$class}" : $class;
    }

    /**
     * @return list<array{sql: string}>
     */
    protected function captureSeederQueries(string $seederClass): array
    {
        $connection = DB::connection();

        $queries = $connection->pretend(function () use ($seederClass): void {
            $seeder = app($seederClass);
            if (method_exists($seeder, 'setContainer')) {
                $seeder->setContainer(app());
            }

            $seeder();
        });

        return collect($queries)
            ->filter(fn (array $query) => isset($query['query']) && Str::startsWith(Str::lower($query['query']), ['insert', 'update']))
            ->map(fn (array $query) => ['sql' => $query['query']])
            ->values()
            ->all();
    }

    /**
     * @param list<array{sql: string}> $queries
     * @return array<string, list<array<string, mixed>>>
     */
    protected function analyzeQueries(array $queries): array
    {
        $issues = [];

        foreach ($queries as $query) {
            $parsed = $this->parseQuery($query['sql']);
            if (! $parsed) {
                continue;
            }

            [$table, $columns] = $parsed;
            $schema = $this->getTableSchema($table);
            if (! $schema) {
                $issues[$table][] = [
                    'sql' => $query['sql'],
                    'missing_columns' => ['<table missing>'],
                    'missing_required' => [],
                ];
                continue;
            }

            $missingColumns = array_values(array_diff($columns, $schema['columns']));
            $missingRequired = array_values(array_diff($schema['required'], $columns));

            if ($missingColumns || $missingRequired) {
                $issues[$table][] = [
                    'sql' => $query['sql'],
                    'missing_columns' => $missingColumns,
                    'missing_required' => $missingRequired,
                ];
            }
        }

        return $issues;
    }

    /**
     * @return array{0: string, 1: array<int, string>}|null
     */
    protected function parseQuery(string $sql): ?array
    {
        if (preg_match('/insert into\\s+`?([^\\s`(]+)`?\\s*\\(([^)]+)\\)/i', $sql, $m)) {
            $table = $m[1];
            $columns = $this->normalizeColumns($m[2]);

            return [$table, $columns];
        }

        if (preg_match('/update\\s+`?([^\\s`]+)`?\\s+set\\s+(.+?)\\s+where/i', $sql, $m)) {
            $table = $m[1];
            $set = $m[2];
            $parts = preg_split('/,\\s*/', $set);
            $columns = [];
            foreach ($parts as $part) {
                if (preg_match('/`?([^`=\\s]+)`?\\s*=/', $part, $cm)) {
                    $columns[] = $cm[1];
                }
            }

            return [$table, $columns];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeColumns(string $columnList): array
    {
        return collect(explode(',', $columnList))
            ->map(fn (string $col) => trim($col, " `\n\r\t"))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{columns: array<int, string>, required: array<int, string>}|null
     */
    protected function getTableSchema(string $table): ?array
    {
        if (isset($this->tableSchemaCache[$table])) {
            return $this->tableSchemaCache[$table];
        }

        if (! Schema::hasTable($table)) {
            return $this->tableSchemaCache[$table] = null;
        }

        $columns = Schema::getColumnListing($table);
        $required = $this->resolveRequiredColumns($table);

        return $this->tableSchemaCache[$table] = [
            'columns' => $columns,
            'required' => $required,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function resolveRequiredColumns(string $table): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.columns')
                ->select('column_name')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('is_nullable', 'NO')
                ->whereNull('column_default')
                ->where('extra', 'not like', '%auto_increment%')
                ->pluck('column_name')
                ->diff(['created_at', 'updated_at', 'deleted_at'])
                ->values()
                ->all();
        }

        if ($driver === 'pgsql') {
            $schema = DB::selectOne('select current_schema() as schema')->schema ?? 'public';

            return DB::table('information_schema.columns')
                ->select('column_name')
                ->where('table_schema', $schema)
                ->where('table_name', $table)
                ->where('is_nullable', 'NO')
                ->whereNull('column_default')
                ->where('is_identity', 'NO')
                ->pluck('column_name')
                ->diff(['created_at', 'updated_at', 'deleted_at'])
                ->values()
                ->all();
        }

        if ($driver === 'sqlite') {
            $escapedTable = str_replace("'", "''", $table);

            return collect(DB::select("PRAGMA table_info('{$escapedTable}')"))
                ->filter(static fn ($column) => (int)($column->notnull ?? 0) === 1)
                ->filter(static fn ($column) => $column->dflt_value === null)
                ->filter(static fn ($column) => (int)($column->pk ?? 0) === 0)
                ->pluck('name')
                ->diff(['created_at', 'updated_at', 'deleted_at'])
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param array<string, array<string, list<array<string, mixed>>>> $report
     * @return list<array<string, mixed>>
     */
    protected function flattenIssues(array $report): array
    {
        $flat = [];
        foreach ($report as $seeder => $tables) {
            foreach ($tables as $table => $issues) {
                foreach ($issues as $issue) {
                    $flat[] = [
                        'seeder' => $seeder,
                        'table' => $table,
                        'sql' => $issue['sql'],
                        'missing_columns' => Arr::get($issue, 'missing_columns', []),
                        'missing_required' => Arr::get($issue, 'missing_required', []),
                    ];
                }
            }
        }

        return $flat;
    }
}
