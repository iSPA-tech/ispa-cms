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
use Cms\Core\Workflows\Presenters\WorkflowInstancePresenter;

/**
 * Read-only endpoints for workflow instance inspection.
 *
 * Provides endpoints to view a workflow instance summary and its
 * individual execution steps with result data, logs, and error details.
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

        if (!$instance) {
            return ires()->data(['message' => 'Instance not found'], 404);
        }

        $summary = $presenter->toAdminArray($instance);

        return ires()->data([
            'instance' => $instance,
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
