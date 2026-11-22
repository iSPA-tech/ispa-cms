<?php

declare(strict_types=1);

namespace Cms\Skeleton\Listeners;

use Cms\Skeleton\Events\SkeletonThingSaved;
use Cms\Skeleton\Jobs\SendSkeletonThingNotification;

/** Queues the notification mail when an address is configured (config skeleton.notify_email). */
final class QueueSkeletonThingNotification
{
    public function handle(SkeletonThingSaved $event): void
    {
        if (blank(config('skeleton.notify_email'))) {
            return;
        }

        SendSkeletonThingNotification::dispatch((int) $event->skeletonThing->id);
    }
}
