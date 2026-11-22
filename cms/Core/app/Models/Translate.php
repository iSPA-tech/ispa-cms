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
use Cms\Core\Traits\Models\StatusScope;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Translate
 * About Translate Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 * ==== Properties - Fields
 * @property int $id
 * @property string $modules
 * @property string $group_name
 * @property string $name
 * @property array $text
 * @property bool $for_web
 * @property bool $for_adm
 * @property bool $for_app
 * @property string $status
 * @property boolean $is_used
 * @property string $last_scanned_at
 * @property string $used_in
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 *
 * @mixin Eloquent
 */
class Translate extends Model
{
    use HasFactory, SoftDeletes;
    use StatusScope;

    /** @var string */
    public $table = CoreTableConstant::TRANSLATES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'modules', 'group_name', 'name', 'text', 'for_web', 'for_adm', 'for_app', 'status',
        'is_used', 'last_scanned_at', 'used_in',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'modules' => 'json',
        'text' => 'json',
        'for_web' => 'boolean',
        'for_adm' => 'boolean',
        'for_app' => 'boolean',
        'is_used' => 'boolean',
        'last_scanned_at' => 'datetime',
        'used_in' => 'array',
    ];
}
