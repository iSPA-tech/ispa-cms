<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use App\Constants\Tables;
use Cms\Core\Traits\ModelBoot;
use Cms\Content\Models\Content;
use Cms\Core\Models\Enumerate;
use App\Observers\ProvinceObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Class Province
 * About the Province Entity
 *
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string uuid
 * @property string uid
 * @property string name
 * @property string global_name
 * @property string slug
 * @property int type
 * @property int parent_id
 * @property int language_id
 * @property string maps_id
 * @property string longitude
 * @property string latitude
 * @property int priority
 * @property string config
 * @property string status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 * @property Province children - HasMany - Has many children
 * @property Province parent - belongsTo
 */
class Province extends Model
{
    use SoftDeletes;
    use ModelBoot;

    /** @var string */
    private static string $observer = ProvinceObserver::class;
    /** @var string */
    public $table = Tables::PROVINCES;

    //  Append attribute to called when return data
    protected $appends = ['id_name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid', 'uid', 'name', 'global_name', 'slug', 'type', 'parent_id', 'language_id',
        'maps_id', 'longitude', 'latitude', 'priority', 'config', 'status',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'config' => 'json',
        'parent_id' => 'integer',
        'language_id' => 'integer',
        'status' => 'string',
    ];

    /**
     * Provinces relationship
     *
     * @return morphMany
     * @author malayvuong
     * @since 1.0.0
     */
    public function contents(): morphMany
    {
        return $this->morphMany(Content::class, 'target');
    }

    /**
     * Return id_name of province
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.3.8 - 2022-02-10, 14:13
     */
    public function idName(): Attribute
    {
        return new Attribute(
            get: fn() => $this->id . '_' . str()->slug($this->global_name)
        );
    }

    /**
     * Enumerate status relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 6.62.6 - 2023-06-23, 13:54 ICT
     */
    public function statuses(): BelongsTo
    {
        return $this->belongsTo(Enumerate::class, 'status', 'enum_key');
    }

    /**
     * Parent relationship
     *
     * @return belongsTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function parent(): belongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id', 'id');
    }

    /**
     * Children relationship
     *
     * @return HasMany
     * @author malayvuong
     * @since  1.0.0
     */
    public function children(): hasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }
}
