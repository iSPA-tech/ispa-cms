<?php

declare(strict_types=1);

namespace Cms\Skeleton\Events;

use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A thing was created or updated through SkeletonThingService. */
final class SkeletonThingSaved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly SkeletonThing $skeletonThing)
    {
    }
}
