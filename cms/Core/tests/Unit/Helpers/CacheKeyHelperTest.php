<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Helpers\CacheKeyHelper;

test('cache key helper builds custom keys', function () {
    $key = CacheKeyHelper::custom([
        'type' => 'banner',
        'lang' => 'en',
    ]);

    expect($key)->toBe('type:banner|lang:en');
});

test('cache key helper formats arrays and parses keys', function () {
    $key = CacheKeyHelper::format('cache:%s:%s', ['a', 'b'], 'c');
    expect($key)->toBe('cache:a,b:c');

    $parsed = CacheKeyHelper::parse('cache_posts:new_post:lang_en:pageSize_10');
    expect($parsed)->toMatchArray([
        'group' => 'cache_posts',
        'type' => 'new_post',
        'params' => ['lang_en', 'pageSize_10'],
    ]);
});

test('cache key helper extracts tags', function () {
    expect(CacheKeyHelper::tagFromKey('cache_posts:new_post:lang_en'))
        ->toBe(['cache_posts', 'new_post']);

    expect(CacheKeyHelper::tagFromKey('singlekey'))->toBe(['singlekey']);
});

test('cache key helper formats underscore patterns and parses basic keys', function () {
    $key = CacheKeyHelper::format('cache_%s:%s', 'posts', 1);
    expect($key)->toBe('cache_posts:1');

    $parsed = CacheKeyHelper::parse($key);
    expect($parsed)->toMatchArray([
        'group' => 'cache_posts',
        'type' => '1',
        'params' => [],
    ]);
});
