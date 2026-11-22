<?php

namespace App\Console\Commands;

use App\Services\Tracking\TrackingAggregateReconciler;

class BackfillTrackingTargetTotalsCommand extends BackfillTrackingSiteTotalCommand
{
    protected $signature = 'cms:tracking-backfill-target-totals
                            {--checkpoint-id= : Stable backup/checkpoint identifier}
                            {--max-id= : Fixed maximum tracks ID}
                            {--after-id=0 : Resume after this tracks ID}
                            {--limit=100000 : Maximum rows to scan}
                            {--output= : Optional manifest output path}';

    protected $description = 'Build a bounded dry-run entity baseline manifest';

    public function handle(TrackingAggregateReconciler $reconciler): int
    {
        return $this->build($reconciler, 'tracking:target-baseline');
    }
}
