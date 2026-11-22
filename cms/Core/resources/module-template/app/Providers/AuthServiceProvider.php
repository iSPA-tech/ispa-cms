<?php

declare(strict_types=1);

namespace Cms\Skeleton\Providers;

use Cms\Skeleton\Models\SkeletonThing;
use Cms\Skeleton\Policies\SkeletonThingPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        SkeletonThing::class => SkeletonThingPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
