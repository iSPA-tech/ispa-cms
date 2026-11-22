<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audits base-admin translation usage against import payloads and generated runtime packs.
 *
 * Scans the base-admin Vue/JS/TS source files for static and literal translation key usages,
 * then cross-references them against the CMS import payload files and runtime JSON packs.
 * Produces a structured report identifying missing keys, legacy alias usage, and unharvested
 * literal text strings that should be extracted as translation keys.
 *
 * Usage: php artisan cms:admin-i18n-audit
 * Options:
 *   --frontend        Path to the base-admin workspace root (default: ../base-admin)
 *   --export          Path to the exported translates.php payload
 *   --modules-root    Path to the Laravel cms modules root
 *   --runtime-dir     Path to generated admin runtime packs
 *   --report-dir      Directory where audit reports should be written
 *   --write-report    Write JSON and Markdown reports (0/1, default: 1)
 *   --strict          Fail when missing import or runtime keys are detected (0/1)
 *   --refresh-usage   Run i18n:scan-translates in dry-run mode before auditing (0/1)
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AdminI18nAuditCommand extends Command
{
    protected $signature = 'cms:admin-i18n-audit
        {--frontend= : Path to the base-admin workspace root}
        {--export= : Path to the exported translates.php payload}
        {--modules-root= : Path to the Laravel cms modules root}
        {--runtime-dir= : Path to generated admin runtime packs}
        {--report-dir= : Directory where audit reports should be written}
        {--write-report=1 : Write JSON and Markdown reports (0/1)}
        {--strict=0 : Fail when missing import or runtime keys are detected (0/1)}
        {--refresh-usage=0 : Run i18n:scan-translates in dry-run mode before auditing (0/1)}';

    protected $description = 'Audit base-admin translation usage against import payloads and generated runtime packs';

    private const LEGACY_PAGE_MODULE_MAP = [
        'education' => 'Education',
        'student' => 'Education',
        'product' => 'Product',
        'warehouse' => 'Product',
        'real-estate' => 'RealEstate',
        'content' => 'Content',
        'post' => 'Content',
        'category' => 'Content',
        'comment' => 'Content',
        'media' => 'Content',
        'order' => 'Ecommerce',
        'customer' => 'Ecommerce',
    ];

    private const UI_LITERAL_PROPERTY_NAMES = [
        'title',
        'label',
        'placeholder',
        'message',
        'description',
        'subtitle',
        'content',
        'text',
        'okText',
        'cancelText',
        'emptyText',
        'buttonText',
    ];

    /**
     * Execute the console command.
     *
     * Orchestrates the full audit pipeline: static key scan, literal text scan,
     * import inventory load, alias map build, runtime inventory load, usage aggregation,
     * report generation, optional file write, and optional strict-mode failure.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        $frontendRoot = $this->normalizePath(
            $this->option('frontend') ?: base_path('../base-admin')
        );
        $exportPath = $this->normalizePath(
            $this->option('export') ?: base_path('cms/Core/database/seeders/exported/translates.php')
        );
        $modulesRoot = $this->normalizePath(
            $this->option('modules-root') ?: base_path('cms')
        );
        $runtimeDir = $this->normalizePath(
            $this->option('runtime-dir')
            ?: storage_path('app/public/i18n/' . config('ispa-cms.app_version') . '/adm')
        );
        $reportDir = $this->normalizePath(
            $this->option('report-dir') ?: storage_path('app/reports/admin-i18n')
        );

        if (!File::isDirectory($frontendRoot)) {
            $this->error("Frontend root not found: {$frontendRoot}");
            return self::FAILURE;
        }

        if (!File::exists($exportPath)) {
            $this->error("Export payload not found: {$exportPath}");
            return self::FAILURE;
        }

        $dynamicScan = null;
        if ($this->asBoolean($this->option('refresh-usage'))) {
            $dynamicScan = $this->runLegacyUsageScanner($frontendRoot);
        }

        $this->info('Scanning base-admin translation usage...');
        $usages = $this->collectStaticKeyUsages($frontendRoot);
        $literalCandidates = $this->collectLiteralTextCandidates($frontendRoot);

        $inventory = $this->loadImportInventory($exportPath, $modulesRoot);
        $aliasMap = $this->buildAliasMap($inventory);
        $runtimeInventory = $this->loadRuntimeInventory($runtimeDir);
        $aggregatedUsage = $this->aggregateUsage($usages, $inventory, $aliasMap, $runtimeInventory);

        $report = $this->buildReport(
            $frontendRoot,
            $exportPath,
            $modulesRoot,
            $runtimeDir,
            $aggregatedUsage,
            $literalCandidates,
            $runtimeInventory,
            $dynamicScan
        );

        $this->renderSummary($report);

        if ($this->asBoolean($this->option('write-report'))) {
            $writtenReport = $this->writeReportFiles($reportDir, $report);
            $this->line("Report JSON: {$writtenReport['json']}");
            $this->line("Report Markdown: {$writtenReport['markdown']}");
        }

        if ($this->asBoolean($this->option('strict')) && $this->hasStrictFailures($report)) {
            $this->error('Admin i18n audit failed in strict mode.');
            return self::FAILURE;
        }

        $this->info('Admin i18n audit completed.');
        return self::SUCCESS;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return $path;
        }

        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        return str_replace('\\', '/', $path);
    }

    private function asBoolean(mixed $value): bool
    {
        return (string)$value === '1' || $value === true || $value === 1;
    }

    private function runLegacyUsageScanner(string $frontendRoot): ?array
    {
        $this->info('Running legacy usage scanner in dry-run mode...');

        try {
            Artisan::call('i18n:scan-translates', [
                '--frontend' => $frontendRoot,
                '--backend' => base_path(),
                '--update-db' => 0,
                '--update-used-in' => 0,
                '--log-dynamic' => 1,
                '--dry-run' => 1,
            ]);

            $output = Artisan::output();
            $logPath = null;

            if (preg_match('/Dynamic log:\s*(.+)/', $output, $matches) === 1) {
                $logPath = trim($matches[1]);
            }

            $dynamicCount = 0;
            if ($logPath && File::exists($logPath)) {
                $lines = preg_split("/\r\n|\n|\r/", trim((string)File::get($logPath)));
                $dynamicCount = count(array_filter($lines, static fn($line) => trim((string)$line) !== ''));
            }

            return [
                'log_path' => $logPath,
                'dynamic_entries' => $dynamicCount,
            ];
        } catch (Throwable $throwable) {
            $this->warn('Legacy usage scanner could not complete: ' . $throwable->getMessage());

            return [
                'error' => $throwable->getMessage(),
            ];
        }
    }

    private function collectStaticKeyUsages(string $frontendRoot): array
    {
        $scanRoot = File::isDirectory($frontendRoot . '/src') ? $frontendRoot . '/src' : $frontendRoot;
        $usageMap = [];

        $this->scanDirectory($scanRoot, ['vue', 'js', 'ts', 'jsx', 'tsx'], function (string $file, string $content) use (&$usageMap, $frontendRoot): void {
            $ownership = $this->resolveOwnership($file, $frontendRoot);

            foreach ($this->extractStaticTranslationKeys($content) as [$key, $line]) {
                $token = $key . '|' . $file . '|' . $line;
                $usageMap[$token] = [
                    'key' => $key,
                    'file' => $file,
                    'line' => $line,
                    'ownership_type' => $ownership['type'],
                    'ownership_label' => $ownership['label'],
                ];
            }
        });

        return array_values($usageMap);
    }

    private function collectLiteralTextCandidates(string $frontendRoot): array
    {
        $scanRoot = File::isDirectory($frontendRoot . '/src') ? $frontendRoot . '/src' : $frontendRoot;
        $candidates = [];

        $this->scanDirectory($scanRoot, ['vue', 'js', 'ts', 'jsx', 'tsx'], function (string $file, string $content) use (&$candidates, $frontendRoot): void {
            $ownership = $this->resolveOwnership($file, $frontendRoot);

            foreach ($this->extractLiteralCandidates($content) as [$text, $line, $kind]) {
                $token = $kind . '|' . $text . '|' . $file . '|' . $line;
                $candidates[$token] = [
                    'text' => $text,
                    'file' => $file,
                    'line' => $line,
                    'kind' => $kind,
                    'ownership_type' => $ownership['type'],
                    'ownership_label' => $ownership['label'],
                ];
            }
        });

        return array_values($candidates);
    }

    private function scanDirectory(string $root, array $extensions, callable $callback): void
    {
        if (!File::isDirectory($root)) {
            return;
        }

        $files = File::allFiles($root);

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (Str::contains($path, ['/node_modules/', '/vendor/', '/dist/', '/build/', '/coverage/', '/storage/'])) {
                continue;
            }

            $filename = $file->getFilename();
            $extension = $file->getExtension();
            $allowed = in_array($extension, $extensions, true);

            if (!$allowed && Str::endsWith($filename, '.blade.php') && in_array('blade.php', $extensions, true)) {
                $allowed = true;
            }

            if (!$allowed) {
                continue;
            }

            $content = File::get($path);
            $callback($path, $content);
        }
    }

    private function extractStaticTranslationKeys(string $content): array
    {
        $results = [];

        $patterns = [
            '/\$\s*t\s*\(\s*(["\'])([^"\']+)\1\s*(?:,|\))/u',
            '/(?<![\w$])t\s*\(\s*(["\'])([^"\']+)\1\s*(?:,|\))/u',
            '/i18n\.global\.t\s*\(\s*(["\'])([^"\']+)\1\s*(?:,|\))/u',
            '/\$\s*t\s*\(\s*`([^`$]+)`\s*(?:,|\))/u',
            '/\btext\s*:\s*(["\'])([^"\']+)\1/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $captureIndex = isset($matches[2]) ? 2 : 1;

            foreach ($matches[$captureIndex] as $index => $capture) {
                $key = trim((string)($capture[0] ?? ''));
                $offset = $matches[0][$index][1] ?? 0;

                if (!$this->looksLikeTranslationKey($key)) {
                    continue;
                }

                $results[] = [$key, $this->offsetToLine($content, $offset)];
            }
        }

        $deduped = [];
        foreach ($results as [$key, $line]) {
            $deduped[$key . '@' . $line] = [$key, $line];
        }

        return array_values($deduped);
    }

    private function extractLiteralCandidates(string $content): array
    {
        $results = [];

        $uiPropertyPattern = '/\b(' . implode('|', array_map('preg_quote', self::UI_LITERAL_PROPERTY_NAMES)) . ')\s*[:=]\s*(["\'])([^"\']+)\2/u';
        if (preg_match_all($uiPropertyPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[3] as $index => $capture) {
                $text = trim((string)($capture[0] ?? ''));
                $offset = $matches[0][$index][1] ?? 0;

                if ($this->shouldKeepLiteralCandidate($text) && !$this->lineContainsTranslationCall($content, $offset)) {
                    $results[] = [$text, $this->offsetToLine($content, $offset), 'ui-property'];
                }
            }
        }

        $templateAttributePattern = '/\b(label|placeholder|title|ok-text|cancel-text|empty-text)\s*=\s*(["\'])([^"\']+)\2/u';
        if (preg_match_all($templateAttributePattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[3] as $index => $capture) {
                $text = trim((string)($capture[0] ?? ''));
                $offset = $matches[0][$index][1] ?? 0;

                if ($this->shouldKeepLiteralCandidate($text) && !$this->lineContainsTranslationCall($content, $offset)) {
                    $results[] = [$text, $this->offsetToLine($content, $offset), 'template-attribute'];
                }
            }
        }

        $deduped = [];
        foreach ($results as [$text, $line, $kind]) {
            $deduped[$kind . '|' . $text . '@' . $line] = [$text, $line, $kind];
        }

        return array_values($deduped);
    }

    private function shouldKeepLiteralCandidate(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || $this->looksLikeTranslationKey($value)) {
            return false;
        }

        if (Str::startsWith($value, [':', '/', '#', '.', '@', 'http://', 'https://', 'mailto:'])) {
            return false;
        }

        if (preg_match('/^\{\{.*\}\}$/u', $value) === 1) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9_.-]+$/u', $value) === 1 && !Str::contains($value, ' ')) {
            return false;
        }

        if (preg_match('/^[A-Z0-9_]+$/u', $value) === 1) {
            return false;
        }

        if (Str::contains($value, ['<', '>', '{{', '}}'])) {
            return false;
        }

        return preg_match('/[A-Za-z]/u', $value) === 1 || preg_match('/[\p{L}]/u', $value) === 1;
    }

    private function lineContainsTranslationCall(string $content, int $offset): bool
    {
        $start = strrpos(substr($content, 0, $offset), "\n");
        $end = strpos($content, "\n", $offset);

        $start = $start === false ? 0 : $start + 1;
        $end = $end === false ? strlen($content) : $end;
        $line = substr($content, $start, $end - $start);

        return Str::contains($line, ['$t(', 't(', 'i18n.global.t(']);
    }

    private function offsetToLine(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
    }

    private function looksLikeTranslationKey(string $value): bool
    {
        return preg_match('/^[a-z](?:[a-z0-9_-]*)(?:\.[A-Za-z0-9_-]+)+(?:\.[A-Za-z0-9_-]+)*$/u', trim($value)) === 1;
    }

    private function resolveOwnership(string $file, string $frontendRoot): array
    {
        $normalizedFile = str_replace('\\', '/', $file);
        $normalizedRoot = rtrim(str_replace('\\', '/', $frontendRoot), '/');
        $relative = ltrim(Str::after($normalizedFile, $normalizedRoot), '/');

        if (preg_match('#^src/modules/([^/]+)/#', $relative, $matches) === 1) {
            return [
                'type' => 'module',
                'label' => $this->normalizeModuleToken($matches[1]),
            ];
        }

        if (preg_match('#^src/pages/([^/]+)/#', $relative, $matches) === 1) {
            $pageRoot = trim((string)$matches[1]);

            if (isset(self::LEGACY_PAGE_MODULE_MAP[$pageRoot])) {
                return [
                    'type' => 'module',
                    'label' => self::LEGACY_PAGE_MODULE_MAP[$pageRoot],
                ];
            }
        }

        return [
            'type' => 'core',
            'label' => 'Core',
        ];
    }

    private function normalizeModuleToken(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return 'Unknown';
        }

        if (str_contains($value, '-')) {
            return str_replace(' ', '', ucwords(str_replace('-', ' ', $value)));
        }

        return ucfirst($value);
    }

    private function loadImportInventory(string $exportPath, string $modulesRoot): array
    {
        $inventory = [];

        $this->mergePayloadRowsIntoInventory($inventory, $this->requirePayloadFile($exportPath), 'core-export');
        $coreSidecars = File::glob(dirname($exportPath) . '/translates-admin-*.php');
        sort($coreSidecars);
        foreach ($coreSidecars as $coreSidecar) {
            if (!File::exists($coreSidecar)) {
                continue;
            }

            $this->mergePayloadRowsIntoInventory(
                $inventory,
                $this->requirePayloadFile($coreSidecar),
                'core-sidecar:' . basename($coreSidecar)
            );
        }

        if (File::isDirectory($modulesRoot)) {
            foreach (File::directories($modulesRoot) as $modulePath) {
                $moduleName = basename($modulePath);
                $seederPath = $modulePath . '/database/seeders/sql/translate.php';
                $sidecarPaths = File::glob($modulePath . '/database/seeders/sql/translate-admin-*.php');
                sort($sidecarPaths);

                if (!File::exists($seederPath)) {
                    $seederPath = null;
                }

                if ($seederPath !== null) {
                    $this->mergePayloadRowsIntoInventory(
                        $inventory,
                        $this->requirePayloadFile($seederPath),
                        'module-seeder:' . $moduleName
                    );
                }

                foreach ($sidecarPaths as $sidecarPath) {
                    if (!File::exists($sidecarPath)) {
                        continue;
                    }

                    $this->mergePayloadRowsIntoInventory(
                        $inventory,
                        $this->requirePayloadFile($sidecarPath),
                        'module-sidecar:' . $moduleName . ':' . basename($sidecarPath)
                    );
                }
            }
        }

        ksort($inventory);

        return $inventory;
    }

    private function requirePayloadFile(string $path): array
    {
        $payload = require $path;

        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    private function mergePayloadRowsIntoInventory(array &$inventory, array $rows, string $source): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $canonicalKey = $this->resolveCanonicalImportKey($row);
            if ($canonicalKey === null) {
                continue;
            }

            if (!isset($inventory[$canonicalKey])) {
                $inventory[$canonicalKey] = [
                    'key' => $canonicalKey,
                    'group' => Str::before($canonicalKey, '.'),
                    'modules' => [],
                    'for_adm' => false,
                    'sources' => [],
                    'row_names' => [],
                ];
            }

            $inventory[$canonicalKey]['for_adm'] = $inventory[$canonicalKey]['for_adm'] || ((bool)($row['for_adm'] ?? false));
            $inventory[$canonicalKey]['sources'][$source] = true;
            $inventory[$canonicalKey]['row_names'][trim((string)($row['name'] ?? ''))] = true;

            foreach ($this->normalizeModulesPayload($row['modules'] ?? []) as $module) {
                $inventory[$canonicalKey]['modules'][$module] = true;
            }
        }

        foreach ($inventory as &$entry) {
            $entry['sources'] = array_values(array_keys($entry['sources']));
            $entry['row_names'] = array_values(array_keys($entry['row_names']));
            $entry['modules'] = array_values(array_keys($entry['modules']));
        }
    }

    private function normalizeModulesPayload(mixed $modules): array
    {
        if (is_string($modules)) {
            $decoded = json_decode($modules, true);
            $modules = is_array($decoded) ? $decoded : [$modules];
        }

        if (!is_array($modules)) {
            return [];
        }

        $normalized = [];
        foreach ($modules as $module) {
            if (!is_scalar($module)) {
                continue;
            }

            $value = trim((string)$module);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_values(array_keys($normalized));
    }

    private function resolveCanonicalImportKey(array $row): ?string
    {
        $group = trim((string)($row['group_name'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));

        if ($group === '' || $name === '') {
            return null;
        }

        if (Str::contains($name, '.')) {
            if ($group === 'validation') {
                if (Str::startsWith($name, 'validation.attributes.')) {
                    return $name;
                }

                if (Str::startsWith($name, 'validation.v.a.')) {
                    return 'validation.attributes.' . Str::after($name, 'validation.v.a.');
                }

                if (Str::startsWith($name, 'validation.a.')) {
                    return 'validation.attributes.' . Str::after($name, 'validation.a.');
                }

                if (Str::startsWith($name, 'validation.')) {
                    return $name;
                }

                if (Str::startsWith($name, 'v.a.')) {
                    return 'validation.attributes.' . Str::after($name, 'v.a.');
                }

                if (Str::startsWith($name, 'a.')) {
                    return 'validation.attributes.' . Str::after($name, 'a.');
                }
            }

            return $name;
        }

        $base = $name;

        if ($group === 'validation') {
            if (Str::startsWith($base, 'v.a.')) {
                $base = 'attributes.' . Str::after($base, 'v.a.');
            } elseif (Str::startsWith($base, 'a.')) {
                $base = 'attributes.' . Str::after($base, 'a.');
            }
        }

        return $group . '.' . $base;
    }

    private function buildAliasMap(array $inventory): array
    {
        $aliasMap = [];

        foreach ($inventory as $canonicalKey => $entry) {
            $group = $entry['group'] ?? Str::before($canonicalKey, '.');
            $base = Str::after($canonicalKey, $group . '.');

            if (($entry['for_adm'] ?? false) !== true) {
                continue;
            }

            $aliasMap[$canonicalKey][] = $canonicalKey;

            if ($group === 'validation') {
                if (Str::startsWith($base, 'attributes.')) {
                    $aliasMap['v.a.' . Str::after($base, 'attributes.') ][] = $canonicalKey;
                }

                continue;
            }

            $shortGroup = mb_substr($group, 0, 1);
            $aliasMap[$shortGroup . '.' . $base][] = $canonicalKey;

            $adminAlias = $this->buildAdminAlias($shortGroup, $base);
            if ($adminAlias !== null) {
                $aliasMap[$adminAlias][] = $canonicalKey;
            }
        }

        foreach ($aliasMap as $alias => $keys) {
            $aliasMap[$alias] = array_values(array_unique($keys));
        }

        return $aliasMap;
    }

    private function buildAdminAlias(string $shortGroup, string $baseName): ?string
    {
        $baseName = trim($baseName);
        if ($baseName === '') {
            return null;
        }

        if (!Str::contains($baseName, '.')) {
            return $shortGroup . '.' . $baseName;
        }

        $segments = explode('.', $baseName);
        $first = array_shift($segments);

        $mapped = match ($first) {
            'attributes' => 'a',
            'between' => 'bw',
            default => $first,
        };

        if (empty($segments)) {
            return $shortGroup . '.' . $mapped;
        }

        return $shortGroup . '.' . $mapped . '.' . implode('.', $segments);
    }

    private function loadRuntimeInventory(string $runtimeDir): array
    {
        $inventory = [
            'locales' => [
                'en' => [],
                'vi' => [],
            ],
            'available_locales' => [],
        ];

        foreach (['en', 'vi'] as $locale) {
            $path = rtrim($runtimeDir, '/') . '/' . $locale . '.json';

            if (!File::exists($path)) {
                continue;
            }

            $payload = json_decode((string)File::get($path), true);
            if (!is_array($payload)) {
                continue;
            }

            $flattened = [];
            $this->flattenRuntimePayload($payload, '', $flattened);
            $inventory['locales'][$locale] = $flattened;
            $inventory['available_locales'][] = $locale;
        }

        return $inventory;
    }

    private function flattenRuntimePayload(array $payload, string $prefix, array &$flattened): void
    {
        foreach ($payload as $key => $value) {
            if ($key === '__groups') {
                continue;
            }

            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if (is_array($value)) {
                $this->flattenRuntimePayload($value, $path, $flattened);
                continue;
            }

            $flattened[$path] = true;
        }
    }

    private function aggregateUsage(array $usages, array $inventory, array $aliasMap, array $runtimeInventory): array
    {
        $aggregated = [];

        foreach ($usages as $usage) {
            $key = $usage['key'];

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'key' => $key,
                    'occurrences' => 0,
                    'files' => [],
                    'ownership' => [
                        'core' => false,
                        'modules' => [],
                    ],
                    'canonical_candidates' => [],
                    'import_present' => false,
                    'runtime_missing_locales' => [],
                    'legacy_alias_usage' => false,
                ];
            }

            $aggregated[$key]['occurrences']++;
            $aggregated[$key]['files'][] = [
                'file' => $usage['file'],
                'line' => $usage['line'],
                'ownership_type' => $usage['ownership_type'],
                'ownership_label' => $usage['ownership_label'],
            ];

            if ($usage['ownership_type'] === 'module') {
                $aggregated[$key]['ownership']['modules'][$usage['ownership_label']] = true;
            } else {
                $aggregated[$key]['ownership']['core'] = true;
            }
        }

        foreach ($aggregated as $key => &$entry) {
            $candidates = $inventory[$key]['for_adm'] ?? false
                ? [$key]
                : ($aliasMap[$key] ?? []);

            $candidates = array_values(array_unique(array_filter(
                $candidates,
                static fn(string $candidate): bool => isset($inventory[$candidate]) && (($inventory[$candidate]['for_adm'] ?? false) === true)
            )));

            $entry['canonical_candidates'] = $candidates;
            $entry['import_present'] = !empty($candidates);
            $entry['legacy_alias_usage'] = count($candidates) === 1 && $candidates[0] !== $key;

            $missingLocales = [];
            foreach (['en', 'vi'] as $locale) {
                if (!isset($runtimeInventory['locales'][$locale][$key])) {
                    $missingLocales[] = $locale;
                }
            }

            $entry['runtime_missing_locales'] = $missingLocales;
            $entry['ownership']['modules'] = array_values(array_keys($entry['ownership']['modules']));
        }

        ksort($aggregated);

        return array_values($aggregated);
    }

    private function buildReport(
        string $frontendRoot,
        string $exportPath,
        string $modulesRoot,
        string $runtimeDir,
        array $aggregatedUsage,
        array $literalCandidates,
        array $runtimeInventory,
        ?array $dynamicScan
    ): array
    {
        $coreMissing = [];
        $moduleMissing = [];
        $runtimeMissing = [];
        $aliasUsage = [];

        foreach ($aggregatedUsage as $entry) {
            if (!$entry['import_present']) {
                if ($entry['ownership']['core']) {
                    $coreMissing[] = $entry;
                } else {
                    $moduleMissing[] = $entry;
                }

                continue;
            }

            if (!empty($entry['runtime_missing_locales'])) {
                $runtimeMissing[] = $entry;
            }

            if ($entry['legacy_alias_usage']) {
                $aliasUsage[] = $entry;
            }
        }

        $coreLiteralCandidates = array_values(array_filter(
            $literalCandidates,
            static fn(array $candidate): bool => $candidate['ownership_type'] === 'core'
        ));
        $moduleLiteralCandidates = array_values(array_filter(
            $literalCandidates,
            static fn(array $candidate): bool => $candidate['ownership_type'] === 'module'
        ));

        return [
            'generated_at' => now()->toIso8601String(),
            'inputs' => [
                'frontend_root' => $frontendRoot,
                'export_path' => $exportPath,
                'modules_root' => $modulesRoot,
                'runtime_dir' => $runtimeDir,
            ],
            'summary' => [
                'used_key_count' => count($aggregatedUsage),
                'core_missing_count' => count($coreMissing),
                'module_missing_count' => count($moduleMissing),
                'runtime_missing_count' => count($runtimeMissing),
                'alias_usage_count' => count($aliasUsage),
                'literal_text_candidate_count' => count($literalCandidates),
                'runtime_available_locales' => $runtimeInventory['available_locales'],
                'scanner_dynamic_entries' => $dynamicScan['dynamic_entries'] ?? null,
            ],
            'core_missing_candidates' => $coreMissing,
            'module_missing_candidates' => $moduleMissing,
            'runtime_missing_candidates' => $runtimeMissing,
            'legacy_alias_usage' => $aliasUsage,
            'literal_text_candidates' => [
                'core' => $coreLiteralCandidates,
                'module' => $moduleLiteralCandidates,
            ],
            'dynamic_scan' => $dynamicScan,
            'used_keys' => $aggregatedUsage,
        ];
    }

    private function renderSummary(array $report): void
    {
        $summary = $report['summary'];

        $this->info('Admin i18n audit summary');
        $this->line('Used keys: ' . $summary['used_key_count']);
        $this->line('Missing from import payloads (core/shared): ' . $summary['core_missing_count']);
        $this->line('Missing from import payloads (module-specific): ' . $summary['module_missing_count']);
        $this->line('Missing from runtime packs: ' . $summary['runtime_missing_count']);
        $this->line('Legacy alias usage: ' . $summary['alias_usage_count']);
        $this->line('Literal text candidates: ' . $summary['literal_text_candidate_count']);

        if (!empty($summary['runtime_available_locales'])) {
            $this->line('Runtime locales found: ' . implode(', ', $summary['runtime_available_locales']));
        } else {
            $this->warn('No generated runtime locale packs were found. Run cms:translation-generate before strict audits.');
        }

        if (!empty($report['dynamic_scan']['log_path'] ?? null)) {
            $this->line('Dynamic scan log: ' . $report['dynamic_scan']['log_path']);
        }
    }

    private function writeReportFiles(string $reportDir, array $report): array
    {
        File::ensureDirectoryExists($reportDir);

        $timestamp = now()->format('Ymd-His');
        $jsonPath = rtrim($reportDir, '/') . "/admin-i18n-audit-{$timestamp}.json";
        $markdownPath = rtrim($reportDir, '/') . "/admin-i18n-audit-{$timestamp}.md";
        $latestJsonPath = rtrim($reportDir, '/') . '/admin-i18n-audit-latest.json';
        $latestMarkdownPath = rtrim($reportDir, '/') . '/admin-i18n-audit-latest.md';

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($jsonPath, $json);
        File::put($latestJsonPath, $json);

        $markdown = $this->buildMarkdownReport($report);
        File::put($markdownPath, $markdown);
        File::put($latestMarkdownPath, $markdown);

        return [
            'json' => $jsonPath,
            'markdown' => $markdownPath,
        ];
    }

    private function buildMarkdownReport(array $report): string
    {
        $lines = [
            '# Admin i18n Audit',
            '',
            'Generated at: ' . $report['generated_at'],
            '',
            '## Summary',
            '',
            '- Used keys: ' . $report['summary']['used_key_count'],
            '- Missing import keys (core/shared): ' . $report['summary']['core_missing_count'],
            '- Missing import keys (module-specific): ' . $report['summary']['module_missing_count'],
            '- Missing runtime keys: ' . $report['summary']['runtime_missing_count'],
            '- Legacy alias usage: ' . $report['summary']['alias_usage_count'],
            '- Literal text candidates: ' . $report['summary']['literal_text_candidate_count'],
            '- Runtime locales found: ' . implode(', ', $report['summary']['runtime_available_locales'] ?: ['none']),
            '',
            '## Core Missing Candidates',
            '',
        ];

        $lines = array_merge($lines, $this->renderKeySection($report['core_missing_candidates']));
        $lines[] = '';
        $lines[] = '## Module Missing Candidates';
        $lines[] = '';
        $lines = array_merge($lines, $this->renderKeySection($report['module_missing_candidates']));
        $lines[] = '';
        $lines[] = '## Runtime Missing Candidates';
        $lines[] = '';
        $lines = array_merge($lines, $this->renderKeySection($report['runtime_missing_candidates']));
        $lines[] = '';
        $lines[] = '## Legacy Alias Usage';
        $lines[] = '';
        $lines = array_merge($lines, $this->renderKeySection($report['legacy_alias_usage']));
        $lines[] = '';
        $lines[] = '## Literal Text Candidates';
        $lines[] = '';
        $lines[] = '### Core / Shared';
        $lines[] = '';
        $lines = array_merge($lines, $this->renderLiteralSection($report['literal_text_candidates']['core']));
        $lines[] = '';
        $lines[] = '### Module-specific';
        $lines[] = '';
        $lines = array_merge($lines, $this->renderLiteralSection($report['literal_text_candidates']['module']));

        if (!empty($report['dynamic_scan'])) {
            $lines[] = '';
            $lines[] = '## Legacy Scanner';
            $lines[] = '';

            if (!empty($report['dynamic_scan']['error'] ?? null)) {
                $lines[] = '- Status: failed';
                $lines[] = '- Error: ' . $report['dynamic_scan']['error'];
            } else {
                $lines[] = '- Dynamic entries: ' . (string)($report['dynamic_scan']['dynamic_entries'] ?? 0);
                $lines[] = '- Log path: ' . ($report['dynamic_scan']['log_path'] ?? 'n/a');
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function renderKeySection(array $items): array
    {
        if (empty($items)) {
            return ['- None'];
        }

        $lines = [];
        foreach ($items as $item) {
            $owners = $item['ownership']['core']
                ? 'Core'
                : implode(', ', $item['ownership']['modules']);
            $runtimeMissing = empty($item['runtime_missing_locales'])
                ? 'none'
                : implode(', ', $item['runtime_missing_locales']);
            $canonical = empty($item['canonical_candidates'])
                ? 'none'
                : implode(', ', $item['canonical_candidates']);

            $lines[] = '- `' . $item['key'] . '`';
            $lines[] = '  owners: ' . $owners;
            $lines[] = '  canonical: ' . $canonical;
            $lines[] = '  runtime missing: ' . $runtimeMissing;
            $lines[] = '  sample file: ' . ($item['files'][0]['file'] ?? 'n/a') . ':' . ($item['files'][0]['line'] ?? '1');
        }

        return $lines;
    }

    private function renderLiteralSection(array $items): array
    {
        if (empty($items)) {
            return ['- None'];
        }

        $lines = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $lines[] = '- `' . $item['text'] . '`';
            $lines[] = '  kind: ' . $item['kind'];
            $lines[] = '  file: ' . $item['file'] . ':' . $item['line'];
            $lines[] = '  owner: ' . $item['ownership_label'];
        }

        if (count($items) > 50) {
            $lines[] = '- Additional literal candidates omitted from Markdown output. See JSON report for the full list.';
        }

        return $lines;
    }

    private function hasStrictFailures(array $report): bool
    {
        return $report['summary']['core_missing_count'] > 0
            || $report['summary']['module_missing_count'] > 0
            || $report['summary']['runtime_missing_count'] > 0;
    }
}
