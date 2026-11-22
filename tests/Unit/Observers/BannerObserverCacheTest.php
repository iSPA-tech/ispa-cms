<?php

namespace Tests\Unit\Observers;

use App\Models\Banner;
use Tests\TestCase;
use App\Constants\Constant;
use App\Observers\BannerObserver;
use Illuminate\Support\Facades\Cache;
use Cms\Core\Services\Cache\CacheVersionService;

class BannerObserverCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function bannerKey(): string
    {
        return (new CacheVersionService())->versionedKey('cache:banner:slider_secondary', Constant::CACHE_BY_BANNERS);
    }

    public function test_clean_save_of_existing_banner_invalidates_banner_cache(): void
    {
        // Image-only edits leave the Banner row unchanged (images live in the media pivot).
        $banner = new Banner();
        $banner->exists = true;

        $before = $this->bannerKey();
        (new BannerObserver())->saved($banner);

        $this->assertNotSame($before, $this->bannerKey());
    }

    public function test_changed_save_leaves_invalidation_to_updated_event(): void
    {
        $banner = new Banner();
        $banner->exists = true;
        $banner->forceFill(['name' => 'Top banner'])->syncChanges();

        $before = $this->bannerKey();
        (new BannerObserver())->saved($banner);

        $this->assertSame($before, $this->bannerKey());
    }
}
