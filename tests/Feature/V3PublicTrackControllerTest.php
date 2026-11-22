<?php

namespace Tests\Feature;

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Requests\V3\SaveTrackingRequest;
use Tests\TestCase;
use Tests\Traits\WithApiVerifyHeader;

uses(TestCase::class, WithApiVerifyHeader::class);

beforeEach(function () {
    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
    ]);
});

function postTrack(array $payload): \Illuminate\Testing\TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test->withHeaders($test->withApiVerify())
        ->postJson('/api/v3/track', $payload);
}

test('the accepted target vocabulary is the configured alias list', function (): void {
    //  Callers send aliases, never model class names, so nothing outside
    //  config('tracking.target_aliases') can reach the tracking pipeline.
    expect(SaveTrackingRequest::allowedTargets())
        ->toContain('post')
        ->toContain('category')
        ->toContain('place')
        ->and(SaveTrackingRequest::allowedTargets())
        ->not->toContain('Cms\\Content\\Models\\Post');
});

test('an unknown target alias is rejected', function (): void {
    postTrack(['target' => 'users', 'id' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target');
});

test('a model class name is not accepted as a target', function (): void {
    postTrack(['target' => 'Cms\\Content\\Models\\Post', 'id' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target');
});

test('target and id are both required', function (): void {
    postTrack([])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['target', 'id']);
});

test('a non positive id is rejected', function (): void {
    postTrack(['target' => 'post', 'id' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('id');
});

test('a malformed visitor key is rejected', function (string $visitor): void {
    postTrack(['target' => 'post', 'id' => 1, 'visitor' => $visitor])
        ->assertStatus(422)
        ->assertJsonValidationErrors('visitor');
})->with([
    'too short' => 'abc',
    'illegal characters' => 'visitor key with spaces',
    'too long' => str_repeat('a', 129),
]);

test('uuid addressing is refused for ordinary aliases', function (): void {
    postTrack(['target' => 'post', 'uuid' => 'af16e54e-998b-4701-814c-76f1e69ab077'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('uuid');
});

test('a page beacon with an allowlisted path is accepted', function (): void {
    config()->set('tracking.frontend_base_url', 'https://acme.test');
    config()->set('tracking.public_hosts', ['acme.test']);
    config()->set('tracking.public_path_patterns', ['#^/xe(?:/[a-z0-9\-]+){0,4}$#']);

    postTrack(['target' => 'page', 'path' => '/xe/toyota', 'visitor' => 'reader-0000001'])
        ->assertOk()
        ->assertJsonPath('data.accepted', true);
});

test('a page beacon outside the allowlist is rejected before the pipeline', function (): void {
    config()->set('tracking.frontend_base_url', 'https://acme.test');
    config()->set('tracking.public_hosts', ['acme.test']);
    config()->set('tracking.public_path_patterns', ['#^/xe$#']);

    postTrack(['target' => 'page', 'path' => '/wp-admin/install', 'visitor' => 'reader-0000001'])
        ->assertStatus(404);
});

test('a page beacon needs a path and no id', function (): void {
    postTrack(['target' => 'page'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('path');

    postTrack(['target' => 'page', 'path' => 'xe/khong-co-slash-dau'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('path');
});

test('an entity beacon cannot smuggle a path', function (): void {
    postTrack(['target' => 'post', 'id' => 1, 'path' => '/xe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('path');
});

test('the ingest route is registered for POST only', function (): void {
    //  It has to stay a write: the loopback rate limit bypass in
    //  RouteServiceProvider exempts GET requests only.
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->getName() === 'v3.track.store');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->methods())->not->toContain('GET')
        ->and($route->gatherMiddleware())->toContain('throttle:tracking-ingest');
});
