<?php

use App\Jobs\ProcessTrackingEvent;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingAggregateWriter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Cache::flush();
    uniqueJobSetNow('2026-07-23 10:00:00 UTC');
    config([
        'tracking.pending_dispatch.batch' => 10,
        'tracking.pending_dispatch.backoff_seconds' => 60,
        'tracking.pending_dispatch.max_backoff_seconds' => 3600,
        'tracking.pending_dispatch.max_runtime_seconds' => 10,
        'tracking.pending_dispatch.unique_for_seconds' => null,
        'tracking.pending_dispatch.lock' => 'tracking:test-unique-dispatch',
    ]);
});

afterEach(function (): void {
    uniqueJobSetNow(null);
    Schema::dropIfExists('jobs');
    TrackingTestSchema::drop();
});

/**
 * Freeze (or unfreeze) the clock for both Carbon flavours, the cache lock store reads one and the receipts the other.
 */
function uniqueJobSetNow(?string $time): void
{
    CarbonImmutable::setTestNow($time);
    Carbon::setTestNow($time);
}

/**
 * Switch the app to the real `database` queue driver on the test database, so pushes are really stored.
 */
function uniqueJobUseDatabaseQueue(): void
{
    Schema::create('jobs', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config(['queue.default' => 'database']);
}

/**
 * Event ids of the jobs currently stored on the `tracking` database queue.
 *
 * @return list<string>
 */
function uniqueJobQueuedEventIds(): array
{
    return DB::table('jobs')
        ->where('queue', 'tracking')
        ->pluck('payload')
        ->map(static fn (string $payload): string => unserialize(
            json_decode($payload, true)['data']['command']
        )->eventId)
        ->sort()
        ->values()
        ->all();
}

/**
 * Play the `tracking` queue worker: run every stored job once through the real job handler.
 */
function uniqueJobDrainQueue(): int
{
    $ran = 0;
    while (($job = app('queue')->connection('database')->pop('tracking')) !== null) {
        $job->fire();
        $ran++;
    }

    return $ran;
}

/**
 * Replace the aggregate writer with a stub that only records which receipts it was asked to process.
 *
 * @return ArrayObject<int, string>
 */
function uniqueJobStubWriter(): ArrayObject
{
    $processed = new ArrayObject();
    $stub = Mockery::mock(TrackingAggregateWriter::class);
    $stub->shouldReceive('process')->andReturnUsing(
        static function (string $eventId) use ($processed): void {
            $processed[] = $eventId;
        }
    );
    app()->instance(TrackingAggregateWriter::class, $stub);

    return $processed;
}

function uniqueJobPendingReceipt(array $overrides = []): TrackingEventReceipt
{
    return TrackingEventReceipt::query()->create(array_replace([
        'event_id' => (string) Illuminate\Support\Str::ulid(),
        'envelope_version' => 1,
        'write_generation' => 1,
        'generation_mode' => TrackingRuntimeState::MODE_LEGACY,
        'dispatched_at' => CarbonImmutable::now('UTC')->subMinute(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinute(),
        'replay_until' => CarbonImmutable::now('UTC')->addDays(30),
        'event_date' => '2026-07-23',
        'counter_deltas' => [
            'legacy_views' => 1,
            'page_views' => 1,
            'scans' => 0,
            'place_scans' => 0,
            'item_scans' => 0,
            'downloads' => 0,
            'site_sessions' => 1,
            'site_visitors' => 1,
            'target_sessions' => 0,
            'target_visitors' => 0,
        ],
        'throttle_contract_version' => 1,
        'throttle_decided_at' => CarbonImmutable::now('UTC')->subMinute(),
        'status' => TrackingEventReceipt::STATUS_PENDING,
        'expires_at' => CarbonImmutable::now('UTC')->addDays(32),
    ], $overrides));
}

test('the job is unique per receipt event id', function (): void {
    $job = new ProcessTrackingEvent('01JUNIQUEEVENT00000000001');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('01JUNIQUEEVENT00000000001')
        ->and(UniqueLock::getKey($job))
        ->toBe(UniqueLock::getKey(new ProcessTrackingEvent('01JUNIQUEEVENT00000000001')))
        ->not->toBe(UniqueLock::getKey(new ProcessTrackingEvent('01JUNIQUEEVENT00000000002')));
});

test('dispatching the same event id while its job is still queued stores one job', function (): void {
    uniqueJobUseDatabaseQueue();
    $skipped = 0;
    Event::listen(UniqueJobSkipped::class, static function () use (&$skipped): void {
        $skipped++;
    });

    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000002')->onQueue('tracking');

    expect(uniqueJobQueuedEventIds())->toBe([
        '01JUNIQUEEVENT00000000001',
        '01JUNIQUEEVENT00000000002',
    ])->and($skipped)->toBe(2);
});

test('a new dispatch is accepted once the worker ran the job', function (): void {
    uniqueJobUseDatabaseQueue();
    $processed = uniqueJobStubWriter();

    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');

    expect(uniqueJobDrainQueue())->toBe(1)
        ->and($processed->getArrayCopy())->toBe(['01JUNIQUEEVENT00000000001'])
        ->and(DB::table('jobs')->count())->toBe(0);

    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');

    expect(uniqueJobQueuedEventIds())->toBe(['01JUNIQUEEVENT00000000001']);
});

test('a job that failed for good does not leave its lock behind', function (): void {
    uniqueJobUseDatabaseQueue();

    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    expect(DB::table('jobs')->count())->toBe(1);

    $job = app('queue')->connection('database')->pop('tracking');
    $job->fail(new RuntimeException('writer exploded'));

    expect(DB::table('jobs')->count())->toBe(0);

    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');

    expect(uniqueJobQueuedEventIds())->toBe(['01JUNIQUEEVENT00000000001']);
});

test('the sync queue runs every dispatch inline and never blocks the next one', function (): void {
    config(['queue.default' => 'sync']);
    $processed = uniqueJobStubWriter();

    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');

    expect($processed->getArrayCopy())->toBe([
        '01JUNIQUEEVENT00000000001',
        '01JUNIQUEEVENT00000000001',
    ])->and((new ProcessTrackingEvent('01JUNIQUEEVENT00000000001'))
        ->uniqueVia()
        ->lock(UniqueLock::getKey(new ProcessTrackingEvent('01JUNIQUEEVENT00000000001')), 5)
        ->get())->toBeTrue();
});

test('pending poller with no consumer keeps exactly one queued job per pending receipt', function (): void {
    uniqueJobUseDatabaseQueue();
    $receipts = [
        uniqueJobPendingReceipt(),
        uniqueJobPendingReceipt(),
        uniqueJobPendingReceipt(),
    ];
    $expected = collect($receipts)->pluck('event_id')->sort()->values()->all();

    // Twelve runs two hours apart: every run is past the longest backoff, so every run claims every receipt.
    for ($run = 1; $run <= 12; $run++) {
        expect(Artisan::call('cms:tracking-dispatch-pending'))->toBe(0);
        uniqueJobSetNow(CarbonImmutable::now('UTC')->addHours(2)->toDateTimeString() . ' UTC');
    }

    expect(uniqueJobQueuedEventIds())->toBe($expected);
    foreach ($receipts as $receipt) {
        // The backoff bookkeeping still counts every claim, only the queue stays bounded.
        expect($receipt->refresh()->dispatch_attempts)->toBe(12);
    }

    // A consumer finally shows up: the queue drains, the lock is released and the next poll re-enqueues.
    uniqueJobStubWriter();
    expect(uniqueJobDrainQueue())->toBe(3)
        ->and(DB::table('jobs')->count())->toBe(0);

    expect(Artisan::call('cms:tracking-dispatch-pending'))->toBe(0)
        ->and(uniqueJobQueuedEventIds())->toBe($expected);
});

test('a lock that outlived its purged job expires after uniqueFor', function (): void {
    $job = new ProcessTrackingEvent('01JUNIQUEEVENT00000000001');
    $lock = new UniqueLock($job->uniqueVia());

    expect($lock->acquire($job))->toBeTrue()
        ->and($lock->acquire(new ProcessTrackingEvent('01JUNIQUEEVENT00000000001')))->toBeFalse();

    uniqueJobSetNow(CarbonImmutable::now('UTC')->addSeconds($job->uniqueFor() - 1)->toDateTimeString() . ' UTC');
    expect($lock->acquire(new ProcessTrackingEvent('01JUNIQUEEVENT00000000001')))->toBeFalse();

    uniqueJobSetNow(CarbonImmutable::now('UTC')->addSeconds(2)->toDateTimeString() . ' UTC');
    expect($lock->acquire(new ProcessTrackingEvent('01JUNIQUEEVENT00000000001')))->toBeTrue();
});

test('uniqueFor defaults to the replay window and never drops below the longest backoff', function (): void {
    $job = new ProcessTrackingEvent('01JUNIQUEEVENT00000000001');

    config(['tracking.manual_replay_days' => 7]);
    expect($job->uniqueFor())->toBe(7 * 86400);

    config(['tracking.pending_dispatch.unique_for_seconds' => 7200]);
    expect($job->uniqueFor())->toBe(7200);

    config(['tracking.pending_dispatch.unique_for_seconds' => 10]);
    expect($job->uniqueFor())->toBe(3600 + 300);

    config([
        'tracking.pending_dispatch.unique_for_seconds' => null,
        'tracking.manual_replay_days' => 0,
        'tracking.pending_dispatch.max_backoff_seconds' => 7200,
    ]);
    expect($job->uniqueFor())->toBe(7200 + 300);
});

test('a redis queue keeps its locks on the queue redis connection whatever the cache driver is', function (): void {
    config(['queue.default' => 'redis', 'cache.default' => 'array']);

    $store = (new ProcessTrackingEvent('01JUNIQUEEVENT00000000001'))->uniqueVia()->getStore();
    $lockConnection = new ReflectionProperty(RedisStore::class, 'lockConnection');

    expect($store)->toBeInstanceOf(RedisStore::class)
        // The lock connection is the connection the redis queue lives on, not the evicting cache connection.
        ->and($lockConnection->getValue($store))->toBe(config('queue.connections.redis.connection'))
        ->and(config('cache.stores.redis.lock_connection'))->toBe(config('queue.connections.redis.connection'))
        ->and(config('cache.stores.redis.connection'))->not->toBe(config('queue.connections.redis.connection'));
});

test('non redis queues lock through the default cache store', function (): void {
    config(['queue.default' => 'database', 'cache.default' => 'array']);
    expect((new ProcessTrackingEvent('01JUNIQUEEVENT00000000001'))->uniqueVia()->getStore())
        ->toBeInstanceOf(ArrayStore::class);

    config(['queue.default' => 'sync']);
    expect((new ProcessTrackingEvent('01JUNIQUEEVENT00000000001'))->uniqueVia()->getStore())
        ->toBeInstanceOf(ArrayStore::class);
});

test('an unusable default cache store falls back without throwing at dispatch', function (): void {
    config(['queue.default' => 'sync']);
    $processed = uniqueJobStubWriter();

    foreach (['apc', 'store-that-does-not-exist'] as $default) {
        config(['cache.default' => $default]);
        Cache::forgetDriver($default);

        expect((new ProcessTrackingEvent('01JUNIQUEEVENT00000000001'))->uniqueVia()->getStore())
            ->toBeInstanceOf(ArrayStore::class);
        ProcessTrackingEvent::dispatch('01JUNIQUEEVENT00000000001')->onQueue('tracking');
    }

    expect($processed->getArrayCopy())->toHaveCount(2);
});
