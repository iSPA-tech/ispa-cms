<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Monitoring\RedisMemoryProbe;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Scheduled watch that warns (CMSLogger) before a queue backlog fills shared Redis:
 * the queue Redis above a share of its maxmemory (naming the biggest keys), a queue
 * above its length limit, a queue that grew at each of the last N checks, or more
 * failed jobs within an hour than allowed. One alert per queue per `alert_ttl`.
 * Settings live in `core.queue_watch` (cms/Core/config/queue_watch.php).
 *
 * Background: on 2026-10-23 a `tracking` queue with no worker reached 3.23M jobs and
 * pushed the shared .215 Redis out of memory before anyone was told.
 */
class QueueWatchCommand extends Command
{
    private const CACHE_PREFIX = 'core:queue_watch:';

    protected $signature = 'cms:queue-watch {--no-alert : Print queue sizes without alerting or recording history}';

    protected $description = 'Alert when a queue backs up or keeps growing, or when failed jobs pile up.';

    /**
     * Run the watch for the default queue connection.
     *
     * @return int Command exit code (always success so the scheduler keeps running).
     */
    public function handle(): int
    {
        $config = (array) config('core.queue_watch', []);
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        if (! ($config['enabled'] ?? false) || in_array($driver, ['sync', 'null'], true)) {
            $this->info(sprintf(
                'Queue watch skipped (enabled: %s, driver: %s).',
                ($config['enabled'] ?? false) ? 'yes' : 'no',
                $driver,
            ));

            return self::SUCCESS;
        }

        $alert = ! $this->option('no-alert');

        if ($driver === 'redis') {
            $this->watchRedisMemory($connection, $config, $alert);
            $this->watchQueues($connection, $config, $alert);
        }

        $this->watchFailedJobs($config, $alert);

        return self::SUCCESS;
    }

    /**
     * Alert when the queue connection's Redis uses more than `redis_memory_percent` of its
     * maxmemory, again at `redis_memory_critical_percent`, naming the biggest keys.
     *
     * Every app on a shared Redis runs this watch, so each level alerts once per `alert_ttl`
     * across all of them (a claim key on that Redis), not once per app. The alert text is
     * fixed per level so the Telegram throttle still folds repeats when Redis rejects the claim.
     *
     * @param string $connection Queue connection name.
     * @param array<string, mixed> $config Queue watch settings.
     * @param bool $alert False for a read-only run (prints the biggest keys instead).
     * @return void
     */
    private function watchRedisMemory(string $connection, array $config, bool $alert): void
    {
        $warnAt = (int) ($config['redis_memory_percent'] ?? 80);
        if ($warnAt <= 0) {
            return;
        }

        $redis = (string) config("queue.connections.{$connection}.connection", 'default');
        $probe = app(RedisMemoryProbe::class);

        try {
            $memory = $probe->memory($redis);
        } catch (Throwable $e) {
            // The queue size reads below alert when the connection itself is failing.
            Log::warning('Queue watch could not read Redis memory', ['connection' => $redis, 'error' => $e::class]);

            return;
        }

        if ($memory['max'] <= 0) {
            $this->info(sprintf('Redis memory: %s used, no maxmemory limit to compare with.', $this->bytes($memory['used'])));

            return;
        }

        $percent = $memory['used'] / $memory['max'] * 100;
        $criticalAt = max($warnAt, (int) ($config['redis_memory_critical_percent'] ?? 95));
        $this->info(sprintf('Redis memory: %s of %s (%.1f%%).', $this->bytes($memory['used']), $this->bytes($memory['max']), $percent));

        if ($percent < $warnAt) {
            return;
        }

        $level = $percent >= $criticalAt ? $criticalAt : $warnAt;
        $ttl = max(60, (int) ($config['alert_ttl'] ?? 3600));
        if ($alert && ! $probe->claim($redis, "queue_watch:redis_memory:{$level}", $ttl)) {
            // Another app on this Redis (or an earlier run) already reported this level.
            return;
        }

        $prefix = (string) config('database.redis.options.prefix', '');
        $queueKeys = array_map(static fn (string $queue): string => "{$prefix}queues:{$queue}", array_keys($this->queueLimits($connection, $config)));

        try {
            $keys = $probe->biggestKeys($redis, max(1, (int) ($config['redis_scan_limit'] ?? 5000)), 5, $queueKeys);
        } catch (Throwable $e) {
            $keys = null;
            Log::warning('Queue watch could not list the biggest Redis keys', ['connection' => $redis, 'error' => $e::class]);
        }

        $details = [sprintf(
            'Used %s of %s (%.1f%%), eviction policy %s.',
            $this->bytes($memory['used']),
            $this->bytes($memory['max']),
            $percent,
            $memory['policy'] !== '' ? $memory['policy'] : 'unknown',
        )];

        if ($keys === null) {
            $details[] = 'The biggest keys could not be listed.';
        } else {
            $details[] = sprintf('Biggest keys (sampled %s of %s keys in this database):', number_format($keys['scanned']), number_format($keys['keys']));
            foreach ($keys['top'] as $row) {
                $details[] = sprintf('  %s  %s', $this->bytes($row['bytes']), $row['key']);
            }
            if ($keys['groups'] !== []) {
                $details[] = 'Biggest key groups in the sample:';
                foreach ($keys['groups'] as $row) {
                    $details[] = sprintf('  %s  %s keys  %s', $this->bytes($row['bytes']), number_format($row['keys']), $row['group']);
                }
            }
        }

        if (! $alert) {
            $this->line(implode(PHP_EOL, $details));

            return;
        }

        CMSLogger::warning([
            'message' => sprintf(
                'Redis memory of queue connection "%s" is above %d%% of maxmemory (seen from %s). Writes fail on every app sharing this Redis once it is full.',
                $redis,
                $level,
                $this->appLabel(),
            ),
            'details' => implode("\n", $details),
        ]);
    }

    /**
     * Read every watched queue, record its size history and alert on backlog or growth.
     *
     * @param string $connection Queue connection name.
     * @param array<string, mixed> $config Queue watch settings.
     * @param bool $alert False for a read-only run.
     * @return void
     */
    private function watchQueues(string $connection, array $config, bool $alert): void
    {
        $checks = max(1, (int) ($config['growth_checks'] ?? 6));
        $minLength = max(0, (int) ($config['growth_min_length'] ?? 100));
        $rows = [];

        foreach ($this->queueLimits($connection, $config) as $queue => $limit) {
            try {
                $size = (int) Queue::connection($connection)->size($queue);
            } catch (Throwable $e) {
                Log::warning('Queue watch could not read a queue size', ['queue' => $queue, 'error' => $e::class]);
                $this->notify($config, $alert, "unreadable:{$connection}", sprintf(
                    'Queue watch on %s could not read the size of queue "%s" (%s).',
                    $this->appLabel(),
                    $queue,
                    $e::class,
                ));

                // The connection itself is failing; the remaining queues would only repeat this.
                break;
            }

            $history = $this->recordSize($config, $connection, $queue, $size, $checks, $alert);
            $over = $size > $limit;
            $growing = $this->isGrowing($history, $checks, $minLength);
            $rows[] = [$queue, number_format($size), number_format($limit), $this->trend($history), $over || $growing ? 'ALERT' : 'ok'];

            if (! $over && ! $growing) {
                continue;
            }

            $reasons = array_filter([
                $over ? 'above the limit of ' . number_format($limit) : null,
                $growing ? "grew at each of the last {$checks} checks" : null,
            ]);

            $this->notify($config, $alert, "queue:{$connection}:{$queue}", sprintf(
                'Queue "%s" on %s (connection %s) holds %s jobs, %s. Trend: %s. Make sure a worker runs for this queue.',
                $queue,
                $this->appLabel(),
                $connection,
                number_format($size),
                implode(' and ', $reasons),
                $this->trend($history),
            ));
        }

        $this->table(['Queue', 'Jobs', 'Limit', 'Trend', 'Status'], $rows);
    }

    /**
     * Alert when more failed jobs than allowed landed in failed_jobs within one hour.
     *
     * Compares max(id) with a baseline taken at most an hour ago (cheap primary-key read,
     * no scan of failed_jobs). The baseline restarts after an alert and once it is an hour old.
     *
     * @param array<string, mixed> $config Queue watch settings.
     * @param bool $alert False for a read-only run.
     * @return void
     */
    private function watchFailedJobs(array $config, bool $alert): void
    {
        $limit = (int) ($config['failed_jobs_per_hour'] ?? 0);
        $failed = (array) config('queue.failed', []);

        if ($limit <= 0 || ! in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)) {
            return;
        }

        try {
            $maxId = (int) DB::connection($failed['database'] ?? null)
                ->table((string) ($failed['table'] ?? 'failed_jobs'))
                ->max('id');
        } catch (Throwable $e) {
            Log::warning('Queue watch could not read failed jobs', ['error' => $e::class]);

            return;
        }

        $key = self::CACHE_PREFIX . 'failed_jobs:baseline';
        $baseline = $this->cacheGet($config, $key);
        $now = now()->getTimestamp();
        $current = ['id' => $maxId, 'at' => $now];

        if (! is_array($baseline) || $maxId < (int) ($baseline['id'] ?? 0)) {
            // First run, or failed_jobs was emptied: start counting from here.
            $alert && $this->cachePut($config, $key, $current, 7200);
            $this->info('Failed jobs: baseline recorded.');

            return;
        }

        $added = $maxId - (int) $baseline['id'];
        $minutes = max(1, intdiv($now - (int) $baseline['at'], 60));
        $this->info(sprintf('Failed jobs: %d new in the last %d min (limit %d per hour).', $added, $minutes, $limit));

        if ($added > $limit) {
            $this->notify($config, $alert, 'failed_jobs', sprintf(
                '%s jobs failed on %s within %d min (limit %s per hour). Inspect them with "php artisan queue:failed".',
                number_format($added),
                $this->appLabel(),
                $minutes,
                number_format($limit),
            ));
            $alert && $this->cachePut($config, $key, $current, 7200);
        } elseif ($now - (int) $baseline['at'] >= 3600) {
            $alert && $this->cachePut($config, $key, $current, 7200);
        }
    }

    /**
     * Watched queues with their length limits: configured entries ("name" or "name:limit")
     * plus the connection's own default queue.
     *
     * @param string $connection Queue connection name.
     * @param array<string, mixed> $config Queue watch settings.
     * @return array<string, int> Limit per queue name.
     */
    private function queueLimits(string $connection, array $config): array
    {
        $default = max(1, (int) ($config['max_length'] ?? 10000));
        $entries = $config['queues'] ?? [];
        $entries = is_string($entries) ? explode(',', $entries) : (array) $entries;
        $entries[] = (string) config("queue.connections.{$connection}.queue", 'default');

        $limits = [];
        foreach ($entries as $entry) {
            [$name, $limit] = array_pad(explode(':', trim((string) $entry), 2), 2, null);
            $name = trim((string) $name);

            if ($name === '' || (isset($limits[$name]) && $limit === null)) {
                continue;
            }

            $limits[$name] = (int) $limit > 0 ? (int) $limit : $default;
        }

        return $limits;
    }

    /**
     * Append the current size to the queue's recent history (stored only on alerting runs).
     *
     * @param array<string, mixed> $config Queue watch settings.
     * @param string $connection Queue connection name.
     * @param string $queue Queue name.
     * @param int $size Current queue size.
     * @param int $checks Number of growth checks to keep (history holds checks + 1 sizes).
     * @param bool $store Whether to persist the history.
     * @return list<int> Sizes, oldest first, current size last.
     */
    private function recordSize(array $config, string $connection, string $queue, int $size, int $checks, bool $store): array
    {
        $key = self::CACHE_PREFIX . "history:{$connection}:{$queue}";
        $history = $this->cacheGet($config, $key);
        $history = array_values(array_map('intval', is_array($history) ? $history : []));
        $history[] = $size;
        $history = array_slice($history, -($checks + 1));

        // Expires when the 5-minute schedule stops, so a stale run never counts as growth.
        $store && $this->cachePut($config, $key, $history, ($checks + 2) * 300);

        return $history;
    }

    /**
     * True when the history is full, strictly increasing and the latest size reached the floor.
     *
     * @param list<int> $history Sizes, oldest first.
     * @param int $checks Required number of consecutive increases.
     * @param int $minLength Minimum latest size for a growth alert.
     * @return bool
     */
    private function isGrowing(array $history, int $checks, int $minLength): bool
    {
        if (count($history) < $checks + 1 || end($history) < $minLength) {
            return false;
        }

        for ($i = 1, $n = count($history); $i < $n; $i++) {
            if ($history[$i] <= $history[$i - 1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Human-readable trend over the recorded history.
     *
     * @param list<int> $history Sizes, oldest first.
     * @return string
     */
    private function trend(array $history): string
    {
        if (count($history) < 2) {
            return 'first check';
        }

        $first = $history[0];
        $last = $history[count($history) - 1];

        return sprintf(
            '%s%s over the last %d checks (%s to %s)',
            $last >= $first ? '+' : '-',
            number_format(abs($last - $first)),
            count($history) - 1,
            number_format($first),
            number_format($last),
        );
    }

    /**
     * Send one CMSLogger warning per key per `alert_ttl`.
     *
     * @param array<string, mixed> $config Queue watch settings.
     * @param bool $alert False for a read-only run.
     * @param string $key Dedupe key (queue or check name).
     * @param string $message Alert text (app, queue, size, trend; never connection secrets).
     * @return void
     */
    private function notify(array $config, bool $alert, string $key, string $message): void
    {
        if (! $alert) {
            return;
        }

        try {
            $first = $this->store($config)->add(
                self::CACHE_PREFIX . 'alert:' . $key,
                true,
                max(60, (int) ($config['alert_ttl'] ?? 3600)),
            );
        } catch (Throwable) {
            // A failing dedupe store (e.g. Redis out of memory) must not silence the alert.
            $first = true;
        }

        if ($first) {
            CMSLogger::warning(['message' => $message]);
        }
    }

    /**
     * Byte count in binary units, e.g. "1.95 GB".
     *
     * @param int $bytes Byte count.
     * @return string
     */
    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return $power === 0 ? "{$bytes} B" : sprintf('%.2f %s', $bytes / (1024 ** $power), $units[$power]);
    }

    /**
     * App name and host, so alerts from apps sharing one Redis can be told apart.
     *
     * @return string
     */
    private function appLabel(): string
    {
        $name = (string) config('app.name', 'iSPA CMS');
        $host = parse_url((string) config('app.url', ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? "{$name} ({$host})" : $name;
    }

    /**
     * Cache store for history and dedupe.
     *
     * @param array<string, mixed> $config Queue watch settings.
     * @return Repository
     */
    private function store(array $config): Repository
    {
        $store = $config['cache_store'] ?? null;

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    /**
     * Read a cache value; a failing store reads as empty.
     *
     * @param array<string, mixed> $config Queue watch settings.
     * @param string $key Cache key.
     * @return mixed
     */
    private function cacheGet(array $config, string $key): mixed
    {
        try {
            return $this->store($config)->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write a cache value; a failing store is ignored (the watch keeps alerting).
     *
     * @param array<string, mixed> $config Queue watch settings.
     * @param string $key Cache key.
     * @param mixed $value Value to store.
     * @param int $ttl Seconds to keep it.
     * @return void
     */
    private function cachePut(array $config, string $key, mixed $value, int $ttl): void
    {
        try {
            $this->store($config)->put($key, $value, $ttl);
        } catch (Throwable) {
            // Missing history only delays growth detection.
        }
    }
}
