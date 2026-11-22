<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Models\Backup;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place backups are copied to (S3, local, SFTP, WebDAV, Google Drive, OneDrive).
 *
 * Secrets live only in the encrypted `credentials` column, which is hidden from serialization.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $driver
 * @property array<string, mixed> $config Non-secret settings
 * @property array<string, mixed>|null $credentials
 * @property int $retention_days
 * @property int $lock_days
 * @property string|null $schedule `daily@HH:MM` in Asia/Ho_Chi_Minh, null = none
 * @property bool $use_for_safety
 * @property bool $enabled
 */
class BackupDestination extends Model
{
    use HasUuid;

    public const DRIVERS = ['s3', 'local', 'sftp', 'webdav', 'gdrive', 'onedrive'];

    /** @var string */
    protected $table = CoreTableConstant::BACKUP_DESTINATIONS;

    /** @var array<int, string> */
    protected $fillable = [
        'name', 'driver', 'config', 'credentials', 'retention_days', 'lock_days', 'schedule',
        'use_for_safety', 'enabled', 'last_success_at', 'last_failure_at', 'last_error_code', 'created_by',
    ];

    /** @var array<int, string> */
    protected $hidden = ['credentials'];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'retention_days' => 'integer',
            'lock_days' => 'integer',
            'use_for_safety' => 'boolean',
            'enabled' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /**
     * Report, for each stored credential key, whether it holds a non-empty value (for write-only APIs).
     *
     * @return array<string, bool>
     */
    public function credentialsSet(): array
    {
        $stored = $this->credentials;
        if (!is_array($stored)) {
            return [];
        }

        $result = [];
        foreach ($stored as $key => $value) {
            $result[(string) $key] = $value !== null && $value !== '' && $value !== [];
        }

        return $result;
    }

    /**
     * Copies stored on this destination.
     *
     * @return HasMany<BackupCopy, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class, 'destination_id');
    }
}
