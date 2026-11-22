<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentRunStep;

/**
 * Orchestrates high-level agent run operations: stop, cancel, and rerun.
 *
 * Acts as a facade over AgentRunControlService (for stop) and
 * AgenticAdhocDispatchService (for rerun). Cancel directly transitions
 * runs in awaiting_approval or awaiting_apply status to cancelled,
 * updating all related approvals, steps, and workflow entities.
 *
 * @see AgentRunControlService
 * @see AgenticAdhocDispatchService
 * @see AgentScheduleRuntimeSyncService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentRunOperationsService
{
    /**
     * Initialize the operations service with dispatch, control, and sync dependencies.
     *
     * @param AgenticAdhocDispatchService     $adhocDispatchService Dispatches ad-hoc agent tool executions for reruns.
     * @param AgentRunControlService          $runControlService    Handles stop lifecycle for running agent runs.
     * @param AgentScheduleRuntimeSyncService $runtimeSyncService   Synchronizes schedule state after operations.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected AgenticAdhocDispatchService $adhocDispatchService,
        protected AgentRunControlService $runControlService,
        protected AgentScheduleRuntimeSyncService $runtimeSyncService,
    ) {
    }

    /**
     * Request a stop for a running agent run.
     *
     * @param AgentRun    $run   The agent run to stop.
     * @param User|null   $actor The operator requesting the stop.
     * @param string|null $note  Optional reason for stopping.
     * @return array{run: AgentRun, approval: AgentApproval|null}
     */
    public function stop(AgentRun $run, ?User $actor = null, ?string $note = null): array
    {
        return $this->runControlService->requestStop($run, $actor, $note);
    }

    /**
     * Cancel an agent run that is awaiting approval or apply.
     *
     * @param AgentRun    $run   The agent run to cancel.
     * @param User|null   $actor The operator cancelling the run.
     * @param string|null $note  Optional cancellation reason.
     * @return array{run: AgentRun, approval: AgentApproval|null}
     *
     * @throws \InvalidArgumentException When the run cannot be cancelled from its current status.
     */
    public function cancel(AgentRun $run, ?User $actor = null, ?string $note = null): array
    {
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep', 'steps', 'approvals']);

        if (! in_array($run->status, ['awaiting_approval', 'awaiting_apply'], true)) {
            throw new \InvalidArgumentException("Agent run [{$run->id}] cannot be cancelled from status [{$run->status}].");
        }

        $result = DB::transaction(function () use ($run, $actor, $note) {
            $approval = $run->approvals()->latest('id')->first();

            if ($approval && in_array($approval->status, ['pending', 'approved'], true)) {
                $approval->fill([
                    'status' => 'cancelled',
                    'reviewer_note' => $note ?: $approval->reviewer_note,
                    'reviewed_by_user_id' => $actor?->getKey(),
                    'reviewed_at' => now(),
                    'apply_outcome' => 'cancelled',
                    'apply_error' => $note,
                    'apply_finished_at' => now(),
                ])->save();
            }

            $run->fill([
                'status' => 'cancelled',
                'post_approval_outcome' => 'cancelled',
                'terminal_reason' => 'operator_cancelled',
                'stopped_by_user_id' => $actor?->getKey(),
                'stopped_at' => now(),
                'finished_at' => now(),
                'last_checkpoint_at' => now(),
            ])->save();

            $step = $run->steps()->orderByDesc('step_index')->first();
            if ($step instanceof AgentRunStep) {
                $step->fill([
                    'status' => 'cancelled',
                    'terminal_reason' => 'operator_cancelled',
                    'observation_summary' => $note ?: ($step->observation_summary ?: 'Cancelled by operator.'),
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
                    'error_message' => $note ?: 'Cancelled by operator.',
                ])->save();
            }

            return [
                'run' => $run->fresh(['profile', 'workflowInstance', 'parentWorkflowStep', 'sourceRun', 'stoppedBy', 'steps', 'approvals', 'artifacts', 'toolCalls']),
                'approval' => $approval?->fresh(['artifact', 'run.profile', 'reviewer']),
            ];
        });

        $this->runtimeSyncService->syncFromRun($run->fresh(['workflowInstance']));

        return $result;
    }

    /**
     * Rerun a completed or failed agent run with the same parameters.
     *
     * @param AgentRun  $run   The source run to re-execute.
     * @param User|null $actor The operator triggering the rerun.
     * @return array<string, mixed> The dispatch result including the new instance and run.
     *
     * @throws \InvalidArgumentException When the run cannot be re-run from its current status.
     */
    public function rerun(AgentRun $run, ?User $actor = null): array
    {
        if (in_array($run->status, ['init', 'running', 'awaiting_approval', 'awaiting_apply', 'applied'], true)) {
            throw new \InvalidArgumentException("Agent run [{$run->id}] cannot be re-run from status [{$run->status}].");
        }

        return $this->adhocDispatchService->rerun($run, $actor);
    }
}
