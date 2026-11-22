<?php

declare(strict_types=1);

namespace Cms\Skeleton\Policies;

use Cms\Core\Policies\AbstractModelPolicy;

/**
 * Permission keys `skeleton.skeleton_thing.<ability>` (viewAny, view, create, edit, update, delete), seeded by
 * SkeletonPermissionSeeder. The local environment passes every check.
 */
final class SkeletonThingPolicy extends AbstractModelPolicy
{
    protected function prefix(): string
    {
        return 'skeleton.skeleton_thing';
    }
}
