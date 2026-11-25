<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Models\User;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Validate a workflow package file and dry-run it on this site; writes nothing, calls nothing outside, spends nothing.
 *
 * Usage: php artisan cms:workflow-package-check file.workflow.json [--bind=user_1=<uuid>] [--as=new-slug]
 *        [--sample=sample.json] [--user=<uuid or email>] [--no-dry-run]
 * Prints the report as JSON (manifest, problems, binding and slug problems, warnings, dry run). Fails when the package
 * has a blocking problem.
 *
 * @package Cms\Core\Console\Commands
 * @since   2026-11-24
 */
class WorkflowPackageCheckCommand extends Command
{
    protected $signature = 'cms:workflow-package-check
        {file : Workflow package file}
        {--bind=* : Binding values, name=value}
        {--as= : Slug to install under (copy)}
        {--sample= : JSON file with a sample record ({"root": {...}}), replaces the package sample}
        {--user= : UUID or email of the user whose node permissions are checked}
        {--no-dry-run : Skip the dry run}';

    protected $description = 'Validate and dry-run a workflow package without installing it.';

    public function handle(WorkflowPackageInspector $inspector): int
    {
        $package = json_decode((string) @file_get_contents((string) $this->argument('file')), true);
        if (!is_array($package)) {
            $this->error('The file must hold a JSON workflow package.');

            return self::FAILURE;
        }

        $sample = null;
        if (is_string($this->option('sample')) && $this->option('sample') !== '') {
            $sample = json_decode((string) @file_get_contents((string) $this->option('sample')), true);
            if (!is_array($sample)) {
                $this->error('The sample file must hold a JSON object.');

                return self::FAILURE;
            }
        }

        $report = $inspector->inspect($package, self::user((string) $this->option('user')), self::bindings((array) $this->option('bind')),
            $this->option('as') ? (string) $this->option('as') : null, $sample, !$this->option('no-dry-run'));
        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param list<string> $pairs `name=value`
     * @return array<string, string>
     */
    public static function bindings(array $pairs): array
    {
        $values = [];
        foreach ($pairs as $pair) {
            if (is_string($pair) && str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $values[trim($name)] = trim($value);
            }
        }

        return $values;
    }

    public static function user(string $key): ?User
    {
        $key = trim($key);

        // Compare uuid only with a uuid-shaped value: PostgreSQL refuses to cast other text to its uuid column type.
        return $key === '' ? null : User::query()
            ->when(Str::isUuid($key), static fn ($q) => $q->where('uuid', $key), static fn ($q) => $q->where('email', $key))
            ->first();
    }
}
