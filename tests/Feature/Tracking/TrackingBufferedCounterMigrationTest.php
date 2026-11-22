<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    Schema::dropIfExists('tracking_counter_flush_batches');
    Schema::dropIfExists('tracking_counter_runtime');
});

afterEach(function (): void {
    Schema::dropIfExists('tracking_counter_flush_batches');
    Schema::dropIfExists('tracking_counter_runtime');
});

test('buffered counter migration creates a receipt owned runtime by default', function (): void {
    $migration = require database_path(
        'migrations/2026_09_03_120000_add_redis_buffered_tracking_runtime.php'
    );
    $migration->up();

    expect(Schema::hasColumns('tracking_counter_runtime', [
        'id',
        'active_driver',
        'transition_state',
        'epoch',
        'changed_at',
        'changed_by',
        'change_reason',
    ]))->toBeTrue()
        ->and((array) DB::table('tracking_counter_runtime')->where('id', 1)->first())
        ->toMatchArray([
            'id' => 1,
            'active_driver' => 'receipt',
            'transition_state' => 'stable',
            'epoch' => 1,
        ])
        ->and(config('tracking.buffer.enabled'))->toBeFalse();
});

test('flush manifests permit one checksum per bucket', function (): void {
    $migration = require database_path(
        'migrations/2026_09_03_120000_add_redis_buffered_tracking_runtime.php'
    );
    $migration->up();

    expect(Schema::hasColumns('tracking_counter_flush_batches', [
        'write_generation',
        'deltas',
    ]))->toBeTrue();

    DB::table('tracking_counter_flush_batches')->insert([
        'bucket_id' => '2026-09-03T10:e1',
        'checksum' => str_repeat('a', 64),
        'status' => 'applied',
        'write_generation' => 1,
        'deltas' => json_encode(['site' => ['legacy_views' => 10]], JSON_THROW_ON_ERROR),
        'target_count' => 2,
        'total_views' => 10,
        'applied_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('tracking_counter_flush_batches')->insert([
        'bucket_id' => '2026-09-03T10:e1',
        'checksum' => str_repeat('b', 64),
        'status' => 'applied',
        'write_generation' => 1,
        'deltas' => json_encode(['site' => ['legacy_views' => 1]], JSON_THROW_ON_ERROR),
        'target_count' => 1,
        'total_views' => 1,
        'applied_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
