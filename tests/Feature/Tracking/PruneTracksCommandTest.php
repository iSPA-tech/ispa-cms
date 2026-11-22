<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingPruneRun;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Storage::fake('local');
    Carbon::setTestNow('2026-07-23 12:00:00 UTC');
    config()->set('tracking.prune_enabled', false);
    config()->set('tracking.read_mode', 'aggregate');
    config()->set('tracking.aggregate_contract_version', 1);
    config()->set('tracking.prune.minimum_retention_days', 365);
    config()->set('tracking.prune.pause_milliseconds', 0);
    DB::table(Tables::TRACKING_RUNTIME_STATE)->where('id', 1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
        'direct_counter_owner' => 'event',
        'write_generation' => 2,
    ]);
    $manifest = json_encode([
        'schema' => 'tracking-generation-manifest-v1',
        'rotation_id' => '00000000-0000-4000-8000-000000000001',
        'closed_generation' => 1,
        'next_held_generation' => 2,
        'previous_manifest_checksum' => null,
        'deltas' => [],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $checksum = hash('sha256', $manifest);
    Storage::disk('local')->put(
        'tracking/manifests/00000000-0000-4000-8000-000000000001.json',
        $manifest
    );
    $this->freeze = TrackingGenerationRun::query()->create([
        'rotation_id' => '00000000-0000-4000-8000-000000000001',
        'status' => 'released',
        'closed_generation' => 1,
        'closed_mode' => TrackingRuntimeState::MODE_DUAL,
        'first_ledger_id' => null,
        'maximum_ledger_id' => null,
        'next_held_generation' => 2,
        'barrier_started_at' => now('UTC')->subDay(),
        'manifest_state' => 'signed',
        'manifest_id' => 'generation:1',
        'manifest_checksum' => $checksum,
        'completed_at' => now('UTC')->subDay(),
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    TrackingTestSchema::drop();
});

test('tracks prune is dry by default even while mutation gates are disabled', function (): void {
    seedPruneTracks();

    expect(Artisan::call('cms:tracks-prune', [
        '--before' => '2025-07-01 00:00:00',
        '--freeze-max-id' => 3,
        '--window-size' => 2,
    ]))->toBe(0)
        ->and(DB::table(Tables::TRACKS)->count())->toBe(4)
        ->and(TrackingPruneRun::query()->count())->toBe(0);

    expect(Artisan::call('cms:tracks-prune', [
        '--before' => '2025-07-01 00:00:00',
        '--freeze-max-id' => 3,
        '--window-size' => 2,
        '--force' => true,
    ]))->toBe(1)
        ->and(DB::table(Tables::TRACKS)->count())->toBe(4);
});

test('guarded prune deletes explicit eligible ids and records only aggregate progress', function (): void {
    seedPruneTracks();
    $arguments = [
        '--before' => '2025-07-01 00:00:00',
        '--freeze-max-id' => 3,
        '--window-size' => 2,
        '--chunk' => 1,
        '--run-id' => '00000000-0000-4000-8000-000000000099',
    ];
    expect(Artisan::call('cms:tracks-prune', $arguments))->toBe(0);
    preg_match('/reconciliation_hash=([a-f0-9]{64})/', Artisan::output(), $matches);
    enablePruneGates($matches[1]);

    expect(Artisan::call('cms:tracks-prune', [
        ...$arguments,
        '--force' => true,
    ]))->toBe(0);

    $run = TrackingPruneRun::query()->firstOrFail();
    expect(DB::table(Tables::TRACKS)->pluck('id')->all())->toBe([3, 4])
        ->and($run->status)->toBe('completed')
        ->and($run->expected_rows)->toBe(2)
        ->and($run->expected_views)->toBe(5)
        ->and($run->deleted_rows)->toBe(2)
        ->and($run->deleted_views)->toBe(5)
        ->and($run->getAttributes())->not->toHaveKeys([
            'ip_address',
            'session_id',
            'url',
            'agent',
            'target_type',
            'target_id',
        ]);
});

test('running prune resumes the same fixed window and refuses changed arguments', function (): void {
    seedPruneTracks();
    $arguments = [
        '--before' => '2025-07-01 00:00:00',
        '--freeze-max-id' => 3,
        '--window-size' => 2,
        '--chunk' => 1,
        '--run-id' => '00000000-0000-4000-8000-000000000098',
    ];
    Artisan::call('cms:tracks-prune', $arguments);
    preg_match('/reconciliation_hash=([a-f0-9]{64})/', Artisan::output(), $matches);
    enablePruneGates($matches[1]);
    config()->set('tracking.prune.pause_milliseconds', 1100);

    expect(Artisan::call('cms:tracks-prune', [
        ...$arguments,
        '--max-runtime' => 1,
        '--force' => true,
    ]))->toBe(0)
        ->and(TrackingPruneRun::query()->value('status'))->toBe('running')
        ->and(TrackingPruneRun::query()->value('deleted_rows'))->toBe(1);

    config()->set('tracking.prune.pause_milliseconds', 0);
    expect(Artisan::call('cms:tracks-prune', [
        ...$arguments,
        '--window-size' => 3,
        '--force' => true,
    ]))->toBe(1)
        ->and(DB::table(Tables::TRACKS)->whereIn('id', [1, 2])->count())->toBe(1);

    expect(Artisan::call('cms:tracks-prune', [
        ...$arguments,
        '--force' => true,
    ]))->toBe(0)
        ->and(TrackingPruneRun::query()->value('status'))->toBe('completed')
        ->and(DB::table(Tables::TRACKS)->whereIn('id', [1, 2])->count())->toBe(0);
});

function seedPruneTracks(): void
{
    DB::table(Tables::TRACKS)->insert([
        [
            'view' => 2,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-02 00:00:00',
        ],
        [
            'view' => 3,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-03 00:00:00',
        ],
        [
            'view' => 5,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2026-07-01 00:00:00',
        ],
        [
            'view' => 7,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-02 00:00:00',
        ],
    ]);
}

function enablePruneGates(string $hash): void
{
    config()->set('tracking.prune_enabled', true);
    config()->set('tracking.prune.freeze_max_id', 3);
    config()->set(
        'tracking.prune.freeze_manifest_checksum',
        TrackingGenerationRun::query()->value('manifest_checksum')
    );
    config()->set('tracking.prune.worker_contract_version', 1);
    config()->set('tracking.prune.legacy_jobs_disabled', true);
    config()->set('tracking.prune.aggregate_consumers_only', true);
    config()->set('tracking.prune.raw_caches_invalidated', true);
    config()->set('tracking.prune.backup_restore_proof', 'restore-proof-1');
    config()->set('tracking.prune.reconciliation_hash', $hash);
}
