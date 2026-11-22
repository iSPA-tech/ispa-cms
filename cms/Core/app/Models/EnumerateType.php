<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class EnumerateType
 * About the EnumerateType Entity
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
 * @property string $key_name
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
class EnumerateType extends Model
{
    use HasFactory;

    /** @var string */
    public $table = CoreTableConstant::ENUMERATE_TYPES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'key_name',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [];
}
