<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use App\Constants\Tables;
use Cms\Core\Models\Language;
use Cms\Core\Models\Category;
use Cms\Core\Traits\ModelBoot;
use App\Observers\GalleryObserver;
use Cms\Core\Models\AbstractBaseModel;
use App\Traits\Models\ViewCounterTrait;
use Cms\Core\Traits\Models\GetSlugTrait;
use Cms\Core\Traits\Models\ResolvesAppendedRelations;
use Cms\Core\Traits\Models\StatusScope;
use Cms\Core\Traits\Models\HasMediaTrait;
use App\Traits\Models\PublishedDateTrait;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class Gallery
 * About the Gallery Entity
 *
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string name
 * @property string description
 * @property string hashtag
 * @property string content
 * @property int language_id
 * @property int user_id
 * @property int category_id
 * @property string custom
 * @property string status
 * @property string published_at
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 * @property-read string slug
 *
 * === Relationships
 */
class Gallery extends AbstractBaseModel
{
    use HasFactory, SoftDeletes, HasMediaTrait;
    use PublishedDateTrait, ViewCounterTrait, SerializeDateTrait, GetSlugTrait, StatusScope;
    use ResolvesAppendedRelations;
    use ModelBoot;

    /** @var string */
    private static string $observer = GalleryObserver::class;
    /** @var string */
    public $table = Tables::GALLERIES;

    //  Append attribute to called when return data
    protected $appends = [
        'slug',
        'published_date',
        //  Count the view
        'total_view',
        'image',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'description', 'hashtag', 'content', 'language_id',
        'user_id', 'category_id', 'custom', 'status', 'published_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'custom' => 'json',
        'content' => 'json',
        'hashtag' => 'json',
        'language_id' => 'integer',
        'user_id' => 'integer',
        'category_id' => 'integer',
    ];

    /**
     * Categories relationship (multiple) - For EDIT
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-12, 19:39 ICT
     */
    public function editableCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_target', 'target_id', 'category_id')
            ->where('target_type', __CLASS__)
            ->where('is_root', true);
    }

    /**
     * Children relationship
     *
     * @return belongsTo
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function language(): belongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Category relationship (single)
     *
     * @author malayvuong
     * @since 6.47.5 - 2022-08-13, 14:15 ICT
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Category relationships
     *
     * @return BelongsToMany
     *
     * @author malayvuong
     * @since 6.0.0 - 2022-01-22, 10:19
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_target', 'target_id', 'category_id')->where('target_type', __CLASS__);
    }

    /**
     * User Relationships
     *
     * @return BelongsTo
     *
     * @author phamanhphien
     * @since 1.0.0
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
