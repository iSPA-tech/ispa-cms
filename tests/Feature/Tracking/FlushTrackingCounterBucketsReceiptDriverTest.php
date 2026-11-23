<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingCounterRuntime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

// With the Redis counter gate off and the receipt driver owning the counters, the scheduled flusher must
// not open a Redis connection: sites that never enabled Redis counters (Redis absent, or present with a
// password the site does not know) otherwise log `tracking.buffer_flush_failed` every fifteen minutes.
// No Redis server is needed here: the tracking connection points at a closed port, so any Redis call
// fails the command.
beforeEach(function (): void {
    TrackingTestSchema::create();
    (require database_path('migrations/2026_09_03_120000_add_redis_buffered_tracking_runtime.php'))->up();
    config([
        'database.redis.tracking' => [
            'url' => null,
            'host' => '127.0.0.1',
            'username' => null,
            'password' => null,
            'port' => 1,
            'database' => 2,
        ],
        'tracking.buffer.enabled' => false,
        'tracking.buffer.connection' => 'tracking',
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists(Tables::TRACKING_COUNTER_FLUSH_BATCHES);
    Schema::dropIfExists(Tables::TRACKING_COUNTER_RUNTIME);
    TrackingTestSchema::drop();
});

test('flush succeeds without touching Redis while the receipt driver is stable', function (): void {
    expect(TrackingCounterRuntime::query()->findOrFail(1)->only(['active_driver', 'transition_state']))
        ->toBe(['active_driver' => 'receipt', 'transition_state' => 'stable'])
        ->and(Artisan::call('cms:tracking-buffer-flush'))->toBe(0)
        ->and(Artisan::output())->toContain('Flushed tracking buckets: 0');
});

test('flush still reads Redis while buckets drain after a switch back to receipts', function (): void {
    TrackingCounterRuntime::query()->whereKey(1)->update([
        'transition_state' => TrackingCounterRuntime::TRANSITION_DRAINING_REDIS,
    ]);

    expect(Artisan::call('cms:tracking-buffer-flush'))->toBe(1);
});
