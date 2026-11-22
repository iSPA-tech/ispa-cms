<?php

use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    CarbonImmutable::setTestNow('2026-07-23 10:00:00 UTC');
    config(['tracking.receipt_prune_enabled' => true]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TrackingTestSchema::drop();
});

function prunableCommandReceipt(array $overrides = []): TrackingEventReceipt
{
    return TrackingEventReceipt::query()->create(array_replace([
        'event_id' => (string) Str::ulid(),
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => TrackingRuntimeState::MODE_LEGACY,
        'dispatched_at' => CarbonImmutable::now('UTC')->subDays(10),
        'occurred_at' => CarbonImmutable::now('UTC')->subDays(10),
        'replay_until' => CarbonImmutable::now('UTC')->subDays(3),
        'event_date' => '2026-07-13',
        'counter_deltas' => ['legacy_views' => 1],
        'throttle_contract_version' => 1,
        'throttle_decided_at' => CarbonImmutable::now('UTC')->subDays(10),
        'status' => TrackingEventReceipt::STATUS_APPLIED,
        'processed_at' => CarbonImmutable::now('UTC')->subDays(9),
        'expires_at' => CarbonImmutable::now('UTC')->subDay(),
    ], $overrides));
}

test('receipt prune dry run never mutates an open raw-writing generation', function (): void {
    prunableCommandReceipt();

    expect(Artisan::call('cms:tracking-prune-receipts', ['--dry-run' => true]))->toBe(0)
        ->and(TrackingEventReceipt::query()->count())->toBe(1);
});

test('receipt prune refuses mutation for an open raw-writing generation', function (): void {
    prunableCommandReceipt();

    expect(Artisan::call('cms:tracking-prune-receipts', ['--force' => true]))->toBe(1)
        ->and(TrackingEventReceipt::query()->count())->toBe(1);
});

test('receipt prune deletes only expired terminal rows from a signed closed generation', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'write_generation' => 2,
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
    ]);
    TrackingGenerationRun::query()->create([
        'rotation_id' => (string) Str::uuid(),
        'active_rotation_key' => null,
        'status' => 'completed',
        'closed_generation' => 1,
        'closed_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'next_held_generation' => 2,
        'barrier_started_at' => CarbonImmutable::now('UTC')->subDays(2),
        'manifest_state' => 'signed',
        'manifest_id' => 'manifest-generation-1',
        'manifest_checksum' => str_repeat('a', 64),
        'completed_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);
    prunableCommandReceipt([
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
    ]);
    prunableCommandReceipt([
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'event_id' => (string) Str::ulid(),
        'expires_at' => CarbonImmutable::now('UTC')->addDay(),
    ]);

    expect(Artisan::call('cms:tracking-prune-receipts', [
        '--force' => true,
        '--limit' => 10,
    ]))->toBe(0)
        ->and(TrackingEventReceipt::query()->count())->toBe(1)
        ->and(TrackingEventReceipt::query()->sole()->expires_at)
        ->toBeGreaterThan(CarbonImmutable::now('UTC'));
});

test('unresolved quarantine blocks receipt pruning', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'write_generation' => 2,
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
    ]);
    prunableCommandReceipt([
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'status' => TrackingEventReceipt::STATUS_QUARANTINED,
        'quarantine_reason' => 'replay_expired',
        'resolution_status' => null,
    ]);

    expect(Artisan::call('cms:tracking-prune-receipts', ['--force' => true]))->toBe(1)
        ->and(TrackingEventReceipt::query()->count())->toBe(1);
});

test('only compensated quarantine may be pruned', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'write_generation' => 2,
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
    ]);
    prunableCommandReceipt([
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'status' => TrackingEventReceipt::STATUS_QUARANTINED,
        'quarantine_reason' => 'manual_review',
        'resolution_status' => 'dismissed',
    ]);

    expect(Artisan::call('cms:tracking-prune-receipts', ['--force' => true]))->toBe(1)
        ->and(TrackingEventReceipt::query()->count())->toBe(1);
});
