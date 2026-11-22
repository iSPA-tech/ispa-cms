<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingCounterFlushBatch;
use App\Models\Tracking\TrackingCounterRuntime;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingBufferedCounterStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RedisReachable;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    RedisReachable::orSkip();
    TrackingTestSchema::create();
    (require database_path('migrations/2026_09_03_120000_add_redis_buffered_tracking_runtime.php'))->up();
    $this->prefix = 'tracking:test-flush:' . strtolower((string) Str::ulid());
    $trackingConnection = [
        ...config('database.redis.cache'),
        'database' => (int) config('database.redis.cache.database') + 1,
    ];
    config([
        'database.redis.tracking' => $trackingConnection,
        'tracking.buffer.enabled' => true,
        'tracking.buffer.connection' => 'tracking',
        'tracking.buffer.prefix' => $this->prefix,
        'tracking.buffer.retention_seconds' => 3600,
        'tracking.buffer.seal_after_minutes' => 70,
        'tracking.analytics_timezone' => 'Asia/Ho_Chi_Minh',
    ]);
    $this->store = app(TrackingBufferedCounterStore::class);
    $this->store->activateRedis(7);
});

afterEach(function (): void {
    if (! isset($this->store)) {
        return; // skipped before setup: Redis was not reachable
    }
    foreach ($this->store->registeredBuckets() as $bucket) {
        $this->store->delete($bucket);
    }
    Redis::connection('tracking')->del($this->prefix . ':control');
    Redis::connection('tracking')->del($this->prefix . ':buckets');
    Schema::dropIfExists(Tables::TRACKING_COUNTER_FLUSH_BATCHES);
    Schema::dropIfExists(Tables::TRACKING_COUNTER_RUNTIME);
    TrackingTestSchema::drop();
});

function flushPayload(string $eventId, string $key, string $type, int $id, int $views): array
{
    return [
        'event_id' => $eventId,
        'occurred_at' => CarbonImmutable::parse('2026-09-03 03:15:00 UTC'),
        'target_key' => $key,
        'target_type' => $type,
        'target_id' => $id,
        'target_kind' => 'entity',
        'display_label' => strtoupper($type) . ' ' . $id,
        'public_path' => '/target/' . $id,
        'is_rankable' => true,
        'deltas' => [
            'legacy_views' => $views,
            'page_views' => $views,
            'site_sessions' => 1,
            'site_visitors' => 1,
            'target_sessions' => 1,
            'target_visitors' => 1,
        ],
    ];
}

test('flush applies a sealed bucket once and deletes it only after commit', function (): void {
    $this->store->record(flushPayload('event-1', 'post:42', 'post', 42, 1));
    $this->store->record(flushPayload('event-2', 'vehicle-model:42', 'vehicle-model', 42, 2));
    $bucket = $this->store->sealAll()[0];

    expect(Artisan::call('cms:tracking-buffer-flush', ['--all' => true]))->toBe(0)
        ->and($this->store->registeredBuckets())->toBe([])
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(3)
        ->and(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-09-03')->value('views'))->toBe(3)
        ->and(DB::table(Tables::TRACKING_DAILY)->where('date', '2026-09-03')->value('sessions'))->toBe(2)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->where('target_key', 'post:42')->value('live_views'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->where('target_key', 'vehicle-model:42')->value('live_views'))->toBe(2)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->where('target_key', 'vehicle-model:42')->value('total_visitors'))->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_CATALOG)->where('target_key', 'post:42')->value('public_path'))->toBe('/target/42');

    $manifest = TrackingCounterFlushBatch::query()->where('bucket_id', $bucket)->sole();
    expect($manifest->status)->toBe('applied')
        ->and($manifest->write_generation)->toBe(1)
        ->and($manifest->deltas['site']['legacy_views'])->toBe(3)
        ->and($manifest->total_views)->toBe(3)
        ->and($manifest->target_count)->toBe(2)
        ->and(Artisan::call('cms:tracking-buffer-flush', ['--all' => true]))->toBe(0)
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(3);
});

test('same bucket id with a changed checksum fails closed and preserves Redis data', function (): void {
    $this->store->record(flushPayload('event-1', 'post:42', 'post', 42, 1));
    $bucket = $this->store->sealAll()[0];
    expect(Artisan::call('cms:tracking-buffer-flush', ['--all' => true]))->toBe(0);

    $this->store->record(flushPayload('event-2', 'post:42', 'post', 42, 2));
    $this->store->seal($bucket);

    expect(Artisan::call('cms:tracking-buffer-flush', ['--all' => true]))->toBe(1)
        ->and($this->store->registeredBuckets())->toBe([$bucket])
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(1);
});

test('flush fails closed when the database says Redis but the control fence is missing', function (): void {
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
        'write_generation' => 1,
    ]);
    TrackingCounterRuntime::query()->whereKey(1)->update([
        'active_driver' => TrackingCounterRuntime::DRIVER_REDIS,
        'transition_state' => TrackingCounterRuntime::TRANSITION_STABLE,
        'epoch' => 7,
    ]);
    Redis::connection('tracking')->del($this->prefix . ':control');

    expect(Artisan::call('cms:tracking-buffer-flush'))->toBe(1)
        ->and(Artisan::output())->toContain('control fence does not match');
});

test('buffer flusher is scheduled every fifteen minutes with overlap protection', function (): void {
    $schedule = app(\App\Console\Kernel::class)->resolveConsoleSchedule();
    $event = collect($schedule->events())->first(
        static fn ($event): bool => str_contains($event->command, 'cms:tracking-buffer-flush')
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
