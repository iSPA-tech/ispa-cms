<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Dump;

use Cms\Core\Models\Backup\Backup;
use Illuminate\Support\Facades\DB;

/**
 * Refuses to start a dump when the staging disk cannot hold it.
 *
 * Estimate = 1.3 x the size of the last succeeded backup of the same database, otherwise raw database size / 4.
 */
class FreeSpaceGuard
{
    /**
     * Throw BACKUP_NO_SPACE when free space is below estimate + min_free_bytes.
     *
     * @throws \RuntimeException
     */
    public function ensure(string $connection): void
    {
        $staging = (string) config('core.backup.staging_path');
        $needed = $this->estimate($connection) + (int) config('core.backup.min_free_bytes');
        $free = $this->freeBytes($this->existingPath($staging));

        if ($free < $needed) {
            throw new \RuntimeException(sprintf(
                'BACKUP_NO_SPACE: %d bytes free in staging, %d needed',
                (int) $free,
                $needed
            ));
        }
    }

    /**
     * Expected dump size in bytes.
     */
    public function estimate(string $connection): int
    {
        $dbName = (string) config("database.connections.{$connection}.database");
        $last = Backup::query()
            ->where('db_name', $dbName)
            ->where('status', Backup::STATUS_SUCCEEDED)
            ->where('size_bytes', '>', 0)
            ->orderByDesc('id')
            ->first();

        if ($last !== null) {
            return (int) ceil(1.3 * (int) $last->size_bytes);
        }

        return (int) ceil($this->rawSizeBytes($connection) / 4);
    }

    /**
     * Free bytes on the disk holding the path. Overridable in tests.
     */
    protected function freeBytes(string $path): float
    {
        $free = @disk_free_space($path);

        return $free === false ? 0.0 : $free;
    }

    /**
     * Raw database size in bytes: MySQL data+index length, Postgres pg_database_size, SQLite file size.
     * Overridable in tests.
     */
    protected function rawSizeBytes(string $connection): int
    {
        $config = (array) config("database.connections.{$connection}");
        $db = DB::connection($connection);

        return match ($config['driver'] ?? '') {
            'mysql', 'mariadb' => (int) ($db->selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS s FROM information_schema.tables WHERE table_schema = ?',
                [$config['database']]
            )->s ?? 0),
            'pgsql' => (int) ($db->selectOne('SELECT pg_database_size(current_database()) AS s')->s ?? 0),
            'sqlite' => is_file((string) $config['database']) ? (int) filesize((string) $config['database']) : 0,
            default => 0,
        };
    }

    /**
     * Nearest existing ancestor of a path, so disk_free_space works before the staging dir is created.
     */
    private function existingPath(string $path): string
    {
        while ($path !== '' && ! file_exists($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        return $path === '' ? '.' : $path;
    }
}
