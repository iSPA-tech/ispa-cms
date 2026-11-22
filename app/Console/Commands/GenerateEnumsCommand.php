<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Cms\Core\Models\Enumerate;
use Cms\Core\Models\EnumerateType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Generate native PHP enums in app/Enums from enumerate types and enumerate values stored in the database.
 *
 * This command is intentionally manual and uses a manifest hash to avoid unnecessary regeneration when DB source data
 * is unchanged. It also protects concurrent runs with a storage file lock.
 */
class GenerateEnumsCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;

    /**
     * The command signature.
     *
     * - --type: optional comma-separated key_name filters (e.g. status,storage)
     * - --with-inactive: include inactive + soft-deleted rows
     * - --resolve-collisions: suffix duplicate case names with _{id}
     * - --dry-run: do not write enum files or manifest
     * - --force: regenerate regardless of manifest hash comparison
     */
    protected $signature = 'cms:enums:generate
                            {--type= : Optional comma-separated enumerate type key_name values}
                            {--with-inactive : Include inactive and soft-deleted enumerates}
                            {--resolve-collisions : Auto-suffix duplicate case names with _{id}}
                            {--dry-run : Preview changes without writing files}
                            {--force : Ignore manifest hash and regenerate all selected types}';

    /**
     * The command description.
     *
     * @var string
     */
    protected $description = 'Generate native PHP enums from enumerate types and enumerate rows.';

    /**
     * Execute enum generation.
     *
     * @return int Command exit code.
     */
    public function handle(): int
    {
        $this->ensureDirectories();

        $lockHandle = $this->acquireLock();

        try {
            $typeFilters = $this->parseTypeFilters();
            $withInactive = (bool)$this->option('with-inactive');
            $resolveCollisions = (bool)$this->option('resolve-collisions');
            $dryRun = (bool)$this->option('dry-run');
            $force = (bool)$this->option('force');

            $manifestPath = $this->manifestPath();
            $existingManifest = $this->loadManifest($manifestPath);
            $types = $this->loadTypes($typeFilters);

            if ($types->isEmpty()) {
                $this->warn('No enumerate types matched the current filter.');

                return self::SUCCESS;
            }

            $manifestTypes = $typeFilters === [] ? [] : (array)($existingManifest['types'] ?? []);
            $tableRows = [];

            foreach ($types as $type) {
                if (trim((string)$type->key_name) === '') {
                    $tableRows[] = [
                        'type' => '#'.$type->id,
                        'class' => '-',
                        'cases' => 0,
                        'status' => 'skipped',
                        'file' => '-',
                    ];
                    $this->warn("Skipped enumerate type id {$type->id} because key_name is empty.");
                    continue;
                }

                $typeResult = $this->processType(
                    type: $type,
                    withInactive: $withInactive,
                    resolveCollisions: $resolveCollisions,
                    dryRun: $dryRun,
                    force: $force,
                    existingManifestTypes: (array)($existingManifest['types'] ?? []),
                );

                $manifestTypes[$typeResult['type_key']] = $typeResult['manifest'];
                $tableRows[] = $typeResult['output'];
            }

            $missingTypes = array_values(array_diff($typeFilters, $types->pluck('key_name')->all()));
            foreach ($missingTypes as $missingType) {
                $tableRows[] = [
                    'type' => $missingType,
                    'class' => '-',
                    'cases' => 0,
                    'status' => 'skipped',
                    'file' => '-',
                ];
                $this->warn("Requested type `{$missingType}` was not found.");
            }

            $this->table(['type', 'class', 'cases', 'status', 'file'], $tableRows);

            if (!$dryRun) {
                $manifestPayload = [
                    'generated_at' => now()->toISOString(),
                    'with_inactive' => $withInactive,
                    'types' => $manifestTypes,
                ];
                $this->persistManifest($manifestPath, $manifestPayload);
                $this->info("Manifest updated: {$manifestPath}");
            } else {
                $this->info('Dry-run mode enabled. No files were written.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * Parse and normalize the optional --type filter.
     *
     * @return array<int, string>
     */
    private function parseTypeFilters(): array
    {
        $rawTypeFilter = trim((string)$this->option('type'));
        if ($rawTypeFilter === '') {
            return [];
        }

        return collect(explode(',', $rawTypeFilter))
            ->map(static fn (string $type): string => trim($type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Load enumerate types targeted by this run.
     *
     * @param array<int, string> $typeFilters
     * @return Collection<int, EnumerateType>
     */
    private function loadTypes(array $typeFilters): Collection
    {
        $query = EnumerateType::query()
            ->select(['id', 'name', 'key_name', 'updated_at'])
            ->orderBy('id');

        if ($typeFilters !== []) {
            $query->whereIn('key_name', $typeFilters);
        }

        /** @var Collection<int, EnumerateType> $types */
        $types = $query->get();

        return $types;
    }

    /**
     * Generate one enum file + manifest entry for a specific enumerate type.
     *
     * @param EnumerateType $type Source enumerate type record.
     * @param bool $withInactive Include inactive/soft-deleted enumerate rows.
     * @param bool $resolveCollisions Auto-resolve duplicate case names.
     * @param bool $dryRun Preview mode.
     * @param bool $force Force regeneration regardless of manifest hash.
     * @param array<string, mixed> $existingManifestTypes Existing manifest type map.
     * @return array{type_key: string, manifest: array{hash: string, count: int, file: string}, output: array<string, int|string>}
     *
     * @throws JsonException
     */
    private function processType(
        EnumerateType $type,
        bool $withInactive,
        bool $resolveCollisions,
        bool $dryRun,
        bool $force,
        array $existingManifestTypes
    ): array {
        $typeKey = (string)$type->key_name;
        $className = Str::studly($typeKey).'Enum';
        $relativeFile = 'app/Enums/'.$className.'.php';
        $absoluteFile = base_path($relativeFile);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className)) {
            throw new RuntimeException(
                "Cannot generate enum class for type `{$typeKey}`. Resolved class `{$className}` is not a valid PHP class name."
            );
        }

        $rows = $this->loadEnumeratesForType($type, $withInactive);
        $typeHash = $this->computeTypeHash($type, $rows);

        $fileExists = File::exists($absoluteFile);
        $manifestHash = (string)(($existingManifestTypes[$typeKey]['hash'] ?? ''));
        $hashChanged = $typeHash !== $manifestHash;
        $shouldGenerate = $force || $hashChanged || !$fileExists;

        $status = 'unchanged';

        if ($shouldGenerate) {
            $cases = $this->buildCases($typeKey, $rows, $resolveCollisions);
            $content = $this->renderEnumFile($typeKey, $className, $cases);
            $currentContent = $fileExists ? (string)File::get($absoluteFile) : null;
            $contentChanged = !$fileExists || $currentContent !== $content;

            if ($contentChanged && !$dryRun) {
                File::put($absoluteFile, $content);
            }

            if ($contentChanged) {
                $status = $fileExists ? 'updated' : 'created';
            }
        }

        return [
            'type_key' => $typeKey,
            'manifest' => [
                'hash' => $typeHash,
                'count' => $rows->count(),
                'file' => $relativeFile,
            ],
            'output' => [
                'type' => $typeKey,
                'class' => $className,
                'cases' => $rows->count(),
                'status' => $status,
                'file' => $relativeFile,
            ],
        ];
    }

    /**
     * Load enumerate rows for a given type with deterministic ordering.
     *
     * Resolution rule:
     * - Prefer `type_id` mapping when present.
     * - Fallback to legacy `type` string mapping only when `type_id` is null.
     *
     * Sorting is by `id` ascending to keep output stable across runs.
     *
     * @param EnumerateType $type
     * @param bool $withInactive
     * @return Collection<int, Enumerate>
     */
    private function loadEnumeratesForType(EnumerateType $type, bool $withInactive): Collection
    {
        $query = Enumerate::query()
            ->select(['id', 'name', 'name_alias', 'enum_key', 'type', 'type_id', 'status', 'deleted_at', 'updated_at'])
            ->where(function ($builder) use ($type): void {
                $builder->where('type_id', $type->id)
                    ->orWhere(function ($legacyBuilder) use ($type): void {
                        // Legacy fallback is allowed only for rows without type_id.
                        $legacyBuilder->whereNull('type_id')
                            ->where('type', $type->key_name);
                    });
            })
            ->orderBy('id');

        if ($withInactive) {
            $query->withTrashed();
        } else {
            $query->where('status', true);
        }

        /** @var Collection<int, Enumerate> $rows */
        $rows = $query->get();

        return $rows;
    }

    /**
     * Build normalized enum cases from enumerate rows.
     *
     * @param string $typeKey
     * @param Collection<int, Enumerate> $rows
     * @param bool $resolveCollisions
     * @return array<int, array{case_name: string, enum_key: string, id: int, name: string, name_alias: string}>
     */
    private function buildCases(string $typeKey, Collection $rows, bool $resolveCollisions): array
    {
        $preparedCases = [];
        $collisions = [];

        /** @var Enumerate $row */
        foreach ($rows as $row) {
            $enumKey = (string)$row->enum_key;
            $baseCaseName = $this->normalizeCaseName($enumKey);

            if ($baseCaseName === '') {
                throw new RuntimeException(
                    "Cannot derive a valid enum case name for type `{$typeKey}` from enum_key `{$enumKey}` (id: {$row->id})."
                );
            }

            $preparedCases[] = [
                'base_case_name' => $baseCaseName,
                'enum_key' => $enumKey,
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'name_alias' => (string)($row->name_alias ?? ''),
            ];
            $collisions[$baseCaseName][] = [
                'id' => (int)$row->id,
                'enum_key' => $enumKey,
            ];
        }

        if (!$resolveCollisions) {
            $duplicateEntries = collect($collisions)->filter(
                static fn (array $entries): bool => count($entries) > 1
            );
            if ($duplicateEntries->isNotEmpty()) {
                $messages = [];
                foreach ($duplicateEntries as $caseName => $entries) {
                    $ids = implode(',', array_column($entries, 'id'));
                    $enumKeys = implode(',', array_unique(array_column($entries, 'enum_key')));
                    $messages[] = "case={$caseName} enum_key={$enumKeys} ids={$ids}";
                }
                $messageBody = implode(' | ', $messages);
                throw new RuntimeException(
                    "Duplicate enum case names detected for type `{$typeKey}`. {$messageBody}. Use --resolve-collisions to auto-suffix with _{id}."
                );
            }
        }

        $resolvedCases = [];
        $usedCaseNames = [];

        foreach ($preparedCases as $case) {
            $caseName = $case['base_case_name'];
            if (isset($usedCaseNames[$caseName])) {
                $caseName = $this->resolveCaseNameCollision(
                    $case['base_case_name'],
                    (int)$case['id'],
                    $usedCaseNames
                );
            }

            $usedCaseNames[$caseName] = true;
            $resolvedCases[] = [
                'case_name' => $caseName,
                'enum_key' => $case['enum_key'],
                'id' => (int)$case['id'],
                'name' => $case['name'],
                'name_alias' => $case['name_alias'],
            ];
        }

        return $resolvedCases;
    }

    /**
     * Normalize enum_key into a valid UPPER_SNAKE enum case identifier.
     *
     * @param string $enumKey
     * @return string
     */
    private function normalizeCaseName(string $enumKey): string
    {
        $upper = Str::upper($enumKey);
        $normalized = preg_replace('/[^A-Z0-9_]+/', '_', $upper) ?? '';
        $normalized = preg_replace('/_+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized !== '' && preg_match('/^\d/', $normalized) === 1) {
            $normalized = 'K_'.$normalized;
        }

        return $normalized;
    }

    /**
     * Resolve duplicate case names by suffixing with _{id} in a deterministic way.
     *
     * @param string $baseCaseName
     * @param int $rowId
     * @param array<string, bool> $usedCaseNames
     * @return string
     */
    private function resolveCaseNameCollision(string $baseCaseName, int $rowId, array $usedCaseNames): string
    {
        $candidate = "{$baseCaseName}_{$rowId}";
        while (isset($usedCaseNames[$candidate])) {
            $candidate .= "_{$rowId}";
        }

        return $candidate;
    }

    /**
     * Render a native enum class file.
     *
     * @param string $typeKey
     * @param string $className
     * @param array<int, array{case_name: string, enum_key: string, id: int, name: string, name_alias: string}> $cases
     * @return string
     */
    private function renderEnumFile(string $typeKey, string $className, array $cases): string
    {
        $caseLines = [];

        foreach ($cases as $case) {
            $name = $this->escapeDocCommentText($case['name']);
            $alias = $this->escapeDocCommentText($case['name_alias'] !== '' ? $case['name_alias'] : '-');
            $value = str_replace(['\\', '\''], ['\\\\', '\\\''], $case['enum_key']);
            $caseLines[] = "    /** id: {$case['id']} | name: {$name} | alias: {$alias} */";
            $caseLines[] = "    case {$case['case_name']} = '{$value}';";
            $caseLines[] = '';
        }

        $body = rtrim(implode(PHP_EOL, $caseLines));
        if ($body !== '') {
            $body = PHP_EOL.$body.PHP_EOL;
        } else {
            $body = PHP_EOL;
        }

        return <<<PHP
<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\\Enums;

/**
 * Native enum generated from enumerates type `{$typeKey}`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum {$className}: string
{{$body}}
PHP;
    }

    /**
     * Compute canonical hash for one enumerate type and its included enumerate rows.
     *
     * @param EnumerateType $type
     * @param Collection<int, Enumerate> $rows
     * @return string
     *
     * @throws JsonException
     */
    private function computeTypeHash(EnumerateType $type, Collection $rows): string
    {
        $payload = [
            'type' => [
                'key_name' => (string)$type->key_name,
                'updated_at' => $this->normalizeDateTimeValue($type->updated_at),
            ],
            'rows' => $rows->map(function (Enumerate $row): array {
                return [
                    'id' => (int)$row->id,
                    'enum_key' => (string)$row->enum_key,
                    'name' => (string)$row->name,
                    'name_alias' => (string)($row->name_alias ?? ''),
                    'status' => (bool)$row->status,
                    'deleted_at' => $this->normalizeDateTimeValue($row->deleted_at),
                    'updated_at' => $this->normalizeDateTimeValue($row->updated_at),
                ];
            })->all(),
        ];

        return hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Normalize date-like values so hash input remains stable.
     *
     * @param mixed $value
     * @return string|null
     */
    private function normalizeDateTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string)$value;
    }

    /**
     * Escape dynamic text used inside generated doc comments.
     *
     * @param string $value
     * @return string
     */
    private function escapeDocCommentText(string $value): string
    {
        $escaped = str_replace('*/', '* /', $value);
        $escaped = preg_replace('/\s+/', ' ', $escaped) ?? $escaped;

        return trim($escaped);
    }

    /**
     * Ensure output directories exist before generation begins.
     */
    private function ensureDirectories(): void
    {
        File::ensureDirectoryExists($this->enumDirectoryPath());
        File::ensureDirectoryExists($this->manifestDirectoryPath());
    }

    /**
     * Acquire exclusive filesystem lock for generation process.
     *
     * @return resource
     */
    private function acquireLock()
    {
        $lockPath = $this->lockPath();
        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Unable to open lock file: {$lockPath}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Enum generation is already running (lock is held).');
        }

        return $handle;
    }

    /**
     * Release an acquired lock handle.
     *
     * @param resource $lockHandle
     */
    private function releaseLock($lockHandle): void
    {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Load manifest JSON from disk.
     *
     * @param string $manifestPath
     * @return array<string, mixed>
     */
    private function loadManifest(string $manifestPath): array
    {
        if (!File::exists($manifestPath)) {
            return [];
        }

        $raw = File::get($manifestPath);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist manifest JSON to disk.
     *
     * @param string $manifestPath
     * @param array<string, mixed> $manifestPayload
     *
     * @throws JsonException
     */
    private function persistManifest(string $manifestPath, array $manifestPayload): void
    {
        File::put(
            $manifestPath,
            json_encode($manifestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        );
    }

    /**
     * Build absolute path to app/Enums.
     *
     * @return string
     */
    private function enumDirectoryPath(): string
    {
        return base_path('app/Enums');
    }

    /**
     * Build absolute path to storage/app/cms/enums.
     *
     * @return string
     */
    private function manifestDirectoryPath(): string
    {
        return storage_path('app/cms/enums');
    }

    /**
     * Build absolute manifest file path.
     *
     * @return string
     */
    private function manifestPath(): string
    {
        return $this->manifestDirectoryPath().'/enums-manifest.json';
    }

    /**
     * Build absolute lock file path.
     *
     * @return string
     */
    private function lockPath(): string
    {
        return $this->manifestDirectoryPath().'/.lock';
    }
}
