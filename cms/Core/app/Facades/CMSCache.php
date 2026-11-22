<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Facades;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static mixed remember(string $key, int $ttl, Closure $callback)
 * @method static mixed rememberRandom(string $key, Closure $callback, int $minTtl = 3600, int $maxTtl = 7200)
 * @method static mixed rememberWithTracking(string $key, Closure $callback, int $ttl = 3600, ?string $title = null)
 * @method static mixed rememberRandomWithTracking(string $key, Closure $callback, int $minTtl = 3600, int $maxTtl = 7200, ?string $title = null)
 * @method static string buildKey(string $prefix, array $segments = [])
 * @method static void store(string $cacheKey, string $title, int $ttl = 0)
 * @method static void autoForget(Model $model, Request $request)
 * @method static void forgetByTag(string ...$tags)
 * @method static mixed get(string $key)
 * @method static void forget(string $cacheKey = null)
 * @method static void forgetByPostType(mixed $model)
 * @method static void markOnline(string $identifier, int $ttl = 300)
 * @method static void put(string $key, mixed $value, int $ttl)
 * @method static int countOnline(int $ttl = 300)
 *
 * @see \App\Services\CacheService
 * @package Cms\Core\Facades
 */
class CMSCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'CMSCache';
    }
}
