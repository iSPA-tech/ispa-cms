<?php

declare(strict_types=1);

use App\Cache\PrefixScopedRedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;

/*
 * 2026-11-27: Laravel's RedisStore::flush is FLUSHDB. On server.215 about 11 sites
 * share one Redis cache database, so `cache:clear` on one site emptied every site's cache. A `redis` cache store now
 * deletes only the keys under its own connection + cache prefix. Needs a reachable Redis (the app's default
 * connection, database 15); skipped otherwise.
 */

uses(\Tests\TestCase::class);

beforeEach(function (): void {
    $base = config('database.redis.default');
    // .215 runs phpredis; exercise that client (it is the one whose connection prefix and SCAN options matter).
    if (extension_loaded('redis')) {
        config(['database.redis.client' => 'phpredis']);
    }
    config([
        'database.redis.flush_site1' => array_merge($base, ['database' => 15, 'options' => ['prefix' => 'xflush_site1_']]),
        'database.redis.flush_site2' => array_merge($base, ['database' => 15, 'options' => ['prefix' => 'xflush_site2_']]),
        'cache.stores.flush_a' => ['driver' => 'redis', 'connection' => 'flush_site1', 'prefix' => 'cache_a_'],
        'cache.stores.flush_c' => ['driver' => 'redis', 'connection' => 'flush_site1', 'prefix' => 'cache_c_'],
        'cache.stores.flush_b' => ['driver' => 'redis', 'connection' => 'flush_site2', 'prefix' => 'cache_b_'],
    ]);
    app()->forgetInstance('redis');
    Facade::clearResolvedInstance('redis');
    Cache::forgetDriver(['flush_a', 'flush_b', 'flush_c']);

    try {
        Redis::connection('flush_site1')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No reachable Redis for the flush test.');
    }
});

afterEach(function (): void {
    foreach (['flush_a', 'flush_b', 'flush_c'] as $store) {
        try {
            Cache::store($store)->flush();
        } catch (Throwable) {
        }
    }
});

function seedFlushStores(): void
{
    foreach (['flush_a' => 'A', 'flush_b' => 'B', 'flush_c' => 'C'] as $store => $value) {
        Cache::store($store)->put('k1', $value . '1', 600);
        Cache::store($store)->put('k2', $value . '2', 600);
        Cache::store($store)->forever('k*glob', $value . '*');
    }
}

test('redis cache stores are prefix-scoped', function (): void {
    expect(Cache::store('flush_a')->getStore())->toBeInstanceOf(PrefixScopedRedisStore::class);
});

test('cache:clear on one store keeps other prefixes on the same database', function (): void {
    seedFlushStores();

    $this->artisan('cache:clear', ['store' => 'flush_a'])->assertSuccessful();

    expect(Cache::store('flush_a')->get('k1'))->toBeNull()
        ->and(Cache::store('flush_a')->get('k*glob'))->toBeNull()
        // Same connection prefix, other cache prefix.
        ->and(Cache::store('flush_c')->get('k1'))->toBe('C1')
        ->and(Cache::store('flush_c')->get('k*glob'))->toBe('C*')
        // Other connection prefix (another site on the same database).
        ->and(Cache::store('flush_b')->get('k1'))->toBe('B1')
        ->and(Cache::store('flush_b')->get('k2'))->toBe('B2');
});

test('the flush also works when phpredis adds the prefix to SCAN patterns itself', function (): void {
    $client = Redis::connection('flush_site1')->client();
    if (!$client instanceof \Redis || !defined('Redis::SCAN_PREFIX')) {
        $this->markTestSkipped('phpredis SCAN_PREFIX is not available.');
    }
    $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_PREFIX);
    seedFlushStores();

    Cache::store('flush_a')->flush();

    expect(Cache::store('flush_a')->get('k2'))->toBeNull()
        ->and(Cache::store('flush_c')->get('k2'))->toBe('C2')
        ->and(Cache::store('flush_b')->get('k2'))->toBe('B2');
});
