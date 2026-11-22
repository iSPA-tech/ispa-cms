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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored (or probe) file on one backup destination.
 *
 * @property int $id
 * @property int|null $backup_id Null for destination probes
 * @property int $destination_id
 * @property string $kind
 * @property string $path
 * @property string $status
 */
class BackupCopy extends Model
{
    public const KIND_BACKUP = 'backup';
    public const KIND_PROBE = 'probe';

    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_STORED = 'stored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PRUNED = 'pruned';
    public const STATUS_PRUNE_REFUSED = 'prune_refused';

    /** @var string */
    protected $table = CoreTableConstant::BACKUP_COPIES;

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => self::KIND_BACKUP];

    /** @var array<int, string> */
    protected $fillable = [
        'backup_id', 'destination_id', 'kind', 'path', 'status', 'size_bytes', 'uploaded_at',
        'prune_attempted_at', 'pruned_at', 'error_code',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'prune_attempted_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    /**
     * The backup this copy belongs to.
     *
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }

    /**
     * The destination holding this copy.
     *
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }
}
