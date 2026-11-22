<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingCounterRuntime;
use App\Models\Tracking\TrackingEventReceipt;
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
    $this->prefix = 'tracking:test-switch:' . strtolower((string) Str::ulid());
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
        'tracking.buffer.require_aof' => false,
        'tracking.analytics_timezone' => 'Asia/Ho_Chi_Minh',
        'tracking.generation_rotation_enabled' => true,
    ]);
    $this->store = app(TrackingBufferedCounterStore::class);
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
        'direct_counter_owner' => 'event',
    ]);
});

afterEach(function (): void {
    if (! isset($this->store)) {
        return; // skipped before setup: Redis was not reachable
    }
    foreach ($this->store->registeredBuckets() as $bucket) {
        $this->store->delete($bucket);
    }
    foreach (['tracking', 'tracking_shared'] as $connection) {
        if (config('database.redis.' . $connection) !== null) {
            Redis::connection($connection)->del($this->prefix . ':control');
            Redis::connection($connection)->del($this->prefix . ':buckets');
        }
    }
    Schema::dropIfExists(Tables::TRACKING_COUNTER_FLUSH_BATCHES);
    Schema::dropIfExists(Tables::TRACKING_COUNTER_RUNTIME);
    TrackingTestSchema::drop();
});

function pendingSwitchReceipt(): void
{
    $now = CarbonImmutable::now('UTC');
    TrackingEventReceipt::query()->create([
        'event_id' => (string) Str::ulid(),
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'dispatched_at' => $now,
        'occurred_at' => $now,
        'replay_until' => $now->addDay(),
        'event_date' => $now->toDateString(),
        'counter_deltas' => ['legacy_views' => 1],
        'throttle_contract_version' => 1,
        'throttle_decided_at' => $now,
        'status' => TrackingEventReceipt::STATUS_PENDING,
        'expires_at' => $now->addDays(2),
    ]);
}

function switchBufferPayload(string $eventId, int $views): array
{
    return [
        'event_id' => $eventId,
        'occurred_at' => CarbonImmutable::parse('2026-09-03 03:15:00 UTC'),
        'target_key' => 'vehicle-model:42',
        'target_type' => 'vehicle-model',
        'target_id' => 42,
        'target_kind' => 'entity',
        'display_label' => 'Vehicle model 42',
        'public_path' => '/xe/example',
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

test('switch requires approval and capability before opening Redis', function (): void {
    expect(Artisan::call('cms:tracking-counter-switch', ['driver' => 'redis']))->toBe(1);

    config(['tracking.buffer.enabled' => false]);
    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'test switch',
    ]))->toBe(1)
        ->and(TrackingCounterRuntime::query()->findOrFail(1)->active_driver)
        ->toBe(TrackingCounterRuntime::DRIVER_RECEIPT);

    config([
        'tracking.buffer.enabled' => true,
        'tracking.buffer.require_aof' => true,
    ]);
    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'durability gate',
    ]))->toBe(1)
        ->and(TrackingCounterRuntime::query()->findOrFail(1)->active_driver)
        ->toBe(TrackingCounterRuntime::DRIVER_RECEIPT);
});

test('Redis switch refuses a connection that shares the application cache database', function (): void {
    config([
        'database.redis.tracking_shared' => config('database.redis.cache'),
        'tracking.buffer.connection' => 'tracking_shared',
        'tracking.buffer.require_aof' => false,
    ]);

    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'unsafe shared cache database',
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('dedicated Redis database')
        ->and(TrackingCounterRuntime::query()->findOrFail(1)->active_driver)
        ->toBe(TrackingCounterRuntime::DRIVER_RECEIPT);
});

test('an idempotent Redis switch repairs a missing control fence on the dedicated database', function (): void {
    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'initial switch',
    ]))->toBe(0);

    Redis::connection('tracking')->del($this->prefix . ':control');
    expect($this->store->record(switchBufferPayload('before-repair', 1)))
        ->toBe(TrackingBufferedCounterStore::RESULT_FALLBACK);

    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'repair missing control',
    ]))->toBe(0)
        ->and($this->store->record(switchBufferPayload('after-repair', 1)))
        ->toBe(TrackingBufferedCounterStore::RESULT_BUFFERED);
});

test('receipt to Redis switches the fence and remains draining until receipts settle', function (): void {
    pendingSwitchReceipt();

    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'buffer views',
    ]))->toBe(0);

    $runtime = TrackingCounterRuntime::query()->findOrFail(1);
    expect($runtime->active_driver)->toBe(TrackingCounterRuntime::DRIVER_REDIS)
        ->and($runtime->transition_state)->toBe(TrackingCounterRuntime::TRANSITION_DRAINING_RECEIPTS)
        ->and($this->store->record(switchBufferPayload('switch-event', 1)))
        ->toBe(TrackingBufferedCounterStore::RESULT_BUFFERED);

    expect(Artisan::call('cms:tracking-reconcile', [
        '--rotate-generation' => true,
        '--auto-release' => true,
        '--max-hold-seconds' => 30,
        '--max-runtime' => 2,
    ]))->toBe(1)
        ->and(DB::table(Tables::TRACKING_GENERATION_RUNS)->count())->toBe(0);

    TrackingEventReceipt::query()->update(['status' => TrackingEventReceipt::STATUS_APPLIED]);
    expect(Artisan::call('cms:tracking-buffer-flush'))->toBe(0)
        ->and(TrackingCounterRuntime::query()->findOrFail(1)->transition_state)
        ->toBe(TrackingCounterRuntime::TRANSITION_STABLE);
});

test('Redis to receipt fences new views and flushes every bucket before stable', function (): void {
    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'redis',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'buffer views',
    ]))->toBe(0);
    $this->store->record(switchBufferPayload('switch-event', 3));

    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'receipt',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'rollback buffer',
    ]))->toBe(0)
        ->and($this->store->registeredBuckets())->toBe([])
        ->and($this->store->record(switchBufferPayload('after-switch', 1)))
        ->toBe(TrackingBufferedCounterStore::RESULT_FALLBACK)
        ->and(TrackingCounterRuntime::query()->findOrFail(1)->active_driver)
        ->toBe(TrackingCounterRuntime::DRIVER_RECEIPT)
        ->and(TrackingCounterRuntime::query()->findOrFail(1)->transition_state)
        ->toBe(TrackingCounterRuntime::TRANSITION_STABLE)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->where('target_key', 'vehicle-model:42')->value('live_views'))
        ->toBe(3);

    expect(Artisan::call('cms:tracking-counter-switch', [
        'driver' => 'receipt',
        '--approve' => true,
        '--operator' => 'test',
        '--reason' => 'idempotent retry',
    ]))->toBe(0)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->where('target_key', 'vehicle-model:42')->value('live_views'))
        ->toBe(3);
});
