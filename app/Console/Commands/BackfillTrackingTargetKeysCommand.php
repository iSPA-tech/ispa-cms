<?php

namespace App\Console\Commands;

use App\Constants\Tables;
use App\Services\Tracking\TrackingFinalizerDateRange;
use App\Services\Tracking\TrackingTargetKeyResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillTrackingTargetKeysCommand extends Command
{
    protected $signature = 'cms:tracking-backfill-target-keys
                            {--from= : First closed analytics date}
                            {--to= : Last closed analytics date}
                            {--after-id=0 : Resume after this legacy row ID}
                            {--limit=1000 : Maximum legacy rows to inspect}
                            {--max-days=3660 : Maximum bounded date span}
                            {--allow-unresolved : Skip legacy rows whose target no longer resolves (e.g. deleted content) instead of failing the batch}
                            {--force : Persist the verified batch}';

    protected $description = 'Dry-run or copy bounded legacy target-day facts to canonical target keys';

    public function handle(
        TrackingFinalizerDateRange $ranges,
        TrackingTargetKeyResolver $targets
    ): int {
        try {
            $dates = $ranges->resolve(
                null,
                $this->option('from'),
                $this->option('to'),
                false,
                max(1, (int) $this->option('max-days'))
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $lock = Cache::lock('tracking:target-key-backfill', 300);
        if (!$lock->get()) {
            $this->error('Target-key backfill is already running.');

            return self::FAILURE;
        }

        try {
            $from = $dates[0]->toDateString();
            $to = $dates[array_key_last($dates)]->toDateString();
            $rows = DB::table(Tables::TRACKING_TOP_DAILY)
                ->where('id', '>', max(0, (int) $this->option('after-id')))
                ->whereBetween('date', [$from, $to])
                ->orderBy('id')
                ->limit(max(1, min(10000, (int) $this->option('limit'))))
                ->get();

            $resolved = [];
            $unresolved = 0;
            $unresolvedViews = 0;
            foreach ($rows as $row) {
                $target = $targets->resolve(
                    (string) $row->target_type,
                    (int) $row->target_id,
                    null,
                    [],
                    null
                );
                if ($target === null) {
                    $unresolved++;
                    $unresolvedViews += (int) $row->total_views;
                    continue;
                }

                $key = "{$row->date}|{$target['target_key']}";
                if (isset($resolved[$key])) {
                    $this->error('Duplicate canonical target/date detected; create an explicit merge plan.');

                    return self::FAILURE;
                }

                $resolved[$key] = [
                    'date' => $row->date,
                    'target_key' => $target['target_key'],
                    'target_type' => $row->target_type,
                    'target_id' => $row->target_id,
                    'total_views' => (int) $row->total_views,
                    'total_sessions' => 0,
                    'total_visitors' => 0,
                    'total_scans' => 0,
                    'total_downloads' => 0,
                    'aggregate_version' => (int) config('tracking.aggregate_contract_version', 1),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $this->line(implode(' ', [
                'rows=' . $rows->count(),
                'views=' . (int) $rows->sum('total_views'),
                'resolved=' . count($resolved),
                "unresolved={$unresolved}",
                "unresolved_views={$unresolvedViews}",
                'cursor=' . (int) ($rows->last()->id ?? $this->option('after-id')),
                'mode=' . ($this->option('force') ? 'write' : 'dry-run'),
            ]));

            if ($unresolved > 0 && !$this->option('allow-unresolved')) {
                return self::FAILURE;
            }
            if (!$this->option('force') || $resolved === []) {
                return self::SUCCESS;
            }

            return DB::transaction(function () use ($resolved): int {
                foreach ($resolved as $row) {
                    $existing = DB::table(Tables::TRACKING_TARGET_DAILY)
                        ->where('date', $row['date'])
                        ->where('target_key', $row['target_key'])
                        ->lockForUpdate()
                        ->first();
                    if ($existing !== null) {
                        if ((int) $existing->total_views !== $row['total_views']) {
                            $this->error('Canonical target/date conflicts with legacy total.');

                            return self::FAILURE;
                        }

                        continue;
                    }

                    DB::table(Tables::TRACKING_TARGET_DAILY)->insert($row);
                }

                return self::SUCCESS;
            });
        } finally {
            $lock->release();
        }
    }
}
