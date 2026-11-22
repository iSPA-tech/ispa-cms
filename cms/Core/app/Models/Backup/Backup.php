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
 * One database backup run.
 *
 * @property int $id
 * @property string $uuid
 * @property string $reason
 * @property string $status
 * @property string $db_driver
 * @property string $db_name
 * @property string $format
 */
class Backup extends Model
{
    use HasUuid;

    public const REASON_MANUAL = 'manual';
    public const REASON_SCHEDULED = 'scheduled';
    public const REASON_PRE_INSTALL = 'pre_install';
    public const REASON_PRE_IMPORT = 'pre_import';
    public const REASON_PRE_PURGE = 'pre_purge';
    public const REASON_PRE_CORE_UPGRADE = 'pre_core_upgrade';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    public const FORMATS = ['sql.gz', 'pgdump', 'sqlite.gz'];

    /** @var string */
    protected $table = CoreTableConstant::BACKUPS;

    /** @var array<int, string> */
    protected $fillable = [
        'reason', 'status', 'db_driver', 'db_name', 'tables_expected', 'tables_dumped', 'size_bytes', 'sha256',
        'format', 'background_task_uuid', 'started_by', 'started_at', 'finished_at', 'verified_at',
        'restore_tested_at', 'error_code', 'message',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tables_expected' => 'integer',
            'tables_dumped' => 'integer',
            'size_bytes' => 'integer',
            'started_by' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
            'restore_tested_at' => 'datetime',
        ];
    }

    /**
     * Slug of this site used in backup paths: `core.backup.site_slug` when set, otherwise the host of
     * `app.url` (lower-case, only [a-z0-9.-]). Resolved lazily so tests can override `app.url`.
     */
    public static function siteSlug(): string
    {
        $override = config('core.backup.site_slug');
        $source = is_string($override) && $override !== ''
            ? $override
            : (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'));

        $slug = preg_replace('/[^a-z0-9.-]/', '', strtolower($source)) ?? '';

        return $slug !== '' ? $slug : 'site';
    }

    /**
     * Copies of this backup on destinations.
     *
     * @return HasMany<BackupCopy, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class, 'backup_id');
    }
}
