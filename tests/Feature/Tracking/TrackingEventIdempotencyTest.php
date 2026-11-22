<?php

use App\Constants\Tables;
use App\Events\SaveTracking;
use App\Jobs\ProcessTrackingEvent;
use App\Listeners\SaveTrackingHandle;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingBufferedCounterStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();

    config([
        'app.key' => 'base64:' . base64_encode(str_repeat('t', 32)),
        'app.url' => 'https://example.com',
        'tracking.enabled' => true,
        'tracking.public_hosts' => ['example.com'],
        'tracking.public_path_patterns' => ['#^/articles(?:/|$)#'],
        'tracking.manual_replay_days' => 7,
        'tracking.buffer.enabled' => false,
    ]);

    Cache::flush();
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-23 17:30:00', 'UTC'));
});

function enableTrackingBufferForDispatcher(): TrackingBufferedCounterStore
{
    \Tests\Support\RedisReachable::orSkip();
    config([
        'tracking.buffer.enabled' => true,
        'tracking.buffer.connection' => 'cache',
        'tracking.buffer.prefix' => 'tracking:test-dispatcher:' . strtolower((string) \Illuminate\Support\Str::ulid()),
        'tracking.buffer.retention_seconds' => 3600,
    ]);
    $store = app(TrackingBufferedCounterStore::class);
    $store->activateRedis(1);

    return $store;
}

function clearTrackingBufferForDispatcher(TrackingBufferedCounterStore $store): void
{
    foreach ($store->registeredBuckets() as $bucket) {
        $store->delete($bucket);
    }
    $prefix = (string) config('tracking.buffer.prefix');
    \Illuminate\Support\Facades\Redis::connection('cache')->del($prefix . ':control');
    \Illuminate\Support\Facades\Redis::connection('cache')->del($prefix . ':buckets');
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TrackingTestSchema::drop();
});

function trackingWriterEvent(
    string $url = 'https://example.com/articles/42',
    array $tags = ['page_view'],
    ?string $type = 'Cms\\Content\\Models\\Post',
    int $id = 42,
    string $sessionId = 'ssssssssssssssssssssssssssssssssssssssss'
): SaveTracking {
    $request = Request::create($url, 'GET');
    $request->headers->set('User-Agent', 'Tracking Test Browser');
    app()->instance('request', $request);
    Session::setId($sessionId);
    Session::start();

    return new SaveTracking($tags, $type, $id);
}

test('save tracking listener performs admission synchronously and queues only event id', function (): void {
    $listener = app(SaveTrackingHandle::class);
    $event = trackingWriterEvent();

    expect($listener)->not->toBeInstanceOf(ShouldQueue::class);

    $listener->handle($event);

    $receipt = TrackingEventReceipt::query()->sole();
    expect($receipt->event_id)->toBe($event->eventId)
        ->and($receipt->status)->toBe(TrackingEventReceipt::STATUS_PENDING)
        ->and($receipt->write_generation)->toBe(1)
        ->and($receipt->generation_mode)->toBe(TrackingRuntimeState::MODE_LEGACY)
        ->and($receipt->target_key)->toBe('post:42')
        ->and($receipt->dispatch_attempts)->toBe(1)
        ->and($receipt->last_enqueued_at)->not->toBeNull()
        ->and($receipt->counter_deltas)->toMatchArray([
            'legacy_views' => 1,
            'page_views' => 1,
        ])
        ->and($receipt->legacy_session_id)->toBe($event->sessionId);

    Queue::assertPushed(ProcessTrackingEvent::class, function (ProcessTrackingEvent $job) use ($event): bool {
        $serialized = serialize($job);

        return $job->eventId === $event->eventId
            && $job->queue === 'tracking'
            && !str_contains($serialized, 'Tracking Test Browser')
            && !str_contains($serialized, 'https://example.com/articles/42')
            && !str_contains($serialized, 'tracking-test-session');
    });
});

test('stored legacy url drops the query string and fragment so search terms are never kept', function (): void {
    $listener = app(SaveTrackingHandle::class);
    $event = trackingWriterEvent('https://example.com/articles/42?keywords=private+search&page=2#top');

    $listener->handle($event);

    $receipt = TrackingEventReceipt::query()->sole();
    expect($receipt->legacy_url)->toBe('https://example.com/articles/42')
        ->and($receipt->target_key)->toBe('post:42');
});

test('aggregate-safe view uses Redis buffer when its gate is active', function (): void {
    $store = enableTrackingBufferForDispatcher();

    try {
        $receiptQueries = [];
        DB::listen(static function ($event) use (&$receiptQueries): void {
            if (str_contains($event->sql, 'tracking_event_receipts')) {
                $receiptQueries[] = $event->sql;
            }
        });
        $event = trackingWriterEvent();
        app(SaveTrackingHandle::class)->handle($event);
        $queriesDuringAdmission = $receiptQueries;

        expect(TrackingEventReceipt::query()->count())->toBe(0);
        Queue::assertNothingPushed();
        $snapshot = $store->snapshot($store->registeredBuckets()[0]);
        expect($queriesDuringAdmission)->toBe([])
            ->and($snapshot['site']['legacy_views'])->toBe(1)
            ->and($snapshot['targets']['post:42']['target_type'])->toBe('Cms\\Content\\Models\\Post');
    } finally {
        clearTrackingBufferForDispatcher($store);
    }
});

test('scan events and unavailable Redis keep the current receipt path', function (): void {
    $store = enableTrackingBufferForDispatcher();

    try {
        app(SaveTrackingHandle::class)->handle(trackingWriterEvent(
            'https://example.com/articles/42',
            ['page_view', 'scan', 'place']
        ));
        expect(TrackingEventReceipt::query()->count())->toBe(1);
        Queue::assertPushed(ProcessTrackingEvent::class, 1);
    } finally {
        clearTrackingBufferForDispatcher($store);
    }

    config([
        'tracking.buffer.enabled' => true,
        'tracking.buffer.connection' => 'missing-tracking-connection',
    ]);
    app(SaveTrackingHandle::class)->handle(trackingWriterEvent(
        'https://example.com/articles/another',
        ['page_view'],
        'Cms\\Content\\Models\\Post',
        43,
        'tttttttttttttttttttttttttttttttttttttttt'
    ));

    expect(TrackingEventReceipt::query()->count())->toBe(2);
    Queue::assertPushed(ProcessTrackingEvent::class, 2);
});

test('worker applies one legacy event exactly once across duplicate delivery', function (): void {
    $event = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($event);

    $job = new ProcessTrackingEvent($event->eventId);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(DB::table(Tables::TRACKS)->count())->toBe(1)
        ->and(DB::table(Tables::TRACKS)->value('view'))->toBe(1)
        ->and(TrackingEventReceipt::query()->sole()->status)->toBe(TrackingEventReceipt::STATUS_APPLIED);
});

test('dual worker applies raw daily lifetime and target facts atomically once', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_DUAL,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);

    $event = trackingWriterEvent(
        'https://example.com/articles/42',
        ['page_view', 'scan', 'place']
    );
    app(SaveTrackingHandle::class)->handle($event);

    $job = new ProcessTrackingEvent($event->eventId);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(DB::table(Tables::TRACKS)->value('view'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_scans'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-24')->value('views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-24')->value('scans'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->value('live_views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->value('live_scans'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->value('total_views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->value('total_scans'))->toBe(1)
        ->and(TrackingEventReceipt::query()->sole()->status)->toBe(TrackingEventReceipt::STATUS_APPLIED);
});

test('outer transaction rollback removes receipt job and throttle ownership', function (): void {
    $first = trackingWriterEvent();

    try {
        DB::transaction(function () use ($first): void {
            app(SaveTrackingHandle::class)->handle($first);
            throw new RuntimeException('rollback admission');
        });
    } catch (RuntimeException) {
        // Expected rollback.
    }

    expect(TrackingEventReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $second = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($second);

    expect(TrackingEventReceipt::query()->count())->toBe(1);
    Queue::assertPushed(ProcessTrackingEvent::class, 1);
});

test('outer transaction commit persists one receipt then dispatches one job', function (): void {
    $event = trackingWriterEvent();

    DB::transaction(function () use ($event): void {
        app(SaveTrackingHandle::class)->handle($event);
        expect(TrackingEventReceipt::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    expect(TrackingEventReceipt::query()->count())->toBe(1);
    Queue::assertPushed(
        ProcessTrackingEvent::class,
        fn (ProcessTrackingEvent $job): bool => $job->eventId === $event->eventId
    );
});

test('expired pending event quarantines without mutating counters', function (): void {
    $event = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($event);
    DB::table(Tables::TRACKING_EVENT_RECEIPTS)
        ->where('event_id', $event->eventId)
        ->update(['replay_until' => CarbonImmutable::now('UTC')->subSecond()]);

    expect(TrackingEventReceipt::query()->sole()->replay_until)
        ->toBeLessThan(CarbonImmutable::now('UTC'));

    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    expect(TrackingEventReceipt::query()->sole()->status)->toBe(TrackingEventReceipt::STATUS_QUARANTINED)
        ->and(TrackingEventReceipt::query()->sole()->quarantine_reason)->toBe('replay_expired')
        ->and(DB::table(Tables::TRACKS)->count())->toBe(0);
});

test('held generation remains pending until processing opens', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_HELD,
    ]);

    $event = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($event);
    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    expect(TrackingEventReceipt::query()->sole()->status)->toBe(TrackingEventReceipt::STATUS_PENDING)
        ->and(DB::table(Tables::TRACKING_DAILY)->count())->toBe(0);
});

test('different targetless public links have separate throttle scopes', function (): void {
    $first = trackingWriterEvent(
        'https://example.com/articles/first',
        ['page_view'],
        null,
        0
    );
    app(SaveTrackingHandle::class)->handle($first);

    $second = trackingWriterEvent(
        'https://example.com/articles/second',
        ['page_view'],
        null,
        0
    );
    app(SaveTrackingHandle::class)->handle($second);

    $duplicate = trackingWriterEvent(
        'https://example.com/articles/first',
        ['page_view'],
        null,
        0
    );
    app(SaveTrackingHandle::class)->handle($duplicate);

    expect(TrackingEventReceipt::query()->count())->toBe(2)
        ->and(TrackingEventReceipt::query()->pluck('target_key')->unique()->count())->toBe(2);
});

test('aggregate generation receipt and queue payload contain no raw request identity', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);

    $event = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($event);

    $receipt = TrackingEventReceipt::query()->sole();
    expect($receipt->legacy_ip_address)->toBeNull()
        ->and($receipt->legacy_agent)->toBeNull()
        ->and($receipt->legacy_url)->toBeNull()
        ->and($receipt->legacy_session_id)->toBeNull()
        ->and($receipt->legacy_tags)->toBeNull();

    Queue::assertPushed(ProcessTrackingEvent::class, function (ProcessTrackingEvent $job): bool {
        $serialized = serialize($job);

        return !str_contains($serialized, 'example.com')
            && !str_contains($serialized, 'Tracking Test Browser')
            && !str_contains($serialized, 'tracking-test-session');
    });
});

test('stale legacy raw row cache is re-resolved instead of dropping a view', function (): void {
    $event = trackingWriterEvent();
    $cacheKey = 'track:map:' . hash(
        'sha256',
        "{$event->sessionId}|{$event->type}|{$event->id}"
    );
    Cache::put($cacheKey, 999999, now()->addHour());

    app(SaveTrackingHandle::class)->handle($event);
    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    expect(DB::table(Tables::TRACKS)->count())->toBe(1)
        ->and(DB::table(Tables::TRACKS)->value('view'))->toBe(1);
});

test('dual transaction rollback keeps receipt pending and changes no aggregate', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_DUAL,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);
    $event = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($event);
    Schema::drop(Tables::TRACKS);

    expect(fn () => app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']))
        ->toThrow(QueryException::class);

    expect(TrackingEventReceipt::query()->sole()->status)->toBe(TrackingEventReceipt::STATUS_PENDING)
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(0)
        ->and(DB::table(Tables::TRACKING_DAILY)->count())->toBe(0)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->count())->toBe(0)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->count())->toBe(0);
});

test('missing ledger delivery emits an incident without manufacturing a row', function (): void {
    Log::spy();

    app()->call([(new ProcessTrackingEvent('01JTRACKINGMISSING0000000000')), 'handle']);

    expect(TrackingEventReceipt::query()->count())->toBe(0);
    Log::shouldHaveReceived('error')
        ->once()
        ->with('tracking.missing_ledger', ['event_id' => '01JTRACKINGMISSING0000000000']);
});

test('malformed and unsupported pending envelopes quarantine without counters', function (
    array $changes,
    string $reason
): void {
    $event = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($event);
    DB::table(Tables::TRACKING_EVENT_RECEIPTS)
        ->where('event_id', $event->eventId)
        ->update($changes);

    app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);

    expect(TrackingEventReceipt::query()->sole()->status)->toBe(TrackingEventReceipt::STATUS_QUARANTINED)
        ->and(TrackingEventReceipt::query()->sole()->quarantine_reason)->toBe($reason)
        ->and(DB::table(Tables::TRACKS)->count())->toBe(0);
})->with([
    'unsupported version' => [
        ['envelope_version' => 99],
        'unsupported_envelope_version',
    ],
    'unknown counter' => [
        ['counter_deltas' => json_encode(['unknown' => 1], JSON_THROW_ON_ERROR)],
        'malformed_counter_deltas',
    ],
]);

test('admission database failure is fail open observable and releases claims', function (): void {
    Log::spy();
    TrackingRuntimeState::query()->delete();

    $first = trackingWriterEvent();
    expect(fn () => app(SaveTrackingHandle::class)->handle($first))->not->toThrow(Throwable::class);
    expect(TrackingEventReceipt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message): bool => $message === 'tracking.admission_failed'
    );

    TrackingRuntimeState::query()->create([
        'id' => 1,
        'write_generation' => 1,
        'mode' => TrackingRuntimeState::MODE_LEGACY,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
        'direct_counter_owner' => 'legacy',
        'generation_opened_at' => now(),
    ]);

    $second = trackingWriterEvent();
    app(SaveTrackingHandle::class)->handle($second);

    expect(TrackingEventReceipt::query()->count())->toBe(1);
});

test('admission fails open when the receipt store itself is unavailable', function (): void {
    Log::spy();
    Schema::drop(Tables::TRACKING_EVENT_RECEIPTS);

    expect(fn () => app(SaveTrackingHandle::class)->handle(trackingWriterEvent()))
        ->not->toThrow(Throwable::class);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message): bool => $message === 'tracking.admission_failed'
    );
});

test('daily session and visitor dedupe remains site-wide and target-specific', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);

    $first = trackingWriterEvent(
        'https://example.com/articles/42',
        ['page_view'],
        'Cms\\Content\\Models\\Post',
        42
    );
    app(SaveTrackingHandle::class)->handle($first);
    app()->call([(new ProcessTrackingEvent($first->eventId)), 'handle']);

    $second = trackingWriterEvent(
        'https://example.com/articles/43',
        ['page_view'],
        'Cms\\Content\\Models\\Post',
        43
    );
    app(SaveTrackingHandle::class)->handle($second);
    app()->call([(new ProcessTrackingEvent($second->eventId)), 'handle']);

    expect(DB::table(Tables::TRACKING_DAILY)->value('sessions'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_DAILY)->value('visitors'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->sum('total_sessions'))->toBe(2)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->sum('total_visitors'))->toBe(2);
});
