<?php

declare(strict_types=1);

namespace Cms\Core\Jobs;

use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a configuration package (queued `apply` run) in the background.
 *
 * ConfigPackageApplier::apply() records the queued apply run and dispatches this job with its id. Progress messages
 * are admin i18n keys with done/total counts; the task result lists succeeded and failed section names. Exception messages are never copied
 * into the task or the run because they may carry values.
 */
class ApplyConfigPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param int $runId  Id of the queued apply ConfigPackageRun.
     * @param int $userId Acting user id.
     */
    public function __construct(private readonly int $runId, private readonly int $userId)
    {
    }

    /**
     * Run the apply and record background task progress.
     */
    public function handle(ConfigPackageApplier $applier, BackgroundTaskRecorder $recorder): void
    {
        $run = ConfigPackageRun::query()->find($this->runId);

        if ($run === null || $run->kind !== ConfigPackageRun::KIND_APPLY || $run->status !== ConfigPackageRun::STATUS_QUEUED) {
            return;
        }

        $task = $recorder->start('config.package.apply', 'Core', $this->userId > 0 ? $this->userId : null);
        $run->forceFill(['background_task_uuid' => $task->uuid])->save();
        $recorder->progress($task, 0, 'text.background_tasks.step.config_apply_started');

        try {
            $total = max(1, count((array) $run->sections));
            $done = 0;

            $run = $applier->execute($run, $this->userId, function (string $name) use ($recorder, $task, &$done, $total): void {
                $recorder->progress($task, (int) floor($done / $total * 90), 'text.background_tasks.step.config_apply_section', ['done' => $done + 1, 'total' => $total]);
                $done++;
            }, $task);

            $result = (array) ($run->summary['result'] ?? []);

            if ($run->status === ConfigPackageRun::STATUS_SUCCEEDED) {
                $recorder->succeed($task, $result + ['sync_failed' => (array) ($run->summary['sync_failed'] ?? [])]);

                return;
            }

            $recorder->fail($task, 'text.background_tasks.step.config_apply_failed');
            // fail() keeps no result; section names (never values) are stored for the task screen.
            $task->forceFill(['result' => $result])->save();
        } catch (Throwable $e) {
            Log::error('Config package apply failed', ['run_id' => $this->runId, 'exception' => $e::class]);

            $run->forceFill([
                'status' => ConfigPackageRun::STATUS_FAILED,
                'summary' => ['error' => 'Apply failed (' . class_basename($e) . ').', 'result' => ['succeeded' => [], 'failed' => [], 'skipped' => []]],
            ])->save();

            $recorder->fail($task, 'text.background_tasks.step.config_apply_failed');
        }
    }
}
