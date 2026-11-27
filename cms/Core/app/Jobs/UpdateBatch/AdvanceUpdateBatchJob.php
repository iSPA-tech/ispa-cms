<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Jobs\UpdateBatch;

use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Services\UpdateBatch\UpdateBatchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Moves the open upgrade-all run on, on a site where a queue worker runs installs (spec 2026-11-26, decision 1).
 *
 * Pushed when a run starts and when a module install row finishes. Where the scheduler also runs, widget and workflow
 * items are left to the scheduled `cms:update-batch-advance` (its own process), never run in a worker that just
 * installed a module; a queue site without a scheduler runs them here.
 */
final class AdvanceUpdateBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct()
    {
        $this->timeout = (int) config('core.module_packages.job_timeout', 2700);
        $this->onQueue((string) config('core.module_packages.queue', 'default'));
    }

    /**
     * Push the job when a run is open and a queue worker (not the scheduler) runs installs. On a scheduler site the
     * scheduled command moves the run on; a dispatch on `sync` would run it inline in the caller's process.
     */
    public static function kick(): void
    {
        if (InstallRunDispatcher::schedulerRunsInstalls() || app(UpdateBatches::class)->open() === null) {
            return;
        }
        self::dispatch();
    }

    /** Seconds before a job pushes itself again when it left work behind on a site without a scheduler. */
    public const RETRY_SECONDS = 30;

    public function handle(UpdateBatchRunner $runner, SchedulerHeartbeat $heartbeat): void
    {
        $scheduler = $heartbeat->isFresh();
        $more = $runner->advance(itemsAllowed: ! $scheduler);
        // Nothing else would move the run on there: no scheduled tick, and no install job ends to kick it.
        if ($more && ! $scheduler) {
            self::dispatch()->delay(now()->addSeconds(self::RETRY_SECONDS));
        }
    }
}
