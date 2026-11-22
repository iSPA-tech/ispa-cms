<?php

use App\Constants\Tables;
use App\Events\SaveTracking;
use App\Jobs\ProcessTrackingEvent;
use App\Listeners\SaveTrackingHandle;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Cache::flush();
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-23 17:30:00 UTC');
    config([
        'app.key' => 'tracking-writer-test-key',
        'tracking.enabled' => true,
        'tracking.public_hosts' => ['example.com'],
        'tracking.public_path_patterns' => [],
    ]);
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TrackingTestSchema::drop();
});

function aggregateWriterEvent(
    string $url,
    ?string $type,
    int $id,
    string $sessionId
): SaveTracking {
    $request = Request::create($url, 'GET');
    $request->headers->set('User-Agent', 'Aggregate Writer Test');
    app()->instance('request', $request);
    Session::setId($sessionId);
    Session::start();

    return new SaveTracking(['page_view'], $type, $id);
}

test('unclassified events update site facts without creating target rows', function (): void {
    $event = aggregateWriterEvent(
        'https://example.com/private/account',
        null,
        0,
        str_repeat('a', 40)
    );
    app(SaveTrackingHandle::class)->handle($event);
    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    expect(TrackingEventReceipt::query()->sole()->target_key)->toBeNull()
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_DAILY)->value('views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->count())->toBe(0)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->count())->toBe(0)
        ->and(DB::table(Tables::TRACKS)->count())->toBe(0);
});

test('late event increments the canonical closed day and settlement marker', function (): void {
    DB::table(Tables::TRACKING_DAILY)->insert([
        'date' => '2026-07-24',
        'views' => 10,
        'visitors' => 0,
        'place_scan' => 0,
        'item_scan' => 0,
        'scans' => 0,
        'download' => 0,
        'sessions' => 0,
        'is_closed' => true,
        'aggregate_version' => 1,
        'late_event_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $event = aggregateWriterEvent(
        'https://example.com/articles/42',
        'Cms\\Content\\Models\\Post',
        42,
        str_repeat('b', 40)
    );
    app(SaveTrackingHandle::class)->handle($event);
    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    $daily = DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-24')->first();
    expect($daily->views)->toBe(11)
        ->and($daily->late_event_count)->toBe(1)
        ->and($daily->last_applied_at)->not->toBeNull();
});

test('unchanged safe catalog metadata does not churn updated timestamp', function (): void {
    $first = aggregateWriterEvent(
        'https://example.com/articles/42',
        'Cms\\Content\\Models\\Post',
        42,
        str_repeat('c', 40)
    );
    app(SaveTrackingHandle::class)->handle($first);
    app()->call([(new ProcessTrackingEvent($first->eventId)), 'handle']);
    $firstUpdatedAt = DB::table(Tables::TRACKING_TARGET_CATALOG)->value('updated_at');

    CarbonImmutable::setTestNow('2026-07-23 17:31:00 UTC');
    $second = aggregateWriterEvent(
        'https://example.com/articles/42?ref=second',
        'Cms\\Content\\Models\\Post',
        42,
        str_repeat('d', 40)
    );
    app(SaveTrackingHandle::class)->handle($second);
    app()->call([(new ProcessTrackingEvent($second->eventId)), 'handle']);

    expect(DB::table(Tables::TRACKING_TARGET_CATALOG)->value('updated_at'))
        ->toBe($firstUpdatedAt);
});

test('legacy raw rows keep the local wall clock the nightly finalizers window by', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_LEGACY,
        'direct_counter_owner' => 'legacy',
        'first_direct_write_date' => null,
    ]);

    // 17:30 UTC is already 00:30 on the next local day.
    $event = aggregateWriterEvent(
        'https://example.com/private/account',
        null,
        0,
        str_repeat('e', 40)
    );
    app(SaveTrackingHandle::class)->handle($event);
    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    $track = DB::table(Tables::TRACKS)->sole();
    expect(TrackingEventReceipt::query()->sole()->event_date->toDateString())->toBe('2026-07-24')
        ->and(substr((string) $track->created_at, 0, 19))->toBe('2026-07-24 00:30:00')
        ->and(substr((string) $track->updated_at, 0, 19))->toBe('2026-07-24 00:30:00');
});
