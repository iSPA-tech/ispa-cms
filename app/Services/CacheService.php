<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use Closure;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Random\RandomException;
use Illuminate\Http\Request;
use Cms\Content\Models\Post;
use Cms\Core\Models\Category;
use Cms\Product\Models\Brand;
use Cms\Product\Models\Product;
use Illuminate\Support\Facades\Cache;
use Cms\Product\Models\ProductBundle;
use App\Models\{Item, Place, Gallery};
use Cms\Core\Services\Cache\LegacyCacheTagBridge;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache service for managing cache operations.
 *
 * @author malayvuong
 * @since 7.160.1175 - 2025-04-10, 11:35 ICT
 */
class CacheService
{
    /** @var bool */
    public bool $isDiscovery = false;

    /**
     * Remember with tracking.
     *
     * @param string $key
     * @param Closure $callback
     * @param int $minTtl
     * @param int $maxTtl
     * @param string|null $title
     *
     * @return mixed
     * @throws RandomException
     */
    public function rememberRandomWithTracking(string $key, Closure $callback, int $minTtl = 5400, int $maxTtl = 7200, ?string $title = null): mixed
    {
        $ttl = random_int($minTtl, $maxTtl);
        $this->store($key, $title ?? $key, $ttl);

        return $this->remember($key, $ttl, $callback);
    }

    /**
     * Track and store cache key to tag table.
     */
    public function store(string $cacheKey, string $title, int $ttl = 0): void
    {
        //  No-op: persisted cache tag tracking removed.
    }

    /**
     * Remember with fixed TTL.
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        return cache()->remember($key, $ttl, $callback);
    }

    /**
     * Remember with randomized TTL.
     *
     * @throws RandomException
     */
    public function rememberRandom(string $key, Closure $callback, int $minTtl = 5400, int $maxTtl = 7200): mixed
    {
        return $this->remember($key, random_int($minTtl, $maxTtl), $callback);
    }

    /**
     * Remember with tracking.
     *
     * @param string $key
     * @param int $ttl
     * @param Closure $callback
     * @param string|null $title
     *
     * @return mixed
     * @throws RandomException
     */
    public function rememberWithTracking(string $key, Closure $callback, int $ttl = 0, ?string $title = null): mixed
    {
        if ($ttl <= 0) {
            $ttl = random_int(3600, 7200);
        }

        $this->store($key, $title ?? $key, $ttl);
        return $this->remember($key, $ttl, $callback);
    }

    /**
     * Forget cache entries by tags.
     */
    public function forgetByTag(string ...$tags): void
    {
        if (empty($tags)) {
            return;
        }

        app(LegacyCacheTagBridge::class)->invalidate(...$tags);
    }

    /**
     * Retrieve a value or null.
     */
    public function get(string $key): mixed
    {
        return Cache::get($key);
    }

    /**
     * Remove a key from the cache.
     */
    public function forget(string $cacheKey = null): void
    {
        if (empty($cacheKey)) {
            return;
        }
        Cache::forget($cacheKey);
    }

    /**
     * Forget by post type.
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

    /**
     * Mark the user as online and update timestamp.
     */
    public function markOnline(string $identifier, int $ttl = 300): void
    {
        $key = 'online-users';

        $onlineUsers = Cache::get($key, []);
        $now = now()->timestamp;

        // Update or insert current user
        $onlineUsers[$identifier] = $now;

        // Remove users who were last active beyond TTL
        $onlineUsers = array_filter($onlineUsers, static fn($lastActive) => ($now - $lastActive) <= $ttl);

        Cache::put($key, $onlineUsers, $ttl);
    }

    /**
     * Put a value into cache with TTL.
     */
    public function put(string $key, mixed $value, int $ttl): void
    {
        Cache::put($key, $value, $ttl);
    }

    /**
     * Count currently online users.
     */
    public function countOnline(int $ttl = 300): int
    {
        $onlineUsers = Cache::get('online-users', []);
        $now = now()->timestamp;

        // Optional: clean up expired entries here
        $onlineUsers = array_filter($onlineUsers, static fn($lastActive) => ($now - $lastActive) <= $ttl);

        return count($onlineUsers);
    }

}
