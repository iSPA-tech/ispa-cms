<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Helpers;

use App\Models\Place;
use App\Models\Gallery;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Illuminate\Http\Request;
use Cms\Content\Models\Post;
use Cms\Core\Models\Category;
use Cms\Product\Models\Brand;
use Cms\Product\Models\Product;
use Cms\Product\Models\ProductBundle;
use Illuminate\Support\Facades\Facade;
use Cms\Core\Services\Cache\LegacyCacheTagBridge;
use Illuminate\Database\Eloquent\Model;

/**
 * Facade-based helper for application-level cache management.
 *
 * Provides convenience methods for storing, invalidating, and auto-forgetting
 * cache entries tied to Eloquent models. It maps model mutations (dirty
 * attributes, category changes, status transitions) to the appropriate cache
 * keys and tag groups so stale data is purged automatically on save.
 *
 * @see \Cms\Core\Helpers\CacheKeyHelper
 * @see \Cms\Core\Helpers\RedisHelper
 * @package Cms\Core\Helpers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CacheHelper extends Facade
{
    /**
     * Get the singleton facade root instance.
     *
     * @return self  The resolved CacheHelper instance
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function getInstance(): self
    {
        return static::getFacadeRoot();
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 18:01 ICT
     */
    protected static function getFacadeAccessor(): string
    {
        return 'CacheHelper';
    }

    /**
     * Add cache key to tags
     *
     * @param string $cacheKey
     * @param string $title
     * @param int $ttl
     * @return void
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 19:53 ICT
     */
    public function store(string $cacheKey, string $title, int $ttl = 0): void
    {
        //  No-op: CacheTag model removed, persisted tracking disabled.
    }

    /**
     * Auto forget cache
     *
     * @param Model $model
     * @param Request $request
     * @return void
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-24, 15:29 ICT
     */
    public function autoForget(Model $model, Request $request): void
    {
        //  Remove some columns manuals check
        $fillable = array_diff($model->getFillable(), [
            'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'created_by', 'updated_by',
            'category_id', 'status', 'published_at',
        ]);
        //  Check if model has slugs relationship
        if ($model->relationLoaded('slugs')) {
            $fillable = array_diff($fillable, ['slug']);
        }
        //  Get slug (use accessor, not collection)
        $slug = $model->slug ?: null;
        $modelClass = get_class($model);
        //  Get changed columns
        $changed = $model->getDirty();
        //  If inside changed columns have category_id or in request have category_id
//        if ($modelClass === Category::class) {
//            $this->forgetByTag(Constant::CACHE_BY_CATEGORIES);
//        }
        if (isset($changed['category_id']) || $request->has('category_id')) {
            //  Remove cache by category_id
            $this->forgetByTag(Constant::CACHE_BY_CATEGORIES);
        } else
            if (isset($changed['status']) || (isset($changed['published_at']) && $model?->status === StatusEnum::PUBLISHED->value)) {

                //  Remove cache by status & category_id
                $this->forgetByTag(Constant::CACHE_BY_CATEGORIES, Constant::CACHE_BY_POSTS);
            }
        //  Compare changed columns with fillable columns
        if (!empty($slug) && count(array_intersect(array_keys($changed), $fillable)) > 0) {
            $cacheKey = match ($modelClass) {
                Post::class => sprintf(Constant::CACHE_POSTS[Constant::POST_DETAIL_BY_ID], $model->type, $slug),
                Category::class => sprintf(Constant::CACHE_CATEGORIES[Constant::CATEGORY_DETAIL_BY_ID], $slug),
                Product::class => sprintf(Constant::CACHE_PRODUCTS[Constant::PRODUCT_DETAIL_BY_ID], $slug),
                Gallery::class => sprintf(Constant::CACHE_GALLERIES[Constant::GALLERY_DETAIL_BY_ID], $slug),
                Place::class => sprintf(Constant::CACHE_PLACES[Constant::PLACE_DETAIL_BY_ID], $slug),
                \Cms\GeoPlaces\Models\Item::class => sprintf(Constant::CACHE_ITEMS[Constant::ITEM_DETAIL_BY_ID], $slug),
                Brand::class => sprintf(Constant::CACHE_BRANDS[Constant::BRAND_DETAIL_BY_ID], $slug),
                ProductBundle::class => sprintf(Constant::CACHE_PRODUCT_BUNDLES[Constant::PRODUCT_BUNDLE_DETAIL_BY_ID], $slug),
                default => null,
            };

            if (!empty($cacheKey)) {
                //  Remove cache by specific cache key
                $this->forget($cacheKey);
            }
        }
        if ($modelClass === Post::class) {
            $this->forgetByPostType($model);
        }
    }

    /**
     * Remove cache by tags
     *
     * @param string ...$tags  Tag keys to invalidate
     * @return void
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 23:47 ICT
     */
    public function forgetByTag(string ...$tags): void
    {
        if (empty($tags)) {
            return;
        }

        app(LegacyCacheTagBridge::class)->invalidate(...$tags);
    }

    /**
     * Remove cache by cache key
     *
     * @param string $cacheKey
     * @return void
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 23:50 ICT
     */
    protected function remove(string $cacheKey): void
    {
        //  Remove from cache
        cache()->forget($cacheKey);
    }

    /**
     * Remove cache by cache key
     *
     * @param string|null $cacheKey
     * @return void
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 23:53 ICT
     */
    public function forget(string $cacheKey = null): void
    {
        if (empty($cacheKey)) {
            return;
        }

        $this->remove($cacheKey);
    }

    /**
     * Forget by post type
     *
     * @param mixed $model  Eloquent model with a type attribute
     * @return void
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-02, 11:36 ICT
     */
    public function forgetByPostType(mixed $model): void
    {
        $typeConstant = match ($model->type) {
            'audio' => Constant::LATEST_WITH_AUDIO,
            'video' => Constant::LATEST_WITH_VIDEO,
            'document' => Constant::LATEST_WITH_DOCUMENT,
            default => Constant::LATEST_WITH_POST,
        };

        $this->forgetByTag($typeConstant);
    }
}
