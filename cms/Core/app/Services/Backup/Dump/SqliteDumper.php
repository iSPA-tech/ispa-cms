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
 * Dumps a SQLite database: VACUUM INTO a staging copy, then gzip it. Table counts (sqlite_master, type table,
 * excluding sqlite_%) must match between the live database and the staging copy.
 */
class SqliteDumper implements DatabaseDumper
{
    /**
     * Dump the connection's database to a gzip file with verification.
     *
     * @throws \RuntimeException message prefixed with the machine error code
     */
    public function dump(string $connection, string $targetFile): DumpResult
    {
        $source = (string) config("database.connections.{$connection}.database");
        $staging = (string) config('core.backup.staging_path');
        $copy = null;
        $in = null;
        $out = null;

        if ($source === '' || $source === ':memory:') {
            throw new \RuntimeException('BACKUP_DUMP_FAILED: sqlite database is not a file');
        }

        try {
            DumpProcessRunner::ensureDir($staging);
            DumpProcessRunner::ensureDir(dirname($targetFile));
            $sql = "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
            $expected = (int) DB::connection($connection)->getPdo()->query($sql)->fetchColumn();

            $copy = $staging . DIRECTORY_SEPARATOR . 'sqlite-' . bin2hex(random_bytes(8)) . '.db';
            DB::connection($connection)->getPdo()->exec("VACUUM INTO '" . str_replace("'", "''", $copy) . "'");
            chmod($copy, 0600);

            $check = new \PDO('sqlite:' . $copy);
            $dumped = (int) $check->query($sql)->fetchColumn();
            unset($check);
            if ($dumped < $expected) {
                throw new \RuntimeException(sprintf('BACKUP_TABLE_COUNT_MISMATCH: %d of %d tables dumped', $dumped, $expected));
            }

            $in = fopen($copy, 'rb');
            $out = fopen($targetFile, 'wb');
            if ($in === false || $out === false) {
                throw new \RuntimeException('BACKUP_DUMP_FAILED: cannot open files');
            }
            chmod($targetFile, 0600);
            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            $hash = hash_init('sha256');
            while (! feof($in)) {
                $chunk = (string) fread($in, 1048576);
                $bytes = (string) deflate_add($deflate, $chunk, feof($in) ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                fwrite($out, $bytes);
                hash_update($hash, $bytes);
            }
            fclose($in);
            fclose($out);
            $in = $out = null;

            return new DumpResult('sqlite.gz', $expected, $dumped, (int) filesize($targetFile), hash_final($hash));
        } catch (\Throwable $e) {
            foreach ([$in, $out] as $h) {
                if (is_resource($h)) {
                    fclose($h);
                }
            }
            @unlink($targetFile);
            throw $e;
        } finally {
            if ($copy !== null) {
                @unlink($copy);
            }
        }
    }
}
