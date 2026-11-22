<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupRunner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Takes a database backup in this process and prints a result table (no credentials, no paths).
 */
class BackupRunCommand extends Command
{
    protected $signature = 'cms:backup-run {--reason=manual : manual, scheduled, pre_install, pre_import, pre_purge or pre_core_upgrade} {--destination=* : Destination uuid or name (repeatable); default = all enabled}';

    protected $description = 'Back up the site database to the enabled destinations.';

    /**
     * Run the backup; exit code 0 only when every destination stored it.
     */
    public function handle(BackupRunner $runner): int
    {
        $reason = (string) $this->option('reason');
        if (! in_array($reason, [Backup::REASON_MANUAL, Backup::REASON_SCHEDULED, Backup::REASON_PRE_INSTALL, Backup::REASON_PRE_IMPORT, Backup::REASON_PRE_PURGE, Backup::REASON_PRE_CORE_UPGRADE], true)) {
            $this->error('BACKUP_REASON_INVALID');

            return self::FAILURE;
        }

        $ids = [];
        foreach ((array) $this->option('destination') as $ref) {
            $destination = BackupDestination::query()->where('uuid', (string) $ref)->orWhere('name', (string) $ref)->first();
            if ($destination === null) {
                $this->error('BACKUP_DESTINATION_NOT_FOUND');

                return self::FAILURE;
            }
            $ids[] = (int) $destination->id;
        }

        try {
            $backup = $runner->run($reason, null, $ids);
        } catch (RuntimeException $e) {
            $this->error(preg_match('/^(BACKUP_[A-Z0-9_]+)/', $e->getMessage(), $m) === 1 ? $m[1] : 'BACKUP_UNEXPECTED');

            return self::FAILURE;
        }

        $this->table(['Backup', 'Status', 'Tables', 'Size (bytes)', 'Error'], [[
            $backup->uuid, $backup->status, (string) $backup->tables_dumped, (string) $backup->size_bytes, (string) $backup->error_code,
        ]]);
        $rows = [];
        foreach ($backup->copies()->with('destination')->orderBy('id')->get() as $copy) {
            $rows[] = [(string) $copy->destination?->name, $copy->status, (string) $copy->size_bytes, (string) $copy->error_code];
        }
        if ($rows !== []) {
            $this->table(['Destination', 'Status', 'Size (bytes)', 'Error'], $rows);
        }

        return $backup->status === Backup::STATUS_SUCCEEDED ? self::SUCCESS : self::FAILURE;
    }
}
