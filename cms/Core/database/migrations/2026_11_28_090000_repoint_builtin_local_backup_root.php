<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Backup\BackupRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Repoint the built-in local backup destination from a release path to the shared storage folder.
     *
     * The runner used to store `releases/<n>/storage/backups/local`. Once that release was deleted, uploads went to a
     * recreated folder that the next deploy removed, and the pruner marked copies pruned while their files stayed in the
     * shared storage. Only the built-in row with the runner's default root shape is changed.
     */
    public function up(): void
    {
        if (!Schema::hasTable(CoreTableConstant::BACKUP_DESTINATIONS)) {
            return;
        }

        $rows = DB::table(CoreTableConstant::BACKUP_DESTINATIONS)
            ->where('name', BackupRunner::BUILTIN_LOCAL_NAME)
            ->where('driver', 'local')
            ->get(['id', 'config']);

        foreach ($rows as $row) {
            $config = json_decode((string) $row->config, true);
            $config = is_array($config) ? BackupRunner::repointedBuiltinConfig($config) : null;
            if ($config === null) {
                continue;
            }

            DB::table(CoreTableConstant::BACKUP_DESTINATIONS)->where('id', $row->id)->update(['config' => json_encode($config, JSON_UNESCAPED_SLASHES)]);
        }
    }

    /**
     * Nothing to undo: the old release path no longer exists.
     */
    public function down(): void
    {
    }
};
