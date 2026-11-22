<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use DB;
use SplFileInfo;
use JsonException;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;

/**
 * Scans Vue and Laravel source files for translation key usage and updates the translates table.
 *
 * Loads all translation keys from the database, builds a Vue-compatible alias map (short group
 * abbreviations and admin remapping), then recursively scans the frontend (Vue/JS/TS) and
 * backend (PHP/Blade) directories for both static and dynamic translation call-sites.
 * After scanning it marks keys as used/unused in the DB and optionally persists which files
 * reference each key. Dynamic and unresolved key usages are written to a structured log file.
 *
 * Usage: php artisan i18n:scan-translates
 * Options:
 *   --frontend        Path to Vue project root (default: ../base-admin)
 *   --backend         Path to Laravel project root (default: .)
 *   --table           DB table name (default: translates)
 *   --key-col         Column name for translation key (default: name)
 *   --update-db       Update is_used/last_scanned_at columns (0/1, default: 1)
 *   --update-used-in  Save matched file list into used_in (0/1, default: 1)
 *   --log-dynamic     Write dynamic key usages into log file (0/1, default: 1)
 *   --dry-run         No DB updates (0/1, default: 0)
 *
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ScanTranslateUsageCommand extends Command
{
    protected $signature = 'i18n:scan-translates
        {--frontend=admin : Path to Vue project root}
        {--backend=. : Path to Laravel project root}
        {--table=translates : DB table name}
        {--key-col=name : Column name for translation key}
        {--update-db=1 : Update is_used/last_scanned_at (0/1)}
        {--update-used-in=1 : Save matched file list into used_in (0/1)}
        {--log-dynamic=1 : Write dynamic key usages into log file (0/1)}
        {--dry-run=0 : No DB updates (0/1)}';

    protected $description = 'Scan Vue + Laravel for translate key usage, update translates table, and log dynamic keys';

    private string $dynamicLogPath;

    /**
     * Execute the console command.
     *
     * Loads keys from DB, builds alias maps, scans frontend and backend directories for
     * static and dynamic translation usages, updates the DB with is_used flags, and
     * optionally persists the list of referencing files per key.
     *
     * @throws JsonException When JSON encoding for used_in column fails
     * @return int Command::SUCCESS
     */
    public function handle(): int
    {
        $frontendRoot = realpath($this->option('frontend')) ?: $this->option('frontend');
        $backendRoot = realpath($this->option('backend')) ?: $this->option('backend');

        $table = (string)$this->option('table');
        $keyCol = (string)$this->option('key-col');

        $updateDb = (int)$this->option('update-db') === 1;
        $saveUsedIn = (int)$this->option('update-used-in') === 1;
        $logDynamic = (int)$this->option('log-dynamic') === 1;
        $dryRun = (int)$this->option('dry-run') === 1;

        $this->dynamicLogPath = storage_path('logs/i18n-dynamic-keys-' . now()->format('Ymd-His') . '.log');

        /**
         * 1) Load keys from DB and build alias map
         *
         * DB key: translates.name (unique), ex: nav.text
         * Vue key: short group by group_name[0], ex: n.text
         * Admin Vue key: n.a.required (attributes->a, between->bw) as per GenerateLanguageFilePackCommand.
         * Validation aliases are intentionally excluded because admin payloads no longer emit legacy `v.*`.
         */
        $rows = DB::table($table)
            ->select([$keyCol, 'group_name', 'for_adm', 'for_app'])
            ->whereNull('deleted_at')
            ->get();

        $dbKeySet = [];          // exact DB key => true
        $vueAliasToDbKeys = [];  // vueKey => [dbKey1, dbKey2...]
        foreach ($rows as $r) {
            $dbKey = (string)($r->{$keyCol} ?? '');
            $groupCol = (string)($r->group_name ?? '');

            if ($dbKey === '') continue;

            $dbKeySet[$dbKey] = true;

            // derive group from key: nav.a.manage -> nav
            $groupFromKey = Str::before($dbKey, '.');
            if ($groupFromKey === $dbKey || $groupFromKey === '') {
                // no dot => cannot build alias safely
                continue;
            }

            $addAlias = function (string $group) use ($dbKey, &$vueAliasToDbKeys) {
                if ($group === 'validation') {
                    return;
                }

                $shortGroup = mb_substr($group, 0, 1);
                $baseName = Str::after($dbKey, $group . '.'); // a.manage

                // common alias: n.a.manage
                $appAlias = $shortGroup . '.' . $baseName;
                $vueAliasToDbKeys[$appAlias][] = $dbKey;

                // admin remap alias (attributes->a, between->bw) for non-validation groups only
                $admAlias = $this->buildAdminAlias($shortGroup, $baseName);
                if ($admAlias) {
                    $vueAliasToDbKeys[$admAlias][] = $dbKey;
                }
            };

            // Always add alias from derived group (source of truth)
            $addAlias($groupFromKey);

            // Also add alias from group_name column if it's present and different (legacy / data issues)
            if ($groupCol !== '' && $groupCol !== $groupFromKey) {
                $addAlias($groupCol);
            }
        }

        $this->info("DB keys loaded: " . count($dbKeySet) . " ({$table}.{$keyCol})");
        $this->info("Vue aliases generated: " . count($vueAliasToDbKeys));

        foreach ($vueAliasToDbKeys as $vueKey => $dbKeys) {
            $vueAliasToDbKeys[$vueKey] = array_values(array_unique($dbKeys));
        }

        $this->info("Frontend root: {$frontendRoot}");
        $this->info("Backend root: {$backendRoot}");
        if ($logDynamic) {
            $this->info("Dynamic log: {$this->dynamicLogPath}");
        }

        $usedOnly = [];     // DB key => true
        $usedMap = [];     // DB key => [files...]
        $modulesUsed = [];  // module namespace collected from static DB keys containing ::

        // 2) Frontend scan (Vue)
        $this->scanDirectory(
            $frontendRoot,
            ['vue', 'js', 'ts', 'tsx', 'jsx'],
            function (string $file, string $content) use (&$usedOnly, &$usedMap, &$modulesUsed, $dbKeySet, $vueAliasToDbKeys, $logDynamic) {

                // Static keys (string literal)
                foreach ($this->extractVueStaticKeys($content) as [$vueKey, $line]) {
                    $this->trackVueKey($vueKey, $file, $line, $usedOnly, $usedMap, $modulesUsed, $dbKeySet, $vueAliasToDbKeys, $logDynamic);
                }

                foreach ($this->extractVueTextPropKeys($content) as [$vueKey, $line]) {
                    $this->trackVueKey($vueKey, $file, $line, $usedOnly, $usedMap, $modulesUsed, $dbKeySet, $vueAliasToDbKeys, $logDynamic);
                }

                // Dynamic occurrences
                foreach ($this->extractVueDynamicOccurrences($content) as $occ) {
                    if ($logDynamic) {
                        $this->writeDynamicLog([
                            'kind' => 'vue',
                            'file' => $file,
                            'line' => $occ['line'],
                            'expr' => $occ['expr'],
                        ]);
                    }
                }
            }
        );

        // 3) Backend scan (Laravel)
        $this->scanDirectory(
            $backendRoot,
            ['php', 'blade.php'],
            function (string $file, string $content) use (&$usedOnly, &$usedMap, &$modulesUsed, $dbKeySet, $logDynamic) {

                foreach ($this->extractLaravelStaticKeys($content) as [$k, $line]) {
                    if (isset($dbKeySet[$k])) {
                        $this->trackDbKey($k, $file, $line, $usedOnly, $usedMap, $modulesUsed, $dbKeySet);
                        continue;
                    }

                    if ($logDynamic) {
                        $this->writeDynamicLog([
                            'kind' => 'laravel-unresolved-static',
                            'file' => $file,
                            'line' => $line,
                            'expr' => $k,
                        ]);
                    }
                }

                foreach ($this->extractLaravelDynamicOccurrences($content) as $occ) {
                    if ($logDynamic) {
                        $this->writeDynamicLog([
                            'kind' => 'laravel',
                            'file' => $file,
                            'line' => $occ['line'],
                            'expr' => $occ['expr'],
                        ]);
                    }
                }
            }
        );

        // 4) Settings scan (Core settings export)
        $this->scanSetting(
            $backendRoot,
            $usedOnly,
            $usedMap,
            $modulesUsed,
            $dbKeySet,
            $vueAliasToDbKeys,
            $logDynamic
        );

        $usedCount = count($usedOnly);
        $unusedCount = count($dbKeySet) - $usedCount;

        $this->info("Used DB keys: {$usedCount}");
        $this->info("Unused DB keys: {$unusedCount}");
        $this->info("Detected namespaces/modules (from used keys containing ::): " . count($modulesUsed));

        if ($dryRun || !$updateDb) {
            if ($dryRun) $this->warn("Dry-run enabled. No DB updates.");
            if (!$updateDb) $this->warn("update-db=0, skipping DB updates.");
            return self::SUCCESS;
        }

        // 5) Update DB
        $now = now();

        // mark all as unused first
        DB::table($table)->whereNull('deleted_at')->update([
            'is_used' => false,
            'last_scanned_at' => $now,
            'used_in' => '[]',
        ]);

        // mark used keys
        $usedKeys = array_keys($usedOnly);
        foreach (array_chunk($usedKeys, 1000) as $chunk) {
            DB::table($table)
                ->whereNull('deleted_at')
                ->whereIn($keyCol, $chunk)
                ->update(['is_used' => true]);
        }

        if ($saveUsedIn) {
            foreach ($usedKeys as $k) {
                $files = isset($usedMap[$k]) ? array_keys($usedMap[$k]) : [];
                DB::table($table)
                    ->whereNull('deleted_at')
                    ->where($keyCol, $k)
                    ->update([
                        'used_in' => json_encode(
                            array_values($files),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ),
                    ]);
            }
        }

        $this->info("DB updated: {$table} (is_used, last_scanned_at" . ($saveUsedIn ? ", used_in" : "") . ")");

        return self::SUCCESS;
    }

    private function buildAdminAlias(string $shortGroup, string $baseName): ?string
    {
        $baseName = trim($baseName);
        if ($baseName === '') return null;

        if (!Str::contains($baseName, '.')) {
            return $shortGroup . '.' . $baseName;
        }

        $segments = explode('.', $baseName);
        $first = array_shift($segments);

        // Mapping must match GenerateLanguageFilePackCommand
        $mapped = match ($first) {
            'attributes' => 'a',   // Matches mapping: attributes -> a
            'between' => 'bw',     // Matches mapping: between -> bw
            // 'nav.a' => 'n.a',   // Removed: $first never contains "nav."
            default => $first,
        };

        if (empty($segments)) {
            return $shortGroup . '.' . $mapped;
        }

        return $shortGroup . '.' . $mapped . '.' . implode('.', $segments);
    }

    private function scanDirectory(string $root, array $extensions, callable $onFile): void
    {
        if (!is_dir($root)) return;

        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) continue;

            $path = $file->getPathname();

            // Skip heavy folders
            if (Str::contains($path, [
                'node_modules', '/dist/', '/build/', '/.nuxt/', '/.next/',
                '/vendor/', '/storage/', '/bootstrap/cache/',
            ])) {
                continue;
            }

            $name = $file->getFilename();
            $ext = $file->getExtension();

            $ok = in_array($ext, $extensions, true);
            if (!$ok && Str::endsWith($name, '.blade.php') && in_array('blade.php', $extensions, true)) {
                $ok = true;
            }
            if (!$ok) continue;

            $content = @file_get_contents($path);
            if ($content === false) continue;

            $onFile($path, $content);
        }
    }

    /**
     * Return list of [key, line]
     */
    private function extractVueStaticKeys(string $content): array
    {
        $out = [];

        // match $t('key'), t("key"), i18n.global.t('key'), $t(`key`) (no ${})
        $patterns = [
            '/\$\s*t\s*\(\s*(["\'])([^"\']+)\1/u',
            '/(?<![\w$])t\s*\(\s*(["\'])([^"\']+)\1/u',
            '/i18n\.global\.t\s*\(\s*(["\'])([^"\']+)\1/u',
            '/\$\s*t\s*\(\s*`([^`$]+)`/u',
        ];

        foreach ($patterns as $p) {
            if (preg_match_all($p, $content, $m, PREG_OFFSET_CAPTURE)) {
                for ($i = 0, $iMax = count($m[0]); $i < $iMax; $i++) {
                    $key = null;
                    $offset = $m[0][$i][1];

                    if (isset($m[2])) {
                        $key = $m[2][$i][0] ?? null; // quoted group
                    }
                    if ($key === null && isset($m[1])) {
                        $key = $m[1][$i][0] ?? null; // backtick group
                    }

                    if ($key) {
                        $line = $this->offsetToLine($content, $offset);
                        $out[] = [trim($key), $line];
                    }
                }
            }
        }

        // Dedup by key+line
        $uniq = [];
        foreach ($out as [$k, $line]) {
            $uniq[$k . '@' . $line] = [$k, $line];
        }
        return array_values($uniq);
    }

    // =========================
    // Trackers
    // =========================

    private function offsetToLine(string $content, int $offset): int
    {
        if ($offset <= 0) return 1;
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    /**
     * Track Vue key usage with "Canonical Priority" logic.
     * Fixes issue where garbage keys (e.g. 'n.a.manage') in DB shadow the real keys ('nav.a.manage').
     */
    private function trackVueKey(
        string $vueKey,
        string $file,
        int    $line,
        array  &$usedOnly,
        array  &$usedMap,
        array  &$modulesUsed,
        array  $dbKeySet,
        array  $vueAliasToDbKeys,
        bool   $logDynamic,
        string $unresolvedKind = 'vue-unresolved'
    ): void
    {
        $vueKey = trim($vueKey);
        if ($vueKey === '') return;

        // 1. Get the list of DB keys this vueKey maps to
        // Example: vueKey = 'n.a.manage'
        // $targets may be ['nav.a.manage', 'n.a.manage'] (DB stores both forms)
        $targets = $vueAliasToDbKeys[$vueKey] ?? [];

        // 2. Smart logic: check if a canonical (original) key exists that differs from vueKey
        $hasCanonical = false;
        foreach ($targets as $dbKey) {
            if ($dbKey !== $vueKey) {
                $hasCanonical = true;
                break;
            }
        }

        // 3. If mapping found in Alias Map
        if (!empty($targets)) {
            foreach ($targets as $dbKey) {
                // IMPORTANT: If a canonical key exists (nav.a.manage) and the current
                // dbKey is the abbreviated form (n.a.manage) -> SKIP the abbreviation.
                // This prevents is_used from being set on garbage/stale keys.
                if ($hasCanonical && $dbKey === $vueKey) {
                    continue;
                }

                // Final check: verify the target key actually exists in the DB
                if (isset($dbKeySet[$dbKey])) {
                    $this->trackDbKey($dbKey, $file, $line, $usedOnly, $usedMap, $modulesUsed, $dbKeySet);
                }
            }

            // Already resolved via Alias Map — return early, skip direct key check below
            return;
        }

        // 4. Fallback: if NOT in Alias Map, check direct DB key match
        // (For keys without aliases, written in full form: 'general.save')
        if (isset($dbKeySet[$vueKey])) {
            $this->trackDbKey($vueKey, $file, $line, $usedOnly, $usedMap, $modulesUsed, $dbKeySet);
            return;
        }

        // 5. Log error if no matching key was found at all
        if ($logDynamic) {
            $this->writeDynamicLog([
                'kind' => $unresolvedKind,
                'file' => $file,
                'line' => $line,
                'expr' => $vueKey,
            ]);
        }
    }

    /**
     * Track DB key usage (Laravel side, or when Vue key already is DB key).
     */
    private function trackDbKey(
        string $dbKey,
        string $file,
        int    $line,
        array  &$usedOnly,
        array  &$usedMap,
        array  &$modulesUsed,
        array  $dbKeySet
    ): void
    {
        $dbKey = trim($dbKey);
        if ($dbKey === '') return;

        if (!isset($dbKeySet[$dbKey])) return;

        $usedOnly[$dbKey] = true;
        $usedMap[$dbKey][$file] = true;

        if (Str::contains($dbKey, '::')) {
            $module = explode('::', $dbKey, 2)[0] ?? '';
            if ($module !== '') {
                $modulesUsed[$module] = true;
            }
        }
    }

    // =========================
    // Vue (static + dynamic)
    // =========================

    /**
     * Track Vue key usage by resolving alias -> DB key(s).
     * Also logs ambiguous mapping (1 vue key -> many db keys) as vue-ambiguous.
     *
     * @throws JsonException
     */

    /**
     * @throws JsonException
     */
    private function writeDynamicLog(array $row): void
    {
        $line = json_encode([
            'time' => now()->toDateTimeString(),
            'kind' => $row['kind'] ?? null,
            'file' => $row['file'] ?? null,
            'line' => $row['line'] ?? null,
            'expr' => $row['expr'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents($this->dynamicLogPath, $line . PHP_EOL, FILE_APPEND);
    }

    /**
     * Extract translation-like keys inside object props such as:
     * - text: 'n.a.trans-web'
     * - text: ['n.a.translates', 'n.a.trans-web']
     *
     * Return list of [key, line]
     */
    private function extractVueTextPropKeys(string $content): array
    {
        $out = [];

        // Match: text: '...' OR text: "..." OR text: [`...`] (no ${})
        // and:   text: [ '...', "..." , ... ]
        $pattern = '/\btext\s*:\s*(\[[^\]]*\]|(["\'][^"\']+["\']))/u';

        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }

        foreach ($matches[1] as $idx => $cap) {
            $raw = $cap[0];
            $offset = $cap[1];
            $line = $this->offsetToLine($content, $offset);

            // Case 1: array [...]
            if (Str::startsWith(trim($raw), '[')) {
                if (preg_match_all('/(["\'])([^"\']+)\1/u', $raw, $m2)) {
                    foreach ($m2[2] as $k) {
                        $k = trim($k);
                        if ($this->looksLikeTranslateKey($k)) {
                            $out[] = [$k, $line];
                        }
                    }
                }
                continue;
            }

            // Case 2: single quoted string
            if (preg_match('/^(["\'])([^"\']+)\1$/u', trim($raw), $m1)) {
                $k = trim($m1[2]);
                if ($this->looksLikeTranslateKey($k)) {
                    $out[] = [$k, $line];
                }
            }
        }

        // Dedup by key+line
        $uniq = [];
        foreach ($out as [$k, $l]) $uniq[$k . '@' . $l] = [$k, $l];
        return array_values($uniq);
    }

    /**
     * Heuristic filter to avoid capturing normal text.
     * Adjust prefixes based on your key conventions: n., t., s., etc.
     */
    private function looksLikeTranslateKey(string $k): bool
    {
        // must contain at least one dot to look like a key
        if (!Str::contains($k, '.')) return false;

        // allow your known prefixes (expand if you have more)
        // examples: n.a.trans-web, t.type.document, s.group.payment
        return preg_match('/^(n|t|s)\.[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/u', $k) === 1;
    }

    // =========================
    // Laravel (static + dynamic)
    // =========================

    /**
     * Return list of dynamic occurrences: [{line, expr}]
     */
    private function extractVueDynamicOccurrences(string $content): array
    {
        $out = [];

        // Find call sites: $t( ... ), t( ... ), i18n.global.t( ... )
        $callPatterns = [
            '/\$\s*t\s*\(/u',
            '/(?<![\w$])t\s*\(/u',
            '/i18n\.global\.t\s*\(/u',
        ];

        foreach ($callPatterns as $cp) {
            if (!preg_match_all($cp, $content, $m, PREG_OFFSET_CAPTURE)) continue;

            foreach ($m[0] as [$matched, $offset]) {
                $openParenPos = $offset + strlen($matched) - 1; // points to "("
                $arg = $this->readFirstArgument($content, $openParenPos);
                if ($arg === null) continue;

                // dynamic if not a pure string literal OR template literal contains ${}
                if ($this->isVueArgumentDynamic($arg)) {
                    $line = $this->offsetToLine($content, $openParenPos);
                    $out[] = ['line' => $line, 'expr' => trim($arg)];
                }
            }
        }

        return $out;
    }

    /**
     * Read the first argument expression starting right after "(" position.
     * Supports nested parentheses and quoted strings/backticks.
     */
    private function readFirstArgument(string $content, int $openParenPos): ?string
    {
        $len = strlen($content);
        if ($openParenPos < 0 || $openParenPos >= $len || $content[$openParenPos] !== '(') return null;

        $i = $openParenPos + 1;
        $depth = 0;
        $inSQuote = false;
        $inDQuote = false;
        $inBQuote = false;
        $escape = false;

        $start = $i;
        for (; $i < $len; $i++) {
            $ch = $content[$i];

            if ($escape) {
                $escape = false;
                continue;
            }
            if (($inSQuote || $inDQuote || $inBQuote) && $ch === '\\') {
                $escape = true;
                continue;
            }

            if (!$inDQuote && !$inBQuote && $ch === "'") {
                $inSQuote = !$inSQuote;
                continue;
            }
            if (!$inSQuote && !$inBQuote && $ch === '"') {
                $inDQuote = !$inDQuote;
                continue;
            }
            if (!$inSQuote && !$inDQuote && $ch === '`') {
                $inBQuote = !$inBQuote;
                continue;
            }

            if ($inSQuote || $inDQuote || $inBQuote) continue;

            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                if ($depth === 0) {
                    return trim(substr($content, $start, $i - $start));
                }
                $depth--;
                continue;
            }

            if ($ch === ',' && $depth === 0) {
                return trim(substr($content, $start, $i - $start));
            }
        }

        return null;
    }

    private function isVueArgumentDynamic(string $arg): bool
    {
        $arg = trim($arg);

        // 'abc' or "abc"
        if (preg_match('/^(["\'])(.*)\1$/us', $arg)) {
            return false; // static literal
        }

        // `abc` => static, but `a.${b}` => dynamic
        if (preg_match('/^`([\s\S]*)`$/u', $arg, $mm)) {
            return Str::contains($mm[1], '${');
        }

        // everything else considered dynamic: variable, concat, function call, etc.
        return true;
    }

    // =========================
    // Helpers: read first argument, line number, logging
    // =========================

    /**
     * Return list of [key, line]
     */
    private function extractLaravelStaticKeys(string $content): array
    {
        $out = [];

        // Only accept first arg is '...' or "..." without $ interpolation
        $patterns = [
            '/__\s*\(/u',
            '/trans\s*\(/u',
            '/Lang::get\s*\(/u',
            '/@lang\s*\(/u',
        ];

        foreach ($patterns as $cp) {
            if (!preg_match_all($cp, $content, $m, PREG_OFFSET_CAPTURE)) continue;

            foreach ($m[0] as [$matched, $offset]) {
                $openParenPos = $offset + strlen($matched) - 1;
                $arg = $this->readFirstArgument($content, $openParenPos);
                if ($arg === null) continue;

                $key = $this->tryParseLaravelStaticString($arg);
                if ($key !== null) {
                    $line = $this->offsetToLine($content, $openParenPos);
                    $out[] = [$key, $line];
                }
            }
        }

        $uniq = [];
        foreach ($out as [$k, $line]) {
            $uniq[$k . '@' . $line] = [$k, $line];
        }
        return array_values($uniq);
    }

    private function tryParseLaravelStaticString(string $arg): ?string
    {
        $arg = trim($arg);

        // 'abc' always static
        if (preg_match('/^\'([^\']+)\'$/u', $arg, $mm)) return $mm[1];

        // "abc" static ONLY if no $var interpolation
        if (preg_match('/^"([\s\S]*)"$/u', $arg, $mm)) {
            $inside = $mm[1];
            if (Str::contains($inside, '$')) return null; // dynamic
            return $inside;
        }

        return null;
    }

    /**
     * Return list of dynamic occurrences: [{line, expr}]
     */
    private function extractLaravelDynamicOccurrences(string $content): array
    {
        $out = [];

        $patterns = [
            '/__\s*\(/u',
            '/trans\s*\(/u',
            '/Lang::get\s*\(/u',
            '/@lang\s*\(/u',
        ];

        foreach ($patterns as $cp) {
            if (!preg_match_all($cp, $content, $m, PREG_OFFSET_CAPTURE)) continue;

            foreach ($m[0] as [$matched, $offset]) {
                $openParenPos = $offset + strlen($matched) - 1;
                $arg = $this->readFirstArgument($content, $openParenPos);
                if ($arg === null) continue;

                $static = $this->tryParseLaravelStaticString($arg);
                if ($static === null) {
                    $line = $this->offsetToLine($content, $openParenPos);
                    $out[] = ['line' => $line, 'expr' => trim($arg)];
                }
            }
        }

        return $out;
    }

    /**
     * @throws JsonException
     */
    private function scanSetting(
        string $backendRoot,
        array  &$usedOnly,
        array  &$usedMap,
        array  &$modulesUsed,
        array  $dbKeySet,
        array  $vueAliasToDbKeys,
        bool   $logDynamic
    ): void
    {
        $path = rtrim($backendRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cms/Core/database/seeders/exported/settings.php';
        if (!is_file($path)) {
            return;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return;
        }

        $rows = @include $path;
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $inputSetting = $row['input_setting'] ?? null;
            if (!is_string($inputSetting) || $inputSetting === '') {
                continue;
            }

            $decoded = json_decode($inputSetting, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                continue;
            }

            foreach (['title', 'description'] as $field) {
                $value = $decoded[$field] ?? null;
                if (!is_string($value)) {
                    continue;
                }

                $key = trim($value);
                if ($key === '') {
                    continue;
                }
                $line = $this->findSettingLine($content, $field, $key);
                $this->trackVueKey(
                    $key,
                    $path,
                    $line,
                    $usedOnly,
                    $usedMap,
                    $modulesUsed,
                    $dbKeySet,
                    $vueAliasToDbKeys,
                    $logDynamic,
                    'setting-unresolved'
                );
            }
        }
    }

    private function findSettingLine(string $content, string $field, string $key): int
    {
        $needle = '"' . $field . '":"' . $key . '"';
        $pos = strpos($content, $needle);
        if ($pos === false) return 1;

        return $this->offsetToLine($content, $pos);
    }

}
