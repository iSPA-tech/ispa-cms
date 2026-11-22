<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Carbon::setTestNow('2026-07-23 10:00:00 Asia/Ho_Chi_Minh');
    config()->set('tracking.read_mode', 'raw');
    config()->set('tracking.mode_transition_enabled', false);
});

afterEach(function (): void {
    Carbon::setTestNow();
    TrackingTestSchema::drop();
});

test('dual activation is disabled and dry until an operator supplies all proof', function (): void {
    expect(Artisan::call('cms:tracking-transition', [
        '--to' => 'dual',
        '--approve' => true,
    ]))->toBe(1)
        ->and(TrackingRuntimeState::query()->findOrFail(1)->mode)
        ->toBe(TrackingRuntimeState::MODE_LEGACY);
});

test('approved dual activation atomically records generation owner and local boundary', function (): void {
    config()->set('tracking.mode_transition_enabled', true);
    $arguments = [
        '--to' => 'dual',
        '--expected-generation' => 1,
        '--effective-date' => '2026-07-23',
        '--process-contract-version' => 1,
        '--backup-id' => 'backup-checkpoint-a',
        '--operator' => 'operator@example',
        '--reason' => 'approved dual pilot',
        '--approve' => true,
    ];

    expect(Artisan::call('cms:tracking-transition', $arguments))->toBe(0);
    $state = TrackingRuntimeState::query()->findOrFail(1);
    expect($state->mode)->toBe(TrackingRuntimeState::MODE_DUAL)
        ->and($state->write_generation)->toBe(2)
        ->and($state->direct_counter_owner)->toBe('event')
        ->and($state->first_direct_write_date->toDateString())->toBe('2026-07-23')
        ->and(config('tracking.read_mode'))->toBe('raw');

    expect(Artisan::call('cms:tracking-transition', $arguments))->toBe(1)
        ->and(TrackingRuntimeState::query()->findOrFail(1)->write_generation)->toBe(2);
});

test('pending legacy receipt blocks ownership handoff', function (): void {
    config()->set('tracking.mode_transition_enabled', true);
    DB::table(Tables::TRACKING_EVENT_RECEIPTS)->insert([
        'event_id' => 'pending-before-dual',
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => 'legacy',
        'dispatched_at' => now('UTC'),
        'occurred_at' => now('UTC'),
        'replay_until' => now('UTC')->addDay(),
        'event_date' => '2026-07-23',
        'counter_deltas' => '{}',
        'throttle_contract_version' => 1,
        'throttle_decided_at' => now('UTC'),
        'status' => 'pending',
        'expires_at' => now('UTC')->addDays(2),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(Artisan::call('cms:tracking-transition', [
        '--to' => 'dual',
        '--expected-generation' => 1,
        '--effective-date' => '2026-07-23',
        '--process-contract-version' => 1,
        '--backup-id' => 'backup-checkpoint-a',
        '--operator' => 'operator@example',
        '--reason' => 'approved dual pilot',
        '--approve' => true,
    ]))->toBe(1)
        ->and(TrackingRuntimeState::query()->findOrFail(1)->mode)
        ->toBe(TrackingRuntimeState::MODE_LEGACY);
});
