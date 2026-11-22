<?php

namespace App\Console\Commands;

use App\Jobs\TrackingTopDailyAggregationJob;
use App\Services\Tracking\TrackingFinalizerDateRange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrackingTopDailyCommand extends Command
{
    protected $signature = 'cms:tracking-top-daily
                            {--date= : One closed analytics date}
                            {--from= : First closed date in a bounded range}
                            {--to= : Last closed date in a bounded range}
                            {--max-days=31 : Refuse ranges larger than this}
                            {--all : Deprecated; requires --from and --to}';

    protected $description = 'Finalize bounded canonical target tracking dates';

    public function handle(TrackingFinalizerDateRange $ranges): int
    {
        $lock = Cache::lock(
            (string) config('tracking.finalizer.lock', 'tracking:finalizer'),
            3600
        );
        if (!$lock->get()) {
            $this->error('Tracking finalizer is already running.');

            return self::FAILURE;
        }

        try {
            $dates = $ranges->resolve(
                $this->option('date'),
                $this->option('from'),
                $this->option('to'),
                (bool) $this->option('all'),
                min(
                    (int) $this->option('max-days'),
                    (int) config('tracking.finalizer.max_days', 31)
                )
            );
            foreach ($dates as $date) {
                dispatch_sync(new TrackingTopDailyAggregationJob($date));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('tracking.target_finalizer_failed', [
                'exception' => $exception::class,
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
