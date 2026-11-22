<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\RetentionPruner;
use Illuminate\Console\Command;

/**
 * Deletes expired backup copies recorded in core_backup_copies and prints a table per destination (no credentials).
 */
class BackupPruneCommand extends Command
{
    protected $signature = 'cms:backup-prune {--dry-run : List what would be deleted; delete and write nothing} {--destination= : Destination uuid or name; default = all destinations}';

    protected $description = 'Delete backup copies older than each destination retention (lock-aware).';

    /**
     * Run the prune; refusals under an object lock are expected, so only bad input fails.
     */
    public function handle(RetentionPruner $pruner): int
    {
        $only = null;
        $ref = $this->option('destination');
        if ($ref !== null && $ref !== '') {
            $only = BackupDestination::query()->where('uuid', (string) $ref)->orWhere('name', (string) $ref)->first();
            if ($only === null) {
                $this->error('BACKUP_DESTINATION_NOT_FOUND');

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $results = $pruner->prune($only, $dryRun);

        $rows = [];
        $paths = [];
        foreach ($results as $result) {
            $rows[] = [$result['destination'], (string) $result['pruned'], (string) $result['refused'], (string) $result['kept'], (string) count($result['would_delete'])];
            foreach ($result['would_delete'] as $path) {
                $paths[] = [$result['destination'], $path];
            }
        }
        $this->table(['Destination', 'Pruned', 'Refused', 'Kept', 'Would delete'], $rows);
        if ($dryRun && $paths !== []) {
            $this->table(['Destination', 'Would delete (with .sha256 / .FAILED)'], $paths);
        }

        return self::SUCCESS;
    }
}
