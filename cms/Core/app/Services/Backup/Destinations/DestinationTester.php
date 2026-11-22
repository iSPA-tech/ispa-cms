<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Destinations;

use Aws\S3\Exception\S3Exception;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Checks that a destination accepts a write, returns it intact and (unless a lock forbids it) deletes it.
 */
class DestinationTester
{
    /**
     * Run the probe. Results never contain exception text, so credentials cannot leak.
     *
     * @return array{ok: bool, write: bool, read: bool, delete: bool, lock_active: bool, path: string, error_code: ?string}
     */
    public function test(BackupDestination $destination, ?Filesystem $disk = null): array
    {
        $result = ['ok' => false, 'write' => false, 'read' => false, 'delete' => false, 'lock_active' => false, 'path' => '', 'error_code' => null];

        try {
            $disk ??= DestinationDisk::for($destination);
            $config = is_array($destination->config) ? $destination->config : [];
            $prefix = isset($config['prefix']) && is_string($config['prefix']) ? $config['prefix'] : null;
            $path = ObjectKey::fresh($disk, ObjectKey::probe($prefix));
        } catch (Throwable) {
            $result['error_code'] = 'BACKUP_PROBE_WRITE_FAILED';

            return $result;
        }
        $result['path'] = $path;
        $content = 'ispa-backup-probe ' . bin2hex(random_bytes(8));

        try {
            $result['write'] = $disk->put($path, $content) !== false;
        } catch (Throwable) {
            $result['write'] = false;
        }
        if (!$result['write']) {
            $result['error_code'] = 'BACKUP_PROBE_WRITE_FAILED';

            return $result;
        }

        try {
            $result['read'] = $disk->get($path) === $content;
        } catch (Throwable) {
            $result['read'] = false;
        }

        $lock = false;
        try {
            $result['delete'] = $disk->delete($path) !== false;
        } catch (Throwable $e) {
            $lock = $this->isLockRefusal($e);
        }

        if ($lock) {
            $result['lock_active'] = true;
            BackupCopy::query()->create([
                'backup_id' => null, 'destination_id' => $destination->id, 'kind' => BackupCopy::KIND_PROBE,
                'path' => $path, 'status' => BackupCopy::STATUS_STORED, 'size_bytes' => strlen($content), 'uploaded_at' => now(),
            ]);
        }

        if (!$result['read']) {
            $result['error_code'] = 'BACKUP_PROBE_READ_MISMATCH';
        } elseif (!$result['delete'] && !$lock) {
            $result['error_code'] = 'BACKUP_PROBE_DELETE_FAILED';
        }
        $result['ok'] = $result['error_code'] === null;

        return $result;
    }

    /**
     * Whether a delete failure came from an object-lock policy (HTTP 409 / ObjectLockedByBucketPolicy).
     */
    private function isLockRefusal(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof S3Exception && $cur->getStatusCode() === 409) {
                return true;
            }
            if (str_contains($cur->getMessage(), 'ObjectLockedByBucketPolicy')) {
                return true;
            }
        }

        return false;
    }
}
