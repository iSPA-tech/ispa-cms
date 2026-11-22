<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Enumerate
 * About Enumerate Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int $id
 * @property string $name
 * @property string $name_alias
 * @property string $enum_key
 * @property string $type
 * @property int $type_id
 * @property string $input_type
 * @property bool $is_filter
 * @property bool $status
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 * @property-read EnumerateType $enumerateType
 */
class Enumerate extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::ENUMERATES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'name_alias', 'enum_key', 'type', 'type_id', 'is_filter', 'status', 'input_type',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_filter' => 'boolean',
        'status' => 'boolean',
        'type_id' => 'integer',
    ];

    /**
     * Get the enumerate type that this enumerate belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function enumerateType(): BelongsTo
    {
        return $this->belongsTo(EnumerateType::class, 'type_id');
    }
}
