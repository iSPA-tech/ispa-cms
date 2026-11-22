<?php

declare(strict_types=1);

namespace Cms\Skeleton\Providers;

use App\Providers\Abstracts\ModuleServiceProvider;
// @mode full
use Cms\Skeleton\Console\Commands\PurgeTrashedSkeletonThingsCommand;
use Cms\Skeleton\Events\SkeletonThingSaved;
use Cms\Skeleton\Listeners\QueueSkeletonThingNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
// @endmode

final class SkeletonServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Skeleton';
    }

    protected function getModuleNameLower(): string
    {
        return 'skeleton';
    }

    protected function registerModuleProviders(): void
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(AuthServiceProvider::class);
    }
    // @mode full

    public function boot(): void
    {
        parent::boot();
        Event::listen(SkeletonThingSaved::class, QueueSkeletonThingNotification::class);
    }

    protected function registerCommands(): void
    {
        $this->commands([PurgeTrashedSkeletonThingsCommand::class]);
    }

    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('skeleton:purge-trashed')->dailyAt('03:30');
        });
    }
    // @endmode
}
