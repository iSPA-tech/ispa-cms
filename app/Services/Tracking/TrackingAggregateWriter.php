<?php

namespace App\Services\Tracking;

use App\Constants\Tables;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrackingAggregateWriter
{
    private const ALLOWED_COUNTERS = [
        'legacy_views',
        'page_views',
        'scans',
        'place_scans',
        'item_scans',
        'downloads',
        'site_sessions',
        'site_visitors',
        'target_sessions',
        'target_visitors',
    ];

    public function process(string $eventId): void
    {
        if (!TrackingEventReceipt::query()->where('event_id', $eventId)->exists()) {
            Log::error('tracking.missing_ledger', ['event_id' => $eventId]);

            return;
        }

        DB::transaction(function () use ($eventId): void {
            $receipt = TrackingEventReceipt::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($receipt === null || in_array($receipt->status, [
                TrackingEventReceipt::STATUS_APPLIED,
                TrackingEventReceipt::STATUS_QUARANTINED,
            ], true)) {
                return;
            }

            $invalidReason = $this->invalidReason($receipt);
            if ($invalidReason !== null) {
                $this->quarantine($receipt, $invalidReason);

                return;
            }

            if (CarbonImmutable::now('UTC')->greaterThan($receipt->replay_until)) {
                $this->quarantine($receipt, 'replay_expired');

                return;
            }

            $state = TrackingRuntimeState::query()->whereKey(1)->sharedLock()->firstOrFail();
            if (
                (int) $state->write_generation === (int) $receipt->write_generation
                && $state->processing_state === TrackingRuntimeState::PROCESSING_HELD
            ) {
                return;
            }

            $deltas = $receipt->counter_deltas;
            if (in_array($receipt->generation_mode, [
                TrackingRuntimeState::MODE_DUAL,
                TrackingRuntimeState::MODE_AGGREGATE,
            ], true)) {
                $this->applyAggregates($receipt, $deltas);
            }

            if (in_array($receipt->generation_mode, [
                TrackingRuntimeState::MODE_LEGACY,
                TrackingRuntimeState::MODE_DUAL,
            ], true)) {
                $this->applyLegacy($receipt);
            }

            $receipt->forceFill([
                'status' => TrackingEventReceipt::STATUS_APPLIED,
                'processed_at' => CarbonImmutable::now('UTC'),
                'quarantine_reason' => null,
            ])->save();
        });
    }

    /**
     * @param array<string, int> $deltas
     */
    private function applyAggregates(TrackingEventReceipt $receipt, array $deltas): void
    {
        $now = CarbonImmutable::now('UTC');
        $date = $receipt->event_date->toDateString();

        DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->update([
            'live_views' => DB::raw('live_views + ' . $deltas['legacy_views']),
            'live_scans' => DB::raw('live_scans + ' . $deltas['scans']),
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
            'views' => DB::raw('views + ' . $deltas['legacy_views']),
            'visitors' => DB::raw('visitors + ' . $deltas['site_visitors']),
            'sessions' => DB::raw('sessions + ' . $deltas['site_sessions']),
            'scans' => DB::raw('scans + ' . $deltas['scans']),
            'place_scan' => DB::raw('place_scan + ' . $deltas['place_scans']),
            'item_scan' => DB::raw('item_scan + ' . $deltas['item_scans']),
            'download' => DB::raw('download + ' . $deltas['downloads']),
            'late_event_count' => DB::raw(
                'late_event_count + CASE WHEN is_closed THEN 1 ELSE 0 END'
            ),
            'last_applied_at' => $now,
            'updated_at' => $now,
        ]);

        if ($receipt->target_key === null) {
            return;
        }

        DB::table(Tables::TRACKING_TARGET_TOTALS)->insertOrIgnore([
            'target_key' => $receipt->target_key,
            'target_type' => $receipt->target_type,
            'target_id' => $receipt->target_id,
            'baseline_views' => 0,
            'live_views' => 0,
            'baseline_scans' => 0,
            'live_scans' => 0,
            'baseline_quality' => 'unavailable',
            'scans_quality' => 'unavailable',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table(Tables::TRACKING_TARGET_TOTALS)
            ->where('target_key', $receipt->target_key)
            ->update([
                'live_views' => DB::raw('live_views + ' . $deltas['legacy_views']),
                'live_scans' => DB::raw('live_scans + ' . $deltas['scans']),
                'updated_at' => $now,
            ]);

        DB::table(Tables::TRACKING_TARGET_DAILY)->insertOrIgnore([
            'date' => $date,
            'target_key' => $receipt->target_key,
            'target_type' => $receipt->target_type,
            'target_id' => $receipt->target_id,
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
            ->where('target_key', $receipt->target_key)
            ->update([
                'total_views' => DB::raw('total_views + ' . $deltas['legacy_views']),
                'total_sessions' => DB::raw('total_sessions + ' . $deltas['target_sessions']),
                'total_visitors' => DB::raw('total_visitors + ' . $deltas['target_visitors']),
                'total_scans' => DB::raw('total_scans + ' . $deltas['scans']),
                'total_downloads' => DB::raw('total_downloads + ' . $deltas['downloads']),
                'updated_at' => $now,
            ]);

        $catalog = DB::table(Tables::TRACKING_TARGET_CATALOG)
            ->where('target_key', $receipt->target_key)
            ->first();
        $metadata = [
            'target_kind' => $receipt->target_kind,
            'display_label' => $receipt->display_label,
            'public_path' => $receipt->public_path,
            'is_rankable' => $receipt->is_rankable,
        ];

        if ($catalog === null) {
            DB::table(Tables::TRACKING_TARGET_CATALOG)->insert([
                'target_key' => $receipt->target_key,
                ...$metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } elseif ($this->catalogChanged($catalog, $metadata)) {
            DB::table(Tables::TRACKING_TARGET_CATALOG)
                ->where('target_key', $receipt->target_key)
                ->update([...$metadata, 'updated_at' => $now]);
        }
    }

    private function applyLegacy(TrackingEventReceipt $receipt): void
    {
        $sessionId = (string) $receipt->legacy_session_id;
        $targetType = $receipt->target_type;
        $targetId = (int) ($receipt->target_id ?? 0);
        $occurredAt = $this->legacyTimestamp($receipt);
        $cacheKey = 'track:map:' . hash(
            'sha256',
            "{$sessionId}|{$targetType}|{$targetId}"
        );

        $rowId = Cache::get($cacheKey);
        if ($rowId !== null) {
            $updated = DB::table(Tables::TRACKS)->where('id', $rowId)->update([
                'view' => DB::raw('view + 1'),
                'updated_at' => $occurredAt,
            ]);
            if ($updated === 1) {
                return;
            }

            Cache::forget($cacheKey);
        }

        $query = DB::table(Tables::TRACKS)
            ->where('session_id', $sessionId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId);
        $existingId = $query->value('id');

        if ($existingId !== null) {
            DB::table(Tables::TRACKS)->where('id', $existingId)->update([
                'view' => DB::raw('view + 1'),
                'updated_at' => $occurredAt,
            ]);
            $this->cacheRawRowAfterCommit($cacheKey, (int) $existingId);

            return;
        }

        $rowId = DB::table(Tables::TRACKS)->insertGetId([
            'tags' => json_encode($receipt->legacy_tags ?? [], JSON_THROW_ON_ERROR),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $receipt->legacy_ip_address,
            'agent' => $receipt->legacy_agent,
            'url' => $receipt->legacy_url,
            'session_id' => $sessionId,
            'view' => 1,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
        $this->cacheRawRowAfterCommit($cacheKey, (int) $rowId);
    }

    /**
     * Raw tracks rows keep the app's local wall clock, which is what the
     * nightly finalizers window by. The receipt stores its instant as a UTC
     * wall clock and Eloquent reads that back in the app timezone, so the
     * instant is rebuilt in UTC before converting.
     */
    private function legacyTimestamp(TrackingEventReceipt $receipt): string
    {
        return CarbonImmutable::parse((string) $receipt->getRawOriginal('occurred_at'), 'UTC')
            ->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    private function cacheRawRowAfterCommit(string $cacheKey, int $rowId): void
    {
        DB::connection()->afterCommit(static function () use ($cacheKey, $rowId): void {
            try {
                Cache::put($cacheKey, $rowId, now()->addHours(6));
            } catch (Throwable $exception) {
                Log::warning('tracking.raw_row_cache_failed', [
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    private function invalidReason(TrackingEventReceipt $receipt): ?string
    {
        if ((int) $receipt->envelope_version !== (int) config('tracking.envelope_version', 1)) {
            return 'unsupported_envelope_version';
        }

        if (!in_array($receipt->generation_mode, [
            TrackingRuntimeState::MODE_LEGACY,
            TrackingRuntimeState::MODE_DUAL,
            TrackingRuntimeState::MODE_AGGREGATE,
        ], true) || $receipt->event_date === null || !is_array($receipt->counter_deltas)) {
            return 'malformed_envelope';
        }

        $maximum = (int) config('tracking.max_counter_delta', 1);
        foreach ($receipt->counter_deltas as $counter => $delta) {
            if (
                !in_array($counter, self::ALLOWED_COUNTERS, true)
                || !is_int($delta)
                || $delta < 0
                || $delta > $maximum
            ) {
                return 'malformed_counter_deltas';
            }
        }

        foreach (self::ALLOWED_COUNTERS as $counter) {
            if (!array_key_exists($counter, $receipt->counter_deltas)) {
                return 'malformed_counter_deltas';
            }
        }

        return null;
    }

    private function quarantine(TrackingEventReceipt $receipt, string $reason): void
    {
        $receipt->forceFill([
            'status' => TrackingEventReceipt::STATUS_QUARANTINED,
            'quarantine_reason' => $reason,
            'processed_at' => CarbonImmutable::now('UTC'),
        ])->save();
    }

    /**
     * @param object $catalog
     * @param array<string, mixed> $metadata
     */
    private function catalogChanged(object $catalog, array $metadata): bool
    {
        foreach ($metadata as $key => $value) {
            if ($key === 'is_rankable') {
                if ((bool) $catalog->{$key} !== (bool) $value) {
                    return true;
                }
            } elseif ($catalog->{$key} !== $value) {
                return true;
            }
        }

        return false;
    }
}
