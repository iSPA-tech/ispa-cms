<?php

use App\Constants\Tables;
use App\Jobs\TopRangeAggregationJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Carbon::setTestNow('2026-07-25 03:00:00 Asia/Ho_Chi_Minh');
});

afterEach(function (): void {
    Carbon::setTestNow();
    TrackingTestSchema::drop();
});

function seedDailyFact(
    string $targetKey,
    ?string $targetType,
    ?int $targetId,
    int $views,
    string $date = '2026-07-23'
): void {
    DB::table(Tables::TRACKING_TARGET_DAILY)->insert([
        'date' => $date,
        'target_key' => $targetKey,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'total_views' => $views,
        'aggregate_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('page targets are ranked in their own bucket instead of being dropped', function (): void {
    seedDailyFact('route:home', null, null, 90);
    seedDailyFact('route:contact', null, null, 30);
    seedDailyFact('post:5', 'Cms\\Content\\Models\\Post', 5, 70);

    expect(Artisan::call('cms:tracking-top', [
        '--range' => '7d',
        '--all-targets' => true,
    ]))->toBe(0);

    $pages = DB::table(Tables::TRACKING_TOP)
        ->where('range_name', '7d')
        ->where('target_type', TopRangeAggregationJob::ROUTE_TYPE)
        ->orderBy('rank')
        ->get();

    expect($pages->pluck('target_key')->all())->toBe(['route:home', 'route:contact'])
        ->and($pages->pluck('total_views')->map(intval(...))->all())->toBe([90, 30])
        ->and($pages->pluck('rank')->map(intval(...))->all())->toBe([1, 2]);
});

test('ranking pages does not disturb the class-backed buckets', function (): void {
    seedDailyFact('route:home', null, null, 90);
    seedDailyFact('post:5', 'Cms\\Content\\Models\\Post', 5, 70);

    Artisan::call('cms:tracking-top', ['--range' => '7d', '--all-targets' => true]);

    $byType = DB::table(Tables::TRACKING_TOP)
        ->where('range_name', '7d')
        ->pluck('total_views', 'target_type')
        ->map(static fn (mixed $views): int => (int) $views)
        ->all();

    expect($byType)->toBe([
        'Cms\\Content\\Models\\Post' => 70,
        TopRangeAggregationJob::ROUTE_TYPE => 90,
    ]);
});

test('a range without page views keeps no stale page ranking', function (): void {
    seedDailyFact('route:home', null, null, 90, '2026-07-23');
    Artisan::call('cms:tracking-top', ['--range' => '7d', '--all-targets' => true]);
    expect(DB::table(Tables::TRACKING_TOP)->where('target_type', TopRangeAggregationJob::ROUTE_TYPE)->count())
        ->toBe(1);

    DB::table(Tables::TRACKING_TARGET_DAILY)->delete();
    seedDailyFact('post:5', 'Cms\\Content\\Models\\Post', 5, 70);
    Artisan::call('cms:tracking-top', ['--range' => '7d', '--all-targets' => true]);

    expect(DB::table(Tables::TRACKING_TOP)->where('target_type', TopRangeAggregationJob::ROUTE_TYPE)->count())
        ->toBe(0);
});

test('7d window spans exactly 7 days and the top-targets response reports its coverage', function (): void {
    // Now is 2026-07-25, so the closed window is 2026-07-19..2026-07-25 minus today = 07-18..07-24.
    expect(TopRangeAggregationJob::rangeStartFor(Carbon::yesterday(), '7d')->toDateString())->toBe('2026-07-18')
        ->and(TopRangeAggregationJob::rangeStartFor(Carbon::yesterday(), '1m')->toDateString())->toBe('2026-06-25');

    seedDailyFact('route:home', null, null, 90, '2026-07-18');
    seedDailyFact('route:old', null, null, 500, '2026-07-17');

    foreach (['2026-07-17' => 1000, '2026-07-18' => 100, '2026-07-24' => 50] as $date => $views) {
        DB::table(Tables::TRACKING_DAILY)->insert(['date' => $date, 'views' => $views]);
    }

    Artisan::call('cms:tracking-top', ['--range' => '7d', '--all-targets' => true]);
    expect(DB::table(Tables::TRACKING_TOP)->where('range_name', '7d')->pluck('target_key')->all())
        ->toBe(['route:home']);

    request()->query->replace(['range' => '7d']);
    $data = json_decode(
        app(\App\Http\Controllers\V2\Admin\TrackController::class)->topTargets()->toResponse(request())->getContent(),
        true
    );
    $data = $data['data'] ?? $data;

    expect($data['rangeStart'])->toBe('2026-07-18')
        ->and($data['rangeEnd'])->toBe('2026-07-24')
        ->and($data['rangeTotalViews'])->toBe(150);
});
