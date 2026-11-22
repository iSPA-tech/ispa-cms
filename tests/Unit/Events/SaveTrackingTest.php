<?php

use App\Events\SaveTracking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

uses(\Tests\TestCase::class);

test('save tracking normalizes malformed UTF-8 request strings', function () {
    $invalidUserAgent = "bad-agent-" . chr(0x80) . "-ua";

    $request = Request::create('https://example.com/tracking', 'GET');
    $request->headers->set('User-Agent', $invalidUserAgent);

    app()->instance('request', $request);
    Session::start();

    $event = new SaveTracking([]);

    expect(json_encode([$event->agent, $event->url]))->not->toBeFalse();
    expect(str_contains($event->agent, chr(0x80)))->toBeFalse();
    expect($event->agent)->toContain('bad-agent-');
    expect($event->agent)->toBeString();
});

test('save tracking captures immutable identity target and utc occurrence context', function () {
    $request = Request::create('https://example.com/articles/hello?utm_source=test', 'GET');
    app()->instance('request', $request);
    Session::start();

    $event = new SaveTracking(['page_view'], 'Cms\\Content\\Models\\Post', 42);

    expect($event->eventId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($event->type)->toBe('Cms\\Content\\Models\\Post')
        ->and($event->id)->toBe(42)
        ->and($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($event->host)->toBe('example.com')
        ->and($event->path)->toBe('/articles/hello');
});

test('save tracking event id and occurrence time cannot be mutated', function () {
    $request = Request::create('https://example.com/articles/hello', 'GET');
    app()->instance('request', $request);
    Session::start();

    $event = new SaveTracking(['page_view']);
    $occurredAt = $event->occurredAt;

    expect(fn () => $event->eventId = 'replacement')->toThrow(Error::class)
        ->and($event->occurredAt->addDay()->toIso8601String())->not->toBe($occurredAt->toIso8601String())
        ->and($event->occurredAt->toIso8601String())->toBe($occurredAt->toIso8601String());
});

test('save tracking restores a pre-deploy queued envelope safely', function () {
    $event = (new ReflectionClass(SaveTracking::class))->newInstanceWithoutConstructor();

    $event->__unserialize([
        'tags' => ['page_view'],
        'type' => 'Cms\\Content\\Models\\Post',
        'id' => 42,
        'ip' => '127.0.0.1',
        'agent' => 'legacy-agent',
        'url' => 'https://example.com/articles/legacy?utm_source=test',
        'sessionId' => 'legacy-session',
        'occurredAt' => Carbon::parse('2026-07-22 18:30:00', 'UTC'),
    ]);

    expect($event->eventId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($event->occurredAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($event->routeName)->toBeNull()
        ->and($event->routeParameters)->toBe([])
        ->and($event->host)->toBe('example.com')
        ->and($event->path)->toBe('/articles/legacy');
});
