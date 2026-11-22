<?php

use App\Services\Tracking\TrackingClaimStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    Cache::flush();
});

test('old owner cannot release a claim reacquired by a newer event', function (): void {
    Carbon::setTestNow('2026-07-23 10:00:00');
    $claims = app(TrackingClaimStore::class);

    expect($claims->claim('tracking:test-claim', 'event-old', 1))->toBeTrue();

    Carbon::setTestNow('2026-07-23 10:00:02');
    expect($claims->claim('tracking:test-claim', 'event-new', 30))->toBeTrue();

    $claims->release('tracking:test-claim', 'event-old');

    expect(Cache::get('tracking:test-claim'))->toBe('event-new');
});
