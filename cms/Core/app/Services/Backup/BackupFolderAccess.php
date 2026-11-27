<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Opens the backup folders to the web group so the core upgrade runner can write its
 * pre-upgrade backup. Older code made `storage/backups` and its sub-folders 0700, and {@see Destinations\LocalDriver}
 * only sets 0770 on a folder it creates, so a site keeps the locked folders until the web user opens them.
 */
final class BackupFolderAccess
{
    /**
     * Give group read, write and search to every folder under `$root` (itself included) that this process owns and
     * that lacks them; setgid and the other bits stay. Files and links are left alone; never as root. Returns the
     * number of folders changed.
     */
    public static function openForRunner(string $root, ?int $euid = null): int
    {
        $euid ??= function_exists('posix_geteuid') ? posix_geteuid() : null;
        $root = rtrim($root, '/\\');
        clearstatcache(true, $root);
        if ($euid === null || $euid === 0 || is_link($root) || ! is_dir($root)) {
            return 0;
        }

        $changed = self::open($root, $euid) ? 1 : 0;
        try {
            $folders = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($folders as $path => $info) {
                if (! $info->isLink() && $info->isDir() && self::open((string) $path, $euid)) {
                    $changed++;
                }
            }
        } catch (Throwable) {
            // A folder this process cannot read: the ones before it are already open.
        }

        return $changed;
    }

    private static function open(string $path, int $euid): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $euid || ($stat['mode'] & 0070) === 0070) {
            return false;
        }

        return @chmod($path, ($stat['mode'] & 07777) | 0070);
    }
}
