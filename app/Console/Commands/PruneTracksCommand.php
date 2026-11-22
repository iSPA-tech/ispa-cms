<?php

namespace App\Console\Commands;

use App\Constants\Tables;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingPruneRun;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingGenerationRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PruneTracksCommand extends Command
{
    protected $signature = 'cms:tracks-prune
                            {--before= : Delete only rows created and last updated before this timestamp}
                            {--chunk=5000 : Maximum explicit IDs per transaction}
                            {--run-id= : Resume or name one prune run}
                            {--after-id=0 : Initial cursor for a new run}
                            {--freeze-max-id= : Signed raw-freeze maximum ID}
                            {--window-size=50000 : Fixed primary-key window size}
                            {--max-runtime=50 : Maximum runtime in seconds}
                            {--dry-run : Report candidates without mutation}
                            {--force : Required for mutation}';

    protected $description = 'Dry-run or resume guarded pruning of frozen legacy tracks';

    public function handle(): int
    {
        try {
            $cutoff = $this->cutoff();
            $freezeMaxId = $this->requiredNonNegativeInt('freeze-max-id');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $afterId = max(0, (int) $this->option('after-id'));
        $windowSize = max(1, min(1000000, (int) $this->option('window-size')));
        $chunk = max(1, min(20000, (int) $this->option('chunk')));
        $summary = $this->candidateSummary($cutoff, $afterId, $freezeMaxId);
        $hash = $this->reconciliationHash(
            $cutoff,
            $afterId,
            $freezeMaxId,
            $windowSize,
            $summary['rows'],
            $summary['views']
        );
        $existingRun = trim((string) $this->option('run-id')) !== ''
            ? TrackingPruneRun::query()
                ->where('run_id', trim((string) $this->option('run-id')))
                ->first()
            : null;
        $proofHash = $existingRun?->reconciliation_hash ?? $hash;
        $this->line(implode(' ', [
            "candidate_rows={$summary['rows']}",
            "candidate_views={$summary['views']}",
            "after_id={$afterId}",
            "freeze_max_id={$freezeMaxId}",
            "reconciliation_hash={$hash}",
            'mode=' . ($this->option('force') ? 'write' : 'dry-run'),
        ]));

        if (!$this->option('force')) {
            return self::SUCCESS;
        }

        try {
            $this->assertMutationGates($cutoff, $freezeMaxId, $proofHash);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $maxRuntime = max(1, (int) $this->option('max-runtime'));
        $lock = Cache::lock('tracking:tracks-prune', $maxRuntime + 30);
        if (!$lock->get()) {
            $this->error('Another tracks prune worker is active.');

            return self::FAILURE;
        }

        try {
            $run = $this->resolveRun(
                $cutoff,
                $freezeMaxId,
                $windowSize,
                $afterId,
                $summary,
                $proofHash
            );
            $startedAt = microtime(true);
            while (
                $run->status !== 'completed'
                && microtime(true) - $startedAt < $maxRuntime
            ) {
                $run = $this->processOneBatch($run, $cutoff, $chunk);
                $pause = max(0, (int) config('tracking.prune.pause_milliseconds', 250));
                if ($pause > 0 && $run->status !== 'completed') {
                    usleep($pause * 1000);
                }
            }

            $this->line(implode(' ', [
                "run_id={$run->run_id}",
                "status={$run->status}",
                "deleted_rows={$run->deleted_rows}",
                "deleted_views={$run->deleted_views}",
                "last_processed_id={$run->last_processed_id}",
            ]));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function cutoff(): CarbonImmutable
    {
        $value = trim((string) $this->option('before'));
        if ($value === '') {
            throw new RuntimeException('--before is required.');
        }
        $cutoff = CarbonImmutable::parse($value, 'UTC');
        $minimum = max(1, (int) config('tracking.prune.minimum_retention_days', 365));
        if ($cutoff->greaterThan(CarbonImmutable::now('UTC')->subDays($minimum))) {
            throw new RuntimeException('Prune cutoff enters the protected rollback window.');
        }

        return $cutoff;
    }

    private function requiredNonNegativeInt(string $option): int
    {
        if ($this->option($option) === null || !is_numeric($this->option($option))) {
            throw new RuntimeException("--{$option} is required.");
        }

        return max(0, (int) $this->option($option));
    }

    /**
     * @return array{rows:int,views:int}
     */
    private function candidateSummary(
        CarbonImmutable $cutoff,
        int $afterId,
        int $freezeMaxId
    ): array {
        $query = DB::table(Tables::TRACKS)
            ->where('id', '>', $afterId)
            ->where('id', '<=', $freezeMaxId)
            ->where('created_at', '<', $cutoff)
            ->where('updated_at', '<', $cutoff);

        return [
            'rows' => (clone $query)->count(),
            'views' => (int) (clone $query)->sum('view'),
        ];
    }

    private function assertMutationGates(
        CarbonImmutable $cutoff,
        int $freezeMaxId,
        string $hash
    ): void {
        if (!config('tracking.prune_enabled', false)) {
            throw new RuntimeException('Tracks pruning is disabled.');
        }
        $state = TrackingRuntimeState::query()->whereKey(1)->firstOrFail();
        if (
            $state->mode !== TrackingRuntimeState::MODE_AGGREGATE
            || $state->processing_state !== TrackingRuntimeState::PROCESSING_OPEN
            || (string) config('tracking.read_mode') !== 'aggregate'
        ) {
            throw new RuntimeException('Aggregate write and read modes are required.');
        }
        if ((int) config('tracking.prune.freeze_max_id', -1) !== $freezeMaxId) {
            throw new RuntimeException('Freeze maximum ID does not match approved proof.');
        }
        $checksum = (string) config('tracking.prune.freeze_manifest_checksum', '');
        $freeze = TrackingGenerationRun::query()
            ->where('closed_mode', TrackingRuntimeState::MODE_DUAL)
            ->where('status', 'released')
            ->where('manifest_state', 'signed')
            ->where('manifest_checksum', $checksum)
            ->first();
        if ($checksum === '' || $freeze === null) {
            throw new RuntimeException('A released signed raw-freeze manifest is required.');
        }
        if (TrackingEventReceipt::query()
            ->whereIn('generation_mode', [
                TrackingRuntimeState::MODE_LEGACY,
                TrackingRuntimeState::MODE_DUAL,
            ])
            ->where('status', TrackingEventReceipt::STATUS_PENDING)
            ->exists()) {
            throw new RuntimeException('Non-terminal raw-writing receipts block pruning.');
        }
        if (TrackingEventReceipt::query()
            ->where('status', TrackingEventReceipt::STATUS_QUARANTINED)
            ->where(function ($query): void {
                $query->whereNull('resolution_status')
                    ->orWhere('resolution_status', '!=', 'compensated');
            })
            ->exists()) {
            throw new RuntimeException('Uncompensated quarantined events block pruning.');
        }
        if (TrackingGenerationRun::query()
            ->where('closed_mode', TrackingRuntimeState::MODE_AGGREGATE)
            ->where(function ($query): void {
                $query->whereNotIn('status', ['completed', 'released'])
                    ->orWhere('manifest_state', '!=', 'signed')
                    ->orWhereNull('manifest_checksum');
            })
            ->exists()) {
            throw new RuntimeException('The signed aggregate generation chain is incomplete.');
        }
        app(TrackingGenerationRotationService::class)->assertManifestChainComplete();
        if (
            (int) config('tracking.prune.worker_contract_version', 0)
                !== (int) config('tracking.aggregate_contract_version', 1)
            || !config('tracking.prune.legacy_jobs_disabled', false)
            || !config('tracking.prune.aggregate_consumers_only', false)
            || !config('tracking.prune.raw_caches_invalidated', false)
        ) {
            throw new RuntimeException('Worker, legacy-job, consumer, or cache proof is missing.');
        }
        if (trim((string) config('tracking.prune.backup_restore_proof', '')) === '') {
            throw new RuntimeException('Backup restore proof is missing.');
        }
        if (!hash_equals((string) config('tracking.prune.reconciliation_hash', ''), $hash)) {
            throw new RuntimeException('Reconciliation checkpoint does not cover the deletion range.');
        }
    }

    /**
     * @param array{rows:int,views:int} $summary
     */
    private function resolveRun(
        CarbonImmutable $cutoff,
        int $freezeMaxId,
        int $windowSize,
        int $afterId,
        array $summary,
        string $hash
    ): TrackingPruneRun {
        $runId = trim((string) $this->option('run-id'));
        $run = $runId !== ''
            ? TrackingPruneRun::query()->where('run_id', $runId)->first()
            : null;
        if ($run !== null) {
            if (
                $run->cutoff_at->utc()->format('Y-m-d H:i:s') !== $cutoff->format('Y-m-d H:i:s')
                || (int) $run->freeze_max_id !== $freezeMaxId
                || (int) $run->window_size !== $windowSize
                || !hash_equals($run->reconciliation_hash, $hash)
            ) {
                throw new RuntimeException('Resume arguments do not match the existing prune run.');
            }

            return $run;
        }

        return TrackingPruneRun::query()->create([
            'run_id' => $runId !== '' ? $runId : (string) Str::uuid(),
            'cutoff_at' => $cutoff,
            'freeze_max_id' => $freezeMaxId,
            'window_size' => $windowSize,
            'status' => 'running',
            'expected_rows' => $summary['rows'],
            'expected_views' => $summary['views'],
            'current_window_start' => min($afterId + 1, $freezeMaxId),
            'current_window_end' => min($afterId + $windowSize, $freezeMaxId),
            'last_processed_id' => $afterId,
            'deleted_rows' => 0,
            'deleted_views' => 0,
            'reconciliation_hash' => $hash,
            'started_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function processOneBatch(
        TrackingPruneRun $run,
        CarbonImmutable $cutoff,
        int $chunk
    ): TrackingPruneRun {
        if ($run->current_window_start === null || $run->current_window_start > $run->freeze_max_id) {
            return $this->completeRun($run);
        }

        $startedAt = microtime(true);
        $run = DB::transaction(function () use ($run, $cutoff, $chunk): TrackingPruneRun {
            $locked = TrackingPruneRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $rows = DB::table(Tables::TRACKS)
                ->whereBetween('id', [
                    $locked->current_window_start,
                    $locked->current_window_end,
                ])
                ->where('created_at', '<', $cutoff)
                ->where('updated_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'view']);
            if ($rows->isNotEmpty()) {
                $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $views = (int) $rows->sum('view');
                DB::table(Tables::TRACKS)->whereIn('id', $ids)->delete();
                $locked->forceFill([
                    'last_processed_id' => max($ids),
                    'deleted_rows' => (int) $locked->deleted_rows + count($ids),
                    'deleted_views' => (int) $locked->deleted_views + $views,
                ])->save();

                return $locked->fresh();
            }

            $nextStart = (int) $locked->current_window_end + 1;
            if ($nextStart > $locked->freeze_max_id) {
                $locked->forceFill([
                    'status' => 'completed',
                    'last_processed_id' => $locked->freeze_max_id,
                    'current_window_start' => null,
                    'current_window_end' => null,
                    'completed_at' => CarbonImmutable::now('UTC'),
                ])->save();

                return $locked->fresh();
            }

            $locked->forceFill([
                'last_processed_id' => $locked->current_window_end,
                'current_window_start' => $nextStart,
                'current_window_end' => min(
                    $nextStart + (int) $locked->window_size - 1,
                    (int) $locked->freeze_max_id
                ),
            ])->save();

            return $locked->fresh();
        });

        if (microtime(true) - $startedAt > 2.0) {
            throw new RuntimeException('Prune batch exceeded the two-second transaction budget.');
        }

        return $run;
    }

    private function completeRun(TrackingPruneRun $run): TrackingPruneRun
    {
        $run->forceFill([
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $run->fresh();
    }

    private function reconciliationHash(
        CarbonImmutable $cutoff,
        int $afterId,
        int $freezeMaxId,
        int $windowSize,
        int $rows,
        int $views
    ): string {
        return hash('sha256', implode('|', [
            'tracking-prune-v1',
            $cutoff->format('Y-m-d H:i:s'),
            $afterId,
            $freezeMaxId,
            $windowSize,
            $rows,
            $views,
            (int) config('tracking.aggregate_contract_version', 1),
        ]));
    }
}
