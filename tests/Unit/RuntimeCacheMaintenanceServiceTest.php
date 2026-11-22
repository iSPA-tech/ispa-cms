<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RuntimeCacheMaintenanceService;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class RuntimeCacheMaintenanceServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_clear_deletes_only_current_application_prefix_and_preserves_configured_keys(): void
    {
        config()->set('cache.maintenance_preserve_keys', ['acme:vehicle-sitemap:v2']);
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('_prefix')->once()->with('')->andReturn('shared:');
        $connection->shouldReceive('scan')->once()->with(null, [
            'match' => 'shared:acme_cache_*',
            'count' => 500,
        ])->andReturn([0, [
            'shared:acme_cache_page:home',
            'shared:acme_cache_acme:vehicle-sitemap:v2',
            'shared:another_site_cache_page:home',
        ]]);
        $connection->shouldReceive('del')->once()->with('acme_cache_page:home')->andReturn(1);
        $store = Mockery::mock(RedisStore::class);
        $store->shouldReceive('getPrefix')->once()->andReturn('acme_cache_');
        $store->shouldReceive('connection')->once()->andReturn($connection);
        Cache::shouldReceive('getStore')->once()->andReturn($store);
        Artisan::shouldReceive('call')->never();

        $result = (new RuntimeCacheMaintenanceService())->clearApplicationCache();

        self::assertSame(1, $result['cleared']);
        self::assertSame(['acme:vehicle-sitemap:v2'], $result['preserved']);
    }

    public function test_optimize_uses_only_the_fixed_optimize_command(): void
    {
        Artisan::shouldReceive('call')->once()->with('optimize')->andReturn(0);

        (new RuntimeCacheMaintenanceService())->optimizeApplication();

        self::assertTrue(true);
    }

    public function test_clear_fails_before_mutation_when_store_is_not_redis(): void
    {
        Cache::shouldReceive('getStore')->once()->andReturn(Mockery::mock(FileStore::class));
        Artisan::shouldReceive('call')->never();

        $this->expectException(\RuntimeException::class);

        (new RuntimeCacheMaintenanceService())->clearApplicationCache();
    }

    public function test_clear_fails_before_mutation_when_cache_prefix_is_empty(): void
    {
        $store = Mockery::mock(RedisStore::class);
        $store->shouldReceive('getPrefix')->once()->andReturn('');
        $store->shouldReceive('connection')->never();
        Cache::shouldReceive('getStore')->once()->andReturn($store);

        $this->expectException(\RuntimeException::class);

        (new RuntimeCacheMaintenanceService())->clearApplicationCache();
    }
}
