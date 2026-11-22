<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Models\Permission;

class SyncPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:sync-permissions {--skip-cache-clear : Preserve the application cache after synchronization}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upsert all module permissions without removing existing role assignments';

    /**
     * Execute the console command.
     *
     * Runs all module permission seeders additively. Existing permissions and their
     * role assignments (permission_role pivot) are preserved. New permissions are
     * created; existing ones are skipped or updated by each module seeder.
     */
    public function handle(): int
    {
        $this->info('Syncing permissions (non-destructive)...');

        $beforeCount = Permission::query()->count();

        $status = self::SUCCESS;

        if ($this->call('cms:modules-sync-permissions') !== self::SUCCESS) {
            $status = self::FAILURE;
        }

        $afterCount = Permission::query()->count();
        $added = $afterCount - $beforeCount;

        if ($added > 0) {
            $this->components->info("Added {$added} new permission(s). Total: {$afterCount}.");
        } else {
            $this->components->info("No new permissions. Total: {$afterCount}.");
        }

        if (! $this->option('skip-cache-clear')) {
            $this->call('cache:clear');
        }

        if ($status === self::SUCCESS) {
            $this->info('Permissions synced successfully.');
        } else {
            $this->warn('Permissions synced with module permission errors.');
        }

        return $status;
    }
}
