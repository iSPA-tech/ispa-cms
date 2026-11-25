<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Continues the parent of a finished sub-workflow run (`execute_workflow` in `wait` mode).
 *
 * When the child ends before the parent has finished pausing (another worker), the job is released and tried again
 * a few seconds later; a parent that never pauses is left alone after the last try.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class ResumeParentWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 15;

    public function __construct(public readonly int $childInstanceId)
    {
        $this->afterCommit();
    }

    public function handle(SubWorkflowService $subWorkflows): void
    {
        if ($subWorkflows->resumeParent($this->childInstanceId) === 'not_ready' && $this->job !== null) {
            // Back off (2, 4, 8 ... 60 s) so a slow parent step is waited for minutes, not 30 seconds. A parent still
            // waiting after the last try is settled by the scheduled stale sweep
            // (WorkflowProjectRunService::settleOrphanedParents, review finding 36).
            $this->release(min(60, 2 ** min(6, max(1, $this->attempts()))));
        }
    }
}
