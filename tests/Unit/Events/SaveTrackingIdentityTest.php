<?php

use App\Events\SaveTracking;
use Tests\TestCase;

uses(TestCase::class);

test('a stateless request contributes no session identity', function (): void {
    //  The api middleware group has no StartSession, yet session()->getId()
    //  still hands back a freshly generated id on every request. Letting that
    //  through would make the throttle and dedupe keys unique per event.
    expect(session()->isStarted())->toBeFalse();

    $first = new SaveTracking(['page_view'], 'Cms\\Content\\Models\\Post', 7);
    $second = new SaveTracking(['page_view'], 'Cms\\Content\\Models\\Post', 7);

    expect($first->sessionId)->toBeNull()
        ->and($second->sessionId)->toBeNull()
        ->and(session()->getId())->not->toBeEmpty();
});

test('an explicit visitor key becomes the session identity', function (): void {
    $event = new SaveTracking(
        ['page_view'],
        'Cms\\Content\\Models\\Post',
        7,
        'visitor-alpha-0001'
    );

    expect($event->sessionId)->toBe('visitor-alpha-0001');
});

test('the same visitor key yields a stable identity across events', function (): void {
    $first = new SaveTracking(['page_view'], 'Cms\\Content\\Models\\Post', 7, 'visitor-alpha-0001');
    $second = new SaveTracking(['page_view'], 'Cms\\Content\\Models\\Post', 7, 'visitor-alpha-0001');

    expect($first->sessionId)->toBe($second->sessionId)
        ->and($first->eventId)->not->toBe($second->eventId);
});

test('a started session still supplies its own id', function (): void {
    session()->start();

    $event = new SaveTracking(['page_view'], 'Cms\\Content\\Models\\Post', 7);

    expect($event->sessionId)->toBe(session()->getId())
        ->and($event->sessionId)->not->toBeNull();
});

test('a public url override replaces the relay request context', function (): void {
    $event = new SaveTracking(
        ['page_view', 'page'],
        null,
        0,
        'visitor-alpha-0001',
        'https://acme.test/xe/toyota'
    );

    //  The relay's own url and route describe the ingest endpoint, not the page
    //  the reader saw; classifying by them would collapse every page into one
    //  route target.
    expect($event->url)->toBe('https://acme.test/xe/toyota')
        ->and($event->host)->toBe('acme.test')
        ->and($event->path)->toBe('/xe/toyota')
        ->and($event->routeName)->toBeNull()
        ->and($event->routeParameters)->toBe([]);
});

test('an explicit visitor key wins over a started session', function (): void {
    session()->start();

    $event = new SaveTracking(
        ['page_view'],
        'Cms\\Content\\Models\\Post',
        7,
        'visitor-beta-00002'
    );

    expect($event->sessionId)->toBe('visitor-beta-00002');
});
