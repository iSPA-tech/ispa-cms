<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use App\Support\Modules\ModuleStatus;
use Throwable;
use Illuminate\Console\Command;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Cms\Core\Services\ModuleSeederImporter;
use Cms\Core\Services\TranslationModuleSeederImporter;

/**
 * Deploy-time seeder for all CMS modules.
 *
 * Discovers CMS modules from the cms/ directory, then for each module imports exported
 * PHP seed arrays (from database/seeders/exported/*.php) into the database using upsert
 * logic, and imports translation rows via the TranslationModuleSeederImporter. Modules can
 * be filtered by name, restricted to enabled-only status (from the module activator), and
 * translations may be skipped independently. On any per-module failure the command logs the
 * error and continues with the remaining modules, returning FAILURE at the end.
 *
 * Usage: php artisan cms:seed-modules
 * Options:
 *   --module          Only seed the specified module name(s), case-insensitive (repeatable)
 *   --enabled-only    Only seed modules enabled according to the module activator
 *   --skip-translations  Skip translation imports for the selected modules
 *
 * @see    \Cms\Core\Services\ModuleSeederImporter
 * @see    \Cms\Core\Services\TranslationModuleSeederImporter
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SeedModulesCommand extends Command
{
    /**
     * Tables that should upsert on seed instead of skip existing rows.
     * Key = table name, value = columns to PRESERVE (not overwritten) on existing rows.
     */
    private const UPSERT_PRESERVE = [
        'settings' => ['value', 'group_name', 'name'],
        'translates' => ['name', 'text', 'group_name', 'modules', 'for_web', 'for_adm', 'for_app', 'status', 'is_used', 'used_in'],
    ];

    private const UPSERT_KEYS = [
        'settings' => 'setting_key',
    ];

    private const INSERT_DROP_COLUMNS = [
        'settings' => ['id'],
    ];

    /** Legacy widget definitions require stable-key reconciliation, never generic ID imports. */
    private const STABLE_WIDGET_EXPORTS = [
        'widgets.php',
        'widget_themes.php',
        'widget_inputs.php',
    ];

    protected $signature = 'cms:seed-modules'
    . ' {--module=* : Only seed the specified module name(s), case-insensitive}'
    . ' {--enabled-only : Only seed modules enabled according to the module activator}'
    . ' {--skip-translations : Skip translation imports for the selected modules}';

    protected $description = 'Deploy-time seeding for all CMS modules';

    /**
     * Create a new command instance.
     *
     * @param ModuleSeederImporter            $importer            Data seeder importer service
     * @param TranslationModuleSeederImporter $translationImporter Translation seeder importer service
     */
    public function __construct(
        private readonly ModuleSeederImporter            $importer,
        private readonly TranslationModuleSeederImporter $translationImporter
    )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Discovers modules, imports exported seed files, and imports translations.
     * Returns FAILURE if any module encountered an exception.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        $modules = $this->discoverModules(
            collect((array)$this->option('module')),
            (bool)$this->option('enabled-only')
        );
        $skipTranslations = (bool)$this->option('skip-translations');

        if ($modules->isEmpty()) {
            $this->warn('No CMS modules found to seed.');
            return self::FAILURE;
        }

        $overall = self::SUCCESS;

        foreach ($modules as $module) {
            [$name, $path] = $module;
            $this->components->info("Seeding {$name}");

            try {
                $this->seedExportedTables($name, $path);

                if ($skipTranslations) {
                    $this->components->twoColumnDetail('Translations', 'skipped (--skip-translations)');
                } else {
                    $this->seedTranslations($name, $path);
                }
            } catch (Throwable $exception) {
                $overall = self::FAILURE;
                $this->components->error("{$name}: {$exception->getMessage()}");
                report($exception);
                CMSLogger::error([
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                    'module' => $name,
                    'isAttach' => true,
                ]);
            }
        }

        if ($overall === self::SUCCESS) {
            $this->components->info('CMS module seeding completed.');
        } else {
            $this->components->warn('CMS module seeding finished with errors.');
        }

        return $overall;
    }

    private function discoverModules(Collection $filter, bool $enabledOnly): Collection
    {
        $root = base_path('cms');
        if (!File::isDirectory($root)) {
            return collect();
        }

        // Get all available physical modules
        $availableModules = collect(File::directories($root))
            ->map(fn(string $dir) => [basename($dir), $dir]); // [Name, Path]

        // Filter by specific names if provided
        if ($filter->isNotEmpty()) {
             $filters = $filter->map(fn($m) => strtolower((string)$m));
             $availableModules = $availableModules->filter(fn($entry) => $filters->contains(strtolower($entry[0])));
        }

        // Filter by enabled status if requested
        if ($enabledOnly) {
             $availableModules = $availableModules->filter(function($entry) {
                 // Strict: the module must be known to the activator and enabled.
                 return ModuleStatus::isEnabled($entry[0]);
             });
        }

        return $availableModules->sortBy(fn($entry) => strtolower($entry[0]))->values();
    }

    private function seedExportedTables(string $moduleName, string $modulePath): void
    {
        $exportDir = $modulePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'exported';

        if (!File::isDirectory($exportDir)) {
            $this->components->twoColumnDetail('Seeders', 'none found');
            return;
        }

        $files = collect(File::files($exportDir))
            ->filter(fn($file) => $file->getExtension() === 'php')
            // Translation sidecars are consumed by the module translation importer, not as database tables.
            ->reject(fn($file) => str_starts_with($file->getFilename(), 'translates-admin-'))
            ->sortBy(fn($file) => $file->getFilename());

        if ($files->isEmpty()) {
            $this->components->twoColumnDetail('Seeders', 'none found');
            return;
        }

        $this->disableForeignKeyChecks();

        try {
            foreach ($files as $file) {
                $table = $file->getFilenameWithoutExtension();
                $path = $file->getPathname();

                if (in_array($file->getFilename(), self::STABLE_WIDGET_EXPORTS, true)) {
                    $this->components->twoColumnDetail($table, 'skipped (owned by cms:sync-widgets)');
                    continue;
                }

                $records = include $path;
                if (!is_array($records)) {
                    $this->components->warn("{$moduleName}: {$table} export is not an array, skipping");
                    continue;
                }

                $preserveColumns = self::UPSERT_PRESERVE[$table] ?? [];
                $uniqueKey = self::UPSERT_KEYS[$table] ?? 'id';
                $dropInsertColumns = self::INSERT_DROP_COLUMNS[$table] ?? [];
                $result = $this->importer->import($table, $records, $uniqueKey, $preserveColumns, $dropInsertColumns);

                $parts = ["inserted {$result['inserted']}"];
                if ($result['updated'] > 0) {
                    $parts[] = "updated {$result['updated']}";
                }
                $parts[] = "skipped {$result['skipped']}";
                $parts[] = "total {$result['total']}";
                $summary = implode(', ', $parts);
                $this->components->twoColumnDetail($table, $summary);
            }
        } finally {
            $this->enableForeignKeyChecks();
        }
    }

    private function disableForeignKeyChecks(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
            default => null,
        };
    }

    private function enableForeignKeyChecks(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
            default => null,
        };
    }

    private function seedTranslations(string $moduleName, string $modulePath): void
    {
        $result = $this->translationImporter->importFromModule($moduleName, $modulePath);

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
