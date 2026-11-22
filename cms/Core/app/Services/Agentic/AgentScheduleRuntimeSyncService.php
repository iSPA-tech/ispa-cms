<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronizes agent schedule status with runtime execution outcomes.
 *
 * After an agent run or workflow instance completes, updates the associated
 * AgentSchedule record with last_run_at, last_outcome, last_error, and
 * next_run_at based on cadence. Resolves the schedule from workflow context
 * snapshots or trigger references.
 *
 * @see AgentScheduleService
 * @see AgentApprovalService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentScheduleRuntimeSyncService
{
    /**
     * Synchronize schedule state from a completed agent run.
     *
     * @param AgentRun $run The agent run to sync from.
     * @return AgentSchedule|null The updated schedule, or null if not applicable.
     */
    public function syncFromRun(AgentRun $run): ?AgentSchedule
    {
        if ($run->workflowInstance) {
            return $this->syncFromWorkflowInstance($run->workflowInstance, $run);
        }

        if (! $this->isScheduledRun($run) || ! is_numeric($run->trigger_reference_id)) {
            return null;
        }

        $schedule = AgentSchedule::query()->find((int) $run->trigger_reference_id);
        if (! $schedule) {
            return null;
        }

        $terminal = ! in_array($run->status, ['init', 'running', 'awaiting_approval', 'awaiting_apply', 'applied', 'cancelling'], true);

        $schedule->fill([
            'status' => $terminal ? $this->resolveTerminalSteadyState($run, null) : 'running',
            'last_run_id' => $run->getKey(),
            'last_run_at' => now(),
            'last_outcome' => $run->status,
            'last_error' => $run->terminal_reason,
            'next_run_at' => $terminal
                ? $this->resolveNextRunAt($schedule, $this->resolveTerminalSteadyState($run, null))
                : $schedule->next_run_at,
        ])->save();

        return $schedule->fresh(['profile', 'toolDefinition', 'lastRun', 'creator', 'workflowProject']);
    }

    /**
     * Synchronize schedule state from a workflow instance execution.
     *
     * @param WorkflowInstance $instance The workflow instance to sync from.
     * @param AgentRun|null    $run      Optional associated agent run.
     * @return AgentSchedule|null The updated schedule, or null if not applicable.
     */
    public function syncFromWorkflowInstance(WorkflowInstance $instance, ?AgentRun $run = null): ?AgentSchedule
    {
        $schedule = $this->resolveSchedule($instance, $run);
        if (! $schedule) {
            return null;
        }

        $terminal = $this->isWorkflowTerminal($instance);
        $steadyStateAfterTerminal = $this->resolveTerminalSteadyState($run, $instance);

        $schedule->fill([
            'status' => $terminal ? $steadyStateAfterTerminal : 'running',
            'last_run_id' => $run?->getKey() ?? $schedule->last_run_id,
            'last_run_at' => now(),
            'last_outcome' => $instance->status,
            'last_error' => $this->resolveWorkflowError($instance, $run),
            'next_run_at' => $terminal
                ? $this->resolveNextRunAt($schedule, $steadyStateAfterTerminal)
                : $schedule->next_run_at,
        ])->save();

        return $schedule->fresh(['profile', 'toolDefinition', 'lastRun', 'creator', 'workflowProject']);
    }

    protected function resolveSchedule(WorkflowInstance $instance, ?AgentRun $run = null): ?AgentSchedule
    {
        if (! Schema::hasTable('agent_schedules')) {
            return null;
        }

        $scheduleId = $this->resolveScheduleId($instance, $run);

        return $scheduleId ? AgentSchedule::query()->find($scheduleId) : null;
    }

    protected function resolveScheduleId(WorkflowInstance $instance, ?AgentRun $run = null): ?int
    {
        $contextScheduleId = data_get($instance->context_snapshot, 'vars.__runtime.agent_schedule_id');
        if (is_numeric($contextScheduleId)) {
            return (int) $contextScheduleId;
        }

        if (! Schema::hasTable('agent_runs')) {
            return null;
        }

        $scheduledRun = $run;
        if (! $scheduledRun || ! $this->isScheduledRun($scheduledRun)) {
            $scheduledRun = AgentRun::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('trigger_mode', 'schedule')
                ->where('trigger_reference_type', 'agent_schedule')
                ->latest('id')
                ->first();
        }

        if ($scheduledRun && is_numeric($scheduledRun->trigger_reference_id)) {
            return (int) $scheduledRun->trigger_reference_id;
        }

        return null;
    }

    protected function isScheduledRun(AgentRun $run): bool
    {
        return $run->trigger_mode === 'schedule'
            && $run->trigger_reference_type === 'agent_schedule'
            && is_numeric($run->trigger_reference_id);
    }

    protected function isWorkflowTerminal(WorkflowInstance $instance): bool
    {
        return $instance->finished_at !== null
            || in_array($instance->status, ['completed', 'cancelled', 'failed'], true);
    }

    protected function resolveTerminalSteadyState(?AgentRun $run = null, ?WorkflowInstance $instance = null): string
    {
        $contextState = data_get($instance?->context_snapshot, 'vars.__runtime.agent_schedule_steady_state_after_terminal');
        if ($contextState === 'paused') {
            return 'paused';
        }

        return 'active';
    }

    protected function resolveNextRunAt(AgentSchedule $schedule, string $steadyStateAfterTerminal): mixed
    {
        if ($steadyStateAfterTerminal === 'paused') {
            return $schedule->next_run_at;
        }

        return now()->addMinutes(max(1, (int) $schedule->cadence_minutes));
    }

    protected function resolveWorkflowError(WorkflowInstance $instance, ?AgentRun $run = null): ?string
    {
        $terminalReason = $run?->terminal_reason;
        if (is_string($terminalReason) && trim($terminalReason) !== '') {
            return $terminalReason;
        }

        if (! $this->isWorkflowTerminal($instance) || $instance->status === 'completed') {
            return null;
        }

        if (! Schema::hasTable('workflow_instance_steps')) {
            return $instance->status !== 'completed'
                ? "Workflow instance finished with status [{$instance->status}]."
                : null;
        }

        $stepError = WorkflowInstanceStep::query()
            ->where('instance_id', $instance->getKey())
            ->whereNotNull('error_message')
            ->latest('id')
            ->value('error_message');

        if (is_string($stepError) && trim($stepError) !== '') {
            return $stepError;
        }

        return $instance->status !== 'completed'
            ? "Workflow instance finished with status [{$instance->status}]."
            : null;
    }
}
