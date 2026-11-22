<?php

declare(strict_types=1);

namespace Cms\Skeleton\Enums;

/** Publication state of a thing; only published ones appear on the public pages. */
enum SkeletonThingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    /** Translation key of the label (admin and public). */
    public function labelKey(): string
    {
        return 'text.skeleton.things.status_' . $this->value;
    }
}
