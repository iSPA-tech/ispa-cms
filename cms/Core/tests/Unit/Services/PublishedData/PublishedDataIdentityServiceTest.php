<?php

use Cms\Core\Services\PublishedData\PublishedDataIdentityService;

test('published-data identity service prefers provider external identity', function () {
    $service = new PublishedDataIdentityService();

    $left = $service->candidateIdentityHash([
        'source' => 'google_places',
        'external_type' => 'place',
        'external_id' => 'places/abc',
        'source_url' => 'https://example.test/a',
    ]);
    $right = $service->candidateIdentityHash([
        'source' => 'google_places',
        'external_type' => 'place',
        'external_id' => 'places/abc',
        'source_url' => 'https://example.test/b',
    ]);

    expect($left)->toBe($right)->toHaveLength(64);
});

test('published-data identity service supports url and content fallback identities', function () {
    $service = new PublishedDataIdentityService();

    expect($service->candidateIdentityHash(['source_url' => ' https://Example.test/Post ']))
        ->toBe($service->candidateIdentityHash(['source_url' => 'https://example.test/post']))
        ->and($service->candidateIdentityHash(['content_hash' => str_repeat('a', 64)]))->toHaveLength(64)
        ->and($service->nullableUrlHash(null))->toBeNull()
        ->and($service->nullableUrlHash('https://example.test'))->toHaveLength(64);
});

test('published-data identity service requires a candidate identity signal', function () {
    $service = new PublishedDataIdentityService();

    expect(fn () => $service->candidateIdentityHash([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->clusterKey([]))->toThrow(InvalidArgumentException::class);
});
