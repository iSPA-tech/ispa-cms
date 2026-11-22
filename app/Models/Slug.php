<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use App\Constants\Tables;
use App\Enums\StatusEnum;
use Cms\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Class Slug
 * About the Slug Entity
 *
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string slug
 * @property int language_id
 * @property string category
 * @property string target_type
 * @property int target_id
 * @property string created_at
 * @property string updated_at
 * @property string index_now_at
 *
 * === Relationships
 * @property MorphTo target
 */
class Slug extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::SLUGS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'slug', 'category', 'target_type', 'target_id', 'language_id', 'index_now_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'target_id' => 'integer',
        'language_id' => 'integer',
        'index_now_at' => 'datetime',
    ];

    /**
     * Every save stamps the category from the record the slug points to: two kinds of record
     * may share a slug under different URLs, and each resolver looks a slug up by its category.
     */
    protected static function booted(): void
    {
        static::saving(static function (Slug $slug): void {
            $slug->category = static::categoryFor($slug->target_type, $slug->target_id) ?? $slug->category;
        });
    }

    /**
     * The category of a slug pointing to the given record: the owner model's `slugCategoryFor()` (a post gives its
     * type), or the snake-cased class name for a type that no longer exists or has no such method.
     */
    public static function categoryFor(?string $targetType, int|string|null $targetId): ?string
    {
        if (empty($targetType)) {
            return null;
        }

        if (class_exists($targetType) && method_exists($targetType, 'slugCategoryFor')) {
            return $targetType::slugCategoryFor($targetId);
        }

        return Str::snake(class_basename($targetType));
    }

    /**
     * Language relationship
     *
     * @return belongsTo
     * @author malayvuong
     * @since  1.0.0
     */
    public function language(): belongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Get the target model that owns the slug by Gallery
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-28, 08:39 ICT
     */
    public function gallery(): MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id')
            ->select('id', 'name', 'description', 'content', 'hashtag', 'language_id', 'category_id', 'published_at')
            ->where([
                'status' => StatusEnum::PUBLISHED->value,
            ])->with([
                'images:id,name,path,target_type,target_id,category,mime_type,type',
                'slugs:target_id,slug',
                'seo:target_id,title,description,hashtag',
                'category:id,name',
            ]);
    }

    /**
     * Get the target model that owns the slug by Category
     *
     * @return MorphTo
     * @author malayvuong
     * @since 6.62.6 - 2022-08-14, 15:32 ICT
     */
    public function category(): MorphTo
    {
        return $this->morphTo('target')
            ->select('id', 'name', 'description', 'content', 'parent_id', 'hashtag', 'language_id', 'featured', 'config')
            ->where([
                'status' => StatusEnum::PUBLISHED->value,
            ])->with([
                'image:id,name,path,target_type,target_id,category,mime_type,type',
                'slugs:target_id,slug',
                'seo:target_id,title,description,hashtag',
            ]);
    }

    /**
     * Get the target model that owns the slug by Post
     *
     * @return MorphTo
     * @author malayvuong
     * @since 6.62.6 - 2022-08-14, 14:42 ICT
     */
    public function post(): MorphTo
    {
        return $this->morphTo('target')
            ->select('id', 'name', 'description', 'content', 'hashtag', 'video_url', 'category_id', 'published_at', 'created_at')->where([
                'status' => StatusEnum::PUBLISHED->value,
                'language_id' => lang()->id ?? 1,
                ['video_url', '!=', null],
                ['category_id', '!=', null],
            ])->where(function ($q) {
                return $q->whereNull('published_at')->orWhere(function ($e) {
                    return $e->whereNotNull('published_at')->where('published_at', '<', now());
                });
            })->with([
                'image:id,name,path,target_type,target_id,category,mime_type,type',
                'slugs:target_id,slug',
                'seo:target_id,title,description,hashtag',
            ]);
    }

    /**
     * Morph to target
     *
     * @return MorphTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }
}
