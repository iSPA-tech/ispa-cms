<?php

uses(\Tests\TestCase::class);

use Cms\Content\Providers\ContentServiceProvider;

test('content service provider exposes module names', function () {
    $provider = new ContentServiceProvider(app());

    $getName = new ReflectionMethod(ContentServiceProvider::class, 'getModuleName');
    $getName->setAccessible(true);
    $getLower = new ReflectionMethod(ContentServiceProvider::class, 'getModuleNameLower');
    $getLower->setAccessible(true);

    expect($getName->invoke($provider))->toBe('Content');
    expect($getLower->invoke($provider))->toBe('content');
});
