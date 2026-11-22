<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Services\CacheService;
use App\Contracts\LoggerServiceInterface;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Facades\CMSLogger;

test('cms cache facade resolves cache service', function () {
    CMSCache::clearResolvedInstance('CMSCache');
    app()->forgetInstance('CMSCache');

    expect(CMSCache::getFacadeRoot())->toBeInstanceOf(CacheService::class);
});

test('cms logger facade resolves configured logger', function () {
    CMSLogger::clearResolvedInstance('CMSLogger');
    app()->forgetInstance('CMSLogger');

    expect(CMSLogger::getFacadeRoot())->toBeInstanceOf(LoggerServiceInterface::class);
});
