<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Workflows\Presenters\WorkflowInstancePresenter;

/**
 * Read-only endpoints for workflow instance inspection.
 *
 * Provides endpoints to view a workflow instance summary and its
 * individual execution steps with result data, logs, and error details.
 * Access follows WorkflowProjectPolicy: `viewAny` (permission `workflow_project.viewAny`), or `view` on the
 * instance's own project. Anything else, including an
 * unknown instance id, is answered 403 so run history never leaks.
 *
 * @see WorkflowInstance
 * @see WorkflowInstancePresenter
 * @package Cms\Core\Http\Controllers\Workflow
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowInstanceController extends Controller
{
    /**
     * Show a workflow instance with its project and admin summary.
     *
     * @param  int                          $id         Instance ID
     * @param  WorkflowInstancePresenter    $presenter  Presentation formatter
     * @return mixed  Instance data with project and summary
     */
    public function show(int $id, WorkflowInstancePresenter $presenter)
    {
        $instance = WorkflowInstance::query()
            ->with(['project'])
            ->find($id);

        if (!$this->canViewRuns($instance)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        if (!$instance) {
            return ires()->data(['message' => 'Instance not found'], 404);
        }

        $summary = $presenter->toAdminArray($instance);

        // Review fix 36: the run's full data (`context_snapshot`: trigger payloads, variables, step outputs) only for
        // people who may edit the workflow (the designer reads it for sample values); viewers get the same shape with
        // `context_snapshot: null` and the summary.
        $payload = $instance->toArray();
        if (!$this->canEditProject($instance)) {
            $payload['context_snapshot'] = null;
        }

        return ires()->data([
            'instance' => $payload,
            'project' => $instance->project,
            'summary' => $summary,
        ]);
    }

    /**
     * List all execution steps for a workflow instance.
     *
     * @param  int  $id  Instance ID
     * @return mixed  Collection of step data with results and logs
     */
    public function steps(int $id)
    {
        $instance = WorkflowInstance::query()->with(['project'])->find($id);

        if (!$this->canViewRuns($instance)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $steps = WorkflowInstanceStep::query()
            ->where('instance_id', $id)
            ->orderBy('started_at')
            ->get()
            ->map(function ($step) {
                $result = $step->result_data ?? [];
                $logs = $result['logs'] ?? [];
                $duration = $result['duration_ms'] ?? null;

                $stepArray = [
                    'step_id' => $step->step_id,
                    'status' => $step->status,
                    'started_at' => $step->started_at,
                    'finished_at' => $step->finished_at,
                    'duration_ms' => $duration,
                    'result_data' => $result['result'] ?? null,
                    'log_data' => $this->truncateLogs($logs),
                    'error_message' => $step->error_message,
                ];

                if (in_array($step->status, ['failed', 'apply_failed', 'approval_stale'], true)) {
                    $stepArray['is_failed_step'] = true;
                } else {
                    $stepArray['is_failed_step'] = false;
                }

                return $stepArray;
            });

        if ($steps->isEmpty()) {
            return ires()->data([], 200);
        }

        return ires()->data($steps);
    }

    /**
     * Whether the current user may read the run history of this instance (null = unknown instance).
     *
     * An unknown instance is visible only to `viewAny`, so the 404 answer does not reveal which ids exist.
     */
    protected function canEditProject(WorkflowInstance $instance): bool
    {
        $project = $instance->project;

        return $project instanceof WorkflowProject && (bool) user()?->can('update', $project);
    }

    protected function canViewRuns(?WorkflowInstance $instance): bool
    {
        $user = user();
        if (!$user) {
            return false;
        }

        if ($user->can('viewAny', WorkflowProject::class)) {
            return true;
        }

        $project = $instance?->project;

        return $project instanceof WorkflowProject && $user->can('view', $project);
    }

    /**
     * @param array<int,array<string,mixed>> $logs
     */
    protected function truncateLogs(array $logs): array
    {
        $maxLength = 1000;

        return collect($logs)->map(function ($log) use ($maxLength) {
            if (!is_array($log)) {
                return $log;
            }
            $log['message'] = mb_strimwidth((string)($log['message'] ?? ''), 0, $maxLength, '...');
            return $log;
        })->all();
    }
}
