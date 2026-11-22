<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Dump;

/**
 * Picks the dumper for a database connection by its driver.
 */
class DumperFactory
{
    /**
     * @param DumpProcessRunner|null $runner process runner shared by the dumpers
     */
    public function __construct(private readonly ?DumpProcessRunner $runner = null)
    {
    }

    /**
     * Build the dumper for the connection: mysql and mariadb use MysqlDumper, pgsql PostgresDumper, sqlite SqliteDumper.
     *
     * @throws \RuntimeException for an unsupported driver
     */
    public function make(string $connection): DatabaseDumper
    {
        $driver = (string) config("database.connections.{$connection}.driver");
        $runner = $this->runner ?? new DumpProcessRunner();

        return match ($driver) {
            'mysql', 'mariadb' => new MysqlDumper($runner),
            'pgsql' => new PostgresDumper($runner),
            'sqlite' => new SqliteDumper(),
            default => throw new \RuntimeException('BACKUP_DUMP_FAILED: unsupported database driver'),
        };
    }
}
