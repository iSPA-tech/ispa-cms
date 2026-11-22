<?php

declare(strict_types=1);

namespace Cms\Skeleton\Console\Commands;

use Cms\Skeleton\Exceptions\SkeletonException;
use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Console\Command;

/** Removes for good the things that stayed in the trash too long. Scheduled daily by SkeletonServiceProvider. */
final class PurgeTrashedSkeletonThingsCommand extends Command
{
    protected $signature = 'skeleton:purge-trashed {--days= : Age in days (default: config skeleton.purge_after_days)}';

    protected $description = 'Permanently delete things trashed more than N days ago.';

    public function handle(): int
    {
        try {
            $days = $this->days();
        } catch (SkeletonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $purged = SkeletonThing::onlyTrashed()->where('deleted_at', '<', now()->subDays($days))->forceDelete();
        $this->info("{$purged} trashed rows older than {$days} days removed.");

        return self::SUCCESS;
    }

    private function days(): int
    {
        $days = (int) ($this->option('days') ?? config('skeleton.purge_after_days', 30));
        if ($days < 1) {
            throw SkeletonException::invalidDays($days);
        }

        return $days;
    }
}
