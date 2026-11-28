<?php

use App\Http\Middleware\Authenticate;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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

//  2026-11-28: every signed-in admin call counted against the shared 60/min, so a few
//  admin clicks (about ten shell calls per screen) returned "Too Many Attempts.".
function apiRateLimitForUser(string $path, ?User $user): Limit
{
    $request = Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
    $request->setUserResolver(static fn () => $user);

    return RateLimiter::limiter('api')($request);
}

test('signed-in admin requests get a per-account admin budget under their own key', function (): void {
    $limit = apiRateLimitForUser('/api/v2/admin/workflows', (new User())->forceFill(['id' => 42]));

    expect($limit->maxAttempts)->toBe(300)
        ->and($limit->key)->toBe('admin:42');
});

test('unauthenticated admin and signed-in non-admin requests keep the shared limit', function (): void {
    $anonymousAdmin = apiRateLimitForUser('/api/v2/admin/auth/login', null);
    $signedInPublic = apiRateLimitForUser('/api/v2/public/settings', (new User())->forceFill(['id' => 42]));

    expect($anonymousAdmin->maxAttempts)->toBe(60)
        ->and($anonymousAdmin->key)->toBe('203.0.113.9')
        ->and($signedInPublic->maxAttempts)->toBe(60)
        ->and($signedInPublic->key)->toBe(42);
});

test('admin routes authenticate before the api limiter so it sees the account', function (): void {
    $route = Route::getRoutes()->match(Request::create('/api/v2/admin/workflows', 'GET'));
    //  Declared group + route middleware only: gathering controller middleware builds
    //  controllers, and some constructors query the database.
    $resolved = array_values(app('router')->resolveMiddleware($route->middleware()));

    $authenticate = array_search(Authenticate::class . ':sanctum', $resolved, true);
    $apiLimiter = array_search(ThrottleRequests::class . ':api', $resolved, true);

    expect($authenticate)->toBeInt()
        ->and($apiLimiter)->toBeInt()
        ->and($authenticate)->toBeLessThan($apiLimiter);
});
