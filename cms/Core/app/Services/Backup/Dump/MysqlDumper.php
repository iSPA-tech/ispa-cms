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
 * Dumps a MySQL/MariaDB database with mysqldump, gzip-compressing the stream and verifying the result.
 *
 * Credentials go through a 0600 --defaults-extra-file in the 0700 staging dir; the password is never in argv or env.
 * Verification: the number of lines starting with `CREATE TABLE` must reach the live BASE TABLE count
 * (mysqldump can exit 0 with an empty dump) and the dump must end with the `-- Dump completed` trailer.
 */
class MysqlDumper implements DatabaseDumper
{
    private int $tablesDumped = 0;

    private string $lineHead = '';

    private string $tail = '';

    /** Whether the client is Oracle mysqldump (needs --set-gtid-purged=OFF); null until detected once per instance. */
    private ?bool $oracleClient = null;

    /**
     * @param DumpProcessRunner $runner process runner
     */
    public function __construct(private readonly DumpProcessRunner $runner = new DumpProcessRunner())
    {
    }

    /**
     * Dump the connection's database to a gzip file with verification.
     *
     * @throws \RuntimeException message prefixed with the machine error code
     */
    public function dump(string $connection, string $targetFile): DumpResult
    {
        $config = (array) config("database.connections.{$connection}");
        $database = (string) ($config['database'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $staging = (string) config('core.backup.staging_path');
        $this->tablesDumped = 0;
        $this->lineHead = '';
        $this->tail = '';
        $credFile = null;
        $handle = null;

        try {
            DumpProcessRunner::ensureDir($staging);
            DumpProcessRunner::ensureDir(dirname($targetFile));
            $expected = $this->expectedTables($connection, $database);
            $credFile = DumpProcessRunner::writeSecretFile($staging, 'my-', $this->clientFile($config));

            $handle = fopen($targetFile, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('BACKUP_DUMP_FAILED: cannot open target file');
            }
            chmod($targetFile, 0600);

            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            $hash = hash_init('sha256');
            $write = function (string $bytes) use ($handle, $hash): void {
                if ($bytes === '') {
                    return;
                }
                fwrite($handle, $bytes);
                hash_update($hash, $bytes);
            };

            $binary = (string) config('core.backup.binaries.mysqldump');
            $argv = [
                $binary,
                '--defaults-extra-file=' . $credFile,
                ...($this->isOracleClient($binary) ? ['--set-gtid-purged=OFF'] : []),
                '--single-transaction', '--quick', '--triggers', '--hex-blob', '--no-tablespaces',
                '--default-character-set=utf8mb4', '--skip-routines', '--skip-events',
                $database,
            ];

            $result = $this->runner->run($argv, [], function (string $chunk) use ($deflate, $write): void {
                $this->scan($chunk);
                $write((string) deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
            });
            $write((string) deflate_add($deflate, '', ZLIB_FINISH));
            fclose($handle);
            $handle = null;

            if ($this->lineHead !== '' && str_starts_with($this->lineHead, 'CREATE TABLE')) {
                $this->tablesDumped++;
            }
            if ($result['exitCode'] !== 0) {
                throw new \RuntimeException('BACKUP_DUMP_FAILED: mysqldump exited with ' . $result['exitCode']
                    . ': ' . DumpProcessRunner::redact($result['stderr'], $password));
            }
            if (! str_contains($this->tail, '-- Dump completed')) {
                throw new \RuntimeException('BACKUP_DUMP_INCOMPLETE: dump has no completion trailer');
            }
            if (($expected > 0 && $this->tablesDumped < $expected) || ($expected <= 0 && $this->tablesDumped === 0)) {
                throw new \RuntimeException(sprintf(
                    'BACKUP_TABLE_COUNT_MISMATCH: %d of %d tables dumped',
                    $this->tablesDumped,
                    $expected
                ));
            }

            return new DumpResult('sql.gz', $expected, $this->tablesDumped, (int) filesize($targetFile), hash_final($hash));
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($targetFile);
            throw $e;
        } finally {
            if ($credFile !== null) {
                @unlink($credFile);
            }
        }
    }

    /**
     * Whether the binary is the Oracle MySQL client. Detected once from `--version` and cached.
     *
     * Oracle 8.0.32+ takes FLUSH TABLES WITH READ LOCK on GTID servers unless --set-gtid-purged=OFF is given;
     * MariaDB clients do not know that option. Detection failure is treated as "not Oracle" (no flag).
     */
    private function isOracleClient(string $binary): bool
    {
        if ($this->oracleClient !== null) {
            return $this->oracleClient;
        }

        $out = '';
        try {
            $result = $this->runner->run([$binary, '--version'], [], function (string $chunk) use (&$out): void {
                $out .= $chunk;
            });
            $ok = $result['exitCode'] === 0 && trim($out) !== '';
        } catch (\Throwable) {
            $ok = false;
        }

        return $this->oracleClient = $ok && stripos($out, 'MariaDB') === false;
    }

    /**
     * Number of BASE TABLEs in the schema. Returns -1 when it cannot be read. Overridable in tests.
     */
    protected function expectedTables(string $connection, string $database): int
    {
        try {
            $row = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
                [$database]
            );

            return (int) ($row->c ?? -1);
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * Build the [client] section: values double-quoted with backslash and quote escaped (as ispa_backup.sh does).
     *
     * @param array<string, mixed> $config
     */
    private function clientFile(array $config): string
    {
        $q = static fn (string $v): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $v) . '"';
        $lines = ['[client]', 'user=' . $q((string) ($config['username'] ?? '')), 'password=' . $q((string) ($config['password'] ?? ''))];
        $socket = (string) ($config['unix_socket'] ?? '');
        if ($socket !== '') {
            $lines[] = 'socket=' . $q($socket);
        } else {
            $lines[] = 'host=' . $q((string) (($config['host'] ?? '') !== '' ? $config['host'] : '127.0.0.1'));
            $lines[] = 'port=' . (int) (($config['port'] ?? '') !== '' ? $config['port'] : 3306);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Count `CREATE TABLE` lines (partial lines carried across chunks) and keep the stream tail for the trailer check.
     */
    private function scan(string $chunk): void
    {
        $this->tail = substr($this->tail . $chunk, -512);
        $parts = explode("\n", $chunk);
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if (strlen($this->lineHead) < 12) {
                $this->lineHead = substr($this->lineHead . $part, 0, 12);
            }
            if ($i < $last) {
                if (str_starts_with($this->lineHead, 'CREATE TABLE')) {
                    $this->tablesDumped++;
                }
                $this->lineHead = '';
            }
        }
    }
}
