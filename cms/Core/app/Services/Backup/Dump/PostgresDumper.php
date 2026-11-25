<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Dump;

use Illuminate\Support\Facades\DB;

/**
 * Dumps a PostgreSQL database in custom format (pg_dump -Fc) and verifies it with pg_restore --list.
 *
 * Credentials go through a 0600 PGPASSFILE in the 0700 staging dir. Counting rule: archive list entries of the form
 * `<id>; <oid> <oid> TABLE <schema> <name> <owner>` are table definitions; `TABLE DATA` entries are excluded so each
 * table counts once. The count must reach information_schema.tables (BASE TABLE) outside pg_catalog/information_schema.
 */
class PostgresDumper implements DatabaseDumper
{
    /**
     * @param DumpProcessRunner $runner process runner
     */
    public function __construct(private readonly DumpProcessRunner $runner = new DumpProcessRunner())
    {
    }

    /**
     * Dump the connection's database to a pg_dump custom-format file with verification.
     *
     * @throws \RuntimeException message prefixed with the machine error code
     */
    public function dump(string $connection, string $targetFile): DumpResult
    {
        $config = (array) config("database.connections.{$connection}");
        $database = (string) ($config['database'] ?? '');
        $user = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $host = (string) (($config['host'] ?? '') !== '' ? $config['host'] : 'localhost');
        $port = (string) (($config['port'] ?? '') !== '' ? $config['port'] : 5432);
        $staging = (string) config('core.backup.staging_path');
        $passFile = null;

        try {
            DumpProcessRunner::ensureDir($staging);
            DumpProcessRunner::ensureDir(dirname($targetFile));
            $expected = $this->expectedTables($connection);

            $esc = static fn (string $v): string => strtr($v, [':' => '\\:', '\\' => '\\\\']);
            $passFile = DumpProcessRunner::writeSecretFile(
                $staging,
                'pg-',
                implode(':', [$esc($host), $esc($port), $esc($database), $esc($user), $esc($password)]) . "\n"
            );
            $env = ['PGPASSFILE' => $passFile];

            $dump = $this->runner->run([
                (string) config('core.backup.binaries.pg_dump'),
                '-Fc', '--no-owner', '-h', $host, '-p', $port, '-U', $user, '-d', $database, '-f', $targetFile,
            ], $env);
            if ($dump['exitCode'] !== 0 || ! is_file($targetFile)) {
                throw new \RuntimeException('BACKUP_DUMP_FAILED: pg_dump exited with ' . $dump['exitCode']
                    . ': ' . DumpProcessRunner::redact($dump['stderr'], $password));
            }
            chmod($targetFile, 0660);

            $list = '';
            $restore = $this->runner->run(
                [(string) config('core.backup.binaries.pg_restore'), '--list', $targetFile],
                $env,
                function (string $chunk) use (&$list): void {
                    $list .= $chunk;
                }
            );
            if ($restore['exitCode'] !== 0) {
                throw new \RuntimeException('BACKUP_DUMP_INCOMPLETE: pg_restore --list failed: '
                    . DumpProcessRunner::redact($restore['stderr'], $password));
            }

            $dumped = (int) preg_match_all('/^\d+; \d+ \d+ TABLE (?!DATA )/m', $list);
            if (($expected > 0 && $dumped < $expected) || ($expected <= 0 && $dumped === 0)) {
                throw new \RuntimeException(sprintf('BACKUP_TABLE_COUNT_MISMATCH: %d of %d tables dumped', $dumped, $expected));
            }

            return new DumpResult('pgdump', $expected, $dumped, (int) filesize($targetFile), (string) hash_file('sha256', $targetFile));
        } catch (\Throwable $e) {
            @unlink($targetFile);
            throw $e;
        } finally {
            if ($passFile !== null) {
                @unlink($passFile);
            }
        }
    }

    /**
     * Number of BASE TABLEs outside system schemas, -1 when unreadable. Overridable in tests.
     */
    protected function expectedTables(string $connection): int
    {
        try {
            $row = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema')"
            );

            return (int) ($row->c ?? -1);
        } catch (\Throwable) {
            return -1;
        }
    }
}
