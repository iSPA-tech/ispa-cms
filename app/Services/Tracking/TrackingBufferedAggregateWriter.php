<?php

namespace App\Services\Tracking;

use App\Constants\Tables;
use App\Models\Tracking\TrackingCounterFlushBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrackingBufferedAggregateWriter
{
    private const ALLOWED_COUNTERS = [
        'legacy_views',
        'page_views',
        'site_sessions',
        'site_visitors',
        'target_sessions',
        'target_visitors',
    ];

    /** @param array<string, mixed> $snapshot */
    public function apply(array $snapshot): string
    {
        $this->validate($snapshot);
        $canonical = $this->sortRecursive($snapshot);
        $checksum = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $bucketId = (string) $snapshot['bucket_id'];

        return DB::transaction(function () use ($snapshot, $checksum, $bucketId): string {
            $existing = TrackingCounterFlushBatch::query()
                ->where('bucket_id', $bucketId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (!hash_equals($existing->checksum, $checksum)) {
                    throw new RuntimeException("Tracking bucket checksum mismatch [{$bucketId}].");
                }

                return 'already_applied';
            }

            $now = CarbonImmutable::now('UTC');
            $date = (string) $snapshot['date'];
            $site = (array) $snapshot['site'];
            $this->applySite($date, $site, $now);
            foreach ((array) $snapshot['targets'] as $targetKey => $target) {
                $this->applyTarget($date, (string) $targetKey, (array) $target, $now);
            }

            TrackingCounterFlushBatch::query()->create([
                'bucket_id' => $bucketId,
                'checksum' => $checksum,
                'status' => 'applied',
                'write_generation' => (int) $snapshot['write_generation'],
                'deltas' => [
                    'date' => $date,
                    'site' => $site,
                    'targets' => (array) $snapshot['targets'],
                ],
                'target_count' => count((array) $snapshot['targets']),
                'total_views' => (int) ($site['legacy_views'] ?? 0),
                'applied_at' => $now,
            ]);

            return 'applied';
        });
    }

    /** @param array<string, int> $site */
    private function applySite(string $date, array $site, CarbonImmutable $now): void
    {
        $views = (int) ($site['legacy_views'] ?? 0);
        $sessions = (int) ($site['site_sessions'] ?? 0);
        $visitors = (int) ($site['site_visitors'] ?? 0);
        DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->update([
            'live_views' => DB::raw('live_views + ' . $views),
            'updated_at' => $now,
        ]);
        DB::table(Tables::TRACKING_DAILY)->insertOrIgnore([
            'date' => $date,
            'views' => 0,
            'visitors' => 0,
            'place_scan' => 0,
            'item_scan' => 0,
            'scans' => 0,
            'download' => 0,
            'sessions' => 0,
            'is_closed' => false,
            'aggregate_version' => (int) config('tracking.aggregate_contract_version', 1),
            'late_event_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table(Tables::TRACKING_DAILY)->whereDate('date', $date)->update([
            'views' => DB::raw('views + ' . $views),
            'visitors' => DB::raw('visitors + ' . $visitors),
            'sessions' => DB::raw('sessions + ' . $sessions),
            'last_applied_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $target */
    private function applyTarget(
        string $date,
        string $targetKey,
        array $target,
        CarbonImmutable $now
    ): void {
        $deltas = (array) ($target['deltas'] ?? []);
        $views = (int) ($deltas['legacy_views'] ?? 0);
        $sessions = (int) ($deltas['target_sessions'] ?? 0);
        $visitors = (int) ($deltas['target_visitors'] ?? 0);
        $targetType = $target['target_type'] ?? null;
        $targetId = $target['target_id'] ?? null;

        DB::table(Tables::TRACKING_TARGET_TOTALS)->insertOrIgnore([
            'target_key' => $targetKey,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'baseline_views' => 0,
            'live_views' => 0,
            'baseline_scans' => 0,
            'live_scans' => 0,
            'baseline_quality' => 'unavailable',
            'scans_quality' => 'unavailable',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table(Tables::TRACKING_TARGET_TOTALS)->where('target_key', $targetKey)->update([
            'live_views' => DB::raw('live_views + ' . $views),
            'updated_at' => $now,
        ]);

        DB::table(Tables::TRACKING_TARGET_DAILY)->insertOrIgnore([
            'date' => $date,
            'target_key' => $targetKey,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'total_views' => 0,
            'total_sessions' => 0,
            'total_visitors' => 0,
            'total_scans' => 0,
            'total_downloads' => 0,
            'aggregate_version' => (int) config('tracking.aggregate_contract_version', 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table(Tables::TRACKING_TARGET_DAILY)
            ->where('date', $date)
            ->where('target_key', $targetKey)
            ->update([
                'total_views' => DB::raw('total_views + ' . $views),
                'total_sessions' => DB::raw('total_sessions + ' . $sessions),
                'total_visitors' => DB::raw('total_visitors + ' . $visitors),
                'updated_at' => $now,
            ]);

        $metadata = [
            'target_kind' => $target['target_kind'] ?? null,
            'display_label' => $target['display_label'] ?? null,
            'public_path' => $target['public_path'] ?? null,
            'is_rankable' => (bool) ($target['is_rankable'] ?? false),
        ];
        $catalog = DB::table(Tables::TRACKING_TARGET_CATALOG)
            ->where('target_key', $targetKey)
            ->first();
        if ($catalog === null) {
            DB::table(Tables::TRACKING_TARGET_CATALOG)->insert([
                'target_key' => $targetKey,
                ...$metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table(Tables::TRACKING_TARGET_CATALOG)
                ->where('target_key', $targetKey)
                ->update([...$metadata, 'updated_at' => $now]);
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function validate(array $snapshot): void
    {
        if (
            !is_string($snapshot['bucket_id'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:e\d+$/', $snapshot['bucket_id'])
            || !is_string($snapshot['date'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot['date'])
            || ($snapshot['status'] ?? null) !== 'sealed'
            || !is_int($snapshot['write_generation'] ?? null)
            || $snapshot['write_generation'] < 1
        ) {
            throw new RuntimeException('Malformed or unsealed tracking buffer snapshot.');
        }
        foreach ([(array) ($snapshot['site'] ?? [])] as $deltas) {
            $this->validateDeltas($deltas);
        }
        foreach ((array) ($snapshot['targets'] ?? []) as $targetKey => $target) {
            if (!is_string($targetKey) || $targetKey === '' || !is_array($target)) {
                throw new RuntimeException('Malformed tracking buffer target.');
            }
            $this->validateDeltas((array) ($target['deltas'] ?? []));
        }
    }

    /** @param array<string, mixed> $deltas */
    private function validateDeltas(array $deltas): void
    {
        foreach ($deltas as $counter => $value) {
            if (!in_array($counter, self::ALLOWED_COUNTERS, true) || !is_int($value) || $value < 0) {
                throw new RuntimeException("Invalid buffered tracking counter [{$counter}].");
            }
        }
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }
}
