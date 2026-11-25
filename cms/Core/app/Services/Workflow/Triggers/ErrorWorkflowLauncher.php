<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starts a project's error workflow when one of its runs fails.
 *
 * The error workflow is the project in `error_workflow_project_id` (on the failed instance, else on its project). It
 * starts only when its start node is `trigger_error`, it is not the failed project itself, the failed run was not
 * itself started by an error trigger (no error-of-error cascades), and the chain guard allows it. It acts for its
 * own scheduler with the scheduled-run rights checks. Called from WorkflowInstance's `updated` event and from
 * WorkflowProjectRunService::failStaleRunning() (a conditional update fires no model event).
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class ErrorWorkflowLauncher
{
    public function __construct(protected WorkflowProjectRunService $runService)
    {
    }

    /**
     * @return WorkflowInstance|null The error workflow run, or null when none was started.
     */
    public function instanceFailed(int $instanceId): ?WorkflowInstance
    {
        $failed = WorkflowInstance::query()->with('project')->find($instanceId);
        if (! $failed || $failed->status !== 'failed') {
            return null;
        }

        // The error workflow of the version the run executed (recorded on the run when it failed), not the draft's.
        $errorProjectId = (int) ($failed->error_workflow_project_id
            ?: (\Cms\Core\Services\Workflow\Versioning\RunGraph::settingsOf($failed->version_id ? (int) $failed->version_id : null)['error_workflow_project_id'] ?? 0));
        if ($errorProjectId <= 0 || $errorProjectId === (int) $failed->project_id
            || in_array('error', [data_get($failed->context_snapshot, 'trigger.meta.source'), data_get($failed->context_snapshot, 'trigger.source')], true)) {
            return null;
        }

        $errorProject = WorkflowProject::query()->find($errorProjectId);
        if (! $errorProject || ! WorkflowTriggers::publishedStartsWith($errorProject, WorkflowTriggers::ERROR)) {
            return null;
        }

        $lastFailure = WorkflowInstanceStep::query()
            ->where('instance_id', $failed->id)
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->first();
        $stepCode = $lastFailure?->step_id ? WorkflowStep::query()->whereKey($lastFailure->step_id)->value('step_code') : null;

        $meta = [
            'failed_instance' => [
                'id' => $failed->id,
                'project_id' => $failed->project_id,
                'project_slug' => $failed->project?->slug,
                'status' => $failed->status,
                'finished_at' => optional($failed->finished_at)->toISOString(),
            ],
            'step' => $stepCode,
            'error' => Str::limit((string) ($lastFailure?->error_message ?? 'failed'), 1000),
        ];

        try {
            return $this->runService->startTriggered($errorProject, 'error', $meta, TriggerChain::of($failed));
        } catch (WorkflowAlreadyRunning) {
            // The error workflow is busy with an earlier failure: queue this alert instead of losing it (finding 17).
            $startStep = WorkflowTriggers::publishedStartStep($errorProject);
            if ($startStep) {
                StartTriggeredRunJob::queueAgain((int) $errorProject->getKey(), (int) $startStep->getKey(), 'error', $meta, TriggerChain::of($failed), null, null, 1);
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Error workflow not started', [
                'failed_instance_id' => $failed->id,
                'error_project_id' => $errorProjectId,
                'reason' => $e instanceof WorkflowTriggerRefused ? $e->reason : $e->getMessage(),
            ]);

            return null;
        }
    }
}
