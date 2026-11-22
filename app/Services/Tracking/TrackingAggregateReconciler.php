<?php

namespace App\Services\Tracking;

use App\Constants\Tables;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingSiteTotal;
use App\Models\Tracking\TrackingTargetTotal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TrackingAggregateReconciler
{
    public const IMPORTER_VERSION = 1;

    public function __construct(private readonly TrackingTargetKeyResolver $targets)
    {
    }

    /**
     * Build a deterministic, bounded checkpoint manifest without mutating data.
     *
     * @return array<string, mixed>
     */
    public function buildBaselineManifest(
        string $checkpointId,
        int $maximumTrackId,
        int $afterId = 0,
        int $limit = 100000
    ): array {
        if ($checkpointId === '' || $maximumTrackId < 0 || $afterId < 0 || $limit < 1) {
            throw new InvalidArgumentException('A checkpoint ID and bounded track ID window are required.');
        }

        $siteLive = (int) TrackingSiteTotal::query()->whereKey(1)->value('live_views');
        $siteRaw = 0;
        $lastId = $afterId;
        $resolved = [];
        $unresolved = [];
        $unresolvedViews = 0;
        $unclassifiedViews = 0;
        // Respect the operator-provided --limit so large sites can be scanned to
        // completion in a single manifest. The scan streams via chunkById(1000),
        // so memory stays bounded by the distinct-target map, not the row count.
        $remaining = max(1, $limit);
        DB::table(Tables::TRACKS)
            ->where('id', '>', $afterId)
            ->where('id', '<=', $maximumTrackId)
            ->orderBy('id')
            ->chunkById(
                min(1000, $remaining),
                function ($rows) use (
                    &$lastId,
                    &$siteRaw,
                    &$resolved,
                    &$unresolved,
                    &$unresolvedViews,
                    &$unclassifiedViews,
                    &$remaining
                ): bool {
                    foreach ($rows->take($remaining) as $row) {
                        $remaining--;
                        $limitReached = $remaining === 0;
                        $lastId = (int) $row->id;
                        $views = (int) $row->view;
                        $siteRaw += $views;
                        $targetType = $row->target_type !== null
                            ? (string) $row->target_type
                            : null;
                        $targetId = (int) $row->target_id;
                        if ($targetType === null || $targetId <= 0) {
                            $unclassifiedViews += $views;
                            if ($limitReached) {
                                return false;
                            }
                            continue;
                        }

                        $target = $this->targets->resolve(
                            $targetType,
                            $targetId,
                            null,
                            [],
                            null
                        );
                        if ($target === null) {
                            $unresolved["{$targetType}:{$targetId}"] = true;
                            $unresolvedViews += $views;
                            if ($limitReached) {
                                return false;
                            }
                            continue;
                        }

                        $targetKey = $target['target_key'];
                        $resolved[$targetKey] ??= [
                            'target_key' => $targetKey,
                            'target_type' => $targetType,
                            'target_id' => $targetId,
                            'raw_views' => 0,
                        ];
                        $resolved[$targetKey]['raw_views'] += $views;

                        if ($limitReached) {
                            return false;
                        }
                    }

                    return $remaining > 0;
                },
                'id'
            );

        ksort($resolved, SORT_STRING);
        $complete = $afterId === 0
            && !DB::table(Tables::TRACKS)
                ->where('id', '>', $lastId)
                ->where('id', '<=', $maximumTrackId)
                ->exists();
        $negativeBaselines = $siteRaw - $siteLive < 0 ? 1 : 0;
        foreach ($resolved as &$target) {
            $live = (int) TrackingTargetTotal::query()
                ->where('target_key', $target['target_key'])
                ->value('live_views');
            $target['live_views'] = $live;
            $target['baseline_views'] = $target['raw_views'] - $live;
            if ($target['baseline_views'] < 0) {
                $negativeBaselines++;
            }
        }
        unset($target);

        $manifest = [
            'schema' => 'tracking-baseline-manifest-v1',
            'importer_version' => self::IMPORTER_VERSION,
            'checkpoint_id' => $checkpointId,
            'maximum_track_id' => $maximumTrackId,
            'range_after_id' => $afterId,
            'range_last_id' => $lastId,
            'complete' => $complete,
            'site' => [
                'raw_views' => $siteRaw,
                'live_views' => $siteLive,
                'baseline_views' => $siteRaw - $siteLive,
            ],
            'targets' => array_values($resolved),
            'target_count' => count($resolved),
            'unresolved_count' => count($unresolved),
            'unresolved_views' => $unresolvedViews,
            'unclassified_views' => $unclassifiedViews,
            'negative_baseline_count' => $negativeBaselines,
        ];
        $manifest['checksum'] = $this->checksum($manifest);

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function verifyBaselineManifest(array $manifest): void
    {
        foreach ([
            'schema',
            'importer_version',
            'checkpoint_id',
            'maximum_track_id',
            'range_after_id',
            'complete',
            'site',
            'targets',
            'negative_baseline_count',
            'checksum',
        ] as $required) {
            if (!array_key_exists($required, $manifest)) {
                throw new InvalidArgumentException("Baseline manifest is missing [{$required}].");
            }
        }
        if ($manifest['schema'] !== 'tracking-baseline-manifest-v1') {
            throw new InvalidArgumentException('Unsupported baseline manifest schema.');
        }
        if (!hash_equals((string) $manifest['checksum'], $this->checksum($manifest))) {
            throw new InvalidArgumentException('Baseline manifest checksum mismatch.');
        }
        if ((int) $manifest['range_after_id'] !== 0 || !$manifest['complete']) {
            throw new InvalidArgumentException('Only a complete checkpoint manifest can be imported.');
        }
        if ((int) $manifest['negative_baseline_count'] !== 0) {
            throw new InvalidArgumentException('Baseline manifest contains negative baselines.');
        }
        if ((int) data_get($manifest, 'site.baseline_views', -1) < 0) {
            throw new InvalidArgumentException('Site baseline is negative.');
        }

        $keys = [];
        foreach ((array) $manifest['targets'] as $target) {
            $key = (string) ($target['target_key'] ?? '');
            if ($key === '' || isset($keys[$key]) || (int) ($target['baseline_views'] ?? -1) < 0) {
                throw new InvalidArgumentException('Baseline manifest contains invalid target totals.');
            }
            $keys[$key] = true;
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return int number of mutated baseline rows
     */
    public function importBaselineManifest(array $manifest): int
    {
        $this->verifyBaselineManifest($manifest);

        return DB::transaction(function () use ($manifest): int {
            $checksum = (string) $manifest['checksum'];
            $manifestId = (string) $manifest['checkpoint_id'];
            $site = TrackingSiteTotal::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $mutations = 0;

            $this->assertManifestOwnership($site->baseline_manifest_checksum, $checksum);
            if ($site->baseline_manifest_checksum === null) {
                $site->forceFill([
                    'baseline_views' => (int) data_get($manifest, 'site.baseline_views'),
                    'baseline_quality' => 'verified',
                    'baseline_manifest_id' => $manifestId,
                    'baseline_manifest_checksum' => $checksum,
                    'baseline_as_of' => now('UTC'),
                ])->save();
                $mutations++;
            }

            foreach ((array) $manifest['targets'] as $entry) {
                $target = TrackingTargetTotal::query()
                    ->where('target_key', $entry['target_key'])
                    ->lockForUpdate()
                    ->first();
                if ($target !== null) {
                    $this->assertManifestOwnership(
                        $target->baseline_manifest_checksum,
                        $checksum
                    );
                    if ($target->baseline_manifest_checksum !== null) {
                        continue;
                    }
                } else {
                    $target = new TrackingTargetTotal([
                        'target_key' => $entry['target_key'],
                        'target_type' => $entry['target_type'],
                        'target_id' => $entry['target_id'],
                        'live_views' => 0,
                    ]);
                }

                $target->forceFill([
                    'baseline_views' => (int) $entry['baseline_views'],
                    'baseline_quality' => 'verified',
                    'baseline_manifest_id' => $manifestId,
                    'baseline_manifest_checksum' => $checksum,
                    'baseline_as_of' => now('UTC'),
                ])->save();
                $mutations++;
            }

            return $mutations;
        });
    }

    /**
     * @return array<string, int>
     */
    public function reconcile(int $maximumTrackId): array
    {
        if ($maximumTrackId < 0) {
            throw new InvalidArgumentException('A non-negative maximum track ID is required.');
        }

        $site = TrackingSiteTotal::query()->whereKey(1)->firstOrFail();
        $rawSite = (int) DB::table(Tables::TRACKS)
            ->where('id', '<=', $maximumTrackId)
            ->sum('view');
        $expectedSite = (int) $site->baseline_views + (int) $site->live_views;

        $rawTargets = [];
        DB::table(Tables::TRACKS)
            ->where('id', '<=', $maximumTrackId)
            ->whereNotNull('target_type')
            ->where('target_id', '>', 0)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$rawTargets): void {
                foreach ($rows as $row) {
                    $target = $this->targets->resolve(
                        (string) $row->target_type,
                        (int) $row->target_id,
                        null,
                        [],
                        null
                    );
                    if ($target !== null) {
                        $rawTargets[$target['target_key']] =
                            ($rawTargets[$target['target_key']] ?? 0) + (int) $row->view;
                    }
                }
            }, 'id');

        $targetMismatches = 0;
        foreach (TrackingTargetTotal::query()->get() as $target) {
            //  Claim the raw side first: rows excluded from the comparison
            //  below must not leave raw residue behind, or the final
            //  unmatched-raw sweep would report every skipped target as a
            //  mismatch.
            $rawViews = $rawTargets[$target->target_key] ?? 0;
            unset($rawTargets[$target->target_key]);

            if ($target->baseline_quality !== 'verified') {
                continue;
            }
            //  Path targets live only in the aggregate side: page beacons never
            //  write legacy `tracks` rows, so a raw comparison is undefined for
            //  them and would flag every tracked page as a mismatch.
            if ($target->target_id === null) {
                continue;
            }
            $expected = (int) $target->baseline_views + (int) $target->live_views;
            if ($rawViews !== $expected) {
                $targetMismatches++;
            }
        }
        $targetMismatches += count(array_filter(
            $rawTargets,
            static fn (int $views): bool => $views !== 0
        ));

        $receiptFacts = $this->reconcileReceipts();

        return [
            'site_mismatch' => $rawSite === $expectedSite ? 0 : 1,
            'site_difference' => $rawSite - $expectedSite,
            'target_mismatches' => $targetMismatches,
            ...$receiptFacts,
        ];
    }

    /**
     * @return array{
     *   receipt_site_mismatch:int,
     *   receipt_target_mismatches:int,
     *   uncompensated_quarantined_events:int
     * }
     */
    public function reconcileReceipts(): array
    {
        $site = TrackingSiteTotal::query()->whereKey(1)->firstOrFail();
        [$receiptSiteViews, $receiptTargetViews] = $this->appliedReceiptViews();
        $receiptTargetMismatches = 0;
        foreach (TrackingTargetTotal::query()->get() as $target) {
            if (($receiptTargetViews[$target->target_key] ?? 0) !== (int) $target->live_views) {
                $receiptTargetMismatches++;
            }
            unset($receiptTargetViews[$target->target_key]);
        }
        $receiptTargetMismatches += count(array_filter(
            $receiptTargetViews,
            static fn (int $views): bool => $views !== 0
        ));

        return [
            'receipt_site_mismatch' => $receiptSiteViews === (int) $site->live_views ? 0 : 1,
            'receipt_target_mismatches' => $receiptTargetMismatches,
            'uncompensated_quarantined_events' => TrackingEventReceipt::query()
                ->where('status', TrackingEventReceipt::STATUS_QUARANTINED)
                ->where(function ($query): void {
                    $query->whereNull('resolution_status')
                        ->orWhere('resolution_status', '!=', 'compensated');
                })
                ->count(),
        ];
    }

    /**
     * @return array{0:int,1:array<string,int>}
     */
    private function appliedReceiptViews(): array
    {
        $site = 0;
        $targets = [];
        TrackingEventReceipt::query()
            ->where('status', TrackingEventReceipt::STATUS_APPLIED)
            ->whereIn('generation_mode', [
                \App\Models\Tracking\TrackingRuntimeState::MODE_DUAL,
                \App\Models\Tracking\TrackingRuntimeState::MODE_AGGREGATE,
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($receipts) use (&$site, &$targets): void {
                foreach ($receipts as $receipt) {
                    $views = (int) data_get($receipt->counter_deltas, 'legacy_views', 0);
                    $site += $views;
                    if ($receipt->target_key !== null) {
                        $targets[$receipt->target_key] =
                            ($targets[$receipt->target_key] ?? 0) + $views;
                    }
                }
            });

        return [$site, $targets];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function checksum(array $manifest): string
    {
        unset($manifest['checksum']);

        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($manifest),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function assertManifestOwnership(?string $current, string $incoming): void
    {
        if ($current !== null && !hash_equals($current, $incoming)) {
            throw new RuntimeException(
                'Baseline already belongs to a different manifest; correction approval is required.'
            );
        }
    }
}
