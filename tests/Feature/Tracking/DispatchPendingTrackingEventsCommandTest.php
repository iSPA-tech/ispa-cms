<?php

use App\Constants\Tables;
use App\Jobs\ProcessTrackingEvent;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Queue::fake();
    Cache::flush();
    CarbonImmutable::setTestNow('2026-07-23 10:00:00 UTC');
    config([
        'tracking.pending_dispatch.batch' => 10,
        'tracking.pending_dispatch.backoff_seconds' => 60,
        'tracking.pending_dispatch.max_runtime_seconds' => 10,
        'tracking.pending_dispatch.lock' => 'tracking:test-pending-dispatch',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TrackingTestSchema::drop();
});

function pendingCommandReceipt(array $overrides = []): TrackingEventReceipt
{
    return TrackingEventReceipt::query()->create(array_replace([
        'event_id' => (string) \Illuminate\Support\Str::ulid(),
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => TrackingRuntimeState::MODE_LEGACY,
        'dispatched_at' => CarbonImmutable::now('UTC')->subMinute(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinute(),
        'replay_until' => CarbonImmutable::now('UTC')->addDay(),
        'event_date' => '2026-07-23',
        'counter_deltas' => [
            'legacy_views' => 1,
            'page_views' => 1,
            'scans' => 0,
            'place_scans' => 0,
            'item_scans' => 0,
            'downloads' => 0,
            'site_sessions' => 1,
            'site_visitors' => 1,
            'target_sessions' => 0,
            'target_visitors' => 0,
        ],
        'throttle_contract_version' => 1,
        'throttle_decided_at' => CarbonImmutable::now('UTC')->subMinute(),
        'status' => TrackingEventReceipt::STATUS_PENDING,
        'expires_at' => CarbonImmutable::now('UTC')->addDays(2),
    ], $overrides));
}

test('pending poller recovers a committed receipt missed by redis dispatch', function (): void {
    $receipt = pendingCommandReceipt();

    $exit = Artisan::call('cms:tracking-dispatch-pending', [
        '--batch' => 10,
        '--max-runtime' => 10,
    ]);

    expect($exit)->toBe(0)
        ->and($receipt->refresh()->dispatch_attempts)->toBe(1)
        ->and($receipt->last_enqueued_at)->not->toBeNull();
    Queue::assertPushed(
        ProcessTrackingEvent::class,
        fn (ProcessTrackingEvent $job): bool => $job->eventId === $receipt->event_id
    );
});

test('pending poller skips valid held rows but quarantines expired held rows', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_HELD,
    ]);
    TrackingGenerationRun::query()->create([
        'rotation_id' => (string) Str::uuid(),
        'active_rotation_key' => 1,
        'status' => 'held',
        'closed_generation' => 0,
        'closed_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'next_held_generation' => 1,
        'barrier_started_at' => CarbonImmutable::now('UTC'),
        'manifest_state' => 'pending',
    ]);
    $valid = pendingCommandReceipt([
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
    ]);
    $expired = pendingCommandReceipt([
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'replay_until' => CarbonImmutable::now('UTC')->subSecond(),
    ]);

    $exit = Artisan::call('cms:tracking-dispatch-pending');

    expect($exit)->toBe(0)
        ->and($valid->refresh()->status)->toBe(TrackingEventReceipt::STATUS_PENDING)
        ->and($valid->dispatch_attempts)->toBe(0)
        ->and($expired->refresh()->status)->toBe(TrackingEventReceipt::STATUS_QUARANTINED)
        ->and($expired->quarantine_reason)->toBe('replay_expired');
    Queue::assertNothingPushed();
});

test('held poller reads only the closed generation without per receipt state queries', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'write_generation' => 2,
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_HELD,
    ]);
    TrackingGenerationRun::query()->create([
        'rotation_id' => (string) Str::uuid(),
        'active_rotation_key' => 1,
        'status' => 'held',
        'closed_generation' => 1,
        'closed_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'next_held_generation' => 2,
        'barrier_started_at' => CarbonImmutable::now('UTC'),
        'manifest_state' => 'pending',
    ]);

    foreach (range(1, 20) as $index) {
        pendingCommandReceipt([
            'event_id' => (string) Str::ulid(),
            'write_generation' => 2,
            'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        ]);
    }
    $closed = pendingCommandReceipt([
        'event_id' => (string) Str::ulid(),
        'write_generation' => 1,
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
    ]);

    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $exit = Artisan::call('cms:tracking-dispatch-pending', ['--batch' => 5]);
    } finally {
        $connection->disableQueryLog();
    }

    $stateReads = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(static fn (string $query): bool => str_starts_with(strtolower(ltrim($query)), 'select')
            && str_contains($query, 'tracking_runtime_state'))
        ->count();

    expect($exit)->toBe(0)
        ->and($closed->refresh()->dispatch_attempts)->toBe(1)
        ->and($stateReads)->toBeLessThanOrEqual(2)
        ->and(TrackingEventReceipt::query()
            ->where('write_generation', 2)
            ->where('dispatch_attempts', '>', 0)
            ->count())->toBe(0);
    Queue::assertPushed(ProcessTrackingEvent::class, 1);
});

test('pending poller respects cursor and enqueue backoff', function (): void {
    $first = pendingCommandReceipt();
    $backoff = pendingCommandReceipt([
        'last_enqueued_at' => CarbonImmutable::now('UTC'),
    ]);
    $third = pendingCommandReceipt();

    $exit = Artisan::call('cms:tracking-dispatch-pending', [
        '--after-id' => $first->id,
    ]);

    expect($exit)->toBe(0)
        ->and($first->refresh()->dispatch_attempts)->toBe(0)
        ->and($backoff->refresh()->dispatch_attempts)->toBe(0)
        ->and($third->refresh()->dispatch_attempts)->toBe(1);
    Queue::assertPushed(ProcessTrackingEvent::class, 1);
});

test('pending poller skips a receipt already claimed by direct dispatch', function (): void {
    $receipt = pendingCommandReceipt();

    expect(TrackingEventReceipt::claimPendingDispatch(
        $receipt->event_id,
        CarbonImmutable::now('UTC'),
        60
    ))->toBeTrue();

    expect(Artisan::call('cms:tracking-dispatch-pending'))->toBe(0)
        ->and($receipt->refresh()->dispatch_attempts)->toBe(1);
    Queue::assertNothingPushed();
});

test('pending claim resolves event id before updating by primary key', function (): void {
    $receipt = pendingCommandReceipt();
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        expect(TrackingEventReceipt::claimPendingDispatch(
            $receipt->event_id,
            CarbonImmutable::now('UTC'),
            60
        ))->toBeTrue();
    } finally {
        $connection->disableQueryLog();
    }

    $queries = collect($connection->getQueryLog())
        ->pluck('query')
        ->map(static fn (string $query): string => str_replace(['`', '"'], '', $query));

    expect($queries->contains(
        static fn (string $query): bool => str_starts_with($query, 'select')
            && str_contains($query, 'where event_id = ?')
    ))->toBeTrue()
        ->and($queries->contains(
            static fn (string $query): bool => str_starts_with($query, 'update tracking_event_receipts')
                && str_contains($query, 'where tracking_event_receipts.id = ?')
        ))->toBeTrue();
});

test('pending claim retries deadlocks before applying once', function (): void {
    $receipt = pendingCommandReceipt();
    $attempts = 0;
    $active = true;

    DB::connection()->beforeExecuting(function (string $query) use (&$attempts, &$active): void {
        if (
            !$active
            || !str_starts_with(strtolower(ltrim($query)), 'update')
            || !str_contains($query, 'tracking_event_receipts')
        ) {
            return;
        }

        $attempts++;
        if ($attempts < 3) {
            $previous = new PDOException('Deadlock found when trying to get lock', 40001);
            $previous->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

            throw new QueryException('sqlite', $query, [], $previous);
        }
    });

    $thrown = null;
    try {
        $claimed = TrackingEventReceipt::claimPendingDispatch(
            $receipt->event_id,
            CarbonImmutable::now('UTC'),
            60
        );
    } catch (Throwable $exception) {
        $claimed = false;
        $thrown = $exception::class;
    } finally {
        $active = false;
    }

    expect($thrown)->toBeNull()
        ->and($claimed)->toBeTrue()
        ->and($attempts)->toBe(3)
        ->and($receipt->refresh()->dispatch_attempts)->toBe(1);
});

test('pending poller returns failure when its one-server mutex is busy', function (): void {
    $lock = Cache::lock('tracking:test-pending-dispatch', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(Artisan::call('cms:tracking-dispatch-pending'))->toBe(1);
    } finally {
        $lock->release();
    }
});

test('pending re-enqueue backoff doubles per attempt up to the cap', function (): void {
    config(['tracking.pending_dispatch.max_backoff_seconds' => 3600]);

    expect(array_map(
        static fn (int $attempts): int => TrackingEventReceipt::pendingDispatchBackoffSeconds($attempts, 60),
        [0, 1, 2, 3, 6, 7, 30],
    ))->toBe([60, 60, 120, 240, 1920, 3600, 3600])
        ->and(TrackingEventReceipt::pendingDispatchBackoffSeconds(5, 0))->toBe(0);
});

test('pending poller does not pile up duplicates for a receipt nobody consumes', function (): void {
    // Third enqueue: the next one waits 240 s instead of 60 s (a dev worker that skipped the tracking queue
    // turned 518 receipts into 4.7M duplicate jobs with the fixed 60 s backoff).
    $recent = pendingCommandReceipt([
        'dispatch_attempts' => 3,
        'last_enqueued_at' => CarbonImmutable::now('UTC')->subSeconds(200),
    ]);
    $due = pendingCommandReceipt([
        'dispatch_attempts' => 3,
        'last_enqueued_at' => CarbonImmutable::now('UTC')->subSeconds(300),
    ]);
    $capped = pendingCommandReceipt([
        'dispatch_attempts' => 40,
        'last_enqueued_at' => CarbonImmutable::now('UTC')->subSeconds(3601),
    ]);

    expect(Artisan::call('cms:tracking-dispatch-pending'))->toBe(0)
        ->and($recent->refresh()->dispatch_attempts)->toBe(3)
        ->and($due->refresh()->dispatch_attempts)->toBe(4)
        ->and($capped->refresh()->dispatch_attempts)->toBe(41);
    Queue::assertPushed(ProcessTrackingEvent::class, 2);
});
