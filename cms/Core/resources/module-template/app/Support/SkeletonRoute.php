<?php

declare(strict_types=1);

namespace Cms\Skeleton\Support;

use Cms\Skeleton\Models\SkeletonThing;

/** URLs of the public pages, so views and mails never build them by hand. */
final class SkeletonRoute
{
    public static function index(): string
    {
        return route('skeleton.public.index');
    }

    public static function show(SkeletonThing $skeletonThing): string
    {
        return route('skeleton.public.show', ['uuid' => $skeletonThing->uuid]);
    }
}
