<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console;

use App\Jobs\SubmitIndexNowWebmasterJob;
use App\Jobs\RemoveDeletedUploadFilesJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if (config('app.env') === 'local') {
            //  NOTE: This is for Telescope
            $schedule->command('telescope:prune')->daily();
        }
        // $schedule->command('inspire')->hourly();
//        $schedule->job(new RemoveTemporaryFileOnCpanelImagickJob())->daily();
//        $schedule->job(new RemoveTemporaryFileOnCpanelImagickJob())->everyMinute();
        $schedule->command('cms:tracking-dispatch-pending')
            ->everyMinute()
            ->name('cms:tracking-dispatch-pending')
            ->withoutOverlapping(2)
            ->onOneServer();

        $schedule->command('cms:tracking-buffer-flush')
            ->everyFifteenMinutes()
            ->name('cms:tracking-buffer-flush')
            ->withoutOverlapping(10)
            ->onOneServer();

        // Alerts at most once per UTC day; hourly runs catch a runaway AI loop within the day.
        $schedule->command('cms:ai-cost-spike-check')
            ->hourlyAt(17)
            ->name('cms:ai-cost-spike-check')
            ->withoutOverlapping(30)
            ->onOneServer();

        // Per-call AI trace log retention (core.ai_usage.trace.retention_days).
        $schedule->command('cms:ai-call-log-prune')
            ->dailyAt('03:40')
            ->name('cms:ai-call-log-prune')
            ->withoutOverlapping()
            ->onOneServer();

        // Finished admin background task records older than 30 days (queued/running rows are kept).
        $schedule->command('cms:background-tasks-prune')
            ->dailyAt('03:45')
            ->name('cms:background-tasks-prune')
            ->withoutOverlapping()
            ->onOneServer();

        // In-app admin notifications: read rows after 30 days, unread rows after 90 days.
        $schedule->command('cms:notifications-prune')
            ->dailyAt('03:55')
            ->name('cms:notifications-prune')
            ->withoutOverlapping()
            ->onOneServer();

        // Config package runs keep their metadata row; payload and plan are cleared after 14 days.
        $schedule->command('cms:config-package-prune')
            ->dailyAt('03:50')
            ->name('cms:config-package-prune')
            ->withoutOverlapping()
            ->onOneServer();

        // Queue backlog / growth and failed-job alerts (cms/Core/config/queue_watch.php);
        // the command does nothing while the default queue driver is sync.
        if (config('core.queue_watch.enabled')) {
            $schedule->command('cms:queue-watch')
                ->everyFiveMinutes()
                ->name('cms:queue-watch')
                ->withoutOverlapping(10)
                ->onOneServer();
        }

        // Drop processed webhook inbox rows older than 30 days (failed rows are kept).
        $schedule->command('cms:webhook-prune')
            ->dailyAt('03:50')
            ->withoutOverlapping()
            ->onOneServer();

        // App backup retention: deletes only copies recorded in core_backup_copies (lock-aware).
        $schedule->command('cms:backup-prune')
            ->dailyAt('04:20')
            ->name('cms:backup-prune')
            ->withoutOverlapping()
            ->onOneServer();

        // Scheduled app backups: destinations with a `daily@HH:MM` schedule (none by default).
        $schedule->command('cms:backup-scheduled')
            ->everyFifteenMinutes()
            ->name('cms:backup-scheduled')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('cms:daily-tracking')
            ->dailyAt('00:15')
            ->name('cms:daily-tracking')
            ->withoutOverlapping(60)
            ->onOneServer();

        // PlatformHub (hub runtime only): drop download tickets older than one day.
        if (\App\Support\Modules\ModuleStatus::isEnabled('PlatformHub')) {
            $schedule->command('cms:platform-tickets-prune')
                ->dailyAt('03:55')
                ->name('cms:platform-tickets-prune')
                ->withoutOverlapping(30)
                ->onOneServer();

            // Site status reports: keep 30 days of history plus the latest report per domain, nothing older than 90 days.
            $schedule->command('cms:platform-reports-prune')
                ->dailyAt('04:05')
                ->name('cms:platform-reports-prune')
                ->withoutOverlapping(30)
                ->onOneServer();
        }

        // Daily check of enabled module versions against the platform hub revocation list (flags only, never disables).
        $schedule->command('cms:module-revocation-check')
            ->dailyAt('05:10')
            ->name('cms:module-revocation-check')
            ->withoutOverlapping(30)
            ->onOneServer();

        // Daily integrity check of installed runtime module files against the signed package hashes (information only,
        // always exits 0). Runs before the status report, which carries only the changed-file counts.
        $schedule->command('cms:module-integrity')
            ->dailyAt('05:40')
            ->name('cms:module-integrity')
            ->withoutOverlapping(30)
            ->onOneServer();

        // Daily signed status report to the platform hub, minute spread per domain so sites do not hit it together.
        $schedule->command('cms:platform-report')
            ->dailyAt(sprintf('06:%02d', crc32((string) config('ispa-cms.cms_domain', '')) % 60))
            ->name('cms:platform-report')
            ->withoutOverlapping(30)
            ->onOneServer();

        $schedule->job(new SubmitIndexNowWebmasterJob())->twiceDaily();
        $schedule->job(new RemoveDeletedUploadFilesJob())->dailyAt('01:00');

        // R2 replication pipeline. All commands hold their own cache locks.
        if ((string) config('filesystems.disks.r2.key') !== '') {
            // Drain tracked-pending objects + flip fully-synced uploads to CDN.
            $schedule->command('upload:sync-to-r2 --limit=400 --max-runtime=240')
                ->everyFiveMinutes()
                // 10-min lock expiry: a run killed mid-flight (deploy/OOM) must
                // not strand the default 24h mutex and freeze the whole R2 sync.
                ->withoutOverlapping(10)
                ->runInBackground();

            // Safety-net discovery: recent writes often + staggered full scans.
            $schedule->command('upload:r2-discover --recent-hours=24')
                ->everyFifteenMinutes()->withoutOverlapping(10)->runInBackground();
            $schedule->command('upload:r2-discover --full --root=avatar')
                ->dailyAt('01:30')->withoutOverlapping(120)->runInBackground();
            $schedule->command('upload:r2-discover --full --root=geoplaces')
                ->dailyAt('02:00')->withoutOverlapping(120)->runInBackground();
            $schedule->command('upload:r2-discover --full --root=image')
                ->dailyAt('02:45')->withoutOverlapping(180)->runInBackground();

            // Daily cheap existence check on recent activity + current failures.
            $schedule->command('upload:r2-verify --mode=head --recent-hours=24 --include-failed')
                ->dailyAt('03:30')->withoutOverlapping(60)->runInBackground();

            // Drain unverified objects continuously; weekly sampling alone can starve old files.
            $schedule->command('upload:r2-verify --mode=hash --only-unverified --limit=400 --max-runtime=240')
                ->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();

            // Weekly REAL byte-hash proof on a rotating 1/4 slice — all four
            // slices covered within any four-week span.
            $schedule->call(function (): void {
                \Illuminate\Support\Facades\Artisan::call('upload:r2-verify', [
                    '--mode' => 'hash',
                    '--all' => true,
                    '--sample' => now()->weekOfYear % 4,
                    '--rotate-of' => 4,
                    '--limit' => 100000,
                    '--max-runtime' => 3600,
                ]);
            })->weeklyOn(0, '04:00')->name('r2-verify-weekly-rotation')->withoutOverlapping(240);

            // Local eviction of hash-verified files (extra master gate inside
            // the command: refuses when delete_local_after_sync is off).
            if (config('filesystems.disks.r2.delete_local_after_sync')) {
                $schedule->command('upload:r2-evict-local --days=7 --limit=2000 --max-runtime=240')
                    ->everyTenMinutes()->withoutOverlapping(10)->runInBackground();
            }
        }

        //  Command tracking
        $schedule->command('cms:tracking-top-daily')
            ->dailyAt('00:25')
            ->name('cms:tracking-top-daily')
            ->withoutOverlapping(60)
            ->onOneServer();

        //  --all-targets discovers every tracked target type from the daily
        //  facts and rebuilds one per-type ranking each. Never pass a class
        //  name here: the schedule builds a raw unquoted shell line and sh
        //  eats single backslashes (Cms\Content\Models\Post arrives as
        //  CmsContentModelsPost — "DONE" with an untouched table).
        $schedule->command('cms:tracking-top', ['--range=7d', '--all-targets'])
            ->dailyAt('00:37')
            ->name('cms:tracking-top-7d-all')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('cms:tracking-top', ['--range=1m', '--all-targets'])
            ->dailyAt('00:38')
            ->name('cms:tracking-top-1m-all')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('cms:tracking-top', ['--range=3m', '--all-targets'])
            ->dailyAt('00:39')
            ->name('cms:tracking-top-3m-all')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('cms:tracking-top', ['--range=6m', '--all-targets'])
            ->dailyAt('00:40')
            ->name('cms:tracking-top-6m-all')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('cms:tracking-top', ['--range=1y', '--all-targets'])
            ->dailyAt('00:41')
            ->name('cms:tracking-top-1y-all')
            ->withoutOverlapping(60)
            ->onOneServer();

        if (config('tracking.receipt_prune_enabled')) {
            $schedule->command('cms:tracking-prune-receipts --force')
                ->dailyAt('03:10')
                ->name('cms:tracking-prune-receipts')
                ->withoutOverlapping(60)
                ->onOneServer();
        }

        if (config('tracking.generation_rotation_enabled')) {
            $schedule->command(
                'cms:tracking-reconcile --rotate-generation --auto-release --max-hold-seconds=300 --max-runtime=240'
            )
                ->dailyAt('02:20')
                ->name('cms:tracking-generation-rotate')
                ->withoutOverlapping(10)
                ->onOneServer();
        }

        if (config('core.agentic.scheduler.enabled')) {
            $schedule->exec(sprintf(
                '%s %s cms:agentic-run-schedules --limit=%d',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                max(1, (int) config('core.agentic.scheduler.batch_limit', 20))
            ))
                ->name('cms:agentic-run-schedules')
                ->everyMinute()
                ->withoutOverlapping();
        }

        // Re-verify the hash and seal of every workflow version that can still run (guardrails B2).
        $schedule->command('cms:workflow-integrity-check')
            ->name('cms:workflow-integrity-check')
            ->dailyAt('02:15')
            ->withoutOverlapping()
            ->onOneServer();

        // Always scheduled: besides cron-scheduled projects (the command checks WORKFLOW_SCHEDULER_ENABLED itself), it puts
        // live the sensitive workflow changes whose cooling-off ended (guardrails B3), which must not wait for that flag.
        $schedule->command('cms:workflow-run-scheduled')
            ->name('cms:workflow-run-scheduled')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
