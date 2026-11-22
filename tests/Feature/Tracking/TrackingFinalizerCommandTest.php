<?php

use App\Constants\Tables;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

function seedFinalizerTrack(string $targetType, int $targetId, int $views): void
{
    DB::table(Tables::TRACKS)->insert([
        'target_type' => $targetType,
        'target_id' => $targetId,
        'view' => $views,
        'session_id' => "session-{$targetType}-{$targetId}",
        'created_at' => '2026-07-24 08:00:00',
        'updated_at' => '2026-07-24 08:00:00',
    ]);
}

beforeEach(function (): void {
    TrackingTestSchema::create();
    Carbon::setTestNow('2026-07-25 03:00:00 Asia/Ho_Chi_Minh');
    Schema::create('audit_logs', static function (Blueprint $table): void {
        $table->id();
        $table->string('action');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('comments', static function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
    Schema::dropIfExists('audit_logs');
    Schema::dropIfExists('comments');
    TrackingTestSchema::drop();
});

test('unbounded all-day finalizer calls are refused', function (string $command): void {
    expect(Artisan::call($command, ['--all' => true]))->toBe(1);
})->with([
    'site day' => 'cms:daily-tracking',
    'target day' => 'cms:tracking-top-daily',
]);

test('finalizer ranges are bounded and cannot include the open day', function (): void {
    expect(Artisan::call('cms:daily-tracking', [
        '--from' => '2026-06-01',
        '--to' => '2026-07-24',
        '--max-days' => 7,
    ]))->toBe(1)
        ->and(Artisan::call('cms:tracking-top-daily', [
            '--date' => '2026-07-25',
        ]))->toBe(1);
});

test('bounded closed-day ranges run synchronously with success exit codes', function (): void {
    expect(Artisan::call('cms:daily-tracking', [
        '--from' => '2026-07-22',
        '--to' => '2026-07-23',
        '--max-days' => 7,
    ]))->toBe(0)
        ->and(Artisan::call('cms:tracking-top-daily', [
            '--from' => '2026-07-22',
            '--to' => '2026-07-23',
            '--max-days' => 7,
        ]))->toBe(0);
});

test('an unresolvable target type does not discard the rest of the day', function (): void {
    seedFinalizerTrack('Cms\\Content\\Models\\Post', 41, 9);
    seedFinalizerTrack('App\\Models\\ThisClassWasDeleted', 7, 4);

    expect(Artisan::call('cms:tracking-top-daily', [
        '--date' => '2026-07-24',
    ]))->toBe(0);

    $canonical = DB::table(Tables::TRACKING_TARGET_DAILY)->where('date', '2026-07-24')->get();

    expect($canonical->whereNull('target_key'))->toHaveCount(0)
        ->and($canonical->pluck('target_key')->all())->toBe(['post:41'])
        ->and((int) $canonical->firstWhere('target_key', 'post:41')->total_views)->toBe(9);
});

test('legacy upload class maps onto the current upload target key', function (): void {
    seedFinalizerTrack('App\\Models\\Upload', 12, 5);
    seedFinalizerTrack('Cms\\Core\\Models\\Upload', 12, 3);

    expect(Artisan::call('cms:tracking-top-daily', [
        '--date' => '2026-07-24',
    ]))->toBe(0);

    $canonical = DB::table(Tables::TRACKING_TARGET_DAILY)
        ->where('date', '2026-07-24')
        ->get();

    expect($canonical->pluck('target_key')->all())->toBe(['upload:12'])
        ->and((int) $canonical->first()->total_views)->toBe(8)
        ->and($canonical->first()->target_type)->toBe('Cms\\Core\\Models\\Upload');
});

test('a day seen only under a retired class still stores the loadable class', function (): void {
    seedFinalizerTrack('App\\Models\\Upload', 31, 6);

    expect(Artisan::call('cms:tracking-top-daily', [
        '--date' => '2026-07-24',
    ]))->toBe(0);

    $canonical = DB::table(Tables::TRACKING_TARGET_DAILY)
        ->where('date', '2026-07-24')
        ->first();

    expect($canonical->target_key)->toBe('upload:31')
        ->and($canonical->target_type)->toBe('Cms\\Core\\Models\\Upload')
        ->and((int) $canonical->total_views)->toBe(6);
});

test('tracking schedule is staggered and includes protected pending poller', function (): void {
    $exit = Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('cms:tracking-dispatch-pending')
        ->and($output)->toContain('cms:daily-tracking')
        ->and($output)->toContain('cms:tracking-top-daily')
        ->and($output)->toMatch('/15\s+0\s+\* \* \*.*cms:daily-tracking/')
        ->and($output)->toMatch('/25\s+0\s+\* \* \*.*cms:tracking-top-daily/')
        ->and($output)->not->toMatch('/^\s*5\s+0\s+\* \* \*.*cms:(?:daily-tracking|tracking-top-daily)/m');
});
