<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\Dump\DumpProcessRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restores one application backup into an empty scratch database to prove it can be restored.
 *
 * Safety rules, all checked from configuration and a read of the target BEFORE anything is downloaded or written:
 * the target connection must not be the default connection, must not point at the same database as the default or any
 * other configured connection (driver family + database + port; SQLite: same real file), its database name must end in
 * `_restore_test` (SQLite: file name ending `_restore_test.sqlite`), its driver must fit the backup format, and it
 * must hold no table. Nothing is ever dropped. The copy is then verified exactly as `cms:backup-verify` does, restored
 *, and the restored table count
 * and `migrations` row count are compared with the dump. Credentials go through 0600 files in the 0700 work directory.
 */
class BackupRestoreTestCommand extends BackupVerifyCommand
{
    protected $signature = 'cms:backup-restore-test {uuid : Backup uuid} {--into= : Scratch connection name (database must end in _restore_test)} {--destination= : Destination uuid or name; default = first stored copy}';

    protected $description = 'Verify a backup and restore it into an empty *_restore_test scratch database (never the live one).';

    private const DRIVERS = ['sql.gz' => ['mysql', 'mariadb'], 'pgdump' => ['pgsql'], 'sqlite.gz' => ['sqlite']];

    private const NAME_SUFFIX = '_restore_test';

    /**
     * Run the restore test; exit code 0 only when the restore and the count comparison passed.
     */
    public function handle(): int
    {
        $backup = Backup::query()->where('uuid', (string) $this->argument('uuid'))->first();
        if ($backup === null) {
            $this->error('BACKUP_NOT_FOUND');

            return self::FAILURE;
        }

        $into = (string) $this->option('into');
        $refusal = $this->refusal($into, (string) $backup->format);
        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }
        $config = (array) config("database.connections.{$into}");

        try {
            $result = $this->verifyBackup($backup, $this->option('destination'));
            $backup->forceFill(['verified_at' => now()])->save();

            if ($this->targetTableCount($into, $config) !== 0) {
                throw new RuntimeException('BACKUP_RESTORE_TARGET_NOT_EMPTY');
            }
            match ($result['format']) {
                'sql.gz' => $this->restoreMysql($result['file'], $config),
                'pgdump' => $this->restorePostgres($result['file'], $config),
                default => $this->restoreSqlite((string) $result['plain'], $into, $config),
            };

            DB::purge($into);
            [$tables, $migrations] = $this->restoredCounts($into, $config);
            if ($tables !== $result['tables']) {
                throw new RuntimeException('BACKUP_RESTORE_TABLE_COUNT_MISMATCH');
            }
            if ($migrations !== $result['migrations']) {
                throw new RuntimeException('BACKUP_RESTORE_MIGRATIONS_MISMATCH');
            }

            $backup->forceFill(['restore_tested_at' => now()])->save();
        } catch (Throwable $e) {
            $this->error(self::codeOf($e, 'BACKUP_RESTORE_FAILED'));

            return self::FAILURE;
        } finally {
            $this->cleanupWork();
            DB::purge($into);
        }

        try {
            event(new AuditLogEvent('backup-restore-test', ['restore_tested_at'], Backup::class, (int) $backup->id, 'fa fa-undo', 'success', 0));
        } catch (Throwable) {
            $this->warn('BACKUP_AUDIT_FAILED');
        }

        $this->table(['Backup', 'Destination', 'Into', 'Tables', 'Migrations', 'Result'], [[
            $backup->uuid, $result['destination'], $into, (string) $tables,
            $migrations === null ? '-' : (string) $migrations, 'restore_tested',
        ]]);

        return self::SUCCESS;
    }

    /**
     * Machine code of the first safety rule the target breaks, or null when it may be restored into.
     * Reads configuration and, for the emptiness check, the target only; writes nothing.
     */
    private function refusal(string $into, string $format): ?string
    {
        $default = (string) config('database.default');
        if ($into === '') {
            return 'BACKUP_RESTORE_TARGET_UNKNOWN';
        }
        if ($into === $default) {
            return 'BACKUP_RESTORE_TARGET_IS_LIVE';
        }
        $config = config("database.connections.{$into}");
        if (! is_array($config)) {
            return 'BACKUP_RESTORE_TARGET_UNKNOWN';
        }

        $defaultConfig = config("database.connections.{$default}");
        if (! is_array($defaultConfig) || self::sameDatabase($config, $defaultConfig)) {
            return 'BACKUP_RESTORE_TARGET_IS_LIVE';
        }
        foreach ((array) config('database.connections') as $name => $other) {
            if ((string) $name !== $into && is_array($other) && self::sameDatabase($config, $other)) {
                return 'BACKUP_RESTORE_TARGET_SHARED';
            }
        }

        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $nameOk = $driver === 'sqlite'
            ? str_ends_with(basename($database), self::NAME_SUFFIX . '.sqlite')
            : str_ends_with($database, self::NAME_SUFFIX);
        if (! $nameOk) {
            return 'BACKUP_RESTORE_TARGET_NAME';
        }
        if (! in_array($driver, self::DRIVERS[$format] ?? [], true)) {
            return 'BACKUP_RESTORE_DRIVER_MISMATCH';
        }

        try {
            return $this->targetTableCount($into, $config) === 0 ? null : 'BACKUP_RESTORE_TARGET_NOT_EMPTY';
        } catch (Throwable) {
            return 'BACKUP_RESTORE_TARGET_UNREADABLE';
        } finally {
            DB::purge($into);
        }
    }

    /**
     * Whether two connection configs name the same database. Deliberately wide: driver family, database name
     * (case-insensitive) and port must match, the host is ignored; SQLite compares the real path of the file.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameDatabase(array $a, array $b): bool
    {
        $family = static fn (array $c): string => in_array($c['driver'] ?? '', ['mysql', 'mariadb'], true) ? 'mysql' : (string) ($c['driver'] ?? '');
        if ($family($a) !== $family($b)) {
            return false;
        }
        if ($family($a) === 'sqlite') {
            return self::sqlitePath((string) ($a['database'] ?? '')) === self::sqlitePath((string) ($b['database'] ?? ''));
        }
        $defaultPort = $family($a) === 'pgsql' ? 5432 : 3306;
        $port = static fn (array $c): int => (int) (($c['port'] ?? '') !== '' ? $c['port'] : $defaultPort);

        return strtolower((string) ($a['database'] ?? '')) === strtolower((string) ($b['database'] ?? '')) && $port($a) === $port($b);
    }

    /**
     * Canonical path of a SQLite database file (the file need not exist yet).
     */
    private static function sqlitePath(string $path): string
    {
        if ($path === '' || $path === ':memory:') {
            return ':memory:';
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $dir = realpath(dirname($path));

        return ($dir !== false ? $dir : dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
    }

    /**
     * Number of tables in the target. A SQLite file that is missing or empty has none and is not created here.
     *
     * @param array<string, mixed> $config
     */
    private function targetTableCount(string $into, array $config): int
    {
        if (($config['driver'] ?? '') === 'sqlite') {
            $path = (string) ($config['database'] ?? '');
            if (! is_file($path) || (int) filesize($path) === 0) {
                return 0;
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
            $count = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
            unset($pdo);

            return $count;
        }

        return $this->restoredCounts($into, $config, false)[0];
    }

    /**
     * Table count and `migrations` row count (null without that table) of the target connection.
     *
     * @param array<string, mixed> $config
     * @return array{0: int, 1: int|null}
     */
    private function restoredCounts(string $into, array $config, bool $withMigrations = true): array
    {
        $connection = DB::connection($into);
        $tables = (int) match ((string) ($config['driver'] ?? '')) {
            'sqlite' => $connection->selectOne("SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->c,
            'pgsql' => $connection->selectOne("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema')")->c,
            default => $connection->selectOne("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'", [(string) ($config['database'] ?? '')])->c,
        };
        if (! $withMigrations) {
            return [$tables, null];
        }

        return [$tables, Schema::connection($into)->hasTable('migrations') ? $connection->table('migrations')->count() : null];
    }

    /**
     * Put the verified SQLite copy in place of the (missing or table-less) target file via a same-directory rename.
     *
     * @param array<string, mixed> $config
     */
    private function restoreSqlite(string $plain, string $into, array $config): void
    {
        $target = (string) ($config['database'] ?? '');
        DB::purge($into);
        $temp = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target) . '.restoring-' . bin2hex(random_bytes(6));
        try {
            if (! @copy($plain, $temp)) {
                throw new RuntimeException('BACKUP_RESTORE_FAILED');
            }
            @chmod($temp, 0600);
            if ($this->targetTableCount($into, $config) !== 0) {
                throw new RuntimeException('BACKUP_RESTORE_TARGET_NOT_EMPTY');
            }
            if (! @rename($temp, $target)) {
                throw new RuntimeException('BACKUP_RESTORE_FAILED');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Feed the stream-decompressed dump to the mysql client; credentials go through a 0600 defaults file.
     *
     * @param array<string, mixed> $config
     */
    private function restoreMysql(string $file, array $config): void
    {
        $password = (string) ($config['password'] ?? '');
        $credFile = DumpProcessRunner::writeSecretFile($this->workDir(), 'my-', $this->mysqlClientFile($config, $password));
        $stderr = '';
        try {
            $process = new Process(
                [
                    (string) config('core.backup.binaries.mysql'),
                    '--defaults-extra-file=' . $credFile,
                    '--default-character-set=utf8mb4',
                    (string) ($config['database'] ?? ''),
                ],
                null,
                [],
                self::gunzipChunks($file),
                (float) config('core.backup.process_timeout')
            );
            $process->disableOutput();
            try {
                $process->run(function (string $type, string $buffer) use (&$stderr): void {
                    if ($type === Process::ERR) {
                        $stderr = substr($stderr . $buffer, -4096);
                    }
                });
            } catch (ProcessTimedOutException) {
                throw new RuntimeException('BACKUP_RESTORE_FAILED: mysql timed out');
            }
            if ($process->getExitCode() !== 0) {
                throw new RuntimeException('BACKUP_RESTORE_FAILED: mysql exited with ' . (int) $process->getExitCode()
                    . ': ' . DumpProcessRunner::redact($stderr, $password));
            }
        } finally {
            @unlink($credFile);
        }
    }

    /**
     * Restore a pg_dump custom archive with `pg_restore --no-owner`; the password goes through a 0600 PGPASSFILE.
     *
     * @param array<string, mixed> $config
     */
    private function restorePostgres(string $file, array $config): void
    {
        $database = (string) ($config['database'] ?? '');
        $user = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $host = (string) (($config['host'] ?? '') !== '' ? $config['host'] : 'localhost');
        $port = (string) (($config['port'] ?? '') !== '' ? $config['port'] : 5432);
        $esc = static fn (string $v): string => strtr($v, [':' => '\\:', '\\' => '\\\\']);
        $passFile = DumpProcessRunner::writeSecretFile(
            $this->workDir(),
            'pg-',
            implode(':', [$esc($host), $esc($port), $esc($database), $esc($user), $esc($password)]) . "\n"
        );
        try {
            $result = $this->processRunner()->run([
                (string) config('core.backup.binaries.pg_restore'),
                '--no-owner', '--no-privileges', '-h', $host, '-p', $port, '-U', $user, '-d', $database, $file,
            ], ['PGPASSFILE' => $passFile]);
            if ($result['exitCode'] !== 0) {
                throw new RuntimeException('BACKUP_RESTORE_FAILED: pg_restore exited with ' . $result['exitCode']
                    . ': ' . DumpProcessRunner::redact($result['stderr'], $password));
            }
        } finally {
            @unlink($passFile);
        }
    }

    /**
     * The [client] section for the mysql client; values double-quoted with backslash and quote escaped.
     *
     * @param array<string, mixed> $config
     */
    private function mysqlClientFile(array $config, #[\SensitiveParameter] string $password): string
    {
        $q = static fn (string $v): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $v) . '"';
        $lines = ['[client]', 'user=' . $q((string) ($config['username'] ?? '')), 'password=' . $q($password)];
        $socket = (string) ($config['unix_socket'] ?? '');
        if ($socket !== '') {
            $lines[] = 'socket=' . $q($socket);
        } else {
            $lines[] = 'host=' . $q((string) (($config['host'] ?? '') !== '' ? $config['host'] : '127.0.0.1'));
            $lines[] = 'port=' . (int) (($config['port'] ?? '') !== '' ? $config['port'] : 3306);
        }

        return implode("\n", $lines) . "\n";
    }
}
