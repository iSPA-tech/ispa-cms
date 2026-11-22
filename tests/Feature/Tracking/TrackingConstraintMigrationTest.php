<?php

use App\Constants\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(fn () => TrackingTestSchema::create(false));
afterEach(fn () => TrackingTestSchema::drop());

function trackingConstraintMigration(): object
{
    return require database_path(
        'migrations/2026_07_23_180000_finalize_aggregate_tracking_constraints.php'
    );
}

test('constraint migration normalizes legacy null dates before adding constraints', function (): void {
    DB::table(Tables::TRACKING_DAILY)->insert([
        [
            'date' => '2026-07-23',
            'views' => 1,
            'visitors' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'date' => null,
            'views' => 2,
            'visitors' => 4,
            'created_at' => '2026-07-23 00:00:00',
            'updated_at' => '2026-07-23 00:00:00',
        ],
    ]);

    trackingConstraintMigration()->up();

    expect(DB::table(Tables::TRACKING_DAILY)->count())->toBe(1)
        ->and(DB::table(Tables::TRACKING_DAILY)->value('date'))->toBe('2026-07-23')
        ->and(DB::table(Tables::TRACKING_DAILY)->value('views'))->toBe(3)
        ->and(DB::table(Tables::TRACKING_DAILY)->value('visitors'))->toBe(7)
        ->and(Schema::hasIndex(Tables::TRACKING_DAILY, ['date'], 'unique'))->toBeTrue();
});

test('clean constraint migration adds canonical uniqueness', function (): void {
    DB::table(Tables::TRACKING_DAILY)->insert([
        'date' => '2026-07-23',
        'views' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table(Tables::TRACKING_TARGET_DAILY)->insert([
        'date' => '2026-07-23',
        'target_key' => 'post:42',
        'target_type' => 'Cms\\Content\\Models\\Post',
        'target_id' => 42,
        'total_views' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    trackingConstraintMigration()->up();

    expect(Schema::hasIndex(Tables::TRACKING_DAILY, ['date'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex(
            Tables::TRACKING_TARGET_DAILY,
            ['date', 'target_key'],
            'unique'
        ))->toBeTrue()
        ->and(fn () => DB::table(Tables::TRACKING_DAILY)->insert([
            'date' => '2026-07-23',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(\Illuminate\Database\QueryException::class);
});
