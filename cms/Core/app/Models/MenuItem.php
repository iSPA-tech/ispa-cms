<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Cms\Core\Traits\ModelBoot;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Observers\MenuItemObserver;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class MenuItem
 * About the MenuItem Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * @property int id
 * @property int menu_id
 * @property string name
 * @property string title
 * @property string url
 * @property string type
 * @property string target_type
 * @property int target_id
 * @property int parent_id
 * @property int priority
 * @property string custom
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Cms\Core\Models\Menu $menu  Menu that this item belongs to
 * @property-read \Illuminate\Database\Eloquent\Model $target  Polymorphic target entity
 * @property-read \Illuminate\Database\Eloquent\Collection|\Cms\Core\Models\MenuItem[] $items  Child menu items
 *
 * @mixin \Eloquent
 */
class MenuItem extends Model
{
    use HasFactory, SoftDeletes;
    use ModelBoot;

    /** @var string */
    private static string $observer = MenuItemObserver::class;
    /** @var string */
    public $table = CoreTableConstant::MENU_ITEMS;
    /** @var string[] $appends */
    protected $appends = [
        'have_children',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'menu_id', 'name', 'title', 'url', 'type', 'target_type', 'target_id', 'parent_id', 'priority', 'custom',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'menu_id' => 'integer',
        'target_id' => 'integer',
        'parent_id' => 'integer',
        'priority' => 'integer',
        'custom' => 'json',
    ];

    /**
     * Menu relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-11, 15:05 ICT
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id', 'id');
    }

    /**
     * Return attribute has_children
     *
     * @return Attribute
     * @author malayvuong
     *
     * @since 6.47.5 - 2022-08-06 13:23:56 ICT
     */
    public function haveChildren(): Attribute
    {
        return new Attribute(
            get: fn() => $this->hasChildren()
        );
    }

    /**
     * Check category has children
     *
     * @return bool
     * @author malayvuong
     * @since 6.47.5 - 2022-08-06, 00:46 ICT
     */
    public function hasChildren(): bool
    {
        return count($this->items) > 0;
    }

    /**
     * Morph to target
     *
     * @return MorphTo
     *
     * @author malayvuong
     * @since 6.2.6
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /**
     * Children of item
     *
     * @return HasMany
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function items(): HasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id', 'id')
            ->orderBy('priority')
            ->with(['items' => fn($q) => $q->orderBy('priority', 'asc')]);
    }
}
