<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class MetaValueDate
 * About the MetaValueDate Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property integer id
 * @property string value
 * @property string label
 * @property integer user_id
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @mixin \Eloquent
 */
class MetaValueDate extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::META_VALUES_DATE;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'value', 'label', 'user_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
        'value' => 'datetime',
    ];
}
