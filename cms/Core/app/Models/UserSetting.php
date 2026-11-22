<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class UserSetting
 * About the UserSetting Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property integer id
 * @property integer user_id
 * @property string setting_group
 * @property string setting_key
 * @property string setting_value
 * @property string created_at
 * @property string updated_a
 * @property User user belongsTo - Belong to user
 */
class UserSetting extends Model
{
    use HasFactory;

    /** @var string */
    public $table = CoreTableConstant::USERS_SETTINGS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'setting_group', 'setting_key', 'setting_value',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
        'setting_value' => 'array',
    ];
}
