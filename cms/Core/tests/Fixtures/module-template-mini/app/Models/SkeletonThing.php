<?php

namespace Cms\Skeleton\Models;

final class SkeletonThing
{
    protected $table = 'skeleton_things';

    public function label(): string
    {
        // @mode lite
        return 'lite';
        // @endmode
        // @mode normal,full
        return 'normal';
        // @endmode
    }
}
