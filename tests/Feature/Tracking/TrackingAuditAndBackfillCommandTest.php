<?php

use App\Constants\Tables;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create(false);
    Carbon::setTestNow('2026-07-25 03:00:00 Asia/Ho_Chi_Minh');
});

afterEach(function (): void {
    Carbon::setTestNow();
    TrackingTestSchema::drop();
});

test('tracking audit fails on null and duplicate constraint blockers', function (): void {
    DB::table(Tables::TRACKING_DAILY)->insert([
        ['date' => null, 'views' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['date' => '2026-07-23', 'views' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['date' => '2026-07-23', 'views' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table(Tables::TRACKING_TARGET_DAILY)->insert([
        'date' => '2026-07-23',
        'target_key' => null,
        'target_type' => 'Cms\\Content\\Models\\Post',
        'target_id' => 42,
        'total_views' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exit = Artisan::call('cms:tracking-audit-aggregates', [
        '--from' => '2026-07-01',
        '--to' => '2026-07-24',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('site_null_dates=1')
        ->and($output)->toContain('site_duplicate_dates=1')
        ->and($output)->toContain('target_null_keys=1');
});

test('target key backfill is dry by default and copies bounded legacy facts only with force', function (): void {
    DB::table(Tables::TRACKING_TOP_DAILY)->insert([
        'date' => '2026-07-23',
        'target_type' => 'Cms\\Content\\Models\\Post',
        'target_id' => 42,
        'total_views' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $arguments = [
        '--from' => '2026-07-01',
        '--to' => '2026-07-24',
        '--limit' => 100,
    ];
    expect(Artisan::call('cms:tracking-backfill-target-keys', $arguments))->toBe(0)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->count())->toBe(0);

    expect(Artisan::call('cms:tracking-backfill-target-keys', [
        ...$arguments,
        '--force' => true,
    ]))->toBe(0)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->count())->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->value('target_key'))->toBe('post:42')
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->value('total_views'))->toBe(7);
});

test('target key backfill refuses unresolved entity types without partial mutation', function (): void {
    DB::table(Tables::TRACKING_TOP_DAILY)->insert([
        'date' => '2026-07-23',
        'target_type' => 'Unknown\\Private\\Model',
        'target_id' => 42,
        'total_views' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Artisan::call('cms:tracking-backfill-target-keys', [
        '--from' => '2026-07-01',
        '--to' => '2026-07-24',
        '--limit' => 100,
        '--force' => true,
    ]))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->count())->toBe(0);
});
