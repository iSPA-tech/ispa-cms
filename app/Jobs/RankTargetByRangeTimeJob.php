<?php

namespace App\Jobs;

use Cms\Content\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RankTargetByRangeTimeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        (new TopRangeAggregationJob('1y', Post::class, 50))->handle();
    }
}
