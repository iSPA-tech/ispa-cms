<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Illuminate\Support\Facades\DB;

/**
 * Controls the stop/cancel lifecycle of agent runs.
 *
 * Provides request-stop, should-stop polling, and stop-acknowledge
 * operations. Propagates status transitions to associated workflow
 * instances, workflow steps, run steps, and approvals. Synchronizes
 * schedule runtime state after acknowledgment.
 *
 * @see AgentRunOperationsService
 * @see AgentScheduleRuntimeSyncService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentRunControlService
{
    /**
     * Initialize the control service with the runtime sync dependency.
     *
     * @param AgentScheduleRuntimeSyncService $runtimeSyncService Synchronizes schedule state after stop/cancel.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(protected AgentScheduleRuntimeSyncService $runtimeSyncService)
    {
    }

    /**
     * Request a graceful stop for a running agent run.
     *
     * @param AgentRun    $run   The agent run to stop.
     * @param User|null   $actor The user requesting the stop.
     * @param string|null $note  Optional reason for stopping.
     * @return array{run: AgentRun, approval: AgentApproval|null} The refreshed run and latest approval.
     *
     * @throws \InvalidArgumentException When the run cannot be stopped from its current status.
     */
    public function requestStop(AgentRun $run, ?User $actor = null, ?string $note = null): array
    {
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep', 'steps', 'approvals']);

        if (! in_array($run->status, ['init', 'running'], true)) {
            throw new \InvalidArgumentException("Agent run [{$run->id}] cannot be stopped from status [{$run->status}].");
        }

        return DB::transaction(function () use ($run, $actor, $note) {
            $run->fill([
                'operator_request_state' => 'stop_requested',
                'stop_requested_by_user_id' => $actor?->getKey(),
                'stop_requested_at' => now(),
                'last_checkpoint_at' => now(),
            ])->save();

            if ($run->workflowInstance && ! in_array($run->workflowInstance->status, ['cancelled', 'completed', 'failed'], true)) {
                $run->workflowInstance->fill([
                    'status' => 'cancelling',
                    'finished_at' => null,
                ])->save();
            }

            if ($run->parentWorkflowStep && ! in_array($run->parentWorkflowStep->status, ['cancelled', 'success', 'failed'], true)) {
                $run->parentWorkflowStep->fill([
                    'status' => 'cancelling',
                    'error_message' => $note ?: 'Stop requested by operator.',
                ])->save();
            }

            $step = $run->steps()->orderByDesc('step_index')->first();
            if ($step instanceof AgentRunStep) {
                $step->fill([
                    'observation_summary' => $note ?: ($step->observation_summary ?: 'Stop requested by operator.'),
                ])->save();
            }

            return [
                'run' => $run->fresh(['profile', 'workflowInstance', 'parentWorkflowStep', 'sourceRun', 'stoppedBy', 'steps', 'approvals', 'artifacts', 'toolCalls']),
                'approval' => $run->approvals()->latest('id')->first(),
            ];
        });
    }

    /**
     * Check whether a stop has been requested for the given agent run.
     *
     * @param AgentRun|int|null $run The agent run or its ID.
     * @return bool True if a stop has been requested.
     */
    public function shouldStopRun(AgentRun|int|null $run): bool
    {
        $runId = $run instanceof AgentRun ? $run->getKey() : $run;
        if (! $runId) {
            return false;
        }

        try {
            return AgentRun::query()
                ->whereKey($runId)
                ->where('operator_request_state', 'stop_requested')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether a stop has been requested for any run in the given workflow instance.
     *
     * @param WorkflowInstance|int|null $instance The workflow instance or its ID.
     * @return bool True if a stop has been requested or the instance is cancelling.
     */
    public function shouldStopWorkflowInstance(WorkflowInstance|int|null $instance): bool
    {
        $instanceId = $instance instanceof WorkflowInstance ? $instance->getKey() : $instance;
        $instanceStatus = $instance instanceof WorkflowInstance ? $instance->status : null;

        if ($instanceStatus === 'cancelling') {
            return true;
        }

        if (! $instanceId) {
            return false;
        }

        try {
            return AgentRun::query()
                ->where('workflow_instance_id', $instanceId)
                ->where('operator_request_state', 'stop_requested')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Acknowledge and finalize a stop request for a workflow instance.
     *
     * @param WorkflowInstance|int $instance The workflow instance or its ID.
     * @param string|null          $note     Optional note for the cancellation.
     * @return AgentRun|null The cancelled agent run, or null if none found.
     */
    public function acknowledgeRequestedStopForInstance(WorkflowInstance|int $instance, ?string $note = null): ?AgentRun
    {
        $instanceModel = $instance instanceof WorkflowInstance
            ? $instance->fresh()
            : WorkflowInstance::query()->find($instance);

        $run = null;

        try {
            $run = AgentRun::query()
                ->where('workflow_instance_id', $instanceModel?->getKey())
                ->where('operator_request_state', 'stop_requested')
                ->latest('id')
                ->first();
        } catch (\Throwable) {
            $run = null;
        }

        if ($run) {
            return $this->acknowledgeStop($run, $note);
        }

        if ($instanceModel && $instanceModel->status === 'cancelling') {
            $instanceModel->fill([
                'status' => 'cancelled',
                'resume_step_id' => null,
                'finished_at' => now(),
            ])->save();
        }

        return null;
    }

    /**
     * Acknowledge a stop request and cancel the agent run with all associated entities.
     *
     * @param AgentRun    $run  The agent run to acknowledge the stop for.
     * @param string|null $note Optional note for the cancellation.
     * @return AgentRun The cancelled and refreshed agent run.
     */
    public function acknowledgeStop(AgentRun $run, ?string $note = null): AgentRun
    {
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep', 'steps', 'approvals']);

        if ($run->operator_request_state === 'stop_acknowledged' && $run->status === 'cancelled') {
            return $run->fresh(['profile', 'workflowInstance', 'parentWorkflowStep', 'sourceRun', 'stoppedBy', 'steps', 'approvals', 'artifacts', 'toolCalls']);
        }

        $result = DB::transaction(function () use ($run, $note) {
            $approval = $run->approvals()->latest('id')->first();

            if ($approval && in_array($approval->status, ['pending', 'approved'], true)) {
                $approval->fill([
                    'status' => 'cancelled',
                    'apply_outcome' => 'cancelled',
                    'apply_error' => $note,
                    'apply_finished_at' => now(),
                ])->save();
            }

            $run->fill([
                'status' => 'cancelled',
                'post_approval_outcome' => 'cancelled',
                'terminal_reason' => 'operator_stopped',
                'operator_request_state' => 'stop_acknowledged',
                'stop_acknowledged_at' => now(),
                'stopped_by_user_id' => $run->stop_requested_by_user_id ?: $run->stopped_by_user_id,
                'stopped_at' => now(),
                'finished_at' => now(),
                'last_checkpoint_at' => now(),
            ])->save();

            $step = $run->steps()->orderByDesc('step_index')->first();
            if ($step instanceof AgentRunStep) {
                $step->fill([
                    'status' => 'cancelled',
                    'terminal_reason' => 'operator_stopped',
                    'observation_summary' => $note ?: ($step->observation_summary ?: 'Stop acknowledged by runtime.'),
                ])->save();
            }

            if ($run->workflowInstance) {
                $run->workflowInstance->fill([
                    'status' => 'cancelled',
                    'resume_step_id' => null,
                    'finished_at' => now(),
                ])->save();
            }

            if ($run->parentWorkflowStep) {
                $run->parentWorkflowStep->fill([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'error_message' => $note ?: 'Stopped by operator.',
                ])->save();
            }

            return $run->fresh(['profile', 'workflowInstance', 'parentWorkflowStep', 'sourceRun', 'stoppedBy', 'steps', 'approvals', 'artifacts', 'toolCalls']);
        });

        $this->runtimeSyncService->syncFromRun($run->fresh(['workflowInstance']));

        return $result;
    }
}
