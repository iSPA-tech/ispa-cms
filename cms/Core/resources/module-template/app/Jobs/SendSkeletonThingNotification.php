<?php

declare(strict_types=1);

namespace Cms\Skeleton\Jobs;

use Cms\Skeleton\Mail\SkeletonThingSavedMail;
use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/** Mails the configured address that a thing was saved; skips when the row or the address is gone. */
final class SendSkeletonThingNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $skeletonThingId)
    {
    }

    public function handle(): void
    {
        $address = config('skeleton.notify_email');
        $skeletonThing = SkeletonThing::query()->find($this->skeletonThingId);
        if (blank($address) || $skeletonThing === null) {
            return;
        }

        Mail::to($address)->send(new SkeletonThingSavedMail($skeletonThing));
    }
}
