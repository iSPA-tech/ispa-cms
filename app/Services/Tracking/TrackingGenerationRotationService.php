<?php

namespace App\Services\Tracking;

use App\Constants\Tables;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingCounterFlushBatch;
use App\Models\Tracking\TrackingGenerationRun;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class TrackingGenerationRotationService
{
    public function __construct(private readonly TrackingAggregateReconciler $reconciler)
    {
    }

    public function startOrResume(): TrackingGenerationRun
    {
        if (!config('tracking.generation_rotation_enabled', false)) {
            throw new RuntimeException('Tracking generation rotation is disabled.');
        }

        $run = DB::transaction(function (): TrackingGenerationRun {
            $state = TrackingRuntimeState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $active = TrackingGenerationRun::query()
                ->whereNotNull('active_rotation_key')
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                return $active;
            }
            if (
                !in_array($state->mode, [
                    TrackingRuntimeState::MODE_DUAL,
                    TrackingRuntimeState::MODE_AGGREGATE,
                ], true)
                || $state->processing_state !== TrackingRuntimeState::PROCESSING_OPEN
            ) {
                throw new RuntimeException('Rotation requires an open dual or aggregate generation.');
            }

            $first = TrackingEventReceipt::query()
                ->where('write_generation', $state->write_generation)
                ->min('id');
            $maximum = TrackingEventReceipt::query()
                ->where('write_generation', $state->write_generation)
                ->max('id');
            $run = TrackingGenerationRun::query()->create([
                'rotation_id' => (string) Str::uuid(),
                'status' => 'held',
                'closed_generation' => $state->write_generation,
                'closed_mode' => $state->mode,
                'first_ledger_id' => $first,
                'maximum_ledger_id' => $maximum,
                'next_held_generation' => (int) $state->write_generation + 1,
                'barrier_started_at' => CarbonImmutable::now('UTC'),
                'manifest_state' => 'pending',
            ]);

            $state->forceFill([
                'write_generation' => $run->next_held_generation,
                'mode' => TrackingRuntimeState::MODE_AGGREGATE,
                'processing_state' => TrackingRuntimeState::PROCESSING_HELD,
                'direct_counter_owner' => 'event',
                'generation_opened_at' => CarbonImmutable::now('UTC'),
                'changed_by' => 'cms:tracking-reconcile',
                'change_reason' => "rotation:{$run->rotation_id}",
            ])->save();

            return $run;
        });

        return $this->advance($run->fresh());
    }

    public function advance(TrackingGenerationRun $run): TrackingGenerationRun
    {
        if (in_array($run->status, ['completed', 'released'], true)) {
            return $run;
        }

        $pending = TrackingEventReceipt::query()
            ->where('write_generation', $run->closed_generation)
            ->when(
                $run->maximum_ledger_id !== null,
                static fn ($query) => $query->where('id', '<=', $run->maximum_ledger_id)
            )
            ->where('status', TrackingEventReceipt::STATUS_PENDING)
            ->count();
        $unresolved = TrackingEventReceipt::query()
            ->where('write_generation', $run->closed_generation)
            ->when(
                $run->maximum_ledger_id !== null,
                static fn ($query) => $query->where('id', '<=', $run->maximum_ledger_id)
            )
            ->where('status', TrackingEventReceipt::STATUS_QUARANTINED)
            ->where(function ($query): void {
                $query->whereNull('resolution_status')
                    ->orWhere('resolution_status', '!=', 'compensated');
            })
            ->count();
        if ($pending > 0 || $unresolved > 0) {
            return $run;
        }

        if ($run->status === 'held') {
            $run->forceFill(['status' => 'drain_verified'])->save();

            return $run->fresh();
        }

        $verificationSeconds = max(
            0,
            (int) config('tracking.generation_drain_verification_seconds', 60)
        );
        if ($run->updated_at->addSeconds($verificationSeconds)->isFuture()) {
            return $run;
        }

        if ($run->closed_mode === TrackingRuntimeState::MODE_DUAL) {
            $maximumTrackId = (int) DB::table(Tables::TRACKS)->max('id');
            $raw = $this->reconciler->reconcile($maximumTrackId);
            if (
                $raw['site_mismatch'] !== 0
                || $raw['target_mismatches'] !== 0
                || $raw['uncompensated_quarantined_events'] !== 0
            ) {
                throw new RuntimeException('Dual-generation raw reconciliation failed.');
            }
        }

        return $this->sign($run);
    }

    public function release(string $rotationId, string $checksum): TrackingGenerationRun
    {
        if (!config('tracking.generation_rotation_enabled', false)) {
            throw new RuntimeException('Tracking generation rotation is disabled.');
        }
        if ((string) config('tracking.read_mode', 'raw') !== 'aggregate') {
            throw new RuntimeException('Held generation release requires aggregate read mode.');
        }

        return DB::transaction(function () use ($rotationId, $checksum): TrackingGenerationRun {
            $run = TrackingGenerationRun::query()
                ->where('rotation_id', $rotationId)
                ->lockForUpdate()
                ->firstOrFail();
            $state = TrackingRuntimeState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            if (
                $run->status !== 'completed'
                || $run->manifest_state !== 'signed'
                || $run->manifest_checksum === null
                || !hash_equals($run->manifest_checksum, $checksum)
                || (int) $state->write_generation !== (int) $run->next_held_generation
                || $state->processing_state !== TrackingRuntimeState::PROCESSING_HELD
            ) {
                throw new RuntimeException('Rotation release proof does not match the held generation.');
            }

            $state->forceFill([
                'mode' => TrackingRuntimeState::MODE_AGGREGATE,
                'processing_state' => TrackingRuntimeState::PROCESSING_OPEN,
                'changed_by' => 'cms:tracking-reconcile',
                'change_reason' => "release:{$run->rotation_id}",
            ])->save();
            $run->forceFill(['status' => 'released'])->save();

            return $run->fresh();
        });
    }

    public function assertManifestChainComplete(): void
    {
        $previousChecksum = null;
        $previousNextGeneration = null;
        $runs = TrackingGenerationRun::query()
            ->whereIn('status', ['completed', 'released'])
            ->where('manifest_state', 'signed')
            ->orderBy('closed_generation')
            ->get();
        foreach ($runs as $run) {
            $path = $this->manifestPath($run->rotation_id);
            $disk = Storage::disk((string) config('tracking.generation_manifest_disk', 'local'));
            if (!$disk->exists($path)) {
                throw new RuntimeException('Signed generation manifest file is missing.');
            }
            $encoded = $disk->get($path);
            if (
                $run->manifest_checksum === null
                || !hash_equals($run->manifest_checksum, hash('sha256', $encoded))
            ) {
                throw new RuntimeException('Generation manifest checksum mismatch.');
            }
            $manifest = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            if (
                ($manifest['schema'] ?? null) !== 'tracking-generation-manifest-v1'
                || ($manifest['previous_manifest_checksum'] ?? null) !== $previousChecksum
                || (int) ($manifest['closed_generation'] ?? 0) !== (int) $run->closed_generation
                || (
                    $previousNextGeneration !== null
                    && $previousNextGeneration !== (int) $run->closed_generation
                )
            ) {
                throw new RuntimeException('Generation manifest chain is invalid.');
            }
            $previousChecksum = $run->manifest_checksum;
            $previousNextGeneration = (int) $run->next_held_generation;
        }
    }

    private function sign(TrackingGenerationRun $run): TrackingGenerationRun
    {
        [$priorDeltas, $previousChecksum, $previousNextGeneration] =
            $this->loadPriorManifestChain($run);
        if (
            $previousNextGeneration !== null
            && $previousNextGeneration !== (int) $run->closed_generation
        ) {
            throw new RuntimeException('Generation manifest chain has a generation gap.');
        }
        $generationDeltas = $this->generationDeltas($run);
        $cumulativeDeltas = $this->mergeDeltas($priorDeltas, $generationDeltas);
        $this->assertAggregateFacts($cumulativeDeltas);

        $statusCounts = TrackingEventReceipt::query()
            ->where('write_generation', $run->closed_generation)
            ->when(
                $run->maximum_ledger_id !== null,
                static fn ($query) => $query->where('id', '<=', $run->maximum_ledger_id)
            )
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->sortKeys()
            ->all();
        $manifest = [
            'schema' => 'tracking-generation-manifest-v1',
            'rotation_id' => $run->rotation_id,
            'closed_generation' => (int) $run->closed_generation,
            'closed_mode' => $run->closed_mode,
            'first_ledger_id' => $run->first_ledger_id,
            'maximum_ledger_id' => $run->maximum_ledger_id,
            'next_held_generation' => (int) $run->next_held_generation,
            'previous_manifest_checksum' => $previousChecksum,
            'receipt_status_counts' => $statusCounts,
            'deltas' => $generationDeltas,
            'site_live_views' => (int) DB::table(Tables::TRACKING_SITE_TOTALS)
                ->where('id', 1)
                ->value('live_views'),
            'target_live_views' => (int) DB::table(Tables::TRACKING_TARGET_TOTALS)
                ->sum('live_views'),
            'target_count' => DB::table(Tables::TRACKING_TARGET_TOTALS)->count(),
            'uncompensated_quarantined_events' => 0,
        ];
        $manifest = $this->sortRecursive($manifest);
        $encoded = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
        $checksum = hash('sha256', $encoded);
        $path = trim(
            (string) config('tracking.generation_manifest_path', 'tracking/manifests'),
            '/'
        ) . "/{$run->rotation_id}.json";
        Storage::disk((string) config('tracking.generation_manifest_disk', 'local'))
            ->put($path, $encoded);

        return DB::transaction(function () use ($run, $checksum): TrackingGenerationRun {
            $locked = TrackingGenerationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') {
                return $locked;
            }
            if ($locked->status !== 'drain_verified') {
                throw new RuntimeException('Rotation is not ready for manifest signing.');
            }

            $locked->forceFill([
                'status' => 'completed',
                'manifest_state' => 'signed',
                'manifest_id' => "generation:{$locked->closed_generation}",
                'manifest_checksum' => $checksum,
                'completed_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * @return array{0:array<string,mixed>,1:string|null,2:int|null}
     */
    private function loadPriorManifestChain(TrackingGenerationRun $current): array
    {
        $cumulative = $this->emptyDeltas();
        $previousChecksum = null;
        $previousNextGeneration = null;
        $runs = TrackingGenerationRun::query()
            ->where('closed_generation', '<', $current->closed_generation)
            ->whereIn('status', ['completed', 'released'])
            ->where('manifest_state', 'signed')
            ->orderBy('closed_generation')
            ->get();

        foreach ($runs as $run) {
            $path = $this->manifestPath($run->rotation_id);
            $disk = Storage::disk((string) config('tracking.generation_manifest_disk', 'local'));
            if (!$disk->exists($path)) {
                throw new RuntimeException('Signed generation manifest file is missing.');
            }
            $encoded = $disk->get($path);
            if (
                $run->manifest_checksum === null
                || !hash_equals($run->manifest_checksum, hash('sha256', $encoded))
            ) {
                throw new RuntimeException('Generation manifest checksum mismatch.');
            }
            $manifest = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            if (
                ($manifest['schema'] ?? null) !== 'tracking-generation-manifest-v1'
                || ($manifest['previous_manifest_checksum'] ?? null) !== $previousChecksum
                || (int) ($manifest['closed_generation'] ?? 0) !== (int) $run->closed_generation
            ) {
                throw new RuntimeException('Generation manifest chain is invalid.');
            }
            if (
                $previousNextGeneration !== null
                && $previousNextGeneration !== (int) $run->closed_generation
            ) {
                throw new RuntimeException('Generation manifest chain has a generation gap.');
            }

            $cumulative = $this->mergeDeltas(
                $cumulative,
                (array) ($manifest['deltas'] ?? [])
            );
            $previousChecksum = $run->manifest_checksum;
            $previousNextGeneration = (int) $run->next_held_generation;
        }

        return [$cumulative, $previousChecksum, $previousNextGeneration];
    }

    /**
     * @return array<string,mixed>
     */
    private function generationDeltas(TrackingGenerationRun $run): array
    {
        $deltas = $this->emptyDeltas();
        TrackingEventReceipt::query()
            ->where('write_generation', $run->closed_generation)
            ->when(
                $run->maximum_ledger_id !== null,
                static fn ($query) => $query->where('id', '<=', $run->maximum_ledger_id)
            )
            ->where('status', TrackingEventReceipt::STATUS_APPLIED)
            ->orderBy('id')
            ->chunkById(1000, function ($receipts) use (&$deltas): void {
                foreach ($receipts as $receipt) {
                    $counter = $receipt->counter_deltas;
                    $date = $receipt->event_date->toDateString();
                    $site = [
                        'views' => (int) data_get($counter, 'legacy_views', 0),
                        'visitors' => (int) data_get($counter, 'site_visitors', 0),
                        'sessions' => (int) data_get($counter, 'site_sessions', 0),
                        'scans' => (int) data_get($counter, 'scans', 0),
                        'place_scan' => (int) data_get($counter, 'place_scans', 0),
                        'item_scan' => (int) data_get($counter, 'item_scans', 0),
                        'download' => (int) data_get($counter, 'downloads', 0),
                    ];
                    $deltas['site_days'][$date] ??= $this->siteDayMetrics();
                    $this->addMetrics($deltas['site_days'][$date], $site);
                    $deltas['site']['views'] += $site['views'];
                    $deltas['site']['scans'] += $site['scans'];

                    if ($receipt->target_key === null) {
                        continue;
                    }
                    $key = $receipt->target_key;
                    $target = [
                        'views' => $site['views'],
                        'visitors' => (int) data_get($counter, 'target_visitors', 0),
                        'sessions' => (int) data_get($counter, 'target_sessions', 0),
                        'scans' => $site['scans'],
                        'download' => $site['download'],
                    ];
                    $deltas['targets'][$key] ??= ['views' => 0, 'scans' => 0];
                    $deltas['targets'][$key]['views'] += $target['views'];
                    $deltas['targets'][$key]['scans'] += $target['scans'];
                    $deltas['target_days'][$date][$key] ??= $this->targetDayMetrics();
                    $this->addMetrics($deltas['target_days'][$date][$key], $target);
                }
            });

        if (Schema::hasTable(Tables::TRACKING_COUNTER_FLUSH_BATCHES)) {
            TrackingCounterFlushBatch::query()
                ->where('write_generation', $run->closed_generation)
                ->where('status', 'applied')
                ->orderBy('id')
                ->chunkById(100, function ($batches) use (&$deltas): void {
                    foreach ($batches as $batch) {
                        $payload = (array) $batch->deltas;
                        $date = (string) ($payload['date'] ?? '');
                        $counter = (array) ($payload['site'] ?? []);
                        $site = [
                            'views' => (int) ($counter['legacy_views'] ?? 0),
                            'visitors' => (int) ($counter['site_visitors'] ?? 0),
                            'sessions' => (int) ($counter['site_sessions'] ?? 0),
                            'scans' => 0,
                            'place_scan' => 0,
                            'item_scan' => 0,
                            'download' => 0,
                        ];
                        $deltas['site_days'][$date] ??= $this->siteDayMetrics();
                        $this->addMetrics($deltas['site_days'][$date], $site);
                        $deltas['site']['views'] += $site['views'];

                        foreach ((array) ($payload['targets'] ?? []) as $key => $bufferedTarget) {
                            $targetCounter = (array) data_get($bufferedTarget, 'deltas', []);
                            $target = [
                                'views' => (int) ($targetCounter['legacy_views'] ?? 0),
                                'visitors' => (int) ($targetCounter['target_visitors'] ?? 0),
                                'sessions' => (int) ($targetCounter['target_sessions'] ?? 0),
                                'scans' => 0,
                                'download' => 0,
                            ];
                            $deltas['targets'][$key] ??= ['views' => 0, 'scans' => 0];
                            $deltas['targets'][$key]['views'] += $target['views'];
                            $deltas['target_days'][$date][$key] ??= $this->targetDayMetrics();
                            $this->addMetrics($deltas['target_days'][$date][$key], $target);
                        }
                    }
                });
        }

        return $this->sortRecursive($deltas);
    }

    /**
     * @param array<string,mixed> $prior
     * @param array<string,mixed> $next
     * @return array<string,mixed>
     */
    private function mergeDeltas(array $prior, array $next): array
    {
        $merged = $this->emptyDeltas();
        foreach (['views', 'scans'] as $metric) {
            $merged['site'][$metric] =
                (int) data_get($prior, "site.{$metric}", 0)
                + (int) data_get($next, "site.{$metric}", 0);
        }
        foreach ([$prior, $next] as $source) {
            foreach ((array) ($source['targets'] ?? []) as $key => $metrics) {
                $merged['targets'][$key] ??= ['views' => 0, 'scans' => 0];
                $this->addMetrics($merged['targets'][$key], $metrics);
            }
            foreach ((array) ($source['site_days'] ?? []) as $date => $metrics) {
                $merged['site_days'][$date] ??= $this->siteDayMetrics();
                $this->addMetrics($merged['site_days'][$date], $metrics);
            }
            foreach ((array) ($source['target_days'] ?? []) as $date => $targets) {
                foreach ($targets as $key => $metrics) {
                    $merged['target_days'][$date][$key] ??= $this->targetDayMetrics();
                    $this->addMetrics($merged['target_days'][$date][$key], $metrics);
                }
            }
        }

        return $this->sortRecursive($merged);
    }

    /**
     * @param array<string,mixed> $deltas
     */
    private function assertAggregateFacts(array $deltas): void
    {
        $site = DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->first();
        if (
            $site === null
            || (int) $site->live_views !== (int) data_get($deltas, 'site.views', 0)
            || (int) $site->live_scans !== (int) data_get($deltas, 'site.scans', 0)
        ) {
            throw new RuntimeException('Generation site-lifetime reconciliation failed.');
        }

        $expectedTargets = (array) ($deltas['targets'] ?? []);
        foreach (DB::table(Tables::TRACKING_TARGET_TOTALS)->get() as $target) {
            $expected = $expectedTargets[$target->target_key] ?? ['views' => 0, 'scans' => 0];
            if (
                (int) $target->live_views !== (int) $expected['views']
                || (int) $target->live_scans !== (int) $expected['scans']
            ) {
                throw new RuntimeException('Generation target-lifetime reconciliation failed.');
            }
            unset($expectedTargets[$target->target_key]);
        }
        if ($this->containsNonZero($expectedTargets)) {
            throw new RuntimeException('Generation target-lifetime row is missing.');
        }

        $boundary = $site->accurate_from_date ?? null;
        if ($boundary === null) {
            return;
        }
        $boundary = CarbonImmutable::parse($boundary)->toDateString();

        $expectedSiteDays = array_filter(
            (array) ($deltas['site_days'] ?? []),
            static fn (string $date): bool => $date >= $boundary,
            ARRAY_FILTER_USE_KEY
        );
        foreach (DB::table(Tables::TRACKING_DAILY)->where('date', '>=', $boundary)->get() as $day) {
            $expected = $expectedSiteDays[$day->date] ?? $this->siteDayMetrics();
            foreach (array_keys($this->siteDayMetrics()) as $metric) {
                if ((int) $day->{$metric} !== (int) $expected[$metric]) {
                    throw new RuntimeException('Generation site-day reconciliation failed.');
                }
            }
            unset($expectedSiteDays[$day->date]);
        }
        if ($this->containsNonZero($expectedSiteDays)) {
            throw new RuntimeException('Generation site-day row is missing.');
        }

        $expectedTargetDays = array_filter(
            (array) ($deltas['target_days'] ?? []),
            static fn (string $date): bool => $date >= $boundary,
            ARRAY_FILTER_USE_KEY
        );
        foreach (DB::table(Tables::TRACKING_TARGET_DAILY)->where('date', '>=', $boundary)->get() as $day) {
            $expected = $expectedTargetDays[$day->date][$day->target_key]
                ?? $this->targetDayMetrics();
            $columns = [
                'views' => 'total_views',
                'visitors' => 'total_visitors',
                'sessions' => 'total_sessions',
                'scans' => 'total_scans',
                'download' => 'total_downloads',
            ];
            foreach ($columns as $metric => $column) {
                if ((int) $day->{$column} !== (int) $expected[$metric]) {
                    throw new RuntimeException('Generation target-day reconciliation failed.');
                }
            }
            unset($expectedTargetDays[$day->date][$day->target_key]);
        }
        if ($this->containsNonZero($expectedTargetDays)) {
            throw new RuntimeException('Generation target-day row is missing.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDeltas(): array
    {
        return [
            'site' => ['views' => 0, 'scans' => 0],
            'targets' => [],
            'site_days' => [],
            'target_days' => [],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function siteDayMetrics(): array
    {
        return [
            'views' => 0,
            'visitors' => 0,
            'sessions' => 0,
            'scans' => 0,
            'place_scan' => 0,
            'item_scan' => 0,
            'download' => 0,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function targetDayMetrics(): array
    {
        return [
            'views' => 0,
            'visitors' => 0,
            'sessions' => 0,
            'scans' => 0,
            'download' => 0,
        ];
    }

    /**
     * @param array<string,int> $target
     * @param array<string,int> $source
     */
    private function addMetrics(array &$target, array $source): void
    {
        foreach ($source as $metric => $value) {
            $target[$metric] = (int) ($target[$metric] ?? 0) + (int) $value;
        }
    }

    private function containsNonZero(mixed $value): bool
    {
        if (!is_array($value)) {
            return (int) $value !== 0;
        }

        foreach ($value as $child) {
            if ($this->containsNonZero($child)) {
                return true;
            }
        }

        return false;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursive($child);
        }

        return $value;
    }

    private function manifestPath(string $rotationId): string
    {
        return trim(
            (string) config('tracking.generation_manifest_path', 'tracking/manifests'),
            '/'
        ) . "/{$rotationId}.json";
    }
}
