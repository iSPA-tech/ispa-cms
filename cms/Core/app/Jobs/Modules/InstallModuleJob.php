<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Jobs\Modules;

use Cms\Core\Jobs\UpdateBatch\AdvanceUpdateBatchJob;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one module install created by {@see \Cms\Core\Http\Controllers\V2\Admin\ModuleInstallController}.
 *
 * The worker timeout is `core.module_packages.job_timeout`. A queue whose `retry_after` is not above it never gets
 * this job: installs run through the scheduler there ({@see \Cms\Core\Services\Modules\Install\InstallRunDispatcher::usesScheduler()}),
 * so an install is never handed to a second worker (`tries = 1`).
 */
final class InstallModuleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param string $installUuid Install row to run.
     */
    public function __construct(public readonly string $installUuid)
    {
        $this->timeout = (int) config('core.module_packages.job_timeout', 2700);
        $this->onQueue((string) config('core.module_packages.queue', 'default'));
    }

    /**
     * Run the install; only a queued row is picked up, so a duplicate delivery does nothing.
     */
    public function handle(ModuleInstaller $installer): void
    {
        try {
            $install = ModuleInstall::query()->where('uuid', $this->installUuid)->first();
            if ($install === null || $install->status !== ModuleInstall::STATUS_QUEUED) {
                return;
            }

            $installer->run($install);
        } finally {
            // Upgrade-all: an open run moves on to its next item (queue sites; the scheduler handles its own).
            AdvanceUpdateBatchJob::kick();
        }
    }

    /**
     * After a worker kill or timeout: close an unfinished install and its task, and remove its staging folder.
     */
    public function failed(Throwable $e): void
    {
        $install = ModuleInstall::query()->where('uuid', $this->installUuid)->first();
        if ($install === null) {
            return;
        }

        $installer = app(ModuleInstaller::class);
        if ($install->isUnfinished()) {
            $installer->markFailed($install, 'MODULE_INSTALL_JOB_FAILED');
        }
        $installer->cleanStaging($install);
        AdvanceUpdateBatchJob::kick();
    }
}
