<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return list<Limit>
 */
function trackingIngestLimits(string $ip, ?string $visitor = null): array
{
    $payload = ['target' => 'post', 'id' => 7];

    if ($visitor !== null) {
        $payload['visitor'] = $visitor;
    }

    $request = Request::create(
        '/api/v3/track',
        'POST',
        $payload,
        [],
        [],
        ['REMOTE_ADDR' => $ip]
    );

    return (array) RateLimiter::limiter('tracking-ingest')($request);
}

test('the ingest limiter applies a per visitor tier and a per address tier', function (): void {
    $limits = trackingIngestLimits('203.0.113.10', 'visitor-alpha-0001');

    expect($limits)->toHaveCount(2)
        ->and($limits[0])->toBeInstanceOf(Limit::class)
        ->and($limits[1])->toBeInstanceOf(Limit::class)
        ->and($limits[0]->maxAttempts)->toBe(30)
        ->and($limits[1]->maxAttempts)->toBe(600);
});

test('two readers behind one address do not share the tight tier', function (): void {
    //  The case that makes a flat per-IP limit wrong: office NAT, a mobile
    //  carrier, or every visit arriving through the rendering frontend.
    $alpha = trackingIngestLimits('203.0.113.10', 'visitor-alpha-0001');
    $beta = trackingIngestLimits('203.0.113.10', 'visitor-beta-00002');

    expect($alpha[0]->key)->not->toBe($beta[0]->key)
        ->and($alpha[1]->key)->toBe($beta[1]->key);
});

test('one reader keeps the same tight tier key across requests', function (): void {
    $first = trackingIngestLimits('203.0.113.10', 'visitor-alpha-0001');
    $second = trackingIngestLimits('198.51.100.20', 'visitor-alpha-0001');

    expect($first[0]->key)->toBe($second[0]->key)
        ->and($first[1]->key)->not->toBe($second[1]->key);
});

test('the raw visitor key is never used as a cache key', function (): void {
    $limits = trackingIngestLimits('203.0.113.10', 'visitor-alpha-0001');

    expect($limits[0]->key)->not->toContain('visitor-alpha-0001');
});

test('a request without a visitor key falls back to the address', function (): void {
    $anonymous = trackingIngestLimits('203.0.113.10');
    $other = trackingIngestLimits('198.51.100.20');

    expect($anonymous[0]->key)->not->toBe($other[0]->key)
        ->and($anonymous[0]->key)->toContain('address:');
});

test('a single abusive address is still capped by the loose tier', function (): void {
    //  Rotating visitor keys escapes the tight tier, so the address tier has to
    //  keep pointing at the same bucket.
    $keys = collect(range(1, 5))
        ->map(fn (int $i): string => trackingIngestLimits('203.0.113.10', "rotating-key-{$i}")[1]->key)
        ->unique();

    expect($keys)->toHaveCount(1);
});

test('the ingest tiers are configurable', function (): void {
    config()->set('tracking.ingest.per_visitor_per_minute', 5);
    config()->set('tracking.ingest.per_address_per_minute', 50);

    $limits = trackingIngestLimits('203.0.113.10', 'visitor-alpha-0001');

    expect($limits[0]->maxAttempts)->toBe(5)
        ->and($limits[1]->maxAttempts)->toBe(50);
});
