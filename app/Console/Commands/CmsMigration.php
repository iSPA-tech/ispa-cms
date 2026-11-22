<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use App\Support\Modules\ModuleStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CmsMigration extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:migrate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run MySQL stored procedure migrations when the active driver supports them.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->currentDriver() !== 'mysql') {
            $this->warn('Skipping MySQL stored procedure migration because the current database driver is not mysql.');

            return self::SUCCESS;
        }

        foreach ($this->discoverProcedureFiles() as $file) {
            if ($this->runMigrationFile($file)) {
                $this->info(basename($file) . ' migrated');
            }
        }

        return self::SUCCESS;
    }

    protected function currentDriver(): string
    {
        return DB::getDriverName();
    }

    /**
     * @return list<string>
     */
    protected function discoverProcedureFiles(): array
    {
        $directory = database_path('stored-procedures');

        if (!File::isDirectory($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(static fn ($file) => $file->getExtension() === 'php')
            ->sortBy(static fn ($file) => $file->getFilename())
            ->map(static fn ($file) => $file->getRealPath())
            ->filter(static fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();
    }

    protected function runMigrationFile(string $file): bool
    {
        $migrateFile = include $file;

        if (method_exists($migrateFile, 'setEnabledModules')) {
            $migrateFile->setEnabledModules($this->enabledModules());
        }

        if (! $this->requiredModulesAreEnabled($migrateFile)) {
            $this->warn(basename($file) . ' skipped because required modules are disabled.');

            return false;
        }

        $migrateFile->up();

        return true;
    }

    /**
     * @return array<string, bool>
     */
    protected function enabledModules(): array
    {
        return array_fill_keys(ModuleStatus::enabledNames(), true);
    }

    protected function requiredModulesAreEnabled(object $migration): bool
    {
        if (! method_exists($migration, 'requiredModules')) {
            return true;
        }

        $enabledModules = $this->enabledModules();

        foreach ((array) $migration->requiredModules() as $module) {
            if (($enabledModules[(string) $module] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
