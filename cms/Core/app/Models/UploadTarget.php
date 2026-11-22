<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Eloquent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class UploadTarget
 * Pivot model linking uploads to target models.
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property int $upload_id
 * @property int $target_id
 * @property string $target_type
 * @property string|null $category
 * @property bool|null $main
 * @property int|null $order
 *
 * === Relationships
 * @property-read Upload $upload
 *
 * @mixin Eloquent
 */
class UploadTarget extends Model
{
    use HasFactory;

    //  Disable timestamps
    public $timestamps = false;

    // The pivot table has no id column; stop Eloquent from adding
    // "RETURNING id" on PostgreSQL inserts.
    public $incrementing = false;

    protected $primaryKey = null;

    /** @var string $table */
    protected $table = CoreTableConstant::UPLOAD_TARGET_PIVOT;

    /** @var string[] $fillable */
    protected $fillable = [
        'upload_id',
        'target_id',
        'target_type',
        'category',
        'main',
        'order',
    ];

    /**
     * Upload relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 6.62.6 - 2023-05-07, 21:50 ICT
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'upload_id', 'id');
    }
}
