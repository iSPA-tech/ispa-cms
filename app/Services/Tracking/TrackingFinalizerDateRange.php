<?php

namespace App\Services\Tracking;

use Carbon\Carbon;
use InvalidArgumentException;

class TrackingFinalizerDateRange
{
    /**
     * @return list<Carbon>
     */
    public function resolve(
        ?string $date,
        ?string $from,
        ?string $to,
        bool $all,
        int $maximumDays
    ): array {
        if ($date !== null && ($from !== null || $to !== null)) {
            throw new InvalidArgumentException('Use either --date or --from/--to.');
        }
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('--from and --to must be supplied together.');
        }
        if ($all && ($from === null || $to === null)) {
            throw new InvalidArgumentException('Unbounded --all is disabled; provide --from and --to.');
        }

        $timezone = (string) config('tracking.analytics_timezone');
        if ($date !== null) {
            $start = Carbon::parse($date, $timezone)->startOfDay();
            $end = $start->copy();
        } elseif ($from !== null && $to !== null) {
            $start = Carbon::parse($from, $timezone)->startOfDay();
            $end = Carbon::parse($to, $timezone)->startOfDay();
        } else {
            $start = Carbon::now($timezone)->subDay()->startOfDay();
            $end = $start->copy();
        }

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('Finalizer range end precedes its start.');
        }
        if ($start->diffInDays($end) + 1 > max(1, $maximumDays)) {
            throw new InvalidArgumentException('Finalizer range exceeds the approved maximum.');
        }
        if ($end->greaterThanOrEqualTo(Carbon::now($timezone)->startOfDay())) {
            throw new InvalidArgumentException('Finalizer range includes an open analytics day.');
        }

        $dates = [];
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor->addDay()) {
            $dates[] = $cursor->copy();
        }

        return $dates;
    }
}
