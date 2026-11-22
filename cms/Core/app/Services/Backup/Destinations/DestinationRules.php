<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Destinations;

/**
 * Validation rules shared by the destination create/update paths.
 */
class DestinationRules
{
    /**
     * @param DestinationRegistry $registry Known drivers
     */
    public function __construct(private readonly DestinationRegistry $registry)
    {
    }

    /**
     * Validate retention against the lock window and the configured maximum.
     *
     * @return array<int, string> Error codes
     */
    public static function retention(int $retentionDays, int $lockDays): array
    {
        $errors = [];
        if ($retentionDays <= $lockDays) {
            $errors[] = 'BACKUP_RETENTION_NOT_ABOVE_LOCK';
        }
        if ($retentionDays > (int) config('core.backup.max_retention_days', 90)) {
            $errors[] = 'BACKUP_RETENTION_TOO_LONG';
        }

        return $errors;
    }

    /**
     * Validate a full destination input: driver settings plus retention rules.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $credentials
     * @return array<int, string> Error codes; empty when valid
     */
    public function validate(string $driver, array $config, #[\SensitiveParameter] array $credentials, int $retentionDays, int $lockDays): array
    {
        $errors = $this->registry->has($driver)
            ? $this->registry->get($driver)->validate($config, $credentials)
            : ['BACKUP_DRIVER_UNSUPPORTED'];

        return array_values(array_unique([...$errors, ...self::retention($retentionDays, $lockDays)]));
    }
}
