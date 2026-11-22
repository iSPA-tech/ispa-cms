<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Events\PublicMenuInvalidated;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Services\Cache\CorePublicMenuCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

test('invalidateGroup forgets the Blade footer menu cache read by getMenuByGroupKey', function () {
    Event::fake([PublicMenuInvalidated::class]);

    $footerKey = CacheKeyHelper::menuByGroupKey('footer_menu', 3);
    $otherLanguageKey = CacheKeyHelper::menuByGroupKey('footer_menu', 4);

    Cache::put($footerKey, 'stale footer', 86400);
    Cache::put($otherLanguageKey, 'other language footer', 86400);

    (new CorePublicMenuCacheService())->invalidateGroup('footer_menu', 3);

    expect(Cache::has($footerKey))->toBeFalse()
        ->and(Cache::get($otherLanguageKey))->toBe('other language footer');

    Event::assertDispatched(PublicMenuInvalidated::class);
});

test('menuByGroupKey keeps the language id in both key segments', function () {
    expect(CacheKeyHelper::menuByGroupKey('footer_menu', 3))
        ->toEndWith(':group_key_footer_menu:lang_id_3:lang:3');
});
