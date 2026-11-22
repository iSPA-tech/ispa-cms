<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Enumerate;
use Cms\Core\Models\EnumerateType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Scan backend PHP sources for enumerate(enum_key) usages and generate a staged migration plan.
 *
 * This command is intentionally scan-only: it does not mutate business code. It produces:
 * - Markdown plan: storage/logs/enums-usage-plan.md
 * - JSON plan: storage/logs/enums-usage-plan.json
 * - Optional constant-map stub for a later migration phase: config/cms/enums-constant-map.php
 *
 * Inventory resolution is DB-first (enumerate types + enumerates) and falls back to generated native enums under
 * app/Enums when database access is unavailable.
 * @package Cms\Core\Console\Commands
 */
class EnumUsageScanCommand extends Command
{
    /**
     * Command signature.
     *
     * - --paths: comma-separated roots to scan; defaults to app,cms,projects
     * - --with-inactive: include inactive and soft-deleted enumerate rows in DB inventory
     * - --format: output format (md|json|both)
     */
    protected $signature = 'cms:enums:scan-usage
                            {--paths= : Optional comma-separated roots to scan (default: app,cms,projects)}
                            {--with-inactive : Include inactive and soft-deleted enumerates in inventory}
                            {--format=both : Output format (md|json|both)}';

    /**
     * @var string
     */
    protected $description = 'Scan enum_key usages and generate a migration plan toward native PHP enums.';

    /**
     * @var array<int, string>
     */
    private array $excludedDirectories = [
        'vendor',
        'storage',
        'node_modules',
        'base-admin',
    ];

    /**
     * Execute the command.
     *
     * @return int
     */
    public function handle(): int
    {
        $format = strtolower((string)$this->option('format'));
        if (!in_array($format, ['md', 'json', 'both'], true)) {
            $this->error('Invalid --format value. Allowed: md|json|both');

            return self::FAILURE;
        }

        $withInactive = (bool)$this->option('with-inactive');
        $scanRoots = $this->resolveScanRoots((string)$this->option('paths'));

        if ($scanRoots === []) {
            $this->error('No valid scan roots found. Use --paths=app,cms,projects or absolute paths.');

            return self::FAILURE;
        }

        try {
            $inventory = $this->buildInventory($withInactive);

            if (($inventory['type_count'] ?? 0) === 0 || ($inventory['key_count'] ?? 0) === 0) {
                $this->warn('No enum inventory was discovered. Generated reports will be empty.');
            }

            $this->info(sprintf(
                'Inventory source: %s | types: %d | keys: %d',
                (string)$inventory['source'],
                (int)$inventory['type_count'],
                (int)$inventory['key_count']
            ));

            $scanResult = $this->scanUsage($scanRoots, $inventory);
            $payload = $this->buildPlanPayload($scanRoots, $withInactive, $inventory, $scanResult);

            $mdPath = storage_path('logs/enums-usage-plan.md');
            $jsonPath = storage_path('logs/enums-usage-plan.json');

            if ($format === 'md' || $format === 'both') {
                $this->writeMarkdownReport($mdPath, $payload);
                $this->info("Markdown plan written: {$mdPath}");
            }

            if ($format === 'json' || $format === 'both') {
                $this->writeJsonReport($jsonPath, $payload);
                $this->info("JSON plan written: {$jsonPath}");
            }

            $mapResult = $this->writeConstantMapStubIfMissing($payload);
            if ($mapResult['created']) {
                $this->info("Constant mapping stub created: {$mapResult['path']}");
            } else {
                $this->line("Constant mapping stub unchanged: {$mapResult['path']}");
            }

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Files scanned', (string)$scanResult['files_scanned']],
                    ['Total matches', (string)$payload['summary']['total_matches']],
                    ['High confidence', (string)$payload['summary']['by_confidence']['high']],
                    ['Medium confidence', (string)$payload['summary']['by_confidence']['medium']],
                    ['Low confidence', (string)$payload['summary']['by_confidence']['low']],
                    ['Indirect constants', (string)$payload['summary']['indirect_constants']],
                ]
            );

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Resolve scan roots from --paths or defaults.
     *
     * @param string $rawPaths
     * @return array<int, string>
     */
    private function resolveScanRoots(string $rawPaths): array
    {
        $defaults = ['app', 'cms', 'projects'];
        $segments = $rawPaths !== ''
            ? collect(explode(',', $rawPaths))->map(static fn (string $path): string => trim($path))->all()
            : $defaults;

        $roots = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $candidate = Str::startsWith($segment, ['/']) ? $segment : base_path($segment);
            $resolved = realpath($candidate) ?: $candidate;

            if (!is_dir($resolved)) {
                $this->warn("Skipped scan path (not a directory): {$segment}");
                continue;
            }

            $roots[] = rtrim($resolved, DIRECTORY_SEPARATOR);
        }

        return array_values(array_unique($roots));
    }

    /**
     * Build canonical enum inventory with DB-first strategy and app/Enums fallback.
     *
     * @param bool $withInactive
     * @return array<string, mixed>
     */
    private function buildInventory(bool $withInactive): array
    {
        try {
            $inventory = $this->buildInventoryFromDatabase($withInactive);
            if (($inventory['type_count'] ?? 0) > 0 && ($inventory['key_count'] ?? 0) > 0) {
                return $inventory;
            }

            $this->warn('Database inventory is empty. Falling back to app/Enums parsing.');
        } catch (Throwable $throwable) {
            $this->warn('Database inventory unavailable. Falling back to app/Enums parsing.');
            $this->line('Reason: '.$throwable->getMessage());
        }

        return $this->buildInventoryFromEnumFiles();
    }

    /**
     * Build enum inventory from enumerate tables.
     *
     * @param bool $withInactive
     * @return array<string, mixed>
     */
    private function buildInventoryFromDatabase(bool $withInactive): array
    {
        $types = EnumerateType::query()
            ->select(['id', 'key_name'])
            ->orderBy('id')
            ->get();

        $typeIndex = [];
        foreach ($types as $type) {
            $typeKey = trim((string)$type->key_name);
            if ($typeKey === '') {
                continue;
            }

            $enumClass = Str::studly($typeKey).'Enum';
            $typeIndex[(int)$type->id] = [
                'type_key' => $typeKey,
                'enum_class' => $enumClass,
                'values' => [],
            ];
        }

        $query = Enumerate::query()
            ->select(['id', 'enum_key', 'type_id', 'type', 'status', 'deleted_at'])
            ->orderBy('id');

        if (!$withInactive) {
            $query->where('status', true)->whereNull('deleted_at');
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            $enumKey = trim((string)$row->enum_key);
            if ($enumKey === '') {
                continue;
            }

            $typeId = (int)$row->type_id;
            if (!isset($typeIndex[$typeId])) {
                $fallbackType = trim((string)$row->type);
                if ($fallbackType === '') {
                    continue;
                }

                $typeIndex[$typeId] = [
                    'type_key' => $fallbackType,
                    'enum_class' => Str::studly($fallbackType).'Enum',
                    'values' => [],
                ];
            }

            $typeIndex[$typeId]['values'][$enumKey] = $this->caseNameFromEnumKey($enumKey);
        }

        $typesByKey = [];
        foreach ($typeIndex as $entry) {
            $typesByKey[$entry['type_key']] = [
                'enum_class' => $entry['enum_class'],
                'values' => $entry['values'],
            ];
        }

        return $this->finalizeInventory(
            source: 'database',
            withInactive: $withInactive,
            typesByKey: $typesByKey
        );
    }

    /**
     * Build enum inventory by parsing generated native enums under app/Enums.
     *
     * @return array<string, mixed>
     */
    private function buildInventoryFromEnumFiles(): array
    {
        $enumDir = base_path('app/Enums');
        if (!is_dir($enumDir)) {
            return $this->finalizeInventory(
                source: 'app_enums_fallback',
                withInactive: false,
                typesByKey: []
            );
        }

        $typesByKey = [];
        $files = glob($enumDir.DIRECTORY_SEPARATOR.'*Enum.php') ?: [];

        foreach ($files as $filePath) {
            $content = (string)file_get_contents($filePath);

            if (!preg_match('/generated from enumerates type `([^`]+)`/i', $content, $typeMatch)) {
                continue;
            }

            $typeKey = trim((string)($typeMatch[1] ?? ''));
            if ($typeKey === '') {
                continue;
            }

            $className = pathinfo($filePath, PATHINFO_FILENAME);
            $values = [];
            if (preg_match_all('/case\s+([A-Z0-9_]+)\s*=\s*[\'\"]([^\'\"]+)[\'\"]\s*;/', $content, $caseMatches, PREG_SET_ORDER)) {
                foreach ($caseMatches as $caseMatch) {
                    $values[(string)$caseMatch[2]] = (string)$caseMatch[1];
                }
            }

            $typesByKey[$typeKey] = [
                'enum_class' => $className,
                'values' => $values,
            ];
        }

        return $this->finalizeInventory(
            source: 'app_enums_fallback',
            withInactive: true,
            typesByKey: $typesByKey
        );
    }

    /**
     * Finalize inventory lookup indexes used by the scanner.
     *
     * @param string $source
     * @param bool $withInactive
     * @param array<string, array{enum_class: string, values: array<string, string>}> $typesByKey
     * @return array<string, mixed>
     */
    private function finalizeInventory(string $source, bool $withInactive, array $typesByKey): array
    {
        $keySet = [];
        $keyToTypes = [];

        foreach ($typesByKey as $typeKey => $typePayload) {
            foreach (array_keys($typePayload['values']) as $enumKey) {
                $keySet[$enumKey] = true;
                $keyToTypes[$enumKey] = $keyToTypes[$enumKey] ?? [];
                if (!in_array($typeKey, $keyToTypes[$enumKey], true)) {
                    $keyToTypes[$enumKey][] = $typeKey;
                }
            }

            ksort($typesByKey[$typeKey]['values']);
        }

        ksort($typesByKey);
        ksort($keyToTypes);

        return [
            'source' => $source,
            'with_inactive' => $withInactive,
            'types' => $typesByKey,
            'key_set' => $keySet,
            'key_to_types' => $keyToTypes,
            'type_count' => count($typesByKey),
            'key_count' => count($keySet),
        ];
    }

    /**
     * Scan target roots for literal and indirect enum usage patterns.
     *
     * @param array<int, string> $scanRoots
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private function scanUsage(array $scanRoots, array $inventory): array
    {
        $allFiles = $this->discoverPhpFiles($scanRoots);
        $constantDefinitions = $this->discoverConstantDefinitions($allFiles);

        $findings = [];
        $fingerprints = [];
        $constantUsage = [
            'all' => [],
            'status' => [],
            'proven_map' => [],
            'needs_confirmation' => [],
        ];

        foreach ($allFiles as $filePath) {
            $content = (string)file_get_contents($filePath);
            if ($content === '') {
                continue;
            }

            $relativeFile = $this->relativePath($filePath);
            $aliases = $this->extractImportedConstantAliases($content);

            $this->scanWhereLiterals($content, $relativeFile, $inventory, $findings, $fingerprints);
            $this->scanWhereInLiterals($content, $relativeFile, $inventory, $findings, $fingerprints);
            $this->scanWhereMethodLiterals($content, $relativeFile, $inventory, $findings, $fingerprints);
            $this->scanComparisons($content, $relativeFile, $inventory, $findings, $fingerprints);
            $this->scanRuleInLiterals($content, $relativeFile, $inventory, $findings, $fingerprints);
            $this->scanConstantUsages(
                $content,
                $relativeFile,
                $inventory,
                $constantDefinitions,
                $aliases,
                $findings,
                $fingerprints,
                $constantUsage
            );
        }

        usort(
            $findings,
            static function (array $left, array $right): int {
                if ($left['file'] === $right['file']) {
                    return $left['line'] <=> $right['line'];
                }

                return strcmp($left['file'], $right['file']);
            }
        );

        return [
            'files_scanned' => count($allFiles),
            'findings' => $findings,
            'constant_usage' => $constantUsage,
        ];
    }

    /**
     * Discover scan-target PHP files.
     *
     * @param array<int, string> $scanRoots
     * @return array<int, string>
     */
    private function discoverPhpFiles(array $scanRoots): array
    {
        $files = [];

        foreach ($scanRoots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                $pathName = (string)$file->getPathname();

                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if ($this->isExcludedPath($pathName)) {
                    continue;
                }

                $files[] = $pathName;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * Determine whether a file path belongs to an excluded directory.
     *
     * @param string $path
     * @return bool
     */
    private function isExcludedPath(string $path): bool
    {
        $normalized = str_replace('\\\\', '/', $path);

        foreach ($this->excludedDirectories as $segment) {
            if (str_contains($normalized, '/'.$segment.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan where(...) literals including operator form and raw DB contexts.
     *
     * @param string $content
     * @param string $file
     * @param array<string, mixed> $inventory
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @return void
     */
    private function scanWhereLiterals(
        string $content,
        string $file,
        array $inventory,
        array &$findings,
        array &$fingerprints
    ): void {
        $patterns = [
            '/->where\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',
            '/->where\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"][^\'\"]+[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i',
        ];

        foreach ($patterns as $regex) {
            if (!preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $index => $fullMatch) {
                $column = (string)$matches[1][$index][0];
                $literal = (string)$matches[2][$index][0];
                $offset = (int)$fullMatch[1];

                if (!$this->isKnownEnumKey($literal, $inventory)) {
                    continue;
                }

                $patternType = $this->isRawDbContext($content, $offset) ? 'raw_db_query' : 'query_filter';
                $line = $this->lineNumberForOffset($content, $offset);
                $inference = $this->inferTypeKey($literal, $column, $inventory);
                $suggestion = $this->buildWhereSuggestion($column, $literal, $inventory, $inference, $patternType);

                $this->addFinding(
                    findings: $findings,
                    fingerprints: $fingerprints,
                    payload: [
                        'pattern' => $patternType,
                        'file' => $file,
                        'line' => $line,
                        'snippet' => $this->lineSnippetForOffset($content, $offset),
                        'enum_key' => $literal,
                        'type_key' => $inference['type_key'],
                        'confidence' => $this->combineConfidence('high', $inference['confidence']),
                        'suggested_replacement' => $suggestion,
                    ]
                );
            }
        }
    }

    /**
     * Scan whereIn(...) lists and capture known enum keys.
     *
     * @param string $content
     * @param string $file
     * @param array<string, mixed> $inventory
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @return void
     */
    private function scanWhereInLiterals(
        string $content,
        string $file,
        array $inventory,
        array &$findings,
        array &$fingerprints
    ): void {
        if (!preg_match_all('/->whereIn\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*\[(.*?)\]\s*\)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $index => $fullMatch) {
            $column = (string)$matches[1][$index][0];
            $listSource = (string)$matches[2][$index][0];
            $offset = (int)$fullMatch[1];
            $literals = $this->extractQuotedLiterals($listSource);

            $known = array_values(array_filter(
                $literals,
                fn (string $value): bool => $this->isKnownEnumKey($value, $inventory)
            ));

            if ($known === []) {
                continue;
            }

            foreach ($known as $literal) {
                $line = $this->lineNumberForOffset($content, $offset);
                $inference = $this->inferTypeKey($literal, $column, $inventory);
                $suggestion = $this->buildWhereInSuggestion($column, $known, $inventory, $inference);
                $patternType = $this->isRawDbContext($content, $offset) ? 'raw_db_query' : 'query_filter';

                $this->addFinding(
                    findings: $findings,
                    fingerprints: $fingerprints,
                    payload: [
                        'pattern' => $patternType,
                        'file' => $file,
                        'line' => $line,
                        'snippet' => $this->lineSnippetForOffset($content, $offset),
                        'enum_key' => $literal,
                        'type_key' => $inference['type_key'],
                        'confidence' => $this->combineConfidence('high', $inference['confidence']),
                        'suggested_replacement' => $suggestion,
                    ]
                );
            }
        }
    }

    /**
     * Scan ->whereStatus('...') and similar whereX('...') shorthand forms.
     *
     * @param string $content
     * @param string $file
     * @param array<string, mixed> $inventory
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @return void
     */
    private function scanWhereMethodLiterals(
        string $content,
        string $file,
        array $inventory,
        array &$findings,
        array &$fingerprints
    ): void {
        if (!preg_match_all('/->where([A-Z][A-Za-z0-9_]*)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $skipMethods = [
            'In', 'NotIn', 'Between', 'NotBetween', 'Null', 'NotNull', 'Date', 'Day', 'Month', 'Year', 'Time',
            'Raw', 'Column', 'Has', 'Exists', 'Relation', 'JsonContains', 'JsonLength',
        ];

        foreach ($matches[0] as $index => $fullMatch) {
            $methodSuffix = (string)$matches[1][$index][0];
            if (in_array($methodSuffix, $skipMethods, true)) {
                continue;
            }

            $literal = (string)$matches[2][$index][0];
            if (!$this->isKnownEnumKey($literal, $inventory)) {
                continue;
            }

            $column = Str::snake($methodSuffix);
            $offset = (int)$fullMatch[1];
            $line = $this->lineNumberForOffset($content, $offset);
            $inference = $this->inferTypeKey($literal, $column, $inventory);
            $enumRef = $this->enumReference($inference['type_key'], $literal, $inventory);

            $suggestion = $enumRef !== null
                ? "Replace literal with {$enumRef}: ->where{$methodSuffix}({$enumRef})"
                : "Replace literal with {$column} enum case value";

            $this->addFinding(
                findings: $findings,
                fingerprints: $fingerprints,
                payload: [
                    'pattern' => 'query_filter',
                    'file' => $file,
                    'line' => $line,
                    'snippet' => $this->lineSnippetForOffset($content, $offset),
                    'enum_key' => $literal,
                    'type_key' => $inference['type_key'],
                    'confidence' => $this->combineConfidence('high', $inference['confidence']),
                    'suggested_replacement' => $suggestion,
                ]
            );
        }
    }

    /**
     * Scan attribute comparisons against known enum literals.
     *
     * @param string $content
     * @param string $file
     * @param array<string, mixed> $inventory
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @return void
     */
    private function scanComparisons(
        string $content,
        string $file,
        array $inventory,
        array &$findings,
        array &$fingerprints
    ): void {
        $patterns = [
            '/\$[A-Za-z_][A-Za-z0-9_]*(?:->\w+)+\s*(?:===|==|!==|!=)\s*[\'\"]([^\'\"]+)[\'\"]/',
            '/[\'\"]([^\'\"]+)[\'\"]\s*(?:===|==|!==|!=)\s*\$[A-Za-z_][A-Za-z0-9_]*(?:->\w+)+/',
        ];

        foreach ($patterns as $regex) {
            if (!preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $index => $fullMatch) {
                $literal = (string)$matches[1][$index][0];
                if (!$this->isKnownEnumKey($literal, $inventory)) {
                    continue;
                }

                $offset = (int)$fullMatch[1];
                $line = $this->lineNumberForOffset($content, $offset);
                $snippet = $this->lineSnippetForOffset($content, $offset);
                $column = $this->guessComparisonColumn($snippet);
                $inference = $this->inferTypeKey($literal, $column, $inventory);
                $enumRef = $this->enumReference($inference['type_key'], $literal, $inventory);

                $suggestion = $enumRef !== null
                    ? "Replace literal comparison with {$enumRef}" 
                    : 'Replace literal comparison with native enum case value';

                $this->addFinding(
                    findings: $findings,
                    fingerprints: $fingerprints,
                    payload: [
                        'pattern' => 'comparison',
                        'file' => $file,
                        'line' => $line,
                        'snippet' => $snippet,
                        'enum_key' => $literal,
                        'type_key' => $inference['type_key'],
                        'confidence' => $this->combineConfidence('medium', $inference['confidence']),
                        'suggested_replacement' => $suggestion,
                    ]
                );
            }
        }
    }

    /**
     * Scan Rule::in([...]) validation constraints for enum literals.
     *
     * @param string $content
     * @param string $file
     * @param array<string, mixed> $inventory
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @return void
     */
    private function scanRuleInLiterals(
        string $content,
        string $file,
        array $inventory,
        array &$findings,
        array &$fingerprints
    ): void {
        if (!preg_match_all('/Rule::in\(\s*\[(.*?)\]\s*\)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $index => $fullMatch) {
            $listSource = (string)$matches[1][$index][0];
            $offset = (int)$fullMatch[1];
            $literals = $this->extractQuotedLiterals($listSource);

            $known = array_values(array_filter(
                $literals,
                fn (string $value): bool => $this->isKnownEnumKey($value, $inventory)
            ));

            if ($known === []) {
                continue;
            }

            foreach ($known as $literal) {
                $inference = $this->inferTypeKey($literal, 'status', $inventory);
                $suggestion = $this->buildRuleInSuggestion($known, $inventory, $inference);

                $this->addFinding(
                    findings: $findings,
                    fingerprints: $fingerprints,
                    payload: [
                        'pattern' => 'validation_rule',
                        'file' => $file,
                        'line' => $this->lineNumberForOffset($content, $offset),
                        'snippet' => $this->lineSnippetForOffset($content, $offset),
                        'enum_key' => $literal,
                        'type_key' => $inference['type_key'],
                        'confidence' => $this->combineConfidence('high', $inference['confidence']),
                        'suggested_replacement' => $suggestion,
                    ]
                );
            }
        }
    }

    /**
     * Scan Constant::* usages as indirect enum references and collect mapping candidates.
     *
     * @param string $content
     * @param string $file
     * @param array<string, mixed> $inventory
     * @param array<string, array<string, string>> $constantDefinitions
     * @param array<string, string> $aliases
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @param array<string, mixed> $constantUsage
     * @return void
     */
    private function scanConstantUsages(
        string $content,
        string $file,
        array $inventory,
        array $constantDefinitions,
        array $aliases,
        array &$findings,
        array &$fingerprints,
        array &$constantUsage
    ): void {
        if (!preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)::([A-Z0-9_]+)\b/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $index => $fullMatch) {
            $classPrefix = (string)$matches[1][$index][0];
            if ($classPrefix !== 'Constant' && !str_ends_with($classPrefix, 'Constant')) {
                continue;
            }
            $constantName = (string)$matches[2][$index][0];
            if (str_ends_with($constantName, '_')) {
                continue;
            }
            $offset = (int)$fullMatch[1];
            $line = $this->lineNumberForOffset($content, $offset);
            $rawExpression = "{$classPrefix}::{$constantName}";
            $resolvedClass = $this->resolveConstantClass($classPrefix, $aliases);
            $normalizedExpression = $resolvedClass === 'Constant'
                ? "Constant::{$constantName}"
                : "{$resolvedClass}::{$constantName}";

            $resolvedLiteral = $constantDefinitions[$resolvedClass][$constantName] ?? null;
            $isEnumLiteral = $resolvedLiteral !== null && $this->isKnownEnumKey($resolvedLiteral, $inventory);
            $isEnumLikeConstantName = preg_match('/(^STATUS_|^STORAGE_|_STATUS_|_STORAGE_)/', $constantName) === 1;

            // Keep constant scanning focused on enum migration candidates and avoid unrelated table constants.
            if (!$isEnumLiteral && !$isEnumLikeConstantName) {
                continue;
            }

            $inference = $this->inferTypeKey($resolvedLiteral, $this->typeHintFromConstantName($constantName), $inventory);
            $confidence = $isEnumLiteral
                ? $this->combineConfidence('medium', $inference['confidence'])
                : 'low';

            $suggestion = $isEnumLiteral
                ? 'Indirect constant: map first, then replace with native enum case value'
                : 'Indirect constant: unresolved literal, confirm mapping before replacement';

            $this->addFinding(
                findings: $findings,
                fingerprints: $fingerprints,
                payload: [
                    'pattern' => 'constant_indirect',
                    'file' => $file,
                    'line' => $line,
                    'snippet' => $this->lineSnippetForOffset($content, $offset),
                    'enum_key' => $resolvedLiteral,
                    'type_key' => $inference['type_key'],
                    'confidence' => $confidence,
                    'suggested_replacement' => $suggestion,
                    'constant_expression' => $rawExpression,
                    'constant_expression_normalized' => $normalizedExpression,
                ]
            );

            $constantUsage['all'][] = [
                'file' => $file,
                'line' => $line,
                'expression' => $rawExpression,
                'expression_normalized' => $normalizedExpression,
                'resolved_literal' => $resolvedLiteral,
                'type_key' => $inference['type_key'],
            ];

            if (Str::startsWith($constantName, 'STATUS_')) {
                $constantUsage['status'][] = [
                    'file' => $file,
                    'line' => $line,
                    'expression' => $rawExpression,
                    'expression_normalized' => $normalizedExpression,
                    'resolved_literal' => $resolvedLiteral,
                ];
            }

            if ($isEnumLiteral && $inference['type_key'] !== 'unknown') {
                $constantUsage['proven_map'][$inference['type_key']] = $constantUsage['proven_map'][$inference['type_key']] ?? [];
                $constantUsage['proven_map'][$inference['type_key']][$normalizedExpression] = $resolvedLiteral;
            } else {
                $constantUsage['needs_confirmation'][$normalizedExpression] =
                    'Literal value or enum type cannot be proven automatically from code definitions.';
            }
        }
    }

    /**
     * Infer type key from enum key and optional column hint.
     *
     * @param string|null $enumKey
     * @param string|null $columnHint
     * @param array<string, mixed> $inventory
     * @return array{type_key: string, confidence: string}
     */
    private function inferTypeKey(?string $enumKey, ?string $columnHint, array $inventory): array
    {
        if ($enumKey === null || $enumKey === '') {
            return ['type_key' => 'unknown', 'confidence' => 'low'];
        }

        $candidates = $inventory['key_to_types'][$enumKey] ?? [];
        $normalizedColumn = $this->normalizeColumnName($columnHint);

        if ($normalizedColumn !== null && isset($inventory['types'][$normalizedColumn]) && in_array($normalizedColumn, $candidates, true)) {
            return ['type_key' => $normalizedColumn, 'confidence' => 'high'];
        }

        if (count($candidates) === 1) {
            return ['type_key' => (string)$candidates[0], 'confidence' => 'high'];
        }

        if ($normalizedColumn !== null && isset($inventory['types'][$normalizedColumn])) {
            return ['type_key' => $normalizedColumn, 'confidence' => 'medium'];
        }

        if ($candidates !== []) {
            return ['type_key' => (string)$candidates[0], 'confidence' => 'medium'];
        }

        return ['type_key' => 'unknown', 'confidence' => 'low'];
    }

    /**
     * Normalize column hints from query snippets.
     *
     * @param string|null $columnHint
     * @return string|null
     */
    private function normalizeColumnName(?string $columnHint): ?string
    {
        $column = trim((string)$columnHint);
        if ($column === '') {
            return null;
        }

        // Keep the rightmost segment for table-prefixed columns (e.g., products.status -> status).
        if (str_contains($column, '.')) {
            $parts = explode('.', $column);
            $column = (string)end($parts);
        }

        return Str::snake($column);
    }

    /**
     * Build where(...) replacement suggestion.
     *
     * @param string $column
     * @param string $literal
     * @param array<string, mixed> $inventory
     * @param array{type_key: string, confidence: string} $inference
     * @param string $patternType
     * @return string
     */
    private function buildWhereSuggestion(
        string $column,
        string $literal,
        array $inventory,
        array $inference,
        string $patternType
    ): string {
        $enumRef = $this->enumReference($inference['type_key'], $literal, $inventory);

        if ($enumRef === null) {
            return 'Replace literal with enum case value after type mapping confirmation';
        }

        if ($patternType === 'raw_db_query') {
            return "Use enum value in raw DB query: ->where('{$column}', {$enumRef})";
        }

        if ($this->normalizeColumnName($column) === 'status' && Str::contains($enumRef, 'StatusEnum::PUBLISHED->value')) {
            return "Prefer ->where('{$column}', {$enumRef}) or model status scope ->published() when available";
        }

        return "Replace literal with {$enumRef}: ->where('{$column}', {$enumRef})";
    }

    /**
     * Build whereIn(...) replacement suggestion.
     *
     * @param string $column
     * @param array<int, string> $literals
     * @param array<string, mixed> $inventory
     * @param array{type_key: string, confidence: string} $inference
     * @return string
     */
    private function buildWhereInSuggestion(string $column, array $literals, array $inventory, array $inference): string
    {
        $enumRefs = [];
        foreach ($literals as $literal) {
            $ref = $this->enumReference($inference['type_key'], $literal, $inventory);
            if ($ref !== null) {
                $enumRefs[] = $ref;
            }
        }

        if ($enumRefs === []) {
            return 'Replace whereIn literal array with enum values after type mapping confirmation';
        }

        return sprintf(
            "Replace literal list with enum values: ->whereIn('%s', [%s])",
            $column,
            implode(', ', array_values(array_unique($enumRefs)))
        );
    }

    /**
     * Build Rule::in(...) replacement suggestion.
     *
     * @param array<int, string> $literals
     * @param array<string, mixed> $inventory
     * @param array{type_key: string, confidence: string} $inference
     * @return string
     */
    private function buildRuleInSuggestion(array $literals, array $inventory, array $inference): string
    {
        $enumRefs = [];
        foreach ($literals as $literal) {
            $ref = $this->enumReference($inference['type_key'], $literal, $inventory);
            if ($ref !== null) {
                $enumRefs[] = $ref;
            }
        }

        if ($enumRefs === []) {
            return 'Replace Rule::in literals with enum values after mapping confirmation';
        }

        return sprintf(
            'Replace literal list with enum values: Rule::in([%s])',
            implode(', ', array_values(array_unique($enumRefs)))
        );
    }

    /**
     * Resolve enum reference in App\Enums\<Type>Enum::CASE->value format.
     *
     * @param string $typeKey
     * @param string $enumKey
     * @param array<string, mixed> $inventory
     * @return string|null
     */
    private function enumReference(string $typeKey, string $enumKey, array $inventory): ?string
    {
        if ($typeKey === 'unknown') {
            return null;
        }

        $typePayload = $inventory['types'][$typeKey] ?? null;
        if (!is_array($typePayload)) {
            return null;
        }

        $enumClass = (string)($typePayload['enum_class'] ?? '');
        $caseName = (string)($typePayload['values'][$enumKey] ?? '');

        if ($enumClass === '' || $caseName === '') {
            return null;
        }

        return $enumClass.'::'.$caseName.'->value';
    }

    /**
     * Add finding with deduplication.
     *
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, bool> $fingerprints
     * @param array<string, mixed> $payload
     * @return void
     */
    private function addFinding(array &$findings, array &$fingerprints, array $payload): void
    {
        $fingerprint = implode('|', [
            (string)$payload['pattern'],
            (string)$payload['file'],
            (string)$payload['line'],
            (string)($payload['enum_key'] ?? 'null'),
            (string)($payload['constant_expression'] ?? ''),
            (string)$payload['snippet'],
        ]);

        if (isset($fingerprints[$fingerprint])) {
            return;
        }

        $fingerprints[$fingerprint] = true;
        $findings[] = $payload;
    }

    /**
     * Build report payload used by both Markdown and JSON outputs.
     *
     * @param array<int, string> $scanRoots
     * @param bool $withInactive
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $scanResult
     * @return array<string, mixed>
     */
    private function buildPlanPayload(array $scanRoots, bool $withInactive, array $inventory, array $scanResult): array
    {
        $findings = $scanResult['findings'];
        $summaryByType = [];
        $patternCounts = [];
        $confidenceCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $highConfidenceExamples = [];

        foreach ($findings as $finding) {
            $typeKey = (string)($finding['type_key'] ?? 'unknown');
            $pattern = (string)($finding['pattern'] ?? 'unknown');
            $confidence = (string)($finding['confidence'] ?? 'low');

            $summaryByType[$typeKey] = $summaryByType[$typeKey] ?? [
                'type_key' => $typeKey,
                'enum_class' => (string)($inventory['types'][$typeKey]['enum_class'] ?? '-'),
                'total_matches' => 0,
                'high_confidence_auto_replace' => 0,
                'manual_review' => 0,
            ];

            $summaryByType[$typeKey]['total_matches']++;

            if ($confidence === 'high' && $pattern !== 'constant_indirect') {
                $summaryByType[$typeKey]['high_confidence_auto_replace']++;
            } else {
                $summaryByType[$typeKey]['manual_review']++;
            }

            $patternCounts[$pattern] = ($patternCounts[$pattern] ?? 0) + 1;
            $confidenceCounts[$confidence] = ($confidenceCounts[$confidence] ?? 0) + 1;

            if ($confidence === 'high' && $pattern !== 'constant_indirect') {
                $signature = (string)$finding['snippet'].' => '.(string)$finding['suggested_replacement'];
                $highConfidenceExamples[$signature] = [
                    'pattern' => $pattern,
                    'from' => (string)$finding['snippet'],
                    'to' => (string)$finding['suggested_replacement'],
                    'file' => (string)$finding['file'],
                    'line' => (int)$finding['line'],
                ];
            }
        }

        ksort($summaryByType);
        ksort($patternCounts);

        $constantUsage = $scanResult['constant_usage'];
        $statusOccurrences = $constantUsage['status'];
        usort(
            $statusOccurrences,
            static function (array $left, array $right): int {
                if ($left['file'] === $right['file']) {
                    return $left['line'] <=> $right['line'];
                }

                return strcmp($left['file'], $right['file']);
            }
        );

        return [
            'generated_at' => now()->toISOString(),
            'command' => 'cms:enums:scan-usage',
            'options' => [
                'paths' => array_map(fn (string $path): string => $this->relativePath($path), $scanRoots),
                'with_inactive' => $withInactive,
                'format' => (string)$this->option('format'),
            ],
            'scope' => [
                'included_roots' => ['app/**', 'cms/**', 'projects/**'],
                'excluded_roots' => $this->excludedDirectories,
            ],
            'inventory' => [
                'source' => $inventory['source'],
                'with_inactive' => $inventory['with_inactive'],
                'type_count' => $inventory['type_count'],
                'key_count' => $inventory['key_count'],
                'types' => $inventory['types'],
            ],
            'summary' => [
                'files_scanned' => $scanResult['files_scanned'],
                'total_matches' => count($findings),
                'by_pattern' => $patternCounts,
                'by_confidence' => $confidenceCounts,
                'indirect_constants' => count($constantUsage['all']),
            ],
            'summary_by_type' => array_values($summaryByType),
            'high_confidence_patterns' => array_values($highConfidenceExamples),
            'indirect_constant_candidates' => [
                'status_constants' => $statusOccurrences,
                'all_constants' => $constantUsage['all'],
                'proven_map' => $constantUsage['proven_map'],
                'needs_confirmation' => $constantUsage['needs_confirmation'],
            ],
            'findings' => $findings,
            'prompt_phase_2_example' => $this->phaseTwoPromptExample(),
        ];
    }

    /**
     * Write JSON report output.
     *
     * @param string $path
     * @param array<string, mixed> $payload
     * @return void
     * @throws JsonException
     */
    private function writeJsonReport(string $path, array $payload): void
    {
        $this->ensureParentDirectory($path);

        File::put(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    /**
     * Write Markdown report output.
     *
     * @param string $path
     * @param array<string, mixed> $payload
     * @return void
     */
    private function writeMarkdownReport(string $path, array $payload): void
    {
        $this->ensureParentDirectory($path);

        $lines = [];
        $lines[] = '# Enums Usage Migration Plan';
        $lines[] = '';
        $lines[] = '- Generated at: '.$payload['generated_at'];
        $lines[] = '- Inventory source: `'.$payload['inventory']['source'].'`';
        $lines[] = '- Scan paths: `'.implode('`, `', $payload['options']['paths']).'`';
        $lines[] = '- Included inactive inventory rows: `'.($payload['options']['with_inactive'] ? 'true' : 'false').'`';
        $lines[] = '';

        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---:|';
        $lines[] = '| Files scanned | '.$payload['summary']['files_scanned'].' |';
        $lines[] = '| Total matches | '.$payload['summary']['total_matches'].' |';
        $lines[] = '| High confidence | '.$payload['summary']['by_confidence']['high'].' |';
        $lines[] = '| Medium confidence | '.$payload['summary']['by_confidence']['medium'].' |';
        $lines[] = '| Low confidence | '.$payload['summary']['by_confidence']['low'].' |';
        $lines[] = '| Indirect constants | '.$payload['summary']['indirect_constants'].' |';
        $lines[] = '';

        $lines[] = '## Summary Per TypeKey';
        $lines[] = '';
        $lines[] = '| typeKey | EnumClass | Total | High-confidence auto-replace | Manual review |';
        $lines[] = '|---|---|---:|---:|---:|';

        foreach ($payload['summary_by_type'] as $row) {
            $lines[] = sprintf(
                '| `%s` | `%s` | %d | %d | %d |',
                $row['type_key'],
                $row['enum_class'],
                $row['total_matches'],
                $row['high_confidence_auto_replace'],
                $row['manual_review']
            );
        }

        if ($payload['summary_by_type'] === []) {
            $lines[] = '| `-` | `-` | 0 | 0 | 0 |';
        }

        $lines[] = '';
        $lines[] = '## High-Confidence Replacement Rules';
        $lines[] = '';
        $lines[] = '- `where(\'status\', \'published\')` => `where(\'status\', StatusEnum::PUBLISHED->value)` or `->published()` when model scope exists.';
        $lines[] = '- `whereIn(\'status\', [\'draft\',\'published\'])` => `whereIn(\'status\', [StatusEnum::DRAFT->value, StatusEnum::PUBLISHED->value])`.';
        $lines[] = '- `Rule::in([\'draft\',\'published\'])` => `Rule::in([StatusEnum::DRAFT->value, StatusEnum::PUBLISHED->value])`.';
        $lines[] = '';
        $lines[] = 'Detected exact high-confidence examples:';

        $examples = array_slice($payload['high_confidence_patterns'], 0, 50);
        if ($examples === []) {
            $lines[] = '- None found.';
        } else {
            foreach ($examples as $example) {
                $lines[] = sprintf(
                    '- `%s:%d` `%s` => `%s`',
                    $example['file'],
                    $example['line'],
                    $example['from'],
                    $example['to']
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Indirect Constant Mapping Candidates';
        $lines[] = '';
        $lines[] = '### STATUS_* occurrences';
        $lines[] = '';

        $statusOccurrences = $payload['indirect_constant_candidates']['status_constants'];
        if ($statusOccurrences === []) {
            $lines[] = '- None found.';
        } else {
            foreach ($statusOccurrences as $item) {
                $literal = $item['resolved_literal'] ?? 'unknown';
                $lines[] = sprintf(
                    '- `%s:%d` `%s` (normalized `%s`) => `%s`',
                    $item['file'],
                    $item['line'],
                    $item['expression'],
                    $item['expression_normalized'],
                    $literal
                );
            }
        }

        $lines[] = '';
        $lines[] = '### Proven map entries for `config/cms/enums-constant-map.php`';
        $lines[] = '';

        $provenMap = $payload['indirect_constant_candidates']['proven_map'];
        if ($provenMap === []) {
            $lines[] = '- No proven constant-to-enum mappings discovered.';
        } else {
            foreach ($provenMap as $typeKey => $entries) {
                $lines[] = '- `'.$typeKey.'`';
                foreach ($entries as $expression => $literal) {
                    $lines[] = "  - `{$expression}` => `{$literal}`";
                }
            }
        }

        $lines[] = '';
        $lines[] = '### Needs confirmation';
        $lines[] = '';

        $needsConfirmation = $payload['indirect_constant_candidates']['needs_confirmation'];
        if ($needsConfirmation === []) {
            $lines[] = '- None.';
        } else {
            foreach ($needsConfirmation as $expression => $reason) {
                $lines[] = "- `{$expression}`: {$reason}";
            }
        }

        $lines[] = '';
        $lines[] = '## Detailed Findings';
        $lines[] = '';
        $lines[] = 'Full per-match records are provided in `storage/logs/enums-usage-plan.json` under `findings` with file, line, snippet, enum_key, inferred typeKey, confidence, and replacement suggestion.';
        $lines[] = '';

        $lines[] = '## Prompt step 2 example';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = $payload['prompt_phase_2_example'];
        $lines[] = '```';

        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * Create `config/cms/enums-constant-map.php` when missing.
     *
     * Populates only proven mappings and records unresolved constants under __needs_confirmation.
     *
     * @param array<string, mixed> $payload
     * @return array{created: bool, path: string}
     */
    private function writeConstantMapStubIfMissing(array $payload): array
    {
        $path = config_path('cms/enums-constant-map.php');
        if (File::exists($path)) {
            return ['created' => false, 'path' => $path];
        }

        $this->ensureParentDirectory($path);

        $provenMap = $payload['indirect_constant_candidates']['proven_map'] ?? [];
        $needsConfirmation = $payload['indirect_constant_candidates']['needs_confirmation'] ?? [];

        ksort($provenMap);
        ksort($needsConfirmation);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'return [';

        foreach ($provenMap as $typeKey => $entries) {
            $lines[] = "    '{$typeKey}' => [";
            ksort($entries);
            foreach ($entries as $expression => $literal) {
                $lines[] = "        '{$expression}' => '{$literal}',";
            }
            $lines[] = '    ],';
            $lines[] = '';
        }

        $lines[] = "    '__needs_confirmation' => [";
        foreach ($needsConfirmation as $expression => $reason) {
            $escapedReason = str_replace("'", "\\'", (string)$reason);
            $lines[] = "        '{$expression}' => '{$escapedReason}',";
        }
        $lines[] = '    ],';
        $lines[] = '];';

        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);

        return ['created' => true, 'path' => $path];
    }

    /**
     * Build phase-2 prompt example for high-confidence auto-apply execution.
     *
     * @return string
     */
    private function phaseTwoPromptExample(): string
    {
        return 'Step 2: apply only HIGH-confidence replacements from storage/logs/enums-usage-plan.json '
            .'where pattern in [query_filter, comparison, validation_rule] and type_key != unknown. '
            .'Skip constant_indirect and all medium/low confidence findings. '
            .'Generate git patch in small batches (<=50 replacements per commit), run tests for touched modules, '
            .'and write a post-apply report to storage/logs/enums-usage-apply-report.md.';
    }

    /**
     * Discover constant string literal definitions from parsed files.
     *
     * @param array<int, string> $files
     * @return array<string, array<string, string>>
     */
    private function discoverConstantDefinitions(array $files): array
    {
        $definitions = [];

        foreach ($files as $filePath) {
            if (!Str::endsWith($filePath, 'Constant.php')) {
                continue;
            }

            $content = (string)file_get_contents($filePath);
            if ($content === '') {
                continue;
            }

            if (!preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $classMatch)) {
                continue;
            }

            $className = (string)$classMatch[1];
            if (!preg_match_all('/(?:public|protected|private)\s+const\s+([A-Z0-9_]+)\s*=\s*[\'\"]([^\'\"]*)[\'\"]\s*;/', $content, $constMatches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($constMatches as $constMatch) {
                $constName = (string)$constMatch[1];
                $constLiteral = (string)$constMatch[2];
                $definitions[$className] = $definitions[$className] ?? [];
                $definitions[$className][$constName] = $constLiteral;
            }
        }

        return $definitions;
    }

    /**
     * Extract `use ... as Alias` imports for constant classes to resolve alias-based expressions.
     *
     * @param string $content
     * @return array<string, string>
     */
    private function extractImportedConstantAliases(string $content): array
    {
        $aliases = [];

        if (!preg_match_all('/use\s+([^;]+?);/', $content, $useMatches, PREG_SET_ORDER)) {
            return $aliases;
        }

        foreach ($useMatches as $useMatch) {
            $statement = trim((string)$useMatch[1]);
            if ($statement === '') {
                continue;
            }

            if (preg_match('/^([^\s]+)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $statement, $aliasMatch)) {
                $fqn = (string)$aliasMatch[1];
                $alias = (string)$aliasMatch[2];
                $aliases[$alias] = class_basename($fqn);
                continue;
            }

            $aliases[class_basename($statement)] = class_basename($statement);
        }

        return $aliases;
    }

    /**
     * Resolve class aliases used in `<Alias>Constant::CONST` expressions.
     *
     * @param string $rawClass
     * @param array<string, string> $aliases
     * @return string
     */
    private function resolveConstantClass(string $rawClass, array $aliases): string
    {
        if (isset($aliases[$rawClass])) {
            return (string)$aliases[$rawClass];
        }

        return $rawClass;
    }

    /**
     * Infer an initial type hint from constant naming pattern (STATUS_*, STORAGE_*, etc.).
     *
     * @param string $constantName
     * @return string|null
     */
    private function typeHintFromConstantName(string $constantName): ?string
    {
        if (preg_match('/^([A-Z0-9]+)_/', $constantName, $match)) {
            return Str::lower((string)$match[1]);
        }

        return null;
    }

    /**
     * Check whether a string is present in canonical enum inventory.
     *
     * @param string $value
     * @param array<string, mixed> $inventory
     * @return bool
     */
    private function isKnownEnumKey(string $value, array $inventory): bool
    {
        return isset($inventory['key_set'][$value]);
    }

    /**
     * Extract quoted literal values from an expression fragment.
     *
     * @param string $source
     * @return array<int, string>
     */
    private function extractQuotedLiterals(string $source): array
    {
        if (!preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $source, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $matches[1])));
    }

    /**
     * Detect DB::table context near a match offset.
     *
     * @param string $content
     * @param int $offset
     * @return bool
     */
    private function isRawDbContext(string $content, int $offset): bool
    {
        $start = max(0, $offset - 320);
        $lookBack = substr($content, $start, min(320, $offset));

        return str_contains($lookBack, 'DB::table(')
            || str_contains($lookBack, 'DB::query(')
            || str_contains($lookBack, 'DB::select(')
            || str_contains($lookBack, 'DB::statement(');
    }

    /**
     * Resolve line number from byte offset.
     *
     * @param string $content
     * @param int $offset
     * @return int
     */
    private function lineNumberForOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
    }

    /**
     * Capture a concise single-line snippet around a match offset.
     *
     * @param string $content
     * @param int $offset
     * @return string
     */
    private function lineSnippetForOffset(string $content, int $offset): string
    {
        $lineStart = strrpos(substr($content, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $lineEnd = strpos($content, "\n", $offset);
        $lineEnd = $lineEnd === false ? strlen($content) : $lineEnd;

        $line = trim(substr($content, $lineStart, max(0, $lineEnd - $lineStart)));
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        if (mb_strlen($line) > 240) {
            return mb_substr($line, 0, 237).'...';
        }

        return $line;
    }

    /**
     * Best-effort extraction of compared property name for comparison inference.
     *
     * @param string $snippet
     * @return string|null
     */
    private function guessComparisonColumn(string $snippet): ?string
    {
        if (preg_match('/->([A-Za-z_][A-Za-z0-9_]*)\s*(?:===|==|!==|!=)/', $snippet, $match)) {
            return (string)$match[1];
        }

        if (preg_match('/(?:===|==|!==|!=)\s*\$[A-Za-z_][A-Za-z0-9_]*(?:->([A-Za-z_][A-Za-z0-9_]*))/', $snippet, $match)) {
            return (string)$match[1];
        }

        return null;
    }

    /**
     * Convert enum_key to deterministic enum case name.
     *
     * @param string $enumKey
     * @return string
     */
    private function caseNameFromEnumKey(string $enumKey): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper($enumKey)) ?? strtoupper($enumKey);

        return trim($normalized, '_');
    }

    /**
     * Merge base and inferred confidence into a conservative final confidence.
     *
     * @param string $base
     * @param string $inferred
     * @return string
     */
    private function combineConfidence(string $base, string $inferred): string
    {
        $order = ['low' => 1, 'medium' => 2, 'high' => 3];

        return ($order[$base] <= $order[$inferred]) ? $base : $inferred;
    }

    /**
     * Convert absolute path to repository-relative path when possible.
     *
     * @param string $path
     * @return string
     */
    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $base)) {
            return str_replace('\\\\', '/', substr($path, strlen($base)));
        }

        return str_replace('\\\\', '/', $path);
    }

    /**
     * Ensure output file parent directory exists.
     *
     * @param string $filePath
     * @return void
     */
    private function ensureParentDirectory(string $filePath): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }
}
