<?php

namespace App\Console\Commands;

use App\Models\Tracking\TrackingCounterRuntime;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingBufferedCounterStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SwitchTrackingCounterDriverCommand extends Command
{
    protected $signature = 'cms:tracking-counter-switch
                            {driver : receipt|redis}
                            {--approve : Approve the counter owner transition}
                            {--operator= : Operator identity for the audit row}
                            {--reason= : Reason for the transition}';

    protected $description = 'Switch tracking view counters between receipts and Redis without dropping buffered data';

    public function handle(TrackingBufferedCounterStore $store): int
    {
        $driver = strtolower(trim((string) $this->argument('driver')));
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));
        if (
            !$this->option('approve')
            || !in_array($driver, [
                TrackingCounterRuntime::DRIVER_RECEIPT,
                TrackingCounterRuntime::DRIVER_REDIS,
            ], true)
            || $operator === ''
            || $reason === ''
        ) {
            $this->error('Driver switch requires receipt|redis, --approve, --operator and --reason.');

            return self::FAILURE;
        }
        if ($driver === TrackingCounterRuntime::DRIVER_REDIS && !config('tracking.buffer.enabled')) {
            $this->error('Redis counter capability gate is disabled.');

            return self::FAILURE;
        }
        if ($driver === TrackingCounterRuntime::DRIVER_REDIS && !$store->usesDedicatedDatabase()) {
            $this->error('Redis counters require a dedicated Redis database separate from the application cache.');

            return self::FAILURE;
        }

        $lock = Cache::lock('tracking:counter-driver-switch', 300);
        if (!$lock->get()) {
            $this->error('Another tracking counter switch is running.');

            return self::FAILURE;
        }

        try {
            if ($driver === TrackingCounterRuntime::DRIVER_REDIS) {
                return $this->switchToRedis($store, $operator, $reason);
            }

            return $this->switchToReceipt($store, $operator, $reason);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function switchToRedis(
        TrackingBufferedCounterStore $store,
        string $operator,
        string $reason
    ): int {
        $tracking = TrackingRuntimeState::query()->findOrFail(1);
        if (
            $tracking->mode !== TrackingRuntimeState::MODE_AGGREGATE
            || $tracking->processing_state !== TrackingRuntimeState::PROCESSING_OPEN
        ) {
            $this->error('Redis counters require open aggregate tracking runtime.');

            return self::FAILURE;
        }
        if (!$store->durable()) {
            $this->error('Redis counter store is unavailable or does not satisfy the AOF durability gate.');

            return self::FAILURE;
        }

        $runtime = TrackingCounterRuntime::query()->findOrFail(1);
        if ($runtime->active_driver === TrackingCounterRuntime::DRIVER_REDIS) {
            $store->activateRedis((int) $runtime->epoch, (int) $tracking->write_generation);
            Artisan::call('cms:tracking-buffer-flush');
            $this->line("driver=redis transition={$runtime->fresh()->transition_state}");

            return self::SUCCESS;
        }

        $epoch = (int) $runtime->epoch + 1;
        $store->activateRedis($epoch, (int) $tracking->write_generation);
        try {
            DB::transaction(static function () use ($epoch, $operator, $reason): void {
                $runtime = TrackingCounterRuntime::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                $pending = TrackingEventReceipt::query()
                    ->where('status', TrackingEventReceipt::STATUS_PENDING)
                    ->exists();
                $runtime->forceFill([
                    'active_driver' => TrackingCounterRuntime::DRIVER_REDIS,
                    'transition_state' => $pending
                        ? TrackingCounterRuntime::TRANSITION_DRAINING_RECEIPTS
                        : TrackingCounterRuntime::TRANSITION_STABLE,
                    'epoch' => $epoch,
                    'changed_at' => CarbonImmutable::now('UTC'),
                    'changed_by' => $operator,
                    'change_reason' => $reason,
                ])->save();
            });
        } catch (Throwable $exception) {
            $store->activateReceipt();
            throw $exception;
        }

        $this->line('driver=redis transition=' . TrackingCounterRuntime::query()->value('transition_state'));

        return self::SUCCESS;
    }

    private function switchToReceipt(
        TrackingBufferedCounterStore $store,
        string $operator,
        string $reason
    ): int {
        $store->activateReceipt();
        $buckets = $store->sealAll();
        DB::transaction(static function () use ($buckets, $operator, $reason): void {
            $runtime = TrackingCounterRuntime::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $runtime->forceFill([
                'active_driver' => TrackingCounterRuntime::DRIVER_RECEIPT,
                'transition_state' => $buckets === []
                    ? TrackingCounterRuntime::TRANSITION_STABLE
                    : TrackingCounterRuntime::TRANSITION_DRAINING_REDIS,
                'changed_at' => CarbonImmutable::now('UTC'),
                'changed_by' => $operator,
                'change_reason' => $reason,
            ])->save();
        });

        if ($buckets !== [] && Artisan::call('cms:tracking-buffer-flush', ['--all' => true]) !== 0) {
            $this->error('Redis buckets remain pending after the receipt fence was activated.');

            return self::FAILURE;
        }
        $this->line('driver=receipt transition=' . TrackingCounterRuntime::query()->value('transition_state'));

        return self::SUCCESS;
    }
}
