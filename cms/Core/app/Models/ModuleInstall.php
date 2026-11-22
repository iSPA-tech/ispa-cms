<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Models;

use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One runtime module install requested by a Webmaster, run by {@see \Cms\Core\Jobs\Modules\InstallModuleJob}.
 *
 * The API exposes the row by uuid only ({@see self::toApiArray()}); the numeric id and the requesting user id stay
 * internal.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $version
 * @property string $source
 * @property string $kind
 * @property string|null $previous_version
 * @property string $status
 * @property string|null $step_message
 * @property string|null $zip_sha256
 * @property string|null $key_id
 * @property string|null $backup_uuid
 * @property string|null $background_task_uuid
 * @property int|null $requested_by
 * @property bool $confirmed_irreversible
 * @property bool $backup_skipped the person who started it chose to skip the safety backup
 * @property array|null $warnings
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property string|null $error_code
 * @property string|null $third_party_publisher publisher key of a module not published by iSPA, else null
 * @property int|null $third_party_confirmed_by user who confirmed installing a module of that publisher
 * @property \Illuminate\Support\Carbon|null $third_party_confirmed_at
 */
class ModuleInstall extends Model
{
    use HasUuid;

    public const SOURCE_HUB = 'hub';
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_LOCAL = 'local';

    public const KIND_INSTALL = 'install';
    public const KIND_UPGRADE = 'upgrade';
    public const KIND_UNINSTALL = 'uninstall';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_BACKING_UP = 'backing_up';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_PLACING = 'placing';
    public const STATUS_ENABLING = 'enabling';
    public const STATUS_MIGRATING = 'migrating';
    public const STATUS_REMOVING = 'removing';
    public const STATUS_BUILDING_ADMIN = 'building_admin';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** Every status in step order. */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_BACKING_UP,
        self::STATUS_DOWNLOADING,
        self::STATUS_VERIFYING,
        self::STATUS_PLACING,
        self::STATUS_ENABLING,
        self::STATUS_MIGRATING,
        self::STATUS_REMOVING,
        self::STATUS_BUILDING_ADMIN,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    /** Statuses after which nothing runs any more. */
    public const TERMINAL_STATUSES = [self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    /** `third_party_publisher` of a module that is not iSPA's while its publisher key is not known. */
    public const PUBLISHER_UNKNOWN = '*';

    /** Background task type of an install. */
    public const TASK_TYPE = 'core.module_install';

    protected $table = 'core_module_installs';

    protected $fillable = [
        'uuid',
        'name',
        'version',
        'source',
        'kind',
        'previous_version',
        'status',
        'step_message',
        'zip_sha256',
        'key_id',
        'backup_uuid',
        'background_task_uuid',
        'requested_by',
        'confirmed_irreversible',
        'backup_skipped',
        'warnings',
        'started_at',
        'finished_at',
        'error_code',
        'third_party_publisher',
        'third_party_confirmed_by',
        'third_party_confirmed_at',
    ];

    protected $hidden = ['id', 'requested_by', 'third_party_confirmed_by'];

    protected $casts = [
        'requested_by' => 'integer',
        'confirmed_irreversible' => 'boolean',
        'backup_skipped' => 'boolean',
        'warnings' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'third_party_confirmed_by' => 'integer',
        'third_party_confirmed_at' => 'datetime',
    ];

    /**
     * Installs that are still running: not in a terminal status and not finished.
     *
     * @param Builder<ModuleInstall> $query
     * @return Builder<ModuleInstall>
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES)->whereNull('finished_at');
    }

    /**
     * Whether this row upgrades an installed runtime module.
     */
    public function isUpgrade(): bool
    {
        return $this->kind === self::KIND_UPGRADE;
    }

    /**
     * Whether this row removes an installed runtime module.
     */
    public function isUninstall(): bool
    {
        return $this->kind === self::KIND_UNINSTALL;
    }

    /**
     * Whether the install is still running.
     */
    public function isUnfinished(): bool
    {
        return ! in_array($this->status, self::TERMINAL_STATUSES, true) && $this->finished_at === null;
    }

    /**
     * API shape (uuid-first; no numeric ids).
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'version' => $this->version,
            'source' => $this->source,
            'kind' => $this->kind ?? self::KIND_INSTALL,
            'previous_version' => $this->previous_version,
            'status' => $this->status,
            'step_message' => $this->step_message,
            'zip_sha256' => $this->zip_sha256,
            'key_id' => $this->key_id,
            'backup_uuid' => $this->backup_uuid,
            'backup_skipped' => (bool) $this->backup_skipped,
            'background_task_uuid' => $this->background_task_uuid,
            'confirmed_irreversible' => (bool) $this->confirmed_irreversible,
            'warnings' => $this->warnings ?? [],
            'error_code' => $this->error_code,
            // the module is not published by iSPA, and whether this install carried the explicit confirmation.
            'third_party' => $this->third_party_publisher !== null,
            'third_party_confirmed' => $this->third_party_confirmed_at !== null,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
