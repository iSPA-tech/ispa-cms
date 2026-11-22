<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use App\Models\Tracking\TrackingTop;
use Illuminate\Queue\SerializesModels;
use App\Models\Tracking\TrackingTargetDaily;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

class TopRangeAggregationJob implements ShouldQueue
{
    /**
     * Page targets are routes, not Eloquent models, so they carry no class in
     * the daily facts. They rank under this pseudo-type instead of vanishing.
     */
    public const ROUTE_TYPE = 'route';

    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, Queueable, SerializesModels;

    /**
     * The desired range, e.g. '7d', '1m', '3m', '6m', '1y', etc.
     */
    protected string $rangeName;

    /**
     * The target_type to filter by. For example, 'Cms\Content\Models\Post'.
     * If null, we'll include all target types.
     */
    protected ?string $targetType;

    /**
     * How many results to store in `tracking_top`.
     */
    protected int $limit;

    /**
     * Create a new job instance.
     *
     * @param string $rangeName The range identifier (e.g. '7d', '1m', etc).
     * @param string|null $targetType Optional. e.g. 'Cms\Content\Models\Post'
     * @param int $limit How many top items to store.
     */
    public function __construct(string $rangeName, ?string $targetType = null, int $limit = 50)
    {
        $this->rangeName = $rangeName;
        $this->targetType = $targetType;
        $this->limit = $limit;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1) Determine rangeStart / rangeEnd
        $rangeEnd = Carbon::yesterday();
        $rangeStart = static::rangeStartFor($rangeEnd, $this->rangeName);

        // 2) Build the cache only from the canonical target-day facts.
        $query = TrackingTargetDaily::query()
            ->whereNotNull('target_key')
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);

        // If a specific target_type is set, filter it
        if ($this->targetType === self::ROUTE_TYPE) {
            $query->whereNull('target_type');
        } elseif ($this->targetType) {
            $query->where('target_type', $this->targetType);
        }

        $aggregates = $query
            ->selectRaw('target_key, target_type, target_id, SUM(total_views) as total_views_sum')
            ->groupBy('target_key', 'target_type', 'target_id')
            ->orderByDesc('total_views_sum')
            ->limit($this->limit)
            ->get();

        // 3) Remove old data in tracking_top for this range
        TrackingTop::query()->where('range_name', $this->rangeName)
            ->when($this->targetType, function ($q) {
                // If we have a specific target type, also remove old data only for that type
                $q->where('target_type', $this->targetType);
            })
            ->delete();

        // 4) Insert fresh data
        $rank = 1;
        foreach ($aggregates as $row) {
            TrackingTop::query()->create([
                'range_name' => $this->rangeName,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'target_key' => $row->target_key,
                'target_type' => $row->target_type ?? self::ROUTE_TYPE,
                // Page targets are identified by their key alone and carry no
                // entity id, but the ranking table requires one.
                'target_id' => $row->target_id ?? 0,
                'total_views' => $row->total_views_sum,
                'rank' => $rank++,
            ]);
        }
    }

    /**
     * Helper to parse the range string. The window is inclusive of both ends,
     * so a 7d range ending on the 28th starts on the 22nd (exactly 7 days).
     */
    public static function rangeStartFor(Carbon $rangeEnd, string $rangeName): Carbon
    {
        return match ($rangeName) {
            '1m' => $rangeEnd->copy()->subMonth()->addDay(),
            '3m' => $rangeEnd->copy()->subMonths(3)->addDay(),
            '6m' => $rangeEnd->copy()->subMonths(6)->addDay(),
            '1y' => $rangeEnd->copy()->subYear()->addDay(),
            default => $rangeEnd->copy()->subDays(6),
        };
    }
}
