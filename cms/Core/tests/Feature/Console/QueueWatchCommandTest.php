<?php

declare(strict_types=1);

use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Monitoring\RedisMemoryProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * In-memory Redis behind RedisMemoryProbe: INFO memory, SCAN (2 keys per page), MEMORY USAGE,
 * DBSIZE and SET NX. Records every command name.
 */
function fakeQueueWatchRedis(): RedisMemoryProbe
{
    return new class extends RedisMemoryProbe {
        public int $used = 0;
        public int $max = 0;
        /** @var array<string, int> Key => bytes, in SCAN order. */
        public array $keys = [];
        /** @var array<string, true> */
        public array $claims = [];
        public bool $rejectWrites = false;
        /** @var list<string> */
        public array $commands = [];

        protected function raw(string $connection, array $command): mixed
        {
            $this->commands[] = $command[0];

            return match ($command[0]) {
                'INFO' => "# Memory\r\nused_memory:{$this->used}\r\nmaxmemory:{$this->max}\r\nmaxmemory_policy:volatile-lru\r\n",
                'SCAN' => $this->scan((int) $command[1]),
                'MEMORY' => $this->keys[$command[2]] ?? null,
                'DBSIZE' => count($this->keys),
                'SET' => $this->set($command[1]),
            };
        }

        private function scan(int $cursor): array
        {
            $next = $cursor + 2;

            return [$next >= count($this->keys) ? '0' : (string) $next, array_slice(array_keys($this->keys), $cursor, 2)];
        }

        private function set(string $key): ?string
        {
            if ($this->rejectWrites) {
                throw new RuntimeException("OOM command not allowed when used memory > 'maxmemory'.");
            }
            if (isset($this->claims[$key])) {
                return null;
            }
            $this->claims[$key] = true;

            return 'OK';
        }
    };
}

beforeEach(function (): void {
    config()->set('app.name', 'iSPA CMS');
    config()->set('app.url', 'https://cms.example.test');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
    config()->set('queue.failed', ['driver' => 'null']);
    config()->set('core.queue_watch', [
        'enabled' => true,
        'queues' => 'default,tracking:500',
        'max_length' => 1000,
        'growth_checks' => 3,
        'growth_min_length' => 10,
        'failed_jobs_per_hour' => 5,
        'alert_ttl' => 3600,
        'cache_store' => 'array',
        'redis_memory_percent' => 80,
        'redis_memory_critical_percent' => 95,
        'redis_scan_limit' => 3,
    ]);
    config()->set('database.redis.options.prefix', 'ispacms:pro:');
    Cache::store('array')->flush();

    // Faked Redis behind the memory check: no maxmemory (nothing to check) unless a test sets one.
    $this->redis = fakeQueueWatchRedis();
    app()->instance(RedisMemoryProbe::class, $this->redis);

    // Faked Redis queue sizes, keyed by queue name.
    $this->sizes = new ArrayObject(['default' => 0, 'tracking' => 0]);
    $sizes = $this->sizes;
    $queue = Mockery::mock();
    $queue->shouldReceive('size')->andReturnUsing(static fn (?string $name): int => $sizes[$name] ?? 0);
    Queue::shouldReceive('connection')->with('redis')->andReturn($queue);

    // Faked CMSLogger: collect the alert texts.
    $this->alerts = new ArrayObject();
    $this->payloads = new ArrayObject();
    $alerts = $this->alerts;
    $payloads = $this->payloads;
    CMSLogger::shouldReceive('warning')->andReturnUsing(static function (array $payload) use ($alerts, $payloads): void {
        $alerts[] = $payload['message'];
        $payloads[] = $payload;
    });
});

it('alerts when a queue is above its limit and stays quiet below it', function (): void {
    $this->sizes['default'] = 900;
    $this->sizes['tracking'] = 499;
    $this->artisan('cms:queue-watch')->assertSuccessful();
    expect($this->alerts)->toHaveCount(0);

    $this->sizes['tracking'] = 15234;
    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toContain('Queue "tracking" on iSPA CMS (cms.example.test)')
        ->toContain('holds 15,234 jobs, above the limit of 500')
        ->toContain('Trend: +14,735 over the last 1 checks (499 to 15,234)')
        ->not->toContain('password');
});

it('sends one alert per queue per hour', function (): void {
    $this->sizes['tracking'] = 600;

    $this->artisan('cms:queue-watch')->assertSuccessful();
    $this->artisan('cms:queue-watch')->assertSuccessful();
    expect($this->alerts)->toHaveCount(1);

    $this->sizes['default'] = 2000;
    $this->artisan('cms:queue-watch')->assertSuccessful();
    expect($this->alerts)->toHaveCount(2)->and($this->alerts[1])->toContain('Queue "default"');

    $this->travel(61)->minutes();
    $this->artisan('cms:queue-watch')->assertSuccessful();
    expect($this->alerts)->toHaveCount(4);
});

it('alerts when a queue grew at each of the last N checks', function (): void {
    foreach ([12, 20, 30] as $size) {
        $this->sizes['default'] = $size;
        $this->artisan('cms:queue-watch')->assertSuccessful();
    }
    expect($this->alerts)->toHaveCount(0);

    $this->sizes['default'] = 45;
    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toContain('Queue "default"')
        ->toContain('holds 45 jobs, grew at each of the last 3 checks')
        ->toContain('+33 over the last 3 checks (12 to 45)');
});

it('does not count a pause or a small queue as growth', function (): void {
    foreach ([12, 20, 20, 30, 40] as $size) {
        $this->sizes['default'] = $size;
        $this->artisan('cms:queue-watch')->assertSuccessful();
    }
    expect($this->alerts)->toHaveCount(0);

    Cache::store('array')->flush();
    foreach ([1, 2, 3, 4, 5] as $size) {
        $this->sizes['tracking'] = $size;
        $this->artisan('cms:queue-watch')->assertSuccessful();
    }
    expect($this->alerts)->toHaveCount(0);
});

it('prints sizes without alerting or recording history when --no-alert is given', function (): void {
    $this->sizes['tracking'] = 9999;

    $this->artisan('cms:queue-watch', ['--no-alert' => true])
        ->expectsOutputToContain('tracking')
        ->assertSuccessful();

    expect($this->alerts)->toHaveCount(0)
        ->and(Cache::store('array')->get('core:queue_watch:history:redis:tracking'))->toBeNull();
});

it('skips everything on the sync connection', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'queue_watch_missing', 'table' => 'failed_jobs']);
    $untouched = Mockery::mock();
    $untouched->shouldReceive('connection')->never();
    Queue::swap($untouched);
    Log::spy();

    $this->artisan('cms:queue-watch')
        ->expectsOutputToContain('Queue watch skipped (enabled: yes, driver: sync)')
        ->assertSuccessful();

    expect($this->alerts)->toHaveCount(0);
    Log::shouldNotHaveReceived('warning');
});

it('alerts once when the queue connection cannot be read', function (): void {
    $broken = Mockery::mock();
    $broken->shouldReceive('size')->andThrow(new RuntimeException('connection refused to secret-host:6379'));
    Queue::swap(Mockery::mock());
    Queue::shouldReceive('connection')->with('redis')->andReturn($broken);

    $this->artisan('cms:queue-watch')->assertSuccessful();
    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toContain('could not read the size of queue "default" (RuntimeException)')
        ->not->toContain('secret-host');
});

it('alerts when failed_jobs grows faster than the hourly limit', function (): void {
    config()->set('database.connections.queue_watch_failed', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'queue_watch_failed', 'table' => 'failed_jobs']);
    Schema::connection('queue_watch_failed')->create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    $fail = static function (int $count): void {
        for ($i = 0; $i < $count; $i++) {
            DB::connection('queue_watch_failed')->table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'tracking',
                'payload' => '{}', 'exception' => 'boom',
            ]);
        }
    };

    $fail(2);
    $this->artisan('cms:queue-watch')->expectsOutputToContain('Failed jobs: baseline recorded.')->assertSuccessful();

    $fail(5);
    $this->artisan('cms:queue-watch')->assertSuccessful();
    expect($this->alerts)->toHaveCount(0);

    $this->travel(10)->minutes();
    $fail(1);
    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toContain('6 jobs failed on iSPA CMS (cms.example.test) within 10 min (limit 5 per hour)');

    // The baseline restarts after an alert, so the next hour counts from here.
    $fail(3);
    $this->artisan('cms:queue-watch')->assertSuccessful();
    expect($this->alerts)->toHaveCount(1);
});

it('names the biggest keys once per level when shared Redis passes its memory share', function (): void {
    $gb = 1024 ** 3;
    $this->redis->max = 2 * $gb;
    $this->redis->used = (int) (1.5 * $gb);
    $this->redis->keys = [
        'ispacms:pro:laravel_unique_job:App\\Jobs\\ProcessTrackingEvent:101' => 200,
        'ispacms:pro:laravel_unique_job:App\\Jobs\\ProcessTrackingEvent:102' => 200,
        'ispacms:pro:laravel_unique_job:App\\Jobs\\ProcessTrackingEvent:103' => 200,
        'acme_database_menu' => 5000,
        'ispacms:pro:queues:tracking' => (int) (1.5 * $gb),
    ];

    // 75%: below the warning share, so nothing is scanned.
    $this->artisan('cms:queue-watch')->expectsOutputToContain('Redis memory: 1.50 GB of 2.00 GB (75.0%).')->assertSuccessful();
    expect($this->alerts)->toHaveCount(0)
        ->and($this->redis->commands)->not->toContain('SCAN');

    $this->redis->used = (int) (1.7 * $gb);
    $this->artisan('cms:queue-watch')->assertSuccessful();
    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toBe('Redis memory of queue connection "default" is above 80% of maxmemory (seen from iSPA CMS (cms.example.test)). Writes fail on every app sharing this Redis once it is full.')
        ->and($this->payloads[0]['details'])->toContain('Used 1.70 GB of 2.00 GB (85.0%), eviction policy volatile-lru.')
        ->toContain('Biggest keys (sampled 3 of 5 keys in this database):')
        // Not in the 3-key sample, measured because "tracking" is a watched queue.
        ->toContain('  1.50 GB  ispacms:pro:queues:tracking')
        ->toContain('  600 B  3 keys  ispacms:pro:laravel_unique_job:App\\Jobs\\ProcessTrackingEvent:*')
        ->not->toContain('acme_database_menu');

    // Bounded: 3 sampled keys plus the 2 watched queue lists per alert, no more.
    expect(array_count_values($this->redis->commands)['MEMORY'])->toBe(5);

    $this->redis->used = (int) (1.92 * $gb);
    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(2)
        ->and($this->alerts[1])->toContain('is above 95% of maxmemory')
        ->and(array_keys($this->redis->claims))->toBe(['queue_watch:redis_memory:80', 'queue_watch:redis_memory:95']);
});

it('stays quiet when another app on the shared Redis already claimed the memory alert', function (): void {
    $this->redis->max = 1000;
    $this->redis->used = 900;
    $this->redis->claims['queue_watch:redis_memory:80'] = true;

    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(0)
        ->and($this->redis->commands)->not->toContain('SCAN');
});

it('still alerts when the full Redis rejects the alert claim', function (): void {
    $this->redis->max = 1000;
    $this->redis->used = 1000;
    $this->redis->rejectWrites = true;

    $this->artisan('cms:queue-watch')->assertSuccessful();

    expect($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toContain('is above 95% of maxmemory');
});

it('prints the biggest keys without claiming or alerting when --no-alert is given', function (): void {
    $this->redis->max = 1000;
    $this->redis->used = 850;
    $this->redis->keys = ['ispacms:pro:queues:default' => 700];

    $this->artisan('cms:queue-watch', ['--no-alert' => true])
        ->expectsOutputToContain('700 B  ispacms:pro:queues:default')
        ->assertSuccessful();

    expect($this->alerts)->toHaveCount(0)
        ->and($this->redis->claims)->toBe([]);
});
