<?php

namespace App\Jobs;

use App\Services\Tracking\TrackingAggregateWriter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies one committed tracking receipt to the aggregate tables.
 *
 * The job is idempotent, and it is also unique per event id: at most one instance of it may sit on the queue for a
 * receipt at any time. `cms:tracking-dispatch-pending` and the first dispatch both re-enqueue a pending receipt, so
 * without the lock a stopped or missing `tracking` worker let every re-enqueue pile up (3.23M duplicates, 2 GB of
 * shared Redis, on 2026-10-23). With the lock a re-enqueue while the first job is still queued is a no-op, so the
 * queue length is bounded by the number of pending receipts whatever the consumer state.
 *
 * The lock is acquired by `PendingDispatch` when the job is dispatched and released by the worker once the job
 * finished or failed for the last time. The receipt table stays the source of truth: when a lock outlives its job
 * (queue purged by hand), it expires after {@see self::uniqueFor()} and the pending dispatcher enqueues the receipt
 * again.
 */
class ProcessTrackingEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Seconds added on top of the longest re-enqueue backoff so a lock never expires right before the next attempt.
     */
    private const UNIQUE_FOR_MARGIN_SECONDS = 300;

    /**
     * @param string $eventId Receipt event id; also the uniqueness key.
     */
    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('tracking');
    }

    /**
     * Apply the receipt through the aggregate writer.
     *
     * @param TrackingAggregateWriter $writer
     * @return void
     */
    public function handle(TrackingAggregateWriter $writer): void
    {
        $writer->process($this->eventId);
    }

    /**
     * Uniqueness key: one queued job per receipt event id.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return $this->eventId;
    }

    /**
     * Seconds the unique lock is kept when nobody releases it.
     *
     * Defaults to the receipt replay window (`tracking.manual_replay_days`): a receipt is never dispatched after
     * that, and a lock that lives that long keeps exactly one job queued for it even when no worker runs for days.
     * `tracking.pending_dispatch.unique_for_seconds` overrides the default (a shorter lock heals faster after a
     * manual queue purge but allows one extra queued job per receipt per period while no worker runs). The result is
     * never lower than the longest re-enqueue backoff plus a margin, otherwise every backoff step would re-enqueue.
     *
     * @return int
     */
    public function uniqueFor(): int
    {
        $maxBackoff = max(
            (int) config('tracking.pending_dispatch.backoff_seconds', 60),
            (int) config('tracking.pending_dispatch.max_backoff_seconds', 3600)
        );
        $floor = $maxBackoff + self::UNIQUE_FOR_MARGIN_SECONDS;

        $configured = (int) config('tracking.pending_dispatch.unique_for_seconds', 0);
        $lifetime = $configured > 0
            ? $configured
            : max(0, (int) config('tracking.manual_replay_days', 7)) * 86400;

        return max($floor, $lifetime);
    }

    /**
     * Cache repository that holds the unique lock, used both when the job is dispatched and when the worker
     * releases it.
     *
     * Resolution order, first lock capable store wins and nothing here throws:
     *  1. `redis` store when the job's queue connection is a Redis one. Its `lock_connection` is the same
     *     non-evicting Redis connection the queue lives on (config/cache.php), not the evicting cache connection,
     *     and this holds whatever `CACHE_DRIVER` is. Lock and queue then disappear together.
     *  2. The default cache store (file, database, memcached, redis, array...), for `database` and `sync` queues.
     *  3. The in-process `array` store, which only deduplicates inside one process: a last resort for an
     *     unusable store (for instance an `apc` default) so dispatching keeps working without uniqueness.
     *
     * @return CacheRepositoryContract
     */
    public function uniqueVia(): CacheRepositoryContract
    {
        $manager = app('cache');
        $queueConnection = $this->connection ?? config('queue.default');
        $candidates = config("queue.connections.{$queueConnection}.driver") === 'redis'
            ? ['redis', null, 'array']
            : [null, 'array'];

        foreach ($candidates as $name) {
            try {
                $repository = $manager->store($name);
                if ($repository->getStore() instanceof LockProvider) {
                    return $repository;
                }
            } catch (Throwable) {
                // Undefined or misconfigured store: try the next candidate.
            }
        }

        return new CacheRepository(new ArrayStore());
    }
}
