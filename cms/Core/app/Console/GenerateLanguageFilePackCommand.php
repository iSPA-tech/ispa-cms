<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Cms\Core\Support\Testing\TranslationFileGuard;
use JsonException;
use RuntimeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Interfaces\TranslateInterface;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Services\TranslationModuleSeederExporter;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * Artisan command to export translations into JSON (admin/app) and PHP files
 * for all active languages, organizing by group and optimizing keys.
 *
 * @see TranslationModuleSeederExporter
 * @package Cms\Core\Console
 */
class GenerateLanguageFilePackCommand extends Command
{
    protected $signature = 'cms:translation-generate {--base= : Root directory treated as the project base for module lang packs, module.json and translate.php}'
        .' {--public-only : Write only the public JSON packs (storage), never lang/ files or module seeders (used by cms:install)}';
    protected $description = 'Generate JSON and PHP language files for all supported locales';

    /**
     * Map legacy single-letter group prefixes to their canonical admin group names.
     * Prevents generation of duplicate single-letter JSON files (e.g. n.json alongside nav.json).
     */
    private const LEGACY_GROUP_MAP = [
        'a' => 'action',
        'c' => 'cart',
        'e' => 'enum',
        'm' => 'mgs',
        'n' => 'nav',
        'p' => 'pagination',
        's' => 'setting',
        't' => 'text',
        'v' => 'validation',
        'w' => 'web',
        
        
        
    ];

    /**
     * Initialize the command with translation repository, language repository, and module exporter.
     *
     * @param TranslateInterface              $translateRepo  Repository for querying translation rows.
     * @param LanguageInterface               $languageRepo   Repository for querying active languages.
     * @param TranslationModuleSeederExporter $moduleExporter Exporter for writing per-module seeder files.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected TranslateInterface              $translateRepo,
        protected LanguageInterface               $languageRepo,
        protected TranslationModuleSeederExporter $moduleExporter
    )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Module lang packs, module.json files and the module translate.php seeders
     * are all resolved through the application base path. An explicit --base
     * swaps that root for the run; while the test suite runs without --base the
     * root is redirected to an empty tree below storage/framework/testing so
     * tests never rewrite tracked module files. Production behaviour is unchanged.
     *
     * @return int
     * @throws JsonException
     */
    public function handle(): int
    {
        $base = $this->option('base');
        $base = is_string($base) && $base !== '' ? rtrim($base, '/') : null;

        if ($base === null && app()->runningUnitTests()) {
            $base = storage_path('framework/testing/lang-pack-base');
            File::ensureDirectoryExists($base . '/cms');
        }

        if ($base === null) {
            return $this->generate();
        }

        $originalBase = app()->basePath();
        app()->setBasePath($base);

        try {
            return $this->generate();
        } finally {
            app()->setBasePath($originalBase);
        }
    }

    /**
     * Run the generation against the currently active application base path.
     *
     * @return int
     * @throws JsonException
     */
    protected function generate(): int
    {
        $this->info('Starting language pack generation...');

        $languages = $this->languageRepo->get([
            'where' => ['status' => 'status_published'],
        ]);

        $runtimeLocales = $this->resolveRuntimeLocales($languages);

        $translates = $this->translateRepo->get([
            'order' => [
                'group_name' => 'ASC',
                'name' => 'ASC',
            ],
        ]);

        $this->line('Runtime locales: ' . implode(', ', $runtimeLocales));

        try {
            foreach ($runtimeLocales as $locale) {
                $this->generateLanguagePack($locale, $translates);
            }

            $this->assertRequiredRuntimeArtifactsExist();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return CommandAlias::FAILURE;
        }

        if ($this->option('public-only')) {
            $this->info('Public language packs generated (lang files and module seeders left as they are).');

            return CommandAlias::SUCCESS;
        }

        try {
            $moduleSummary = $this->moduleExporter->export($translates);
        } catch (RuntimeException $exception) {
            $this->error('Module translation seeders were not generated: ' . $exception->getMessage());
            return CommandAlias::FAILURE;
        }

        if (!empty($moduleSummary['modules'])) {
            foreach ($moduleSummary['modules'] as $summary) {
                $this->line("Module seeder updated: {$summary['file']} ({$summary['records']} records)");
            }
        }

        if (!empty($moduleSummary['skipped'])) {
            foreach ($moduleSummary['skipped'] as $skipped) {
                $this->warn("Module folder not found for '{$skipped}', skipping seeder export.");
            }
        }

        $this->info('Language pack generation completed.');

        return CommandAlias::SUCCESS;
    }

    /**
     * Generate files for a specific language.
     *
     * @param string $langPath
     * @param Collection $translates
     *
     * @return void
     * @throws JsonException
     */
    protected function generateLanguagePack(string $langPath, Collection $translates): void
    {
        $langPath = trim($langPath);

        $data = [];
        $moduleData = []; // map: moduleName => [ groupName => [...keys...] ]
        $jsonAdmin = [];
        $jsonAdminMerged = [];
        $jsonApp = [];
        // small helper to render array to php lang file content
        $buildPhpContent = static function (array $arr): string {
            $renderArray = static function (array $input, int $indent = 1) use (&$renderArray): string {
                $tabs = str_repeat('    ', $indent);
                $buffer = '[';

                foreach ($input as $key => $value) {
                    $buffer .= "\n{$tabs}'{$key}' => ";

                    if (is_array($value)) {
                        $buffer .= $renderArray($value, $indent + 1) . ',';
                    } else {
                        $escaped = str_replace("'", "\\'", (string)$value);
                        $buffer .= "'{$escaped}',";
                    }
                }

                $buffer .= "\n" . str_repeat('    ', max(0, $indent - 1)) . ']';

                return $buffer;
            };

            return "<?php\n/**\n * Translate key auto generate\n *\n * @updated " . now() . "\n * @version " . config('ispa-cms.app_version') . "\n * @author malayvuong\n * @since 5.0.0\n */\nreturn " . $renderArray($arr) . ";\n";
        };

        // Process each translation entry
        $this->output->progressStart(count($translates));

        foreach ($translates as $value) {
            $groupName = trim((string)($value->group_name ?? ''));
            $fullName = trim((string)($value->name ?? ''));

            if ($groupName === '' || $fullName === '') {
                $this->output->progressAdvance();
                continue;
            }

            $name = $this->normalizeGeneratedTranslationName(
                $groupName,
                $fullName
            );
            $text = $this->resolveTranslationText($value->text ?? [], $langPath);

            if ($text === '') {
                $fallback = trim((string)config('app.fallback_locale'));
                $text = $this->resolveTranslationText($value->text ?? [], $fallback);
            }

            if ($text === '') {
                continue; // Skip if no fallback value either
            }

            // Build PHP structure for central data
            $groupKey = $groupName;
            $this->ensureArrayBucket($data, $groupKey);

            if (strpos($name, '.')) {
                $this->putNestedValue($data[$groupKey], explode('.', $name), $text);
            } else {
                $data[$groupKey][$name] = $text;
            }

            // Also assign into per-module map if the translation has modules defined
            $modulesList = $value->modules ?? null;
            if ($modulesList) {
                // modules may be stored as JSON string or array
                if (is_string($modulesList)) {
                    $decoded = json_decode($modulesList, true, 512, JSON_THROW_ON_ERROR);
                    $modulesList = $decoded ?: [$modulesList];
                }

                if (is_array($modulesList)) {
                    foreach ($this->normalizeModuleNames($modulesList) as $moduleName) {
                        if (!isset($moduleData[$moduleName]) || !is_array($moduleData[$moduleName])) {
                            $moduleData[$moduleName] = [];
                        }
                        $this->ensureArrayBucket($moduleData[$moduleName], $groupName);

                        if (strpos($name, '.')) {
                            $this->putNestedValue($moduleData[$moduleName][$groupName], explode('.', $name), $text);
                        } else {
                            $moduleData[$moduleName][$groupName][$name] = $text;
                        }
                    }
                }
            }

            // Admin JSON — canonical keys only (legacy aliases are created on the frontend)
            if ($this->isTruthy($value->for_adm ?? false)) {
                $adminText = $this->convertPlaceholdersForFrontendJson($text, true);
                [$adminGroupName, $adminBaseName] = $this->resolveAdminJsonTarget(
                    $groupName,
                    $fullName,
                    $name
                );

                $this->ensureArrayBucket($jsonAdmin, $adminGroupName);

                if (str_contains($adminBaseName, '.')) {
                    $canonicalSegments = explode('.', $adminBaseName);
                    $this->putNestedValue($jsonAdmin[$adminGroupName], $canonicalSegments, $adminText);
                } else {
                    $jsonAdmin[$adminGroupName][$adminBaseName] = $adminText;
                }
            }

            // App JSON
            if ($this->isTruthy($value->for_app ?? false)) {
                $appText = $this->convertPlaceholdersForFrontendJson($text, false);
                $appGroup = $groupName[0];
                $this->ensureArrayBucket($jsonApp, $appGroup);

                if (strpos($name, '.')) {
                    $segments = explode('.', $name);
                    $this->putNestedValue($jsonApp[$appGroup], $segments, $appText);
                } else {
                    $jsonApp[$appGroup][$name] = $appText;
                }
            }

            $this->output->progressAdvance();
        }

        // Generate ADMIN JSON files
        $jsonAdminManifest = array_keys($jsonAdmin);
        sort($jsonAdminManifest);

        foreach ($jsonAdmin as $groupName => $groupData) {
            // Individual file: {"key": "value"}
            $this->putPublicJson(
                'i18n/' . config('ispa-cms.app_version') . '/adm/' . $langPath . '/' . $groupName . '.json',
                $groupData
            );

            // Merged file: {"groupName": {"key": "value"}}
            $this->mergeNestedArray($jsonAdminMerged, [$groupName => $groupData]);
        }

        // The stamp changes with the pack's content: an admin that cached an older pack under the same build (a
        // redeploy of the same version, keys seeded later) sees a different stamp and reloads the pack.
        $this->putPublicJson(
            'i18n/' . config('ispa-cms.app_version') . '/adm/' . $langPath . '/manifest.json',
            ['files' => $jsonAdminManifest, 'stamp' => sha1((string) json_encode($jsonAdminMerged, JSON_UNESCAPED_UNICODE))]
        );

        $this->putPublicJson(
            'i18n/' . config('ispa-cms.app_version') . '/adm/' . $langPath . '.json',
            $jsonAdminMerged
        );

        // Generate APP JSON file
        $this->putPublicJson(
            'i18n/' . config('ispa-cms.app_version') . '/app/' . $langPath . '.json',
            $jsonApp
        );

        // A fresh install only needs the packs the admin and the app load; the PHP lang files it ships already match.
        if ($this->option('public-only')) {
            $this->output->progressFinish();

            return;
        }

        // Prepare module folder map (case-insensitive basename => path)
        $cmsPath = base_path('cms');
        $modulePathMap = [];
        if (File::exists($cmsPath) && File::isDirectory($cmsPath)) {
            foreach (File::directories($cmsPath) as $mPath) {
                $modulePathMap[strtolower(basename($mPath))] = $mPath;
            }
        }

        // The `language` disk root was resolved from the real base path at config time, so
        // build the disk from the active base path to honour --base.
        $languageDisk = Storage::build(['driver' => 'local', 'root' => base_path('lang')]);

        // Generate PHP files - central and per-module
        foreach ($data as $groupName => $dataValue) {
            // central combined file
            $phpContent = $buildPhpContent($dataValue);

            TranslationFileGuard::assertWritable(
                $languageDisk->path($langPath . '/' . $groupName . '.php')
            );
            $languageDisk->put(
                $langPath . '/' . $groupName . '.php',
                $phpContent
            );

            // write per-module files only for modules that require this group
            if (!empty($moduleData)) {
                foreach ($moduleData as $moduleName => $groups) {
                    if (!isset($groups[$groupName])) {
                        continue;
                    }

                    $moduleGroupData = $groups[$groupName];
                    $modulePhp = $buildPhpContent($moduleGroupData);

                    $lookup = strtolower($moduleName);
                    if (!isset($modulePathMap[$lookup])) {
                        // no matching module folder found, skip
                        continue;
                    }

                    $moduleLangDir = $modulePathMap[$lookup] . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $langPath;
                    if (!File::exists($moduleLangDir)) {
                        File::makeDirectory($moduleLangDir, 0755, true);
                    }

                    $moduleFile = $moduleLangDir . DIRECTORY_SEPARATOR . $groupName . '.php';
                    TranslationFileGuard::assertWritable($moduleFile);
                    File::put($moduleFile, $modulePhp);
                }
            }
        }

        // After generating individual group files, create one aggregated JSON file per module
        if (!empty($moduleData)) {
            foreach ($moduleData as $moduleName => $groups) {
                $lookup = strtolower($moduleName);
                if (!isset($modulePathMap[$lookup])) {
                    continue; // skip modules without a folder
                }

                $moduleLangDir = $modulePathMap[$lookup] . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $langPath;
                if (!File::exists($moduleLangDir)) {
                    File::makeDirectory($moduleLangDir, 0755, true);
                }

                // Convert placeholders for frontend JSON before encoding
                $groupsConverted = $this->convertFrontendJsonArrayPlaceholders($groups);

                // $groups is an array where keys are group names; write them as a single JSON file
                try {
                    $jsonContent = json_encode($groupsConverted, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                } catch (JsonException) {
                    // fallback to non-pretty if something odd happens
                    $jsonContent = json_encode($groupsConverted, JSON_THROW_ON_ERROR);
                }

                $moduleJsonFile = $moduleLangDir . DIRECTORY_SEPARATOR . 'module.json';
                TranslationFileGuard::assertWritable($moduleJsonFile);
                File::put($moduleJsonFile, $jsonContent);
            }
        }

        $this->output->progressFinish();
    }

    /**
     * Ensure a bucket entry is initialised as an array.
     */
    private function ensureArrayBucket(array &$bucket, string $key): void
    {
        if (!isset($bucket[$key]) || !is_array($bucket[$key])) {
            $bucket[$key] = [];
        }
    }

    /**
     * @param iterable<int, object> $languages
     *
     * @return array<int, string>
     */
    private function resolveRuntimeLocales(iterable $languages): array
    {
        $locales = [];

        foreach ($languages as $language) {
            $path = trim((string)($language->path ?? ''));

            if ($path !== '') {
                $locales[] = $path;
            }
        }

        return array_values(array_unique(array_merge($locales, $this->requiredRuntimeLocales())));
    }

    /**
     * @return array<int, string>
     */
    private function requiredRuntimeLocales(): array
    {
        return ['en', 'vi'];
    }

    /**
     * Write a public JSON translation artifact and prepare its parent directory first.
     *
     * Deployment hooks may pre-create versioned i18n folders, but this command remains
     * the final owner of artifact generation. Ensuring the directory here prevents
     * release-time failures when the hook runs against a clean shared storage tree.
     *
     * @param string                   $path    Public-disk relative path for the JSON artifact.
     * @param array<string|int, mixed> $payload Translation payload to encode as JSON.
     *
     * @return void
     *
     * @throws JsonException
     */
    private function putPublicJson(string $path, array $payload): void
    {
        // Resolve through the public disk so tests using Storage::fake('public')
        // observe the artifacts; in production the disk root is storage/app/public.
        $absolutePath = Storage::disk('public')->path($path);
        $absoluteDirectory = dirname($absolutePath);

        File::ensureDirectoryExists($absoluteDirectory, 02775, true);
        @chmod($absoluteDirectory, 02775);

        if (!File::isDirectory($absoluteDirectory)) {
            throw new RuntimeException('Unable to create public translation artifact directory: ' . $absoluteDirectory);
        }

        if (!File::isWritable($absoluteDirectory)) {
            throw new RuntimeException('Public translation artifact directory is not writable: ' . $absoluteDirectory);
        }

        if (File::exists($absolutePath) && !File::isWritable($absolutePath)) {
            @chmod($absolutePath, 0664);
        }

        if (File::exists($absolutePath) && !File::isWritable($absolutePath)) {
            throw new RuntimeException('Public translation artifact file is not writable: ' . $absolutePath);
        }

        $contents = json_encode($payload, JSON_THROW_ON_ERROR);
        $lastErrorBeforeWrite = error_get_last();
        $written = File::put($absolutePath, $contents, true);

        if ($written === false) {
            $lastErrorAfterWrite = error_get_last();
            $lastErrorMessage = $lastErrorAfterWrite !== $lastErrorBeforeWrite && isset($lastErrorAfterWrite['message'])
                ? $lastErrorAfterWrite['message']
                : 'unknown file_put_contents failure';

            throw new RuntimeException(
                'Unable to write public translation artifact: ' . $absolutePath
                . ' | reason: ' . $lastErrorMessage
                . ' | diagnostics: ' . $this->describePublicArtifactPath($absoluteDirectory, $absolutePath)
            );
        }

        @chmod($absolutePath, 0664);
    }

    /**
     * Build compact filesystem diagnostics for failed public translation writes.
     *
     * The deployment command runs inside short-lived release directories, so the
     * exception must carry enough path, permission, and ownership details before
     * the failed release is removed by deployment cleanup.
     */
    private function describePublicArtifactPath(string $absoluteDirectory, string $absolutePath): string
    {
        $diagnostics = [];

        foreach (['directory' => $absoluteDirectory, 'file' => $absolutePath] as $label => $path) {
            $exists = File::exists($path);

            $diagnostics[$label] = [
                'path' => $path,
                'exists' => $exists,
                'is_directory' => $exists ? File::isDirectory($path) : false,
                'is_writable' => $exists ? File::isWritable($path) : false,
                'permissions' => $exists ? substr(sprintf('%o', @fileperms($path)), -4) : null,
                'owner' => $exists ? @fileowner($path) : null,
                'group' => $exists ? @filegroup($path) : null,
            ];
        }

        $encoded = json_encode($diagnostics, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? 'unavailable' : $encoded;
    }

    private function assertRequiredRuntimeArtifactsExist(): void
    {
        $version = trim((string)config('ispa-cms.app_version'));

        if ($version === '' || $version === '0.0.0') {
            throw new RuntimeException('Invalid app version for translation artifacts. Ensure VERSION exists in the release root.');
        }

        $publicDisk = Storage::disk('public');

        $paths = [
            $publicDisk->path("i18n/{$version}"),
        ];

        foreach ($this->requiredRuntimeLocales() as $locale) {
            $paths[] = $publicDisk->path("i18n/{$version}/adm/{$locale}/manifest.json");
            $paths[] = $publicDisk->path("i18n/{$version}/adm/{$locale}.json");
        }

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                throw new RuntimeException("Missing generated translation artifact: {$path}");
            }
        }
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Assign a value into a nested array structure using dot segments.
     *
     * @param array<string, mixed> $bucket
     * @param array<int, string> $segments
     */
    private function putNestedValue(array &$bucket, array $segments, string $value): void
    {
        if (empty($segments)) {
            return;
        }

        $node =& $bucket;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $node[$segment] = $value;
                return;
            }

            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node =& $node[$segment];
        }
    }

    /**
     * Deep merge nested message trees while preserving existing branches.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function mergeNestedArray(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            if (
                isset($target[$key]) &&
                is_array($target[$key]) &&
                is_array($value)
            ) {
                $this->mergeNestedArray($target[$key], $value);
                continue;
            }

            $target[$key] = $value;
        }
    }

    private function normalizeGeneratedTranslationName(string $groupName, string $fullName): string
    {
        $groupName = trim($groupName);
        $fullName = trim($fullName);
        $name = str_replace($groupName . '.', '', $fullName);

        if ($groupName !== 'validation') {
            return $name;
        }

        return match (true) {
            str_starts_with($name, 'a.') => 'attributes.' . substr($name, 2),
            str_starts_with($name, 'v.a.') => 'attributes.' . substr($name, 4),
            default => $name,
        };
    }

    /**
     * Resolve the admin JSON root group and nested key path that frontend runtime expects.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveAdminJsonTarget(
        string $groupName,
        string $fullName,
        string $normalizedName
    ): array
    {
        $groupName = trim($groupName);
        $fullName = trim($fullName);
        $normalizedName = trim($normalizedName);

        if ($groupName === 'validation') {
            $validationKey = match (true) {
                str_starts_with($fullName, 'validation.attributes.') => $fullName,
                str_starts_with($fullName, 'validation.v.a.') => 'validation.attributes.' . substr($fullName, strlen('validation.v.a.')),
                str_starts_with($fullName, 'validation.a.') => 'validation.attributes.' . substr($fullName, strlen('validation.a.')),
                str_starts_with($normalizedName, 'validation.') => $normalizedName,
                str_starts_with($fullName, 'v.a.') => 'validation.attributes.' . substr($fullName, 4),
                str_starts_with($normalizedName, 'v.a.') => 'validation.attributes.' . substr($normalizedName, 4),
                str_starts_with($fullName, 'a.') => 'validation.attributes.' . substr($fullName, 2),
                str_starts_with($normalizedName, 'a.') => 'validation.attributes.' . substr($normalizedName, 2),
                str_starts_with($normalizedName, 'attributes.') => 'validation.' . $normalizedName,
                default => 'validation.' . ltrim($normalizedName, '.'),
            };

            return ['validation', str_replace('validation.', '', $validationKey)];
        }

        $runtimeKey = match (true) {
            $fullName !== '' && str_contains($fullName, '.') => $fullName,
            $normalizedName !== '' && str_contains($normalizedName, '.') => $normalizedName,
            default => $groupName . '.' . $normalizedName,
        };

        $runtimeGroup = str_contains($runtimeKey, '.')
            ? strstr($runtimeKey, '.', true)
            : $runtimeKey;
        $runtimeBaseName = str_contains($runtimeKey, '.')
            ? substr($runtimeKey, strlen($runtimeGroup) + 1)
            : $runtimeKey;

        // Map legacy single-letter group prefixes to canonical names
        $runtimeGroup = self::LEGACY_GROUP_MAP[$runtimeGroup] ?? $runtimeGroup;

        return [$runtimeGroup, $runtimeBaseName];
    }

    /**
     * Convert Laravel placeholders (:key) to frontend placeholders ({key}).
     *
     * @param string $text The text to convert
     * @param bool $useAdminShortTokens If true, apply admin short token mapping first
     *
     * @return string The converted text
     */
    private function convertPlaceholdersForFrontendJson(string $text, bool $useAdminShortTokens = false): string
    {
        if ($useAdminShortTokens) {
            // Apply admin short token mapping first (preserving existing behavior)
            $text = str_replace(
                [':attribute', ':date', ':values', ':other', ':max', ':min', ':value', ':digits', ':format', ':size'],
                ['{a}', '{d}', '{vs}', '{o}', '{max}', '{min}', '{v}', '{dg}', '{f}', '{s}'],
                $text
            );
        }

        // Generic conversion: :identifier -> {identifier}
        // Pattern matches : followed by a valid identifier (letter or underscore, then alphanumeric or underscore)
        // This will NOT match URLs like http:// or times like 10:30
        return preg_replace('/:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $text);
    }

    /**
     * Recursively convert placeholders in an array of translation strings for frontend JSON.
     *
     * @param array<string, mixed> $input The array to convert
     * @param bool $useAdminShortTokens If true, apply admin short token mapping
     *
     * @return array<string, mixed> The converted array
     */
    private function convertFrontendJsonArrayPlaceholders(array $input, bool $useAdminShortTokens = false): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->convertFrontendJsonArrayPlaceholders($value, $useAdminShortTokens);
            } else if (is_string($value)) {
                $result[$key] = $this->convertPlaceholdersForFrontendJson($value, $useAdminShortTokens);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param mixed $modules
     *
     * @return array<int, string>
     */
    private function normalizeModuleNames(mixed $modules): array
    {
        if (!is_array($modules)) {
            return [];
        }

        $normalized = [];

        foreach ($modules as $module) {
            if (!is_scalar($module)) {
                continue;
            }

            $moduleName = trim((string)$module);

            if ($moduleName === '') {
                continue;
            }

            $normalized[] = $moduleName;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param mixed $textPayload
     */
    private function resolveTranslationText(mixed $textPayload, string $locale): string
    {
        if (!is_array($textPayload)) {
            return '';
        }

        $locale = trim($locale);

        if ($locale === '') {
            return '';
        }

        if (isset($textPayload[$locale]) && is_string($textPayload[$locale])) {
            return $textPayload[$locale];
        }

        foreach ($textPayload as $key => $value) {
            if (!is_string($key) || trim($key) !== $locale || !is_string($value)) {
                continue;
            }

            return $value;
        }

        return '';
    }
}
