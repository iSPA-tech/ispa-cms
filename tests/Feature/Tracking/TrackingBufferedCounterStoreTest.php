<?php

use App\Services\Tracking\TrackingBufferedCounterStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\RedisReachable;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    RedisReachable::orSkip();
    $this->prefix = 'tracking:test-buffer:' . Str::lower((string) Str::ulid());
    config([
        'tracking.buffer.enabled' => true,
        'tracking.buffer.connection' => 'cache',
        'tracking.buffer.prefix' => $this->prefix,
        'tracking.buffer.retention_seconds' => 3600,
        'tracking.analytics_timezone' => 'Asia/Ho_Chi_Minh',
    ]);
    $this->store = app(TrackingBufferedCounterStore::class);
});

afterEach(function (): void {
    if (! isset($this->store)) {
        return; // skipped before setup: Redis was not reachable
    }
    foreach ($this->store->registeredBuckets() as $bucket) {
        $this->store->delete($bucket);
    }
    Redis::connection('cache')->del($this->prefix . ':control');
    Redis::connection('cache')->del($this->prefix . ':buckets');
});

function bufferedPayload(string $eventId, string $targetKey, string $type, int $views = 1): array
{
    return [
        'event_id' => $eventId,
        'occurred_at' => CarbonImmutable::parse('2026-09-03 03:15:00 UTC'),
        'target_key' => $targetKey,
        'target_type' => $type,
        'target_id' => 7757,
        'target_kind' => 'entity',
        'display_label' => 'Target 7757',
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

test('redis fence buffers exact target types and deduplicates event ids', function (): void {
    $this->store->activateRedis(4);

    expect($this->store->record(bufferedPayload('event-1', 'vehicle-model:7757', 'vehicle-model')))
        ->toBe(TrackingBufferedCounterStore::RESULT_BUFFERED)
        ->and($this->store->record(bufferedPayload('event-1', 'vehicle-model:7757', 'vehicle-model')))
        ->toBe(TrackingBufferedCounterStore::RESULT_DUPLICATE)
        ->and($this->store->record(bufferedPayload('event-2', 'post:7757', 'post', 2)))
        ->toBe(TrackingBufferedCounterStore::RESULT_BUFFERED);

    $bucket = $this->store->registeredBuckets()[0];
    $snapshot = $this->store->snapshot($bucket);

    expect($snapshot['date'])->toBe('2026-09-03')
        ->and($snapshot['site']['legacy_views'])->toBe(3)
        ->and($snapshot['targets']['vehicle-model:7757']['target_type'])->toBe('vehicle-model')
        ->and($snapshot['targets']['vehicle-model:7757']['deltas']['legacy_views'])->toBe(1)
        ->and($snapshot['targets']['post:7757']['target_type'])->toBe('post')
        ->and($snapshot['targets']['post:7757']['deltas']['legacy_views'])->toBe(2);
});

test('receipt fence and sealed buckets fall back without changing counters', function (): void {
    $this->store->activateReceipt();
    expect($this->store->record(bufferedPayload('event-1', 'post:7757', 'post')))
        ->toBe(TrackingBufferedCounterStore::RESULT_FALLBACK)
        ->and($this->store->registeredBuckets())->toBe([]);

    $this->store->activateRedis(5);
    expect($this->store->record(bufferedPayload('event-2', 'post:7757', 'post')))
        ->toBe(TrackingBufferedCounterStore::RESULT_BUFFERED);
    $bucket = $this->store->registeredBuckets()[0];
    $this->store->seal($bucket);

    expect($this->store->record(bufferedPayload('event-3', 'post:7757', 'post')))
        ->toBe(TrackingBufferedCounterStore::RESULT_FALLBACK)
        ->and($this->store->snapshot($bucket)['site']['legacy_views'])->toBe(1);

    $this->store->delete($bucket);
    expect($this->store->registeredBuckets())->toBe([]);
});
