<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Traits\HasUuid;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Class Tag
 * -- Shared tag entity (post tags and user tags live in one table, told apart by `type`).
 *
 * @package Cms\Core\Models
 *
 * @property integer id
 * @property string uuid
 * @property string type  `post` or `user`
 * @property string name
 * @property string slug
 * @property string description
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 */
class Tag extends AbstractBaseModel
{
    use HasFactory, SoftDeletes, SerializeDateTrait, HasUuid;

    public const TYPE_POST = 'post';
    public const TYPE_USER = 'user';

    /** @var string */
    public $table = Tables::TAGS;

    /** @var array<string,mixed> Rows default to post tags, as the column does. */
    protected $attributes = ['type' => self::TYPE_POST];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'slug', 'description', 'type'];

    /** @var array */
    protected $casts = [];

    /**
     * Boot the model: drop the public post-tag caches when a post tag changes (user tags have none).
     *
     * @return void
     */
    protected static function booted(): void
    {
        $flush = static function (self $tag, array $slugs): void {
            $cache = '\Cms\Content\Services\Cache\ContentPublicTagCacheService';
            if ($tag->type === self::TYPE_POST && class_exists($cache)) {
                DB::afterCommit(static fn () => app($cache)->invalidateTags($slugs));
            }
        };
        static::saved(static fn (self $tag) => $flush($tag, [$tag->getOriginal('slug'), $tag->slug]));
        static::deleted(static fn (self $tag) => $flush($tag, [$tag->slug]));
        static::restored(static fn (self $tag) => $flush($tag, [$tag->slug]));
    }

    /**
     * Restrict a query to one tag type.
     *
     * @param Builder $query
     * @param string $type TYPE_POST or TYPE_USER
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($this->qualifyColumn('type'), $type);
    }

    /**
     * Tag data relationship
     *
     * @return HasMany
     */
    public function data(): HasMany
    {
        return $this->hasMany(TagData::class, 'tag_id', 'id');
    }

    /**
     * Users carrying this (user) tag; pivot column `source` is manual or sync.
     *
     * @return MorphToMany
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'target', Tables::TAGS_DATA, 'tag_id', 'target_id')->withPivot('source');
    }
}
