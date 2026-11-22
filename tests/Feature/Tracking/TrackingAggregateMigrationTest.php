<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingRuntimeState;
use App\Models\Tracking\TrackingSiteTotal;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    foreach ([
        'tracking_event_receipts',
        'tracking_generation_runs',
        'tracking_runtime_state',
        'tracking_target_catalog',
        'tracking_target_totals',
        'tracking_site_totals',
        'tracking_target_daily_v2',
        Tables::TRACKING_TOP,
        Tables::TRACKING_DAILY,
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create(Tables::TRACKING_DAILY, static function (Blueprint $table): void {
        $table->id();
        $table->integer('views')->default(0);
        $table->integer('visitors')->default(0);
        $table->integer('place_scan')->default(0);
        $table->integer('item_scan')->default(0);
        $table->integer('scans')->default(0);
        $table->integer('download')->default(0);
        $table->integer('sessions')->default(0);
        $table->date('date')->nullable();
        $table->timestamps();
    });

    Schema::create(Tables::TRACKING_TOP, static function (Blueprint $table): void {
        $table->id();
        $table->string('range_name', 50)->nullable();
        $table->string('target_type')->nullable();
        $table->unsignedBigInteger('target_id')->nullable();
        $table->integer('total_views')->default(0);
        $table->timestamps();
    });
});

afterEach(function (): void {
    foreach ([
        'tracking_event_receipts',
        'tracking_generation_runs',
        'tracking_runtime_state',
        'tracking_target_catalog',
        'tracking_target_totals',
        'tracking_site_totals',
        'tracking_target_daily_v2',
        Tables::TRACKING_TOP,
        Tables::TRACKING_DAILY,
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

function runAggregateTrackingMigration(): object
{
    $migration = require database_path('migrations/2026_07_23_120000_add_aggregate_first_tracking_schema.php');
    $migration->up();

    return $migration;
}

test('required tracking instants avoid legacy mysql timestamp defaults', function (): void {
    $migrationColumns = [
        '2026_07_23_120000_add_aggregate_first_tracking_schema.php' => [
            'barrier_started_at',
            'dispatched_at',
            'occurred_at',
            'replay_until',
            'throttle_decided_at',
            'expires_at',
        ],
        '2026_07_23_190000_create_tracking_prune_runs.php' => [
            'cutoff_at',
            'started_at',
        ],
    ];

    foreach ($migrationColumns as $migration => $columns) {
        $source = file_get_contents(database_path("migrations/{$migration}"));

        expect($source)->not->toBeFalse();

        foreach ($columns as $column) {
            expect($source)
                ->toContain("\$table->dateTime('{$column}');")
                ->not->toContain("\$table->timestamp('{$column}');");
        }
    }
});

test('aggregate tracking migration adds staged facts and bounded delivery state', function (): void {
    runAggregateTrackingMigration();

    expect(Schema::hasColumns('tracking_target_daily_v2', [
        'date',
        'target_key',
        'target_type',
        'target_id',
        'total_views',
        'total_sessions',
        'total_visitors',
        'total_scans',
        'total_downloads',
        'aggregate_version',
    ]))->toBeTrue()
        ->and(Schema::hasIndex('tracking_target_daily_v2', 'tracking_target_daily_key_date_idx'))->toBeTrue()
        ->and(Schema::hasIndex('tracking_target_daily_v2', 'tracking_target_daily_morph_date_idx'))->toBeTrue()
        ->and(Schema::hasIndex('tracking_target_daily_v2', ['date', 'target_key'], 'unique'))->toBeFalse()
        ->and(Schema::hasColumns(Tables::TRACKING_DAILY, [
            'is_closed',
            'aggregate_version',
            'aggregated_at',
            'last_applied_at',
            'late_event_count',
        ]))->toBeTrue()
        ->and(Schema::hasColumn(Tables::TRACKING_TOP, 'target_key'))->toBeTrue();

    expect(Schema::hasColumns('tracking_event_receipts', [
        'event_id',
        'envelope_version',
        'write_generation',
        'generation_mode',
        'dispatched_at',
        'occurred_at',
        'replay_until',
        'event_date',
        'target_key',
        'counter_deltas',
        'status',
        'quarantine_reason',
        'resolution_status',
        'compensating_event_id',
        'resolution_manifest_id',
        'resolved_at',
        'dispatch_attempts',
        'last_enqueued_at',
        'processed_at',
        'expires_at',
        'legacy_ip_address',
        'legacy_agent',
        'legacy_url',
        'legacy_session_id',
    ]))->toBeTrue()
        ->and(Schema::hasIndex('tracking_event_receipts', ['event_id'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('tracking_event_receipts', 'tracking_receipts_pending_scan_idx'))->toBeTrue()
        ->and(Schema::hasIndex('tracking_event_receipts', 'tracking_receipts_expiry_idx'))->toBeTrue();

    $counterType = DB::getDriverName() === 'sqlite' ? 'integer' : 'bigint';

    expect(Schema::getColumnType('tracking_target_daily_v2', 'total_views', true))->toContain($counterType)
        ->and(Schema::getColumnType(Tables::TRACKING_DAILY, 'views', true))->toContain($counterType)
        ->and(Schema::getColumnType(Tables::TRACKING_TOP, 'total_views', true))->toContain($counterType)
        ->and(Schema::hasIndex('tracking_target_totals', ['target_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('tracking_target_catalog', ['target_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasColumns('tracking_site_totals', [
            'baseline_scans',
            'live_scans',
            'baseline_quality',
            'scans_quality',
        ]))->toBeTrue();
});

test('aggregate tracking migration bootstraps singleton state without changing legacy rows', function (): void {
    DB::table(Tables::TRACKING_DAILY)->insert([
        'date' => '2026-07-22',
        'views' => 7,
        'visitors' => 2,
        'scans' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runAggregateTrackingMigration();

    expect(DB::table(Tables::TRACKING_DAILY)->whereDate('date', '2026-07-22')->value('views'))->toBe(7)
        ->and(DB::table('tracking_runtime_state')->get()->map(fn ($row) => [
            'id' => $row->id,
            'write_generation' => $row->write_generation,
            'mode' => $row->mode,
            'processing_state' => $row->processing_state,
            'direct_counter_owner' => $row->direct_counter_owner,
        ])->all())->toBe([[
            'id' => 1,
            'write_generation' => 1,
            'mode' => 'legacy',
            'processing_state' => 'open',
            'direct_counter_owner' => 'legacy',
        ]])
        ->and(DB::table('tracking_site_totals')->pluck('id')->all())->toBe([1]);
});

test('aggregate tracking migration resumes after earlier mysql ddl was committed', function (): void {
    $migration = runAggregateTrackingMigration();

    DB::table('tracking_site_totals')
        ->where('id', 1)
        ->update(['live_views' => 37]);
    Schema::drop(Tables::TRACKING_EVENT_RECEIPTS);

    $migration->up();

    expect(Schema::hasTable(Tables::TRACKING_EVENT_RECEIPTS))->toBeTrue()
        ->and(DB::table('tracking_site_totals')->where('id', 1)->value('live_views'))->toBe(37)
        ->and(DB::table('tracking_site_totals')->count())->toBe(1)
        ->and(DB::table('tracking_runtime_state')->count())->toBe(1);
});

test('aggregate tracking migration keeps staged target keys nullable and receipt ids unique', function (): void {
    runAggregateTrackingMigration();

    DB::table('tracking_target_daily_v2')->insert([
        'date' => null,
        'target_key' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $receipt = [
        'event_id' => '01JTRACKINGEVENT00000000001',
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => 'legacy',
        'dispatched_at' => now(),
        'occurred_at' => now(),
        'replay_until' => now()->addDay(),
        'counter_deltas' => json_encode(['legacy_views' => 1], JSON_THROW_ON_ERROR),
        'throttle_contract_version' => 1,
        'throttle_decided_at' => now(),
        'status' => 'pending',
        'expires_at' => now()->addDays(2),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('tracking_event_receipts')->insert($receipt);

    expect(fn () => DB::table('tracking_event_receipts')->insert($receipt))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('aggregate singleton models reject ids other than one', function (): void {
    runAggregateTrackingMigration();

    expect(fn () => TrackingSiteTotal::query()->create(['id' => 2]))
        ->toThrow(LogicException::class)
        ->and(fn () => TrackingRuntimeState::query()->create([
            'id' => 2,
            'write_generation' => 2,
            'mode' => 'legacy',
            'processing_state' => 'open',
            'direct_counter_owner' => 'legacy',
        ]))->toThrow(LogicException::class);
});

test('aggregate receipt envelope fields cannot change after creation', function (): void {
    runAggregateTrackingMigration();

    $receipt = TrackingEventReceipt::query()->create([
        'event_id' => '01JTRACKINGEVENT00000000002',
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => 'legacy',
        'dispatched_at' => now(),
        'occurred_at' => now(),
        'replay_until' => now()->addDay(),
        'counter_deltas' => ['legacy_views' => 1],
        'throttle_contract_version' => 1,
        'throttle_decided_at' => now(),
        'status' => 'pending',
        'expires_at' => now()->addDays(2),
    ]);

    expect(fn () => $receipt->update(['event_id' => '01JTRACKINGEVENT00000000003']))
        ->toThrow(LogicException::class);
});

test('aggregate generation run model permits only one incomplete rotation', function (): void {
    runAggregateTrackingMigration();

    $payload = [
        'status' => 'holding',
        'closed_generation' => 1,
        'closed_mode' => 'dual',
        'next_held_generation' => 2,
        'barrier_started_at' => now(),
    ];

    TrackingGenerationRun::query()->create([
        ...$payload,
        'rotation_id' => '11111111-1111-4111-8111-111111111111',
    ]);

    expect(fn () => TrackingGenerationRun::query()->create([
        ...$payload,
        'rotation_id' => '22222222-2222-4222-8222-222222222222',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('aggregate migration rollback preserves a pre-existing tracking top target key', function (): void {
    Schema::dropIfExists(Tables::TRACKING_TOP);
    Schema::create(Tables::TRACKING_TOP, static function (Blueprint $table): void {
        $table->id();
        $table->string('target_key', 191)->nullable();
        $table->bigInteger('total_views')->default(0);
        $table->timestamps();
    });

    $migration = runAggregateTrackingMigration();
    $migration->down();

    expect(Schema::hasColumn(Tables::TRACKING_TOP, 'target_key'))->toBeTrue();
});
