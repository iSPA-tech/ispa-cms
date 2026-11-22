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
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Cms\Core\Services\Backup\Dump\DumpProcessRunner;
use Illuminate\Console\Command;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Proves that one stored copy of an application backup is readable. It never restores anything.
 *
 * The copy is downloaded into a private 0700 directory under the backup staging path; its size and SHA-256 must match
 * the backup record, the copy row and the `.sha256` sidecar; the dump is then read completely: `sql.gz` is stream
 * decompressed, its `CREATE TABLE` statements counted and its `-- Dump completed` trailer checked; `sqlite.gz` is
 * decompressed to a file that must pass `PRAGMA integrity_check`; `pgdump` must carry the PGDMP header and pass
 * `pg_restore --list`. The table count must equal the recorded one. Staging files are always deleted. Output and
 * errors carry machine codes only, never paths or secrets.
 */
class BackupVerifyCommand extends Command
{
    protected $signature = 'cms:backup-verify {uuid : Backup uuid} {--destination= : Destination uuid or name; default = first stored copy}';

    protected $description = 'Download one copy of a backup and prove it is complete and readable (never restores).';

    private const READ_CHUNK = 1048576;

    private const MYSQL_MIGRATIONS_INSERT = 'INSERT INTO `migrations` VALUES ';

    /** Private working directory of this run; removed in cleanupWork(). */
    protected ?string $workDir = null;

    /**
     * Verify the backup; exit code 0 only when every check passed.
     */
    public function handle(): int
    {
        $backup = Backup::query()->where('uuid', (string) $this->argument('uuid'))->first();
        if ($backup === null) {
            $this->error('BACKUP_NOT_FOUND');

            return self::FAILURE;
        }

        try {
            $result = $this->verifyBackup($backup, $this->option('destination'));
            $backup->forceFill(['verified_at' => now()])->save();
        } catch (Throwable $e) {
            $this->error(self::codeOf($e, 'BACKUP_VERIFY_FAILED'));

            return self::FAILURE;
        } finally {
            $this->cleanupWork();
        }

        $this->table(['Backup', 'Destination', 'Format', 'Size (bytes)', 'Tables', 'Migrations', 'Result'], [[
            $backup->uuid, $result['destination'], $result['format'], (string) $backup->size_bytes,
            (string) $result['tables'], $result['migrations'] === null ? '-' : (string) $result['migrations'], 'verified',
        ]]);

        return self::SUCCESS;
    }

    /**
     * Download and fully check one stored copy. Leaves the downloaded (and, for SQLite, decompressed) files in the
     * work directory for the caller; the caller must call cleanupWork() in a `finally` block.
     *
     * @param mixed $destinationRef Destination uuid or name, or null for the first stored copy.
     * @return array{destination: string, format: string, file: string, plain: string|null, tables: int, migrations: int|null}
     *
     * @throws RuntimeException message prefixed with the machine error code
     */
    protected function verifyBackup(Backup $backup, mixed $destinationRef): array
    {
        if (! in_array($backup->format, Backup::FORMATS, true)) {
            throw new RuntimeException('BACKUP_VERIFY_FORMAT_UNKNOWN');
        }
        if (! is_string($backup->sha256) || $backup->sha256 === '' || (int) $backup->size_bytes <= 0) {
            throw new RuntimeException('BACKUP_VERIFY_NOT_RECORDED');
        }

        $copy = $this->storedCopy($backup, $destinationRef);
        $destination = $copy->destination;
        if (! $destination instanceof BackupDestination) {
            throw new RuntimeException('BACKUP_VERIFY_NO_COPY');
        }

        $work = $this->workDir();
        $file = $work . DIRECTORY_SEPARATOR . 'copy.bin';
        try {
            $disk = DestinationDisk::for($destination);
            $sidecar = (string) $disk->get($copy->path . '.sha256');
            $stream = $disk->readStream($copy->path);
        } catch (Throwable) {
            throw new RuntimeException('BACKUP_VERIFY_DOWNLOAD_FAILED');
        }
        if (! is_resource($stream)) {
            throw new RuntimeException('BACKUP_VERIFY_DOWNLOAD_FAILED');
        }

        $sha256 = $this->download($stream, $file);
        $size = (int) filesize($file);
        if ($size !== (int) $backup->size_bytes || ($copy->size_bytes !== null && $size !== (int) $copy->size_bytes)) {
            throw new RuntimeException('BACKUP_VERIFY_SIZE_MISMATCH');
        }
        if (! hash_equals(strtolower((string) $backup->sha256), $sha256)) {
            throw new RuntimeException('BACKUP_VERIFY_HASH_MISMATCH');
        }
        if (preg_match('/^([0-9a-f]{64})  (\S.*)$/', rtrim($sidecar, "\r\n"), $m) !== 1
            || ! hash_equals($m[1], $sha256)
            || $m[2] !== basename($copy->path)) {
            throw new RuntimeException('BACKUP_VERIFY_SIDECAR_MISMATCH');
        }

        $plain = null;
        [$tables, $migrations] = match ($backup->format) {
            'sql.gz' => $this->checkMysqlDump($file),
            'sqlite.gz' => $this->checkSqliteDump($file, $plain = $work . DIRECTORY_SEPARATOR . 'plain.sqlite'),
            default => $this->checkPostgresDump($file),
        };

        if ($tables <= 0 || ($backup->tables_dumped !== null && $tables !== (int) $backup->tables_dumped)) {
            throw new RuntimeException('BACKUP_VERIFY_TABLE_COUNT_MISMATCH');
        }

        return [
            'destination' => (string) $destination->name,
            'format' => (string) $backup->format,
            'file' => $file,
            'plain' => $plain,
            'tables' => $tables,
            'migrations' => $migrations,
        ];
    }

    /**
     * The stored copy to read: on the given destination, else the first stored copy.
     */
    private function storedCopy(Backup $backup, mixed $destinationRef): BackupCopy
    {
        $query = BackupCopy::query()
            ->where('backup_id', $backup->id)
            ->where('kind', BackupCopy::KIND_BACKUP)
            ->where('status', BackupCopy::STATUS_STORED)
            ->with('destination')
            ->orderBy('id');

        if (is_string($destinationRef) && $destinationRef !== '') {
            $destination = BackupDestination::query()->where('uuid', $destinationRef)->orWhere('name', $destinationRef)->first();
            if ($destination === null) {
                throw new RuntimeException('BACKUP_DESTINATION_NOT_FOUND');
            }
            $query->where('destination_id', $destination->id);
        }

        $copy = $query->first();
        if ($copy === null) {
            throw new RuntimeException('BACKUP_VERIFY_NO_COPY');
        }

        return $copy;
    }

    /**
     * Copy the destination stream to a 0600 staging file and return its SHA-256.
     *
     * @param resource $stream
     */
    private function download($stream, string $file): string
    {
        $out = fopen($file, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new RuntimeException('BACKUP_VERIFY_DOWNLOAD_FAILED');
        }
        chmod($file, 0600);
        $hash = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::READ_CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('BACKUP_VERIFY_DOWNLOAD_FAILED');
                }
                hash_update($hash, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('BACKUP_VERIFY_DOWNLOAD_FAILED');
                }
            }
        } finally {
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return hash_final($hash);
    }

    /**
     * Stream-decompress a mysqldump gzip file completely. Returns [CREATE TABLE count, migrations row count or null].
     *
     * @return array{0: int, 1: int|null}
     */
    private function checkMysqlDump(string $file): array
    {
        $state = ['head' => '', 'tables' => 0, 'migrations' => null, 'inMigrations' => false, 'carry' => '', 'tail' => ''];
        foreach (self::gunzipChunks($file) as $chunk) {
            $this->scanSql($chunk, $state);
        }
        $this->scanSql("\n", $state);
        if (! str_contains($state['tail'], '-- Dump completed')) {
            throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
        }

        return [$state['tables'], $state['migrations']];
    }

    /**
     * Count `CREATE TABLE` lines and rows of `INSERT INTO \`migrations\``; partial lines carry across chunks.
     *
     * @param array{head: string, tables: int, migrations: int|null, inMigrations: bool, carry: string, tail: string} $s
     */
    private function scanSql(string $chunk, array &$s): void
    {
        $s['tail'] = substr($s['tail'] . $chunk, -512);
        $prefixLength = strlen(self::MYSQL_MIGRATIONS_INSERT);
        $parts = explode("\n", $chunk);
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if (! $s['inMigrations'] && strlen($s['head']) < $prefixLength) {
                $s['head'] = substr($s['head'] . $part, 0, $prefixLength);
                if ($s['head'] === self::MYSQL_MIGRATIONS_INSERT) {
                    $s['inMigrations'] = true;
                    $s['migrations'] = ($s['migrations'] ?? 0) + 1;
                    $s['carry'] = '';
                }
            }
            if ($s['inMigrations']) {
                $buffer = $s['carry'] . $part;
                $s['migrations'] += substr_count($buffer, '),(');
                $s['carry'] = substr($buffer, -2);
            }
            if ($i < $last) {
                if (str_starts_with($s['head'], 'CREATE TABLE `migrations` ')) {
                    $s['migrations'] ??= 0;
                }
                if (str_starts_with($s['head'], 'CREATE TABLE')) {
                    $s['tables']++;
                }
                $s['head'] = '';
                $s['inMigrations'] = false;
                $s['carry'] = '';
            }
        }
    }

    /**
     * Decompress a SQLite gzip copy to `$plain` (0600) and check it. Returns [table count, migrations row count or null].
     *
     * @return array{0: int, 1: int|null}
     */
    private function checkSqliteDump(string $file, string $plain): array
    {
        $out = fopen($plain, 'wb');
        if ($out === false) {
            throw new RuntimeException('BACKUP_VERIFY_DECOMPRESS_FAILED');
        }
        chmod($plain, 0600);
        try {
            foreach (self::gunzipChunks($file) as $chunk) {
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }

        $head = (string) file_get_contents($plain, false, null, 0, 16);
        if ($head !== "SQLite format 3\0") {
            throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
        }

        try {
            $pdo = new PDO('sqlite:' . $plain, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
            if ((string) $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
            }
            $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
            $hasMigrations = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migrations'")->fetchColumn() === 1;
            $migrations = $hasMigrations ? (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() : null;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
        } finally {
            unset($pdo);
        }

        return [$tables, $migrations];
    }

    /**
     * Check a pg_dump custom-format archive with `pg_restore --list` and read the `migrations` row count from its data.
     *
     * @return array{0: int, 1: int|null}
     */
    private function checkPostgresDump(string $file): array
    {
        if ((string) file_get_contents($file, false, null, 0, 5) !== 'PGDMP') {
            throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
        }
        $binary = (string) config('core.backup.binaries.pg_restore');

        $list = '';
        $result = $this->processRunner()->run([$binary, '--list', $file], [], function (string $chunk) use (&$list): void {
            $list .= $chunk;
        });
        if ($result['exitCode'] !== 0) {
            throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
        }
        $tables = (int) preg_match_all('/^\d+; \d+ \d+ TABLE (?!DATA )/m', $list);

        $migrations = null;
        if (preg_match('/^\d+; \d+ \d+ TABLE DATA \S+ migrations /m', $list) === 1) {
            $state = ['inCopy' => false, 'rows' => 0, 'line' => ''];
            $data = $this->processRunner()->run(
                [$binary, '--data-only', '--table=migrations', '--file=-', $file],
                [],
                function (string $chunk) use (&$state): void {
                    $lines = explode("\n", $state['line'] . $chunk);
                    $state['line'] = (string) array_pop($lines);
                    foreach ($lines as $line) {
                        if ($state['inCopy']) {
                            if ($line === '\\.') {
                                $state['inCopy'] = false;
                            } else {
                                $state['rows']++;
                            }
                        } elseif (str_starts_with($line, 'COPY ')) {
                            $state['inCopy'] = true;
                        }
                    }
                }
            );
            if ($data['exitCode'] !== 0) {
                throw new RuntimeException('BACKUP_VERIFY_INCOMPLETE');
            }
            $migrations = $state['rows'];
        }

        return [$tables, $migrations];
    }

    /**
     * Decompressed chunks of a gzip file, read in 1 MiB steps; the stream must end cleanly.
     *
     * @return \Generator<int, string>
     */
    protected static function gunzipChunks(string $file): \Generator
    {
        $in = fopen($file, 'rb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($in === false || $inflate === false) {
            throw new RuntimeException('BACKUP_VERIFY_DECOMPRESS_FAILED');
        }
        try {
            while (! feof($in)) {
                $chunk = (string) fread($in, self::READ_CHUNK);
                $out = @inflate_add($inflate, $chunk, feof($in) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($out === false) {
                    throw new RuntimeException('BACKUP_VERIFY_DECOMPRESS_FAILED');
                }
                if ($out !== '') {
                    yield $out;
                }
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                throw new RuntimeException('BACKUP_VERIFY_DECOMPRESS_FAILED');
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Private 0700 working directory of this run under the backup staging path.
     */
    protected function workDir(): string
    {
        if ($this->workDir === null) {
            $staging = rtrim((string) config('core.backup.staging_path'), '/');
            DumpProcessRunner::ensureDir($staging);
            $dir = $staging . DIRECTORY_SEPARATOR . 'verify-' . bin2hex(random_bytes(8));
            DumpProcessRunner::ensureDir($dir);
            $this->workDir = $dir;
        }

        return $this->workDir;
    }

    /**
     * Delete the working directory and everything in it.
     */
    protected function cleanupWork(): void
    {
        if ($this->workDir === null) {
            return;
        }
        foreach (glob($this->workDir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (! in_array(basename($entry), ['.', '..'], true)) {
                @unlink($entry);
            }
        }
        @rmdir($this->workDir);
        $this->workDir = null;
    }

    /**
     * Process runner for external tools (argv arrays only).
     */
    protected function processRunner(): DumpProcessRunner
    {
        return new DumpProcessRunner();
    }

    /**
     * Machine code at the start of an exception message (`BACKUP_X: detail`), else the fallback.
     */
    protected static function codeOf(Throwable $e, string $fallback): string
    {
        return preg_match('/^(BACKUP_[A-Z0-9_]+)/', $e->getMessage(), $m) === 1 ? $m[1] : $fallback;
    }
}
