<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Cms\Core\Database\Seeders\RoleTableSeed;

/**
 * Synchronise database seeders for every enabled CMS module and import module-specific translations.
 *
 * Translations are inserted only when they do not already exist to avoid overriding Core defaults;
 * existing keys only change text when an authored admin sidecar defines different wording (source wins).
 * @package Cms\Core\Console
 */
class SyncModulesDatabaseCommand extends Command
{
    /**
     * Initialize the command with the translation module seeder importer.
     *
     * @param TranslationModuleSeederImporter $importer Importer for module-specific translation seeder files.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        private readonly TranslationModuleSeederImporter $importer
    )
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected $signature = 'cms:modules-sync {module? : Only sync the specified module}'
    . ' {--skip-translations : Skip translation imports for the selected modules}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Run module database seeders and append missing translation rows for each CMS module.';

    /**
     * Run all discoverable database seeders for each enabled CMS module and import module-specific translations.
     *
     * Seeders are executed in priority order. Seeders already called by another discovered seeder
     * are skipped at the top level so aggregate seeders do not run their children twice.
     *
     * @return int Command exit code (SUCCESS or FAILURE).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(): int
    {
        $modules = $this->resolveTargetModules($this->argument('module'));
        $skipTranslations = (bool)$this->option('skip-translations');

        if ($modules->isEmpty()) {
            $this->warn('No matching CMS modules found.');

            return self::FAILURE;
        }

        $overallStatus = self::SUCCESS;

        // Core runs before most modules, so its Webmaster grant would miss permissions created later in
        // this run. Defer it and grant once at the end instead of running it twice.
        RoleTableSeed::$deferPermissionGrant = true;

        try {
            $overallStatus = $this->syncModules($modules, $skipTranslations);
        } finally {
            RoleTableSeed::$deferPermissionGrant = false;
        }

        try {
            $granted = RoleTableSeed::grantAllPermissions();
            $this->components->twoColumnDetail('Webmaster permissions', $granted === null ? 'skipped (no Webmaster role)' : "{$granted} granted");
        } catch (Throwable $exception) {
            $overallStatus = self::FAILURE;
            $this->components->error("Webmaster permissions: {$exception->getMessage()}");
            report($exception);
        }

        if ($overallStatus === self::SUCCESS) {
            $this->components->info('CMS module database sync completed.');
        } else {
            $this->components->warn('CMS module database sync finished with errors.');
        }

        return $overallStatus;
    }

    /**
     * Run the seeders and translation import of each module.
     *
     * @param Collection $modules Modules to sync.
     * @param bool $skipTranslations Whether translation imports are skipped.
     * @return int SUCCESS, or FAILURE when any module failed.
     */
    private function syncModules(Collection $modules, bool $skipTranslations): int
    {
        $overallStatus = self::SUCCESS;

        foreach ($modules as $module) {
            $moduleName = $module->getName();
            $modulePath = $module->getPath();

            $this->components->info("Syncing {$moduleName}");

            try {
                $this->runModuleSeeders($moduleName, $modulePath);

                if ($skipTranslations) {
                    $this->components->twoColumnDetail('Translations', 'skipped (--skip-translations)');
                } else {
                    $this->reportTranslationImport(
                        $moduleName,
                        $this->importer->importFromModule($moduleName, $modulePath)
                    );
                }
            } catch (Throwable $exception) {
                $overallStatus = self::FAILURE;
                $this->components->error("{$moduleName}: {$exception->getMessage()}");
                report($exception);
            }
        }

        return $overallStatus;
    }

    /**
     * Determine which modules should be processed.
     */
    private function resolveTargetModules(?string $requested): Collection
    {
        $modules = collect(Module::allEnabled());

        if ($requested === null) {
            return $modules->values();
        }

        $needle = strtolower($requested);

        return $modules
            ->filter(static fn($module) => strtolower($module->getName()) === $needle)
            ->values();
    }

    /**
     * Execute all discoverable Seeder classes within the module.
     */
    private function runModuleSeeders(string $moduleName, string $modulePath): void
    {
        $seeders = $this->discoverSeederClasses($moduleName, $modulePath);

        if (empty($seeders)) {
            $this->components->twoColumnDetail('Seeders', 'none found');

            return;
        }

        foreach ($seeders as $class) {
            $this->components->twoColumnDetail('Seeder', $class);

            try {
                $this->call('db:seed', [
                    '--class' => $class,
                    '--force' => true,
                ]);
            } catch (Throwable $exception) {
                $this->components->error("Seeder failure: {$exception->getMessage()}");
                report($exception);

                throw $exception;
            }
        }
    }

    /**
     * Locate Seeder classes under the module's database/seeders directory.
     *
     * @return array<int, class-string<Seeder>>
     */
    private function discoverSeederClasses(string $moduleName, string $modulePath): array
    {
        $directory = $modulePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';

        if (!File::isDirectory($directory)) {
            return [];
        }

        $namespace = "Cms\\{$moduleName}\\Database\\Seeders\\";
        $candidates = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace . $file->getFilenameWithoutExtension();
            if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
                continue;
            }

            //  An abstract base — AiRuntimeTaskSeederBase, say — is a subclass
            //  of Seeder and lives in the same directory as the seeders that
            //  extend it, but the container cannot build it. Handing it to
            //  db:seed threw a BindingResolutionException on every deploy.
            if (!(new ReflectionClass($class))->isInstantiable()) {
                continue;
            }

            if (! app()->environment(['local', 'testing']) && Str::endsWith($class, ['DemoSeeder', 'ExampleSeeder', 'SampleSeeder'])) {
                $this->components->warn("Skipping local-only fixture seeder: {$class}");
                continue;
            }

            $candidates[] = $class;
        }

        usort(
            $candidates,
            static fn(string $a, string $b): int => self::seederPriority($a) <=> self::seederPriority($b)
                ?: strcmp($a, $b)
        );

        return $this->withoutNestedSeederCalls($candidates, $namespace);
    }

    /**
     * Assign execution priority to seeders so "*DatabaseSeeder" runs first.
     */
    private static function seederPriority(string $class): int
    {
        return Str::endsWith($class, 'DatabaseSeeder') ? 0 : 10;
    }

    /**
     * @param array<int, class-string<Seeder>> $classes
     * @return array<int, class-string<Seeder>>
     */
    private function withoutNestedSeederCalls(array $classes, string $namespace): array
    {
        $classLookup = array_fill_keys($classes, true);
        $nested = [];

        foreach ($classes as $class) {
            foreach ($this->referencedSeederClasses($class, $namespace) as $referencedClass) {
                if ($referencedClass !== $class && isset($classLookup[$referencedClass])) {
                    $nested[$referencedClass] = true;
                }
            }
        }

        return array_values(array_filter(
            $classes,
            static fn(string $class): bool => ! isset($nested[$class])
        ));
    }

    /**
     * @param class-string<Seeder> $class
     * @return array<int, class-string<Seeder>>
     */
    private function referencedSeederClasses(string $class, string $namespace): array
    {
        $fileName = (new ReflectionClass($class))->getFileName();
        if (! is_string($fileName) || ! is_file($fileName)) {
            return [];
        }

        $source = file_get_contents($fileName);
        if (! is_string($source) || $source === '') {
            return [];
        }

        if (! preg_match_all('/(?<![A-Za-z0-9_\\\\])\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)::class/', $source, $matches)) {
            return [];
        }

        $imports = $this->classImports($source);

        return array_values(array_unique(array_map(
            static fn(string $match): string => str_contains($match, '\\')
                ? ltrim($match, '\\')
                : ($imports[$match] ?? $namespace . $match),
            $matches[1]
        )));
    }

    /**
     * @return array<string, string>
     */
    private function classImports(string $source): array
    {
        if (! preg_match_all('/^use\s+([^;]+);/m', $source, $matches)) {
            return [];
        }

        $imports = [];
        foreach ($matches[1] as $statement) {
            if (str_contains($statement, ' function ') || str_contains($statement, ' const ')) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', trim($statement));
            $class = ltrim((string) ($parts[0] ?? ''), '\\');
            if ($class === '') {
                continue;
            }

            $alias = isset($parts[1])
                ? trim((string) $parts[1])
                : class_basename($class);
            $imports[$alias] = $class;
        }

        return $imports;
    }

    /**
     * Display translation import results.
     *
     * @param string $moduleName
     * @param array{
     *     found: bool,
     *     valid: bool,
     *     created: int,
     *     linked: int,
     *     updated?: int,
     *     skipped: int,
     *     total: int,
     *     path: string,
     *     rewords?: array<int, array<string, mixed>>,
     *     duplicates?: array<int, array{name: string, count: int}>
     * } $result
     */
    private function reportTranslationImport(string $moduleName, array $result): void
    {
        if (!$result['found']) {
            $this->components->twoColumnDetail('Translations', 'none found');

            return;
        }

        if (!$result['valid']) {
            $this->components->warn("Translations file for {$moduleName} must return an array.");

            return;
        }

        $updated = (int) ($result['updated'] ?? 0);
        $summary = "added {$result['created']}, linked {$result['linked']}, updated {$updated}";

        if ($result['skipped'] > 0) {
            $summary .= ", skipped {$result['skipped']}";
        }

        $this->components->twoColumnDetail('Translations', $summary);

        foreach (TranslationModuleSeederImporter::describeRewords($result) as $line) {
            $this->line("  reword: {$line}");
        }

        foreach (TranslationModuleSeederImporter::describeDuplicates($result) as $line) {
            $this->components->warn($line);
        }
    }
}
