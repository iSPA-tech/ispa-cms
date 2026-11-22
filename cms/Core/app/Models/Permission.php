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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Permission
 * About the Permission Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property int $id
 * @property string $name
 * @property string $group
 * @property string $title
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 *
 * ==== Appended / Accessors
 * @property-read string $key Generated attribute: group.name
 *
 * @mixin \Eloquent
 */
class Permission extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::PERMISSIONS;

    //  Append attribute to called when return data
    protected $appends = ['key'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'group', 'title',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Return thumbnail of image
     *
     * @return Attribute
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-10, 14:13
     */
    public function key(): Attribute
    {
        return new Attribute(
            get: fn() => $this->group . '.' . $this->name
        );
    }
}
