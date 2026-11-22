<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Throwable;
use Illuminate\Console\Command;
use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Run permission seeders for all enabled CMS modules (or a single module when specified).
 * @package Cms\Core\Console
 */
class SyncModulePermissionsCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'cms:modules-sync-permissions {module? : Only sync the specified module}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Run permission database seeder for each enabled CMS module.';

    /**
     * Run permission database seeders for all enabled CMS modules or a single specified module.
     *
     * Discovers permission seeder classes (PermissionTableSeeder, PermissionTableSeed, PermissionSeeder) within each module's database/seeders directory
     * and executes them via db:seed. Reports per-module results and any errors encountered.
     *
     * @return int Command exit code (SUCCESS or FAILURE).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(): int
    {
        $modules = $this->resolveTargetModules($this->argument('module'));

        if ($modules->isEmpty()) {
            $this->warn('No matching CMS modules found.');

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        foreach ($modules as $module) {
            $moduleName = $module->getName();
            $modulePath = $module->getPath();
            $seeder = $this->locatePermissionSeeder($moduleName, $modulePath);

            $this->components->info($moduleName);

            if ($seeder === null) {
                $this->components->twoColumnDetail('Permissions', 'no seeder found');
                continue;
            }

            $this->components->twoColumnDetail('Seeder', $seeder);

            try {
                $this->call('db:seed', [
                    '--class' => $seeder,
                    '--force' => true,
                ]);
            } catch (Throwable $exception) {
                $status = self::FAILURE;
                $this->components->error("{$moduleName}: {$exception->getMessage()}");
                report($exception);
            }
        }

        if ($status === self::SUCCESS) {
            $this->components->info('Module permission seeders completed.');
        } else {
            $this->components->warn('Module permission seeding finished with errors.');
        }

        return $status;
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
     * Locate a permission seeder class within the module's seeders directory.
     *
     * Accepted file names end with PermissionTableSeeder, PermissionTableSeed or PermissionSeeder.
     *
     * @param string $moduleName Module name, used to build the class namespace.
     * @param string $modulePath Absolute module path.
     * @return string|null Fully qualified seeder class, or null when none is found.
     */
    private function locatePermissionSeeder(string $moduleName, string $modulePath): ?string
    {
        $directory = $modulePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';

        if (!File::isDirectory($directory)) {
            return null;
        }

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (!Str::endsWith($file->getFilename(), [
                'PermissionTableSeeder.php',
                'PermissionTableSeed.php',
                'PermissionSeeder.php',
            ])) {
                continue;
            }

            $class = sprintf('Cms\\%s\\Database\\Seeders\\%s', $moduleName, $file->getFilenameWithoutExtension());

            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }
}
