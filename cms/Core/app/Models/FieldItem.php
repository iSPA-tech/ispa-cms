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
 * Class FieldItem
 * About the FieldItem Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string name
 * @property int field_id
 * @property string field_name
 * @property string type
 * @property string description
 * @property string options
 * @property string custom
 * @property int priority
 * @property string name_trans_key
 * @property string phd_trans_key
 * @property bool status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 */
class FieldItem extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::FIELD_ITEMS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'field_id', 'field_name', 'type', 'description', 'options',
        'custom', 'priority', 'name_trans_key', 'phd_trans_key', 'status',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'field_id' => 'integer',
        'priority' => 'integer',
        'status' => 'boolean',
        'custom' => 'json',
        'options' => 'json',
    ];
}
