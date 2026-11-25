<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Starts a run for a model-event or app-event trigger, outside the request or transaction that raised the event.
 *
 * Debounce: the listener stores a fresh token under the trigger's debounce key and delays this job; when the job
 * runs and the key holds another token, a later event superseded it and the job does nothing (trailing debounce).
 * The start node is checked again (the project may have changed since the event).
 *
 * Busy: when a run of the project is still in progress the start is not dropped; the job is queued again after
 * `core.workflow_schedule.busy_retry_seconds`, up to `busy_retry_attempts` times (review finding 17).
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class StartTriggeredRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param array<string, mixed> $meta
     * @param list<int>            $chain Chain of the run that raised the event (empty outside a run).
     * @param int                  $busyAttempt How many times this start was queued again because a run was in progress.
     */
    public function __construct(
        public readonly int $projectId,
        public readonly int $stepId,
        public readonly string $source,
        public readonly array $meta,
        public readonly array $chain = [],
        public readonly ?string $debounceKey = null,
        public readonly ?string $debounceToken = null,
        public readonly int $busyAttempt = 0,
    ) {
        $this->afterCommit();
    }

    public function handle(WorkflowProjectRunService $runService): void
    {
        if ($this->debounceKey !== null && TriggerIndex::cache()->get($this->debounceKey) !== $this->debounceToken) {
            return;
        }

        $project = WorkflowProject::query()->find($this->projectId);
        if (! $project || WorkflowTriggers::publishedStartStep($project)?->getKey() !== $this->stepId) {
            return;
        }

        try {
            $runService->startTriggered($project, $this->source, $this->meta, $this->chain);
        } catch (WorkflowAlreadyRunning) {
            self::queueAgain($this->projectId, $this->stepId, $this->source, $this->meta, $this->chain, $this->debounceKey, $this->debounceToken, $this->busyAttempt + 1);
        } catch (\Throwable $e) {
            Log::info('Triggered workflow run not started', [
                'project_id' => $this->projectId,
                'source' => $this->source,
                'reason' => $e instanceof WorkflowTriggerRefused ? $e->reason : $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue a start again later because a run of the project is in progress; gives up (warning) after the limit.
     *
     * @param array<string, mixed> $meta
     * @param list<int>            $chain
     */
    public static function queueAgain(int $projectId, int $stepId, string $source, array $meta, array $chain, ?string $debounceKey, ?string $debounceToken, int $attempt): bool
    {
        $max = max(0, (int) config('core.workflow_schedule.busy_retry_attempts', 120));
        // A sync queue ignores the delay and would retry at once, in a loop: there the start is dropped as before.
        if ($attempt > $max || app('queue')->connection() instanceof \Illuminate\Queue\SyncQueue) {
            Log::warning('Triggered workflow run dropped: a run was still in progress after every retry', [
                'project_id' => $projectId,
                'source' => $source,
                'attempts' => $attempt - 1,
            ]);

            return false;
        }

        self::dispatch($projectId, $stepId, $source, $meta, $chain, $debounceKey, $debounceToken, $attempt)
            ->delay(now()->addSeconds(max(1, (int) config('core.workflow_schedule.busy_retry_seconds', 30))));

        return true;
    }
}
