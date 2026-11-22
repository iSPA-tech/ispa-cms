<?php

namespace App\Jobs;

use App\Constants\Tables;
use App\Models\Tracking\Track;
use App\Models\Tracking\TrackingEventReceipt;
use App\Models\Tracking\TrackingRuntimeState;
use App\Services\Tracking\TrackingTargetKeyResolver;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class TrackingTopDailyAggregationJob implements ShouldQueue
{
    use Queueable;

    protected Carbon $date;

    public function __construct(?Carbon $date = null)
    {
        $this->date = ($date ?? Carbon::yesterday())->copy()->startOfDay();
    }

    public function handle(?TrackingTargetKeyResolver $targets = null): void
    {
        $analyticsToday = Carbon::now((string) config('tracking.analytics_timezone'))
            ->startOfDay();
        if ($this->date->greaterThanOrEqualTo($analyticsToday)) {
            throw new LogicException('Target tracking finalizer cannot close the current analytics day.');
        }

        $date = $this->date->toDateString();
        $state = TrackingRuntimeState::query()->findOrFail(1);
        if (!$this->legacyOwnsDate($state, $date)) {
            return;
        }

        $targets ??= app(TrackingTargetKeyResolver::class);
        $aggregates = Track::query()
            ->whereBetween('updated_at', [
                $this->date,
                $this->date->copy()->endOfDay(),
            ])
            ->whereNotNull('target_type')
            ->where('target_id', '>', 0)
            ->selectRaw('target_type, target_id, SUM(view) AS total_views_sum')
            ->groupBy('target_type', 'target_id')
            ->get();

        // Page views carry no model, so their raw rows hold no target and are
        // skipped above. Their route key lives only on the receipt, and every
        // applied legacy receipt is exactly one view.
        $pages = DB::table(Tables::TRACKING_EVENT_RECEIPTS)
            ->where('event_date', '>=', $date)
            ->where('event_date', '<', $this->date->copy()->addDay()->toDateString())
            ->where('status', TrackingEventReceipt::STATUS_APPLIED)
            ->where('generation_mode', TrackingRuntimeState::MODE_LEGACY)
            ->where('target_kind', 'route')
            ->whereNotNull('target_key')
            ->selectRaw('target_key, COUNT(*) AS total_views_sum')
            ->groupBy('target_key')
            ->get();

        DB::transaction(function () use ($targets, $aggregates, $pages): void {
            $state = TrackingRuntimeState::query()->whereKey(1)->sharedLock()->firstOrFail();
            $date = $this->date->toDateString();
            if (!$this->legacyOwnsDate($state, $date)) {
                return;
            }

            $now = now('UTC');
            $canonicalTotals = [];
            foreach ($aggregates as $aggregate) {
                $this->writeLegacyRollup($aggregate, $date, $now);

                $target = $targets->resolve(
                    (string) $aggregate->target_type,
                    (int) $aggregate->target_id,
                    null,
                    [],
                    null
                );
                $targetKey = $target['target_key'] ?? null;
                if ($targetKey === null) {
                    // Canonical facts require a target_key; a retired class name
                    // must not abort the whole day with a NOT NULL violation.
                    continue;
                }

                // Retired and current class names share one alias, so several
                // aggregates can collapse onto a single (date, target_key) row.
                $canonicalTotals[$targetKey] ??= [
                    'target_type' => $this->canonicalTypeFor(
                        $targetKey,
                        (string) $aggregate->target_type
                    ),
                    'target_id' => $aggregate->target_id,
                    'total_views' => 0,
                ];
                $canonicalTotals[$targetKey]['total_views'] +=
                    (int) $aggregate->total_views_sum;
            }

            // Same row shape the aggregate writer gives route targets.
            foreach ($pages as $page) {
                $canonicalTotals[(string) $page->target_key] = [
                    'target_type' => null,
                    'target_id' => null,
                    'total_views' => (int) $page->total_views_sum,
                ];
            }

            foreach ($canonicalTotals as $targetKey => $totals) {
                $canonicalId = DB::table(Tables::TRACKING_TARGET_DAILY)
                    ->where('date', $date)
                    ->where('target_key', $targetKey)
                    ->value('id');
                $values = [
                    'date' => $date,
                    'target_key' => $targetKey,
                    'target_type' => $totals['target_type'],
                    'target_id' => $totals['target_id'],
                    'total_views' => $totals['total_views'],
                    'aggregate_version' => (int) config('tracking.aggregate_contract_version', 1),
                    'updated_at' => $now,
                ];

                if ($canonicalId === null) {
                    DB::table(Tables::TRACKING_TARGET_DAILY)->insert([
                        ...$values,
                        'created_at' => $now,
                    ]);
                } else {
                    DB::table(Tables::TRACKING_TARGET_DAILY)
                        ->where('id', $canonicalId)
                        ->update($values);
                }
            }
        });
    }

    /**
     * One target_key must always carry one class, otherwise a single target
     * splits into duplicate dashboard rows and loses its label whenever the
     * stored class no longer exists.
     */
    private function canonicalTypeFor(string $targetKey, string $fallback): string
    {
        $alias = Str::before($targetKey, ':');
        $canonical = null;
        foreach ((array) config('tracking.target_aliases', []) as $class => $mapped) {
            // Retired names are listed before their replacement, so the last
            // loadable match is the class labels still resolve through.
            if ($mapped === $alias && class_exists($class)) {
                $canonical = $class;
            }
        }

        return $canonical ?? $fallback;
    }

    private function writeLegacyRollup(object $aggregate, string $date, Carbon $now): void
    {
        DB::table(Tables::TRACKING_TOP_DAILY)->updateOrInsert([
            'date' => $date,
            'target_type' => $aggregate->target_type,
            'target_id' => $aggregate->target_id,
        ], [
            'total_views' => (int) $aggregate->total_views_sum,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function legacyOwnsDate(TrackingRuntimeState $state, string $date): bool
    {
        if ($state->direct_counter_owner === 'legacy') {
            return true;
        }

        return $state->first_direct_write_date !== null
            && $date < $state->first_direct_write_date->toDateString();
    }
}
