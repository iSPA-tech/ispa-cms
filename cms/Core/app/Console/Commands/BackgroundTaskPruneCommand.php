<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\BackgroundTask;
use Illuminate\Console\Command;

/**
 * Marks dead queued/running background tasks as failed, then deletes finished records older than the retention window.
 */
class BackgroundTaskPruneCommand extends Command
{
    protected $signature = 'cms:background-tasks-prune {--days=30 : Retention in days for succeeded and failed tasks} {--stale-hours=6 : Hours after which a queued or running task is marked failed}';

    protected $description = 'Delete succeeded and failed background task rows older than N days; queued/running rows older than the stale window are first marked failed.';

    /**
     * Mark stale tasks failed, prune old finished rows and print both counts.
     *
     * @return int Command exit code.
     */
    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $staleCutoff = now()->subHours(max(1, (int) $this->option('stale-hours')));

        $stale = BackgroundTask::query()
            ->whereIn('status', [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING])
            ->whereRaw('COALESCE(started_at, created_at) < ?', [$staleCutoff])
            ->update([
                'status' => BackgroundTask::STATUS_FAILED,
                'message' => BackgroundTask::STALE_MESSAGE_KEY,
                'finished_at' => now(),
            ]);

        $deleted = BackgroundTask::query()
            ->whereIn('status', [BackgroundTask::STATUS_SUCCEEDED, BackgroundTask::STATUS_FAILED])
            ->where(static function ($query) use ($cutoff): void {
                $query->where('finished_at', '<', $cutoff)
                    ->orWhere(static function ($inner) use ($cutoff): void {
                        $inner->whereNull('finished_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();

        $this->info(sprintf('Marked %d stale background task row(s) as failed.', $stale));
        $this->info(sprintf('Deleted %d background task row(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
