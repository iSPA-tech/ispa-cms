<?php

declare(strict_types=1);

namespace Cms\Core\Jobs;

use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compares a stored configuration package with this site in the background (dry run).
 *
 * The admin API creates a queued `dry_run` run holding the package payload and dispatches this job with the run
 * uuid. Task messages are admin i18n keys with done/total counts; the run summary keeps a safe message (no exception text, which may carry values).
 */
class DryRunConfigPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param string $runUuid Uuid of the queued ConfigPackageRun to fill.
     * @param int    $userId  Acting user id.
     */
    public function __construct(private readonly string $runUuid, private readonly int $userId)
    {
    }

    /**
     * Run the dry run and record background task progress.
     */
    public function handle(ConfigPackageDiffer $differ, BackgroundTaskRecorder $recorder): void
    {
        $run = ConfigPackageRun::query()->where('uuid', $this->runUuid)->first();

        if ($run === null || $run->kind !== ConfigPackageRun::KIND_DRY_RUN || $run->status !== ConfigPackageRun::STATUS_QUEUED) {
            return;
        }

        $task = $recorder->start('config.package.dry_run', 'Core', $this->userId > 0 ? $this->userId : null);
        $run->forceFill(['background_task_uuid' => $task->uuid])->save();
        $recorder->progress($task, 0, 'text.background_tasks.step.config_dry_run_started');

        try {
            $total = max(1, count((array) $run->sections));
            $done = 0;

            $run = $differ->dryRunInto($run, $this->userId, function (string $name) use ($recorder, $task, &$done, $total): void {
                $recorder->progress($task, (int) floor($done / $total * 90), 'text.background_tasks.step.config_dry_run_section', ['done' => $done + 1, 'total' => $total]);
                $done++;
            });

            if ($run->status === ConfigPackageRun::STATUS_SUCCEEDED) {
                $recorder->succeed($task, ['sections' => array_keys((array) ($run->summary['sections'] ?? []))]);
            } else {
                $recorder->fail($task, 'text.background_tasks.step.config_dry_run_failed');
            }
        } catch (Throwable $e) {
            Log::error('Config package dry run failed', ['run_uuid' => $this->runUuid, 'exception' => $e::class]);

            $run->setAttribute('payload', null);
            $run->forceFill([
                'status' => ConfigPackageRun::STATUS_FAILED,
                'summary' => ['error' => 'Dry run failed (' . class_basename($e) . ').', 'errors' => []],
            ])->save();

            $recorder->fail($task, 'text.background_tasks.step.config_dry_run_failed');
        }
    }
}
