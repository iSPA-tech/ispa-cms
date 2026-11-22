<?php


/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use Eloquent;
use App\Models\Seo;
use App\Models\Slug;
use App\Models\Place;
use App\Models\Gallery;
use Cms\Content\Models\Post;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Traits\Models\GetSlugTrait;
use Cms\Core\Traits\Models\StatusScope;
use Cms\Core\Observers\CategoryObserver;
use Cms\Core\Traits\Models\HasMediaTrait;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Traits\Models\HasChildrenTrait;
use Cms\Core\Traits\Models\ResolvesAppendedRelations;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Category
 * About the Category Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int $id
 * @property string $uid Unique random string for QR CODE
 * @property string $name
 * @property string|null $description
 * @property array|null $content
 * @property array|null $hashtag
 * @property int|null $parent_id
 * @property int $language_id
 * @property int $priority
 * @property int $type 0: news, 1: place, 2: gallery, 3: event, 4: product, 5: file, 6: role
 * @property bool $featured
 * @property string $status
 * @property array|null $config
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 * @property-read string $slug
 * @property-read string $route
 * @property-read bool $has_children
 * @property-read string $type_name
 *
 * === Relationships
 * @property-read Collection|Upload[] $images
 * @property-read Upload $image
 * @property-read Collection|Seo[] $seos
 * @property-read Slug $slugs
 * @property-read Collection|Category[] $children
 * @property-read Category $parent
 *
 * @mixin Eloquent
 */
class Category extends AbstractBaseModel
{
    use HasFactory, SoftDeletes;
    use SerializeDateTrait, GetSlugTrait, HasChildrenTrait, HasMediaTrait, StatusScope;
    use ResolvesAppendedRelations;
    use ModelBoot;

    /** @var string */
    private static string $observer = CategoryObserver::class;
    /** @var string */
    public $table = CoreTableConstant::CATEGORIES;

    //  Append attribute to called when return data
    protected $appends = [
        'slug', 'route', 'type_name', 'image',
        'has_children',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uid', 'name', 'description', 'content', 'hashtag',
        'parent_id', 'language_id', 'priority', 'type', 'featured', 'status', 'config',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'hashtag' => 'json',
        'content' => 'json',
        'featured' => 'boolean',
        'language_id' => 'integer',
        'parent_id' => 'integer',
        'priority' => 'integer',
        'type' => 'integer',
        'config' => 'json',
    ];

    /**
     * Parent relationship
     *
     * @return BelongsTo
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
        return $this->hasMany(__CLASS__, 'parent_id')->orderBy('priority')->with('language:id,name');
    }

    /**
     * Public children relationship
     *
     * @return HasMany
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-18, 15:37 ICT
     */
    public function public_children(): HasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id')
            ->published()
            ->orderBy('priority')
            ->with('slugs:id,target_id,slug,target_type');
    }

    /**
     * Get all parent categories
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-04-11, 08:37 ICT
     */
    public function getParentsAttribute()
    {
        $parents = collect();

        $parent = $this->parent;

        while (!is_null($parent)) {
            $parents->prepend($parent);
            $parent = $parent->parent;
        }

        return $parents;
    }

    /**
     * Uploaded images relationship
     *
     * @return BelongsToMany
     * @author vuongnm
     * @since 6.62.6 - 2023-04-04, 16:00 ICT
     */
    public function uploaded(): BelongsToMany
    {
        return $this->belongsToMany(Upload::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'target_id', 'upload_id')
            ->wherePivot('target_type', self::class)
            ->wherePivot('category', 'images')
            ->orderByPivot('main', 'desc')->orderByPivot('order');
    }

    /**
     * Get type name from type int
     *
     * @return Attribute
     * @author malayvuong
     * @since 6.62.6 - 2022-10-01, 23:55 ICT
     */
    public function typeName(): Attribute
    {
        return new Attribute(
            get: fn() => match ($this->type) {
                0 => 'news',
                1 => 'place',
                2 => 'gallery',
                3 => 'event',
                4 => 'product',
                5 => 'file',
                6 => 'role',
                default => 'unknown',
            }
        );
    }

    /**
     * Return router url detail
     *
     * @return Attribute
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-05-01, 11:26 ICT
     * @last_updated 6.47.5 - 2022-08-06, 00:37 ICT - by malayvuong
     */
    public function route(): Attribute
    {
        $slugName = str($this->name)->slug();
        $slugString = $this->slug ?: $slugName ?: 'no-slug';

        //  0: news, 1: place, 2: gallery, 3: , 4: product, 5: file
        $slug = match ($this->type) {
            0, 1 => route('category.' . lang('path', 'vi'), ['slug' => $slugString]),
            2 => route('gallery.index', ['cate' => $slugString]),
            5 => route('document.index.' . lang('path', 'vi'), ['cate' => $slugString]),
            4 => route('home', ['slug' => $slugString]),
            default => '',
        };

        return new Attribute(
            get: fn() => $slug
        );
    }

    /**
     * Gallery relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.62.6 - 2022-08-23, 00:10 ICT
     */
    public function galleries(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'category_target', 'category_id', 'target_id')->orderBy('id', 'desc');
    }

    /**
     * Upload file relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 6.47.5 - 2022-08-08, 17:58 ICT
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Upload::class, 'category_target', 'category_id', 'target_id')->orderBy('id', 'desc');
    }

    /**
     * Post relationships
     *
     * @return BelongsToMany
     *
     * @author malayvuong
     * @since 6.0.0 - 2022-01-22, 10:19
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'category_target', 'category_id', 'target_id')
            ->where('target_type', Post::class)
            ->where('posts.type', 'post');
    }

    /**
     * Audio post relationship via category_target table
     * - Get all audio post belongs to this category
     * - Get all audio post belongs to this category and its children
     * - `posts`.`type` = audio
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-25, 23:37 ICT
     */
    public function audios(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'category_target', 'category_id', 'target_id')
            ->where('target_type', Post::class)
            ->where('posts.type', 'audio');
    }

    /**
     * Post relationships
     *
     * @return BelongsToMany
     *
     * @author malayvuong
     * @since 6.0.0 - 2022-01-22, 10:19
     */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, CoreTableConstant::CATEGORY_TARGET, 'category_id', 'target_id')->where('target_type', Place::class);
    }

    /**
     * Language relationship
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-17, 23:12
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
