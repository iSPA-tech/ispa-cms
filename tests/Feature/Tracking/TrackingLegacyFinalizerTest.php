<?php

use App\Constants\Tables;
use App\Jobs\DailyTrackingJob;
use App\Jobs\TrackingTopDailyAggregationJob;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Carbon::setTestNow('2026-07-25 03:00:00 Asia/Ho_Chi_Minh');

    Schema::dropIfExists('audit_logs');
    Schema::create('audit_logs', static function (Blueprint $table): void {
        $table->id();
        $table->string('action');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::dropIfExists('comments');
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

function insertLegacyTrack(
    string $date,
    ?string $targetType = 'Cms\\Content\\Models\\Post',
    int $targetId = 42,
    int $views = 1
): void {
    DB::table(Tables::TRACKS)->insert([
        'tags' => json_encode(['page_view'], JSON_THROW_ON_ERROR),
        'target_type' => $targetType,
        'target_id' => $targetId,
        'ip_address' => '127.0.0.1',
        'agent' => 'Legacy Finalizer Test',
        'url' => 'https://example.com/articles/42',
        'session_id' => str_repeat('s', 40),
        'view' => $views,
        'created_at' => "{$date} 10:00:00",
        'updated_at' => "{$date} 10:00:00",
    ]);
}

test('legacy finalizers populate site and canonical target day with stable key', function (): void {
    insertLegacyTrack('2026-07-23', views: 7);

    (new DailyTrackingJob(Carbon::parse('2026-07-23')))->handle();
    (new TrackingTopDailyAggregationJob(Carbon::parse('2026-07-23')))->handle();

    expect(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-23')->value('views'))->toBe(7)
        ->and(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-23')->value('is_closed'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->where('date', '2026-07-23')->value('target_key'))->toBe('post:42')
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->where('date', '2026-07-23')->value('total_views'))->toBe(7)
        ->and(DB::table(Tables::TRACKING_TOP_DAILY)->where('date', '2026-07-23')->value('total_views'))->toBe(7);
});

test('legacy finalizers cannot overwrite event-owned direct counters at boundary', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_DUAL,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);
    insertLegacyTrack('2026-07-24', views: 99);
    DB::table(Tables::TRACKING_DAILY)->insert([
        'date' => '2026-07-24',
        'views' => 5,
        'is_closed' => false,
        'aggregate_version' => 1,
        'late_event_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table(Tables::TRACKING_TARGET_DAILY)->insert([
        'date' => '2026-07-24',
        'target_key' => 'post:42',
        'target_type' => 'Cms\\Content\\Models\\Post',
        'target_id' => 42,
        'total_views' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new DailyTrackingJob(Carbon::parse('2026-07-24')))->handle();
    (new TrackingTopDailyAggregationJob(Carbon::parse('2026-07-24')))->handle();

    expect(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-24')->value('views'))->toBe(5)
        ->and(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-07-24')->value('is_closed'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->where('date', '2026-07-24')->value('total_views'))->toBe(5);
});

test('finalizers reject the current open analytics day', function (): void {
    expect(fn () => (new DailyTrackingJob(Carbon::parse('2026-07-25')))->handle())
        ->toThrow(LogicException::class)
        ->and(fn () => (new TrackingTopDailyAggregationJob(Carbon::parse('2026-07-25')))->handle())
        ->toThrow(LogicException::class);
});

function insertLegacyReceipt(
    string $date,
    string $targetKey,
    string $mode = TrackingRuntimeState::MODE_LEGACY,
    string $status = 'applied'
): void {
    $now = now('UTC');

    DB::table(Tables::TRACKING_EVENT_RECEIPTS)->insert([
        'event_id' => (string) Str::ulid(),
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => $mode,
        'dispatched_at' => $now,
        'occurred_at' => $now,
        'replay_until' => $now,
        'event_date' => $date,
        'target_key' => $targetKey,
        'target_kind' => 'route',
        'is_rankable' => true,
        'counter_deltas' => json_encode(['legacy_views' => 1], JSON_THROW_ON_ERROR),
        'throttle_contract_version' => 1,
        'throttle_decided_at' => $now,
        'status' => $status,
        'expires_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('legacy target finalizer ranks page views that only receipts can name', function (): void {
    insertLegacyTrack('2026-07-23', views: 2);
    insertLegacyTrack('2026-07-23', targetType: null, targetId: 0, views: 3);
    insertLegacyReceipt('2026-07-23', 'route:home');
    insertLegacyReceipt('2026-07-23', 'route:home');
    insertLegacyReceipt('2026-07-23', 'route:home');
    insertLegacyReceipt('2026-07-23', 'route:home', status: 'pending');
    insertLegacyReceipt('2026-07-23', 'route:home', mode: TrackingRuntimeState::MODE_DUAL);
    insertLegacyReceipt('2026-07-22', 'route:home');

    (new TrackingTopDailyAggregationJob(Carbon::parse('2026-07-23')))->handle();

    $page = DB::table(Tables::TRACKING_TARGET_DAILY)
        ->where('date', '2026-07-23')
        ->where('target_key', 'route:home')
        ->first();

    expect($page->total_views)->toBe(3)
        ->and($page->target_type)->toBeNull()
        ->and($page->target_id)->toBeNull()
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->where('target_key', 'post:42')->value('total_views'))->toBe(2)
        ->and(DB::table(Tables::TRACKING_TOP_DAILY)->count())->toBe(1);
});
