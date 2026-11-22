<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**********************************************************************************************************************
 * Command to run top-range aggregation.
 **********************************************************************************************************************/

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Jobs\TopRangeAggregationJob;
use App\Models\Tracking\TrackingTop;
use App\Models\Tracking\TrackingTargetDaily;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TrackingTopCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * Example usage:
     *   php artisan cms:tracking-top --range=7d --target=Cms\\Content\\Models\\Post
     *   php artisan cms:tracking-top --range=1y --all-targets
     */
    protected $signature = 'cms:tracking-top
                            {--range=7d : The date range, e.g. 7d,1m,3m,6m,1y}
                            {--target= : (Optional) target_type, e.g. Cms\\Content\\Models\\Post}
                            {--all-targets : Rebuild a separate per-type ranking for every target type seen in the range}';

    /**
     * The console command description.
     */
    protected $description = 'Aggregate and store top items for a given date range (7d,1m,3m,6m,1y), optionally filtered by target_type.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $range = $this->option('range') ?? '7d';
        $targetType = $this->option('target'); // e.g. "Cms\Content\Models\Post"
        $allTargets = (bool) $this->option('all-targets');

        if ($allTargets && $targetType) {
            $this->error('Use either --target or --all-targets, not both.');

            return self::FAILURE;
        }

        $lock = Cache::lock(
            'tracking:top-range:' . hash('sha256', $allTargets ? "{$range}|__all__" : "{$range}|{$targetType}"),
            1800
        );
        if (!$lock->get()) {
            $this->error('This tracking top range rebuild is already running.');

            return self::FAILURE;
        }

        try {
            $types = $allTargets ? $this->discoverTargetTypes($range) : [$targetType ?: null];

            foreach ($types as $type) {
                $this->info("Running TopRangeAggregationJob for range: {$range}" . ($type ? " and target: {$type}" : ''));

                dispatch_sync(new TopRangeAggregationJob($range, $type, 50));
            }

            if ($allTargets) {
                $this->pruneRetiredTypes($range, $types);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Every target bucket with at least one view inside the range: one per
     * concrete class, plus the route bucket for page targets, which carry a
     * null target_type because they are not backed by a model.
     *
     * @return array<int, string>
     */
    protected function discoverTargetTypes(string $range): array
    {
        $rangeEnd = Carbon::yesterday();
        $rangeStart = TopRangeAggregationJob::rangeStartFor($rangeEnd, $range);
        $window = TrackingTargetDaily::query()
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);

        $types = (clone $window)
            ->whereNotNull('target_type')
            ->distinct()
            ->orderBy('target_type')
            ->pluck('target_type')
            ->all();

        if ((clone $window)->whereNull('target_type')->exists()) {
            $types[] = TopRangeAggregationJob::ROUTE_TYPE;
        }

        return $types;
    }

    /**
     * Drop rankings for types that no longer have any views in the range, and
     * null-type rows left behind by legacy untargeted (mixed) runs. Each
     * per-type job only deletes its own type, so without this sweep a retired
     * type would keep serving a stale top list forever.
     *
     * @param array<int, string> $types
     */
    protected function pruneRetiredTypes(string $range, array $types): void
    {
        TrackingTop::query()
            ->where('range_name', $range)
            ->where(function ($query) use ($types) {
                $query->whereNull('target_type');

                if ($types !== []) {
                    $query->orWhereNotIn('target_type', $types);
                } else {
                    $query->orWhereNotNull('target_type');
                }
            })
            ->delete();
    }
}
