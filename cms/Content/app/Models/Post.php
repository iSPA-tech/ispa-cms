<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Models;


use App\Enums\StatusEnum;
use App\Models\Seo;
use App\Models\User;
use App\Models\Slug;
use Cms\Core\Models\Tag;
use Cms\Core\Models\Upload;
use Cms\Core\Models\Category;
use Cms\Core\Models\Language;
use Cms\Content\Constants\ContentTableConstant as Tables;
use Cms\Core\Traits\ModelBoot;
use Cms\Content\Observers\PostObserver;
use Cms\Core\Models\CategoryTarget;
use Cms\Core\Models\AbstractBaseModel;
use App\Traits\Models\ViewCounterTrait;
use Cms\Core\Traits\Models\GetSlugTrait;
use Cms\Core\Traits\Models\ResolvesAppendedRelations;
use Cms\Core\Traits\Models\StatusScope;
use App\Traits\Models\PublishedDateTrait;
use Cms\Core\Traits\Models\HasMediaTrait;
use Cms\Core\Constants\CoreTableConstant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class Post
 * About the Post Entity
 *
 * @package Cms\Content\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string uuid
 * @property string name
 * @property string description
 * @property string hashtag
 * @property array content
 * @property string type article|page|gallery|product
 * @property string video_url
 * @property int category_id
 * @property int parent_id
 * @property int language_id
 * @property int user_id
 * @property string config
 * @property string status
 * @property string published_at
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 * @property-read string slug
 * @property-read bool has_video
 * @property-read string published_date
 *
 * @property Upload images - morphMany
 * @property Upload image - morphOne
 * @property Upload attach - morphOne
 * @property Seo seo - morphOne
 * @property Slug slugs - morphOne
 * @property Post children - HasMany - Has many children
 * @property Post parent - belongsTo
 * @property Category categories - BelongsToMany
 * @property Category category - BelongsTo
 *
 * @api Content public API; signature changes follow the module notes.
 */
class Post extends AbstractBaseModel
{
    use HasFactory, SoftDeletes;
    use PublishedDateTrait, ViewCounterTrait, SerializeDateTrait, GetSlugTrait, HasMediaTrait, StatusScope;
    use ResolvesAppendedRelations;
    use ModelBoot;

    /** @var string */
    private static string $observer = PostObserver::class;

    /** @var string */
    public $table = Tables::POSTS;

    //  Append attribute to called when return data
    protected $appends = [
        'slug', 'has_video', 'published_date',
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
        'uuid', 'name', 'description', 'hashtag', 'content', 'type', 'video_url', 'parent_id',
        'category_id', 'language_id', 'user_id', 'config', 'review_pipeline', 'status', 'published_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'content' => 'json',
        'config' => 'json',
        'review_pipeline' => 'json',
        'hashtag' => 'json',
        'category_id' => 'integer',
        'parent_id' => 'integer',
        'language_id' => 'integer',
        'user_id' => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * A post slug's category is the post type (`page`, `post`, `help_article`, ...): pages, articles and other post
     * types live under different URLs and each resolver asks for its type. A post without a type is an article.
     *
     * @param int|string|null $targetId
     *
     * @return string|null
     */
    public static function slugCategoryFor(int|string|null $targetId): ?string
    {
        $type = $targetId ? static::query()->withoutGlobalScopes()->whereKey($targetId)->value('type') : null;

        return $type ?: 'post';
    }

    /**
     * Tags relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-06, 16:38 ICT
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, Tables::TAGS_DATA, 'target_id', 'tag_id')
            ->where('target_type', __CLASS__)
            ->where((new Tag())->qualifyColumn('type'), Tag::TYPE_POST);
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
     * Check post has video
     *
     * @return Attribute
     *
     * @author malayvuong
     *
     * @since 6.8.1 - 2022-03-09, 16:27
     */
    public function hasVideo(): Attribute
    {
        return new Attribute(
            get: fn() => !is_null($this->video_url)
        );
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
     * Category relationship (single)
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 6.47.5 - 2022-08-13, 14:15 ICT
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Category relationships (multiple)
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
     * Get category target
     *
     * @return HasMany
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 16:48 ICT
     */
    public function target(): HasMany
    {
        return $this->hasMany(CategoryTarget::class, 'target_id')->where('target_type', __CLASS__);
    }

    /**
     * Attach file relationship
     *
     * @return morphMany
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-10-05, 00:13 ICT
     */
    public function attachs(): morphMany
    {
        return $this->morphMany(Upload::class, 'target')->where('category', 'attach')->orderBy('main', 'DESC');
    }

    /**
     * User Relationships
     *
     * @return BelongsTo
     *
     * @author phamanhphien
     *
     * @since 1.0.0
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Children relationship
     *
     * @return HasMany
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function children(): hasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }

    /**
     * Parent relationship
     *
     * @return belongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function parent(): belongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id', 'id');
    }

    /**
     * Posts visible to the public at one captured instant: published, and either undated or published at or
     * before `$asOf`. Soft-deleted rows stay excluded by the default SoftDeletes scope.
     */
    public function scopePublicAt(Builder $query, CarbonInterface $asOf): Builder
    {
        $cutoff = $asOf->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
        $table = $this->getTable();

        return $query
            ->where($table . '.status', StatusEnum::PUBLISHED->value)
            ->where(static function (Builder $query) use ($table, $cutoff): void {
                $query->whereNull($table . '.published_at')->orWhere($table . '.published_at', '<=', $cutoff);
            });
    }
}
