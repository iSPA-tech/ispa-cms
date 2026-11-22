<?php

namespace App\Console\Commands;

use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingCounterRuntime;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingAggregateReconciler;
use App\Services\Tracking\TrackingGenerationRotationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;
use Throwable;

class ReconcileTrackingAggregatesCommand extends Command
{
    protected $signature = 'cms:tracking-reconcile
                            {--max-id= : Fixed maximum tracks ID for raw equality}
                            {--rotate-generation : Start or resume a generation rotation}
                            {--auto-release : Release the rotation once its manifest is signed}
                            {--release-rotation= : Operator-approved rotation ID}
                            {--manifest-checksum= : Exact signed manifest checksum}
                            {--max-hold-seconds=300 : Rotation hold budget}
                            {--max-runtime=300 : Runtime budget}';

    protected $description = 'Reconcile raw, aggregate, and receipt facts';

    public function handle(
        TrackingAggregateReconciler $reconciler,
        TrackingGenerationRotationService $rotations
    ): int {
        if ($this->option('release-rotation')) {
            return $this->releaseRotation($rotations);
        }
        if ($this->option('rotate-generation')) {
            return $this->rotateGeneration($rotations);
        }
        if ($this->option('max-id') === null) {
            $this->error('--max-id is required for bounded raw reconciliation.');

            return self::FAILURE;
        }

        $lock = Cache::lock(
            'tracking:aggregate-reconcile',
            max(30, (int) $this->option('max-runtime')) + 30
        );
        if (!$lock->get()) {
            $this->error('Tracking reconciliation is already running.');

            return self::FAILURE;
        }

        try {
            $result = $reconciler->reconcile(max(0, (int) $this->option('max-id')));
            $this->line(collect($result)
                ->map(static fn (int $value, string $key): string => "{$key}={$value}")
                ->implode(' '));

            return array_sum(array_map(
                static fn (int $value, string $key): int =>
                    $key === 'site_difference' ? 0 : abs($value),
                $result,
                array_keys($result)
            )) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function rotateGeneration(TrackingGenerationRotationService $rotations): int
    {
        if (Schema::hasTable('tracking_counter_runtime')) {
            $counter = TrackingCounterRuntime::query()->find(1);
            if ($counter !== null && (
                $counter->active_driver !== TrackingCounterRuntime::DRIVER_RECEIPT
                || $counter->transition_state !== TrackingCounterRuntime::TRANSITION_STABLE
            )) {
                $this->error('Receipt generation rotation requires a stable receipt counter driver.');

                return self::FAILURE;
            }
        }

        $maxRuntime = max(1, (int) $this->option('max-runtime'));
        $maxHold = max(1, (int) $this->option('max-hold-seconds'));
        $lock = Cache::lock('tracking:generation-rotate', $maxRuntime + 30);
        if (!$lock->get()) {
            $this->error('Tracking generation rotation is already running.');

            return self::FAILURE;
        }

        try {
            if ($this->option('auto-release')) {
                $this->releaseSignedHeldRotation($rotations);
            }

            $startedAt = microtime(true);
            $run = $rotations->startOrResume();
            while (
                $run->status === 'drain_verified'
                && microtime(true) - $startedAt < $maxRuntime
            ) {
                $barrierStartedAt = CarbonImmutable::parse(
                    (string) $run->getRawOriginal('barrier_started_at'),
                    'UTC'
                );
                if (abs($barrierStartedAt->diffInSeconds(CarbonImmutable::now('UTC'))) > $maxHold) {
                    $this->error('Rotation hold budget exceeded; generation remains held.');

                    return self::FAILURE;
                }
                $next = $rotations->advance($run);
                if ($next->status === $run->status) {
                    usleep(250000);
                }
                $run = $next;
            }

            if (
                $this->option('auto-release')
                && $run->status === 'completed'
                && $run->manifest_checksum !== null
            ) {
                $run = $rotations->release($run->rotation_id, $run->manifest_checksum);
            }

            $this->line(implode(' ', [
                "rotation_id={$run->rotation_id}",
                "status={$run->status}",
                "closed_generation={$run->closed_generation}",
                "next_generation={$run->next_held_generation}",
                'manifest_checksum=' . ($run->manifest_checksum ?? 'pending'),
            ]));

            return in_array($run->status, ['completed', 'released'], true)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function releaseSignedHeldRotation(TrackingGenerationRotationService $rotations): void
    {
        $state = TrackingRuntimeState::query()->findOrFail(1);
        $stale = TrackingGenerationRun::query()
            ->where('status', 'completed')
            ->where('manifest_state', 'signed')
            ->whereNotNull('manifest_checksum')
            ->orderByDesc('id')
            ->first();
        if (
            $stale === null
            || $state->processing_state !== TrackingRuntimeState::PROCESSING_HELD
            || (int) $state->write_generation !== (int) $stale->next_held_generation
        ) {
            return;
        }

        $rotations->release($stale->rotation_id, $stale->manifest_checksum);
    }

    private function releaseRotation(TrackingGenerationRotationService $rotations): int
    {
        $rotationId = trim((string) $this->option('release-rotation'));
        $checksum = trim((string) $this->option('manifest-checksum'));
        if ($rotationId === '' || $checksum === '') {
            $this->error('--release-rotation and --manifest-checksum must be supplied together.');

            return self::FAILURE;
        }

        $lock = Cache::lock('tracking:generation-rotate', 120);
        if (!$lock->get()) {
            $this->error('Tracking generation rotation is already running.');

            return self::FAILURE;
        }

        try {
            $run = $rotations->release($rotationId, $checksum);
            $this->line("rotation_id={$run->rotation_id} status={$run->status}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
