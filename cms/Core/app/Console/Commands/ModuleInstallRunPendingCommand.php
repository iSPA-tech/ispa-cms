<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Runs one pending module install (or uninstall) on a site without a queue worker (spec 2026-11-22, decision 5).
 *
 * Scheduled every minute without overlap and in the background (own PHP process), only on sites whose default queue
 * is `sync`. It first closes rows left for the scheduler that nobody claimed in time
 * ({@see InstallRunDispatcher::expireUnclaimed()}); on a real queue it stops there, because a worker runs installs.
 * It then closes claims whose process died, then claims the
 * oldest pending row atomically ({@see InstallRunDispatcher::claimNext()}) and runs {@see InstallModuleJob::handle()}.
 * The run is limited to `core.module_packages.job_timeout` seconds; a run that times out or dies with a fatal error
 * is closed through {@see InstallModuleJob::failed()}, like a worker timeout.
 */
class ModuleInstallRunPendingCommand extends Command
{
    /** Command name the scheduler runs. */
    public const NAME = 'cms:module-install-run-pending';

    protected $signature = 'cms:module-install-run-pending';

    protected $description = 'Run one pending module install when the site has no queue worker (queue is sync).';

    /**
     * Claim and run one pending install.
     */
    public function handle(InstallRunDispatcher $runs, ModuleInstaller $installer): int
    {
        foreach ($runs->expireUnclaimed() as $uuid) {
            $this->line('Closed install ' . $uuid . ' that no scheduled run picked up.');
        }
        if (! $runs->usesScheduler()) {
            return self::SUCCESS;
        }

        foreach ($runs->staleClaims() as $uuid) {
            (new InstallModuleJob($uuid))->failed(new RuntimeException('The scheduled install run stopped without finishing.'));
            $this->line('Closed stale install ' . $uuid . '.');
        }

        $install = $runs->claimNext();
        if (! $install instanceof ModuleInstall) {
            return self::SUCCESS;
        }

        $job = new InstallModuleJob($install->uuid);
        $done = false;
        register_shutdown_function(static function () use (&$done, $job): void {
            if (! $done) {
                $job->failed(new RuntimeException('The scheduled install run timed out or stopped.'));
            }
        });
        $this->limitTime($job->timeout);

        $this->line('Running ' . $install->kind . ' ' . $install->name . ' ' . $install->version . ' (' . $install->uuid . ').');
        try {
            $job->handle($installer);
        } catch (Throwable $e) {
            $job->failed($e);
        } finally {
            $done = true;
            $this->clearTimeLimit();
        }

        return self::SUCCESS;
    }

    /**
     * Stop this process after $seconds (wall clock with pcntl, else PHP's execution time limit); the shutdown
     * handler then closes the run.
     */
    private function limitTime(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function (): void {
                exit(1);
            });
            pcntl_alarm($seconds);

            return;
        }
        @set_time_limit($seconds);
    }

    /**
     * Cancel the time limit after the run returned.
     */
    private function clearTimeLimit(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}
