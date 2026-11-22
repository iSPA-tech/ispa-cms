<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Cms\Core\Traits\ModelBoot;
use Cms\Core\Traits\Models\StatusScope;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Observers\MenuObserver;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Menu
 * About the Menu Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * @property int id
 * @property string group_key
 * @property string name
 * @property int language_id
 * @property string status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 * @property-read MenuItem items - HasMany
 *
 * @mixin \Eloquent
 */
class Menu extends Model
{
    use HasFactory, SoftDeletes;
    use ModelBoot, SerializeDateTrait, StatusScope;

    /** @var string */
    private static string $observer = MenuObserver::class;
    /** @var string */
    public $table = CoreTableConstant::MENUS;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'group_key', 'name', 'language_id', 'status',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'language_id' => 'integer',
    ];

    /**
     * Menu items relationships
     *
     * @return HasMany
     * @author malayvuong
     * @since  1.0.0
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('priority');
    }

    /**
     * Children relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since  1.0.0
     */
    public function language(): belongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
