<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Banner;
use App\Constants\Constant;
use App\Observers\BannerObserver;
use App\Services\CacheService;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Cms\Core\Services\Cache\CacheVersionService;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;

class CacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_put_get_and_forget(): void
    {
        $service = new CacheService();
        $service->put('foo', 'bar', 10);
        $this->assertEquals('bar', $service->get('foo'));
        $service->forget('foo');
        $this->assertNull($service->get('foo'));
    }

    public function test_mark_online_and_count(): void
    {
        $service = new CacheService();
        $service->markOnline('u1', 60);
        $this->assertEquals(1, $service->countOnline(60));
    }

    public function test_remember_with_tracking_reuses_cached_value(): void
    {
        $service = new CacheService();
        $calls = 0;

        $first = $service->rememberWithTracking('tracked-key', function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        }, 3600, 'Tracked key');
        $second = $service->rememberWithTracking('tracked-key', function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        }, 3600, 'Tracked key');

        $this->assertSame('value-1', $first);
        $this->assertSame('value-1', $second);
        $this->assertSame(1, $calls);
    }

    public function test_remember_random_with_tracking_reuses_cached_value(): void
    {
        $service = new CacheService();
        $calls = 0;

        $first = $service->rememberRandomWithTracking('random-tracked-key', function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        }, 3600, 3600, 'Random tracked key');
        $second = $service->rememberRandomWithTracking('random-tracked-key', function () use (&$calls): string {
            $calls++;

            return 'value-' . $calls;
        }, 3600, 3600, 'Random tracked key');

        $this->assertSame('value-1', $first);
        $this->assertSame('value-1', $second);
        $this->assertSame(1, $calls);
    }

    public function test_forget_by_tag_bridges_post_tags_to_versioned_content_scopes(): void
    {
        $service = new CacheService();
        $scope = ContentPostCacheInvalidationService::latestScope('post', 1);
        $baseKey = 'cache:posts:latest:post:lang:1';

        $oldKey = (new ContentCacheVersionService())->versionedKey($baseKey, [$scope]);

        $service->forgetByTag(Constant::LATEST_WITH_POST);

        $newKey = (new ContentCacheVersionService())->versionedKey($baseKey, [$scope]);

        $this->assertNotSame($oldKey, $newKey);
    }

    public function test_forget_by_tag_bridges_category_tags_to_versioned_category_scopes(): void
    {
        $service = new CacheService();
        $scope = CategoryCacheInvalidationService::categoryTreeScope();
        $baseKey = 'cache:categories:tree';

        $oldKey = (new CacheVersionService())->versionedKey($baseKey, [$scope]);

        $service->forgetByTag(Constant::CACHE_BY_CATEGORIES);

        $newKey = (new CacheVersionService())->versionedKey($baseKey, [$scope]);

        $this->assertNotSame($oldKey, $newKey);
    }

    public function test_unknown_legacy_tag_does_not_remove_unrelated_cache_key(): void
    {
        $service = new CacheService();

        $service->put('tagged-key', 'still-here', 3600);
        $service->forgetByTag('unknown-tag');

        $this->assertSame('still-here', $service->get('tagged-key'));
    }

    public function test_banner_key_changes_when_banner_scope_is_bumped(): void
    {
        $before = CacheKeyHelper::bannerType('slider_main');

        app(CacheVersionService::class)->bump(Constant::CACHE_BY_BANNERS);

        $this->assertNotSame($before, CacheKeyHelper::bannerType('slider_main'));
    }

    public function test_clean_banner_update_still_bumps_banner_scope_for_image_sync(): void
    {
        $versions = new CacheVersionService();
        $before = $versions->version(Constant::CACHE_BY_BANNERS);
        $banner = new Banner();
        $banner->id = 1;
        $banner->syncOriginal();
        Event::fake();
        DB::beginTransaction();

        (new BannerObserver())->updating($banner);

        $this->assertSame($before, (new CacheVersionService())->version(Constant::CACHE_BY_BANNERS));
        DB::commit();

        $this->assertGreaterThan($before, (new CacheVersionService())->version(Constant::CACHE_BY_BANNERS));
    }
}
