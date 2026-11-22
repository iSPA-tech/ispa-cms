<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Cms\Core\Observers\MetaClassObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class MetaClass
 * About the MetaClass Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property int id
 * @property string name
 * @property string target
 * @property string type
 * @property int parent_id
 * @property int category_id
 * @property int user_id
 * @property string|null resource_key  Admin resource whose custom fields this class holds
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaClass[] $group  Child meta classes of type 'group'
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MetaAttribute[] $options  Meta attributes associated with this class
 *
 * @mixin \Eloquent
 */
class MetaClass extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::META_CLASSES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'target', 'type', 'parent_id', 'category_id', 'user_id', 'resource_key',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'parent_id' => 'integer',
        'category_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Bootstrap the model and register the MetaClassObserver for model lifecycle events.
     *
     * @return void
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    protected static function booted(): void
    {
        parent::booted();

        self::observe(MetaClassObserver::class);
    }

    /**
     * Group relationships
     *
     * @return HasMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-16, 12:32 ICT
     */
    public function group(): HasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id', 'id')->where('type', 'group');
    }

    /**
     * Attribute relationships
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-11-19, 10:30 ICT
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MetaAttribute::class, CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT, 'meta_class_id', 'meta_attribute_id');
    }
}
