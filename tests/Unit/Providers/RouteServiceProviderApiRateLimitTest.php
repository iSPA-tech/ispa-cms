<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

function apiRateLimitFor(string $method, string $path, string $ip, ?string $internalHeader = null): Limit
{
    $request = Request::create($path, $method, [], [], [], ['REMOTE_ADDR' => $ip]);

    if ($internalHeader !== null) {
        $request->headers->set('X-Internal-Read', $internalHeader);
    }

    return RateLimiter::limiter('api')($request);
}

test('internal loopback reads are unlimited', function (string $path): void {
    expect(apiRateLimitFor('GET', $path, '127.0.0.1', '1'))
        ->toBeInstanceOf(Unlimited::class);
})->with([
    'vehicle root' => '/api/v3/vehicles',
    'nested vehicle' => '/api/v3/vehicles/hudson/hornet/1956',
    'post listing' => '/api/v3/post/all',
    'post detail' => '/api/v3/post/slug/some-article',
    'category tree' => '/api/v3/category/featured',
    'tag listing' => '/api/v3/tag',
    'static settings' => '/api/v3/static/settings',
    'v4 endpoint' => '/api/v4/public/post/count',
]);

test('the internal glossary binding resolver is an unlimited read-only post', function (): void {
    expect(apiRateLimitFor('POST', '/api/v2/wiki/resolve', '127.0.0.1', '1'))
        ->toBeInstanceOf(Unlimited::class);
});

test('a trusted proxy cannot forward a loopback client into the internal bypass', function (): void {
    $trustedProxies = Request::getTrustedProxies();
    $trustedHeaderSet = Request::getTrustedHeaderSet();

    try {
        Request::setTrustedProxies(['203.0.113.10'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/api/v3/vehicles', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        ]);
        $request->headers->set('X-Internal-Read', '1');
        $limit = RateLimiter::limiter('api')($request);

        expect($request->ip())->toBe('127.0.0.1')
            ->and($limit)
            ->toBeInstanceOf(Limit::class)
            ->not->toBeInstanceOf(Unlimited::class)
            ->and($limit->maxAttempts)->toBe(60);
    } finally {
        Request::setTrustedProxies($trustedProxies, $trustedHeaderSet);
    }
});

test('all other API requests keep the sixty per minute limit', function (string $method, string $path, string $ip, ?string $internalHeader): void {
    $limit = apiRateLimitFor($method, $path, $ip, $internalHeader);

    expect($limit)
        ->toBeInstanceOf(Limit::class)
        ->not->toBeInstanceOf(Unlimited::class)
        ->and($limit->maxAttempts)->toBe(60)
        ->and($limit->decaySeconds)->toBe(60);
})->with([
    'external client with spoofed header' => ['GET', '/api/v3/vehicles', '203.0.113.10', '1'],
    'external article read with spoofed header' => ['GET', '/api/v3/post/all', '203.0.113.10', '1'],
    'loopback client without header' => ['GET', '/api/v3/vehicles', '127.0.0.1', null],
    'loopback article read without header' => ['GET', '/api/v3/post/all', '127.0.0.1', null],
    'loopback client with wrong header' => ['GET', '/api/v3/vehicles', '127.0.0.1', 'true'],
    'internal vehicle write' => ['POST', '/api/v3/vehicles', '127.0.0.1', '1'],
    'internal article write' => ['POST', '/api/v3/contact', '127.0.0.1', '1'],
    'other internal wiki write' => ['POST', '/api/v2/wiki/terms', '127.0.0.1', '1'],
]);
