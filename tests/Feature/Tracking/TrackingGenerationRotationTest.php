<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingAggregateWriter;
use App\Services\Tracking\TrackingBufferedAggregateWriter;
use App\Services\Tracking\TrackingBufferedCounterStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Storage::fake('local');
    config()->set('tracking.generation_rotation_enabled', true);
    config()->set('tracking.generation_drain_verification_seconds', 0);
    config()->set('tracking.read_mode', 'aggregate');
    DB::table(Tables::TRACKING_RUNTIME_STATE)->where('id', 1)->update([
        'mode' => TrackingRuntimeState::MODE_DUAL,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
        'direct_counter_owner' => 'event',
        'write_generation' => 1,
    ]);
});

afterEach(function (): void {
    TrackingTestSchema::drop();
});

test('rotation holds the next generation signs proof and requires exact release proof', function (): void {
    $exit = Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]);
    expect($exit)->toBe(0);

    $run = TrackingGenerationRun::query()->firstOrFail();
    $state = TrackingRuntimeState::query()->findOrFail(1);

    expect($run->status)->toBe('completed')
        ->and($run->manifest_state)->toBe('signed')
        ->and($run->manifest_checksum)->toHaveLength(64)
        ->and($state->write_generation)->toBe(2)
        ->and($state->mode)->toBe(TrackingRuntimeState::MODE_AGGREGATE)
        ->and($state->processing_state)->toBe(TrackingRuntimeState::PROCESSING_HELD);

    Storage::disk('local')->assertExists(
        "tracking/manifests/{$run->rotation_id}.json"
    );

    expect(Artisan::call('cms:tracking-reconcile', [
        '--release-rotation' => $run->rotation_id,
        '--manifest-checksum' => str_repeat('0', 64),
    ]))->toBe(1)
        ->and(TrackingRuntimeState::query()->findOrFail(1)->processing_state)
        ->toBe(TrackingRuntimeState::PROCESSING_HELD);

    expect(Artisan::call('cms:tracking-reconcile', [
        '--release-rotation' => $run->rotation_id,
        '--manifest-checksum' => $run->manifest_checksum,
    ]))->toBe(0)
        ->and(TrackingRuntimeState::query()->findOrFail(1)->processing_state)
        ->toBe(TrackingRuntimeState::PROCESSING_OPEN)
        ->and($run->fresh()->status)->toBe('released');
});

test('pending closed-generation receipt keeps hold and rerun resumes one rotation', function (): void {
    createRotationReceipt('event-pending', TrackingEventReceipt::STATUS_PENDING);

    $arguments = [
        '--rotate-generation' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 1,
    ];
    expect(Artisan::call('cms:tracking-reconcile', $arguments))->toBe(1)
        ->and(TrackingGenerationRun::query()->count())->toBe(1)
        ->and(TrackingGenerationRun::query()->value('status'))->toBe('held')
        ->and(TrackingRuntimeState::query()->findOrFail(1)->processing_state)
        ->toBe(TrackingRuntimeState::PROCESSING_HELD);

    expect(Artisan::call('cms:tracking-reconcile', $arguments))->toBe(1)
        ->and(TrackingGenerationRun::query()->count())->toBe(1);
});

test('rotation stays disabled by default and schedule is conditional', function (): void {
    config()->set('tracking.generation_rotation_enabled', false);
    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--max-runtime' => 1,
    ]))->toBe(1)
        ->and(TrackingGenerationRun::query()->count())->toBe(0);

    config()->set('tracking.generation_rotation_enabled', true);
    $schedule = app(\App\Console\Kernel::class)->resolveConsoleSchedule();
    $event = collect($schedule->events())->first(
        static fn ($event): bool => str_contains(
            $event->command,
            'rotate-generation'
        )
    );
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('next rotation verifies prior signed deltas after old receipts are pruned', function (): void {
    createRotationReceipt(
        'dual-applied',
        TrackingEventReceipt::STATUS_PENDING,
        TrackingRuntimeState::MODE_DUAL,
        1,
        1
    );
    app(TrackingAggregateWriter::class)->process('dual-applied');

    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--max-runtime' => 2,
    ]))->toBe(0);
    $first = TrackingGenerationRun::query()->firstOrFail();
    expect(Artisan::call('cms:tracking-reconcile', [
        '--release-rotation' => $first->rotation_id,
        '--manifest-checksum' => $first->manifest_checksum,
    ]))->toBe(0);

    DB::table(Tables::TRACKING_EVENT_RECEIPTS)
        ->where('event_id', 'dual-applied')
        ->update(['expires_at' => now('UTC')->subDay()]);
    config()->set('tracking.receipt_prune_enabled', true);
    expect(Artisan::call('cms:tracking-prune-receipts', [
        '--force' => true,
    ]))->toBe(0)
        ->and(TrackingEventReceipt::query()->count())->toBe(0);

    createRotationReceipt(
        'aggregate-applied',
        TrackingEventReceipt::STATUS_PENDING,
        TrackingRuntimeState::MODE_AGGREGATE,
        2,
        1
    );
    app(TrackingAggregateWriter::class)->process('aggregate-applied');

    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--max-runtime' => 2,
    ]))->toBe(0)
        ->and(TrackingGenerationRun::query()->count())->toBe(2)
        ->and(TrackingGenerationRun::query()->latest('id')->value('status'))->toBe('completed');
});

test('rotation auto-releases after signing when the flag is supplied', function (): void {
    $exit = Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--auto-release' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]);
    expect($exit)->toBe(0);

    $run = TrackingGenerationRun::query()->firstOrFail();
    $state = TrackingRuntimeState::query()->findOrFail(1);

    expect($run->status)->toBe('released')
        ->and($run->manifest_state)->toBe('signed')
        ->and($run->manifest_checksum)->toHaveLength(64)
        ->and($state->write_generation)->toBe(2)
        ->and($state->mode)->toBe(TrackingRuntimeState::MODE_AGGREGATE)
        ->and($state->processing_state)->toBe(TrackingRuntimeState::PROCESSING_OPEN)
        ->and($state->change_reason)->toBe("release:{$run->rotation_id}");
});

test('auto-release resumes and releases a previously signed held rotation', function (): void {
    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]))->toBe(0)
        ->and(TrackingGenerationRun::query()->value('status'))->toBe('completed')
        ->and(TrackingRuntimeState::query()->findOrFail(1)->processing_state)
        ->toBe(TrackingRuntimeState::PROCESSING_HELD);

    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--auto-release' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]))->toBe(0)
        ->and(TrackingGenerationRun::query()->count())->toBe(2)
        ->and(TrackingGenerationRun::query()->pluck('status')->unique()->all())
        ->toBe(['released'])
        ->and(TrackingRuntimeState::query()->findOrFail(1)->write_generation)->toBe(3)
        ->and(TrackingRuntimeState::query()->findOrFail(1)->processing_state)
        ->toBe(TrackingRuntimeState::PROCESSING_OPEN);
});

test('auto-release stays held when aggregate read mode is not active', function (): void {
    config()->set('tracking.read_mode', 'raw');

    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--auto-release' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]))->toBe(1)
        ->and(TrackingGenerationRun::query()->value('status'))->toBe('completed')
        ->and(TrackingRuntimeState::query()->findOrFail(1)->processing_state)
        ->toBe(TrackingRuntimeState::PROCESSING_HELD);
});

test('rotation manifest includes applied Redis buffer batches from the closed generation', function (): void {
    \Tests\Support\RedisReachable::orSkip();
    (require database_path('migrations/2026_09_03_120000_add_redis_buffered_tracking_runtime.php'))->up();
    $prefix = 'tracking:test-rotation-buffer:' . strtolower((string) \Illuminate\Support\Str::ulid());
    config([
        'tracking.buffer.connection' => 'cache',
        'tracking.buffer.prefix' => $prefix,
        'tracking.buffer.retention_seconds' => 3600,
    ]);
    DB::table(Tables::TRACKING_RUNTIME_STATE)->where('id', 1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
        'write_generation' => 1,
    ]);
    $store = app(TrackingBufferedCounterStore::class);
    $store->activateRedis(2, 1);
    $store->record([
        'event_id' => 'buffered-rotation-event',
        'occurred_at' => CarbonImmutable::parse('2026-07-23 10:00:00 UTC'),
        'target_key' => 'post:42',
        'target_type' => 'post',
        'target_id' => 42,
        'target_kind' => 'entity',
        'display_label' => 'Post 42',
        'public_path' => '/articles/42',
        'is_rankable' => true,
        'deltas' => [
            'legacy_views' => 2,
            'page_views' => 2,
            'site_sessions' => 1,
            'site_visitors' => 1,
            'target_sessions' => 1,
            'target_visitors' => 1,
        ],
    ]);
    $bucket = $store->sealAll()[0];
    app(TrackingBufferedAggregateWriter::class)->apply($store->snapshot($bucket));
    $store->delete($bucket);
    $store->activateReceipt();

    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--auto-release' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]))->toBe(0)
        ->and(TrackingGenerationRun::query()->sole()->status)->toBe('released');

    \Illuminate\Support\Facades\Redis::connection('cache')->del($prefix . ':control');
    \Illuminate\Support\Facades\Redis::connection('cache')->del($prefix . ':buckets');
});

function createRotationReceipt(
    string $eventId,
    string $status,
    string $mode = TrackingRuntimeState::MODE_DUAL,
    int $generation = 1,
    int $views = 0
): void
{
    $now = CarbonImmutable::now('UTC');
    TrackingEventReceipt::query()->create([
        'event_id' => $eventId,
        'envelope_version' => 1,
        'write_generation' => $generation,
        'generation_mode' => $mode,
        'dispatched_at' => $now,
        'occurred_at' => $now,
        'replay_until' => $now->addDay(),
        'event_date' => $now->toDateString(),
        'counter_deltas' => [
            'legacy_views' => $views,
            'page_views' => $views,
            'scans' => 0,
            'place_scans' => 0,
            'item_scans' => 0,
            'downloads' => 0,
            'site_sessions' => 0,
            'site_visitors' => 0,
            'target_sessions' => 0,
            'target_visitors' => 0,
        ],
        'throttle_contract_version' => 1,
        'throttle_decided_at' => $now,
        'status' => $status,
        'expires_at' => $now->addDays(2),
        'legacy_session_id' => $mode === TrackingRuntimeState::MODE_DUAL
            ? "session-{$eventId}"
            : null,
        'legacy_tags' => $mode === TrackingRuntimeState::MODE_DUAL
            ? ['page_view']
            : null,
    ]);
}
