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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * Class TagData
 * -- Polymorphic pivot row tying a tag to any model (posts, users...).
 *
 * @package Cms\Core\Models
 *
 * @property integer id
 * @property string target_type
 * @property integer target_id
 * @property integer tag_id
 * @property string|null source  manual|sync, used by user tags only
 * @property string created_at
 * @property string updated_at
 */
class TagData extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::TAGS_DATA;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['target_type', 'target_id', 'tag_id', 'source'];

    /**
     * Boot the model: drop the public post-tag caches when a post pairing changes (user pairings have none).
     *
     * @return void
     */
    protected static function booted(): void
    {
        $cache = '\Cms\Content\Services\Cache\ContentPublicTagCacheService';
        static::saved(static function (self $row) use ($cache): void {
            if ($row->target_type !== User::class && class_exists($cache)) {
                $ids = [$row->getOriginal('tag_id'), $row->tag_id];
                DB::afterCommit(static fn () => app($cache)->invalidatePosts($ids));
            }
        });
        static::deleted(static function (self $row) use ($cache): void {
            if ($row->target_type !== User::class && class_exists($cache)) {
                $id = $row->tag_id;
                DB::afterCommit(static fn () => app($cache)->invalidatePosts([$id]));
            }
        });
    }

    /**
     * Target relationship
     *
     * @return MorphTo
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /**
     * Tag relationship
     *
     * @return HasMany
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'id', 'tag_id');
    }
}
