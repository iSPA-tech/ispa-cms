<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTrackingEvent;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchPendingTrackingEventsCommand extends Command
{
    protected $signature = 'cms:tracking-dispatch-pending
                            {--after-id=0 : Resume after this monotonic receipt ID}
                            {--batch= : Maximum rows to scan per query}
                            {--max-runtime= : Maximum runtime in seconds}';

    protected $description = 'Redispatch committed pending tracking receipts in bounded batches';

    public function handle(): int
    {
        $batch = max(1, min(
            5000,
            (int) ($this->option('batch') ?: config('tracking.pending_dispatch.batch', 500))
        ));
        $maxRuntime = max(1, (int) (
            $this->option('max-runtime')
            ?: config('tracking.pending_dispatch.max_runtime_seconds', 50)
        ));
        $cursor = max(0, (int) $this->option('after-id'));
        $startedAt = microtime(true);
        $lock = Cache::lock(
            (string) config('tracking.pending_dispatch.lock', 'tracking:pending-dispatch'),
            $maxRuntime + 10
        );

        if (!$lock->get()) {
            $this->error('Pending tracking dispatcher is already running.');

            return self::FAILURE;
        }

        try {
            do {
                $state = TrackingRuntimeState::query()->whereKey(1)->firstOrFail();
                $now = CarbonImmutable::now('UTC');
                $receiptsQuery = TrackingEventReceipt::query()
                    ->where('status', TrackingEventReceipt::STATUS_PENDING)
                    ->where('id', '>', $cursor);

                if ($state->processing_state === TrackingRuntimeState::PROCESSING_HELD) {
                    $rotation = TrackingGenerationRun::query()
                        ->whereNotNull('active_rotation_key')
                        ->firstOrFail();
                    $receiptsQuery->where(static function ($query) use ($rotation, $state, $now): void {
                        $query->where('write_generation', $rotation->closed_generation)
                            ->orWhere(static function ($query) use ($state, $now): void {
                                $query->where('write_generation', $state->write_generation)
                                    ->where('replay_until', '<', $now);
                            });
                    });
                }

                $receipts = $receiptsQuery
                    ->orderBy('id')
                    ->limit($batch)
                    ->get();

                if ($receipts->isEmpty()) {
                    break;
                }

                foreach ($receipts as $receipt) {
                    if (microtime(true) - $startedAt >= $maxRuntime) {
                        $this->line("Stopped at receipt ID {$cursor} after runtime limit.");

                        return self::SUCCESS;
                    }

                    $cursor = (int) $receipt->id;
                    $now = CarbonImmutable::now('UTC');

                    if ($now->greaterThan($receipt->replay_until)) {
                        $this->quarantineExpired((int) $receipt->id, $now);
                        continue;
                    }

                    $backoff = (int) config(
                        'tracking.pending_dispatch.backoff_seconds',
                        60
                    );
                    if (
                        $receipt->last_enqueued_at !== null
                        && $receipt->last_enqueued_at->greaterThan($now->subSeconds(
                            TrackingEventReceipt::pendingDispatchBackoffSeconds((int) $receipt->dispatch_attempts, $backoff)
                        ))
                    ) {
                        continue;
                    }

                    if (!TrackingEventReceipt::claimPendingDispatch(
                        (string) $receipt->event_id,
                        $now,
                        $backoff
                    )) {
                        continue;
                    }

                    // ProcessTrackingEvent is unique per event id: while an earlier job is still queued this
                    // dispatch is a no-op, so a missing worker cannot grow the queue past one job per receipt.
                    ProcessTrackingEvent::dispatch($receipt->event_id)->onQueue('tracking');
                }
            } while ($receipts->count() === $batch);

            $this->line("Pending tracking cursor: {$cursor}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('tracking.pending_dispatch_failed', [
                'cursor' => $cursor,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->error('Pending tracking dispatch failed.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function quarantineExpired(int $receiptId, CarbonImmutable $now): void
    {
        DB::transaction(static function () use ($receiptId, $now): void {
            $receipt = TrackingEventReceipt::query()
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->first();
            if (
                $receipt === null
                || $receipt->status !== TrackingEventReceipt::STATUS_PENDING
                || !$now->greaterThan($receipt->replay_until)
            ) {
                return;
            }

            $receipt->forceFill([
                'status' => TrackingEventReceipt::STATUS_QUARANTINED,
                'quarantine_reason' => 'replay_expired',
                'processed_at' => $now,
            ])->save();
        });
    }
}
