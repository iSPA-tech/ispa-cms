<?php

namespace App\Console\Commands;

use App\Constants\Tables;
use App\Services\Tracking\TrackingFinalizerDateRange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditTrackingAggregatesCommand extends Command
{
    protected $signature = 'cms:tracking-audit-aggregates
                            {--from= : First closed analytics date}
                            {--to= : Last closed analytics date}
                            {--after-id=0 : Reserved resume cursor}
                            {--max-days=3660 : Maximum bounded date span}';

    protected $description = 'Audit aggregate rows before final uniqueness constraints';

    public function handle(TrackingFinalizerDateRange $ranges): int
    {
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

        $lock = Cache::lock('tracking:aggregate-audit', 120);
        if (!$lock->get()) {
            $this->error('Aggregate audit is already running.');

            return self::FAILURE;
        }

        try {
            $from = $dates[0]->toDateString();
            $to = $dates[array_key_last($dates)]->toDateString();
            $site = DB::table(Tables::TRACKING_DAILY);
            $target = DB::table(Tables::TRACKING_TARGET_DAILY);

            $siteNullDates = (clone $site)->whereNull('date')->count();
            $siteDuplicateDates = DB::query()->fromSub(
                DB::table(Tables::TRACKING_DAILY)
                    ->whereNotNull('date')
                    ->select('date')
                    ->groupBy('date')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_site_dates'
            )->count();
            $targetNullDates = (clone $target)->whereNull('date')->count();
            $targetNullKeys = (clone $target)->whereNull('target_key')->count();
            $targetDuplicateKeys = DB::query()->fromSub(
                DB::table(Tables::TRACKING_TARGET_DAILY)
                    ->whereNotNull('date')
                    ->whereNotNull('target_key')
                    ->select(['date', 'target_key'])
                    ->groupBy(['date', 'target_key'])
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_target_keys'
            )->count();

            $this->line(implode(' ', [
                "site_rows={$site->whereBetween('date', [$from, $to])->count()}",
                'site_views=' . (int) DB::table(Tables::TRACKING_DAILY)
                    ->whereBetween('date', [$from, $to])
                    ->sum('views'),
                "site_null_dates={$siteNullDates}",
                "site_duplicate_dates={$siteDuplicateDates}",
                "target_rows={$target->whereBetween('date', [$from, $to])->count()}",
                'target_views=' . (int) DB::table(Tables::TRACKING_TARGET_DAILY)
                    ->whereBetween('date', [$from, $to])
                    ->sum('total_views'),
                "target_null_dates={$targetNullDates}",
                "target_null_keys={$targetNullKeys}",
                "target_duplicate_keys={$targetDuplicateKeys}",
            ]));

            return (
                $siteNullDates
                + $siteDuplicateDates
                + $targetNullDates
                + $targetNullKeys
                + $targetDuplicateKeys
            ) === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
