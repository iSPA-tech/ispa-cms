<?php

namespace App\Console\Commands;

use App\Models\Tracking\TrackingCounterRuntime;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingBufferedAggregateWriter;
use App\Services\Tracking\TrackingBufferedCounterStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FlushTrackingCounterBucketsCommand extends Command
{
    protected $signature = 'cms:tracking-buffer-flush {--all : Seal and flush every registered bucket}';

    protected $description = 'Seal Redis tracking counter buckets and apply them idempotently';

    public function handle(
        TrackingBufferedCounterStore $store,
        TrackingBufferedAggregateWriter $writer
    ): int {
        try {
            $runtime = TrackingCounterRuntime::query()->findOrFail(1);
            // With the capability gate off, Redis holds buckets only while it still owns the counters or drains
            // after a switch back to receipts. Otherwise there is nothing to flush, and a site without Redis
            // (or without its password) must not need a connection.
            if (
                !config('tracking.buffer.enabled', false)
                && $runtime->active_driver !== TrackingCounterRuntime::DRIVER_REDIS
                && $runtime->transition_state !== TrackingCounterRuntime::TRANSITION_DRAINING_REDIS
            ) {
                $this->line('Flushed tracking buckets: 0');

                return self::SUCCESS;
            }
            if ($runtime->active_driver === TrackingCounterRuntime::DRIVER_REDIS) {
                $generation = (int) TrackingRuntimeState::query()->whereKey(1)->value('write_generation');
                if (!$store->usesDedicatedDatabase()) {
                    throw new RuntimeException('Tracking Redis shares the application cache database.');
                }
                if (!$store->controlMatches((int) $runtime->epoch, $generation)) {
                    throw new RuntimeException('Tracking Redis control fence does not match the database runtime.');
                }
            }

            $timezone = (string) config('tracking.analytics_timezone');
            $buckets = $this->option('all')
                ? $store->sealAll()
                : $store->sealDue(CarbonImmutable::now($timezone)->subMinutes(
                    (int) config('tracking.buffer.seal_after_minutes', 70)
                ));
            foreach ($buckets as $bucketId) {
                $lock = Cache::lock(
                    'tracking:buffer:flush:' . hash('sha256', $bucketId),
                    (int) config('tracking.buffer.flush_lock_seconds', 300)
                );
                if (!$lock->get()) {
                    continue;
                }
                try {
                    $writer->apply($store->snapshot($bucketId));
                    $store->delete($bucketId);
                } finally {
                    $lock->release();
                }
            }

            $this->completeTransition($store);

            $this->line('Flushed tracking buckets: ' . count($buckets));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('tracking.buffer_flush_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function completeTransition(TrackingBufferedCounterStore $store): void
    {
        $runtime = TrackingCounterRuntime::query()->findOrFail(1);
        $complete = match ($runtime->transition_state) {
            TrackingCounterRuntime::TRANSITION_DRAINING_RECEIPTS =>
                !TrackingEventReceipt::query()
                    ->where('status', TrackingEventReceipt::STATUS_PENDING)
                    ->exists(),
            TrackingCounterRuntime::TRANSITION_DRAINING_REDIS =>
                $store->registeredBuckets() === [],
            default => false,
        };
        if ($complete) {
            $runtime->forceFill([
                'transition_state' => TrackingCounterRuntime::TRANSITION_STABLE,
                'updated_at' => CarbonImmutable::now('UTC'),
            ])->save();
        }
    }
}
