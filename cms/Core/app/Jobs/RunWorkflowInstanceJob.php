<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Jobs;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Services\Agentic\AgentRunControlService;
use Cms\Core\Services\Agentic\AgentScheduleRuntimeSyncService;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that resumes or starts execution of a WorkflowInstance.
 *
 * Dispatched whenever a workflow instance needs to be executed asynchronously (e.g., after
 * an async step completes or the workflow is initially triggered). Before executing,
 * it verifies the instance is in an actionable state and honours any pending cancellation
 * requests via AgentRunControlService. On failure the job marks the instance and creates a
 * failed WorkflowInstanceStep record, then propagates the exception so Laravel's queue
 * failure handling can record it.
 *
 * @see \Cms\Core\Models\Workflows\WorkflowInstance
 * @see \Cms\Core\Workflows\Runtime\WorkflowGraphExecutor
 * @package Cms\Core\Jobs
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RunWorkflowInstanceJob implements ShouldQueue
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param int $instanceId Primary key of the WorkflowInstance to execute
     */
    public function __construct(protected int $instanceId)
    {
    }

    /**
     * Return the ID of the workflow instance this job will execute.
     *
     * @return int Workflow instance primary key
     */
    public function workflowInstanceId(): int
    {
        return $this->instanceId;
    }

    /**
     * Execute the job.
     *
     * Loads the workflow instance, checks its status and any pending stop requests,
     * then delegates to WorkflowGraphExecutor to run the instance graph.
     *
     * @param WorkflowGraphExecutor   $executor          Workflow execution engine
     * @param AgentRunControlService  $runControlService Service for stop-request checks
     * @return void
     */
    public function handle(
        WorkflowGraphExecutor $executor,
        AgentRunControlService $runControlService,
    ): void
    {
        try {
            $instance = WorkflowInstance::query()->find($this->instanceId);
            if (!$instance) {
                return;
            }

            if (in_array($instance->status, ['completed', 'cancelled', 'failed'], true)) {
                return;
            }

            if ($instance->status === 'cancelling' || $runControlService->shouldStopWorkflowInstance($instance)) {
                $runControlService->acknowledgeRequestedStopForInstance(
                    $instance,
                    'Stop acknowledged before queued workflow execution resumed.'
                );
                return;
            }

            $executor->execute($this->instanceId, true);
        } catch (\Throwable $e) {
            Log::error('RunWorkflowInstanceJob failed', [
                'instance_id' => $this->instanceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * Marks the workflow instance as failed, creates a failed WorkflowInstanceStep record,
     * and syncs the agent schedule runtime state.
     *
     * @param \Throwable $e The exception that caused the failure
     * @return void
     */
    public function failed(\Throwable $e): void
    {
        $instance = WorkflowInstance::query()->find($this->instanceId);
        if (! $instance || in_array($instance->status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }

        $instance->fill([
            'status' => 'failed',
            'resume_step_id' => null,
            'finished_at' => now(),
        ])->save();

        WorkflowInstanceStep::query()->create([
            'instance_id' => $instance->id,
            'step_id' => null,
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'error_message' => $e->getMessage(),
        ]);

        app(AgentScheduleRuntimeSyncService::class)->syncFromWorkflowInstance($instance->fresh());
    }
}
