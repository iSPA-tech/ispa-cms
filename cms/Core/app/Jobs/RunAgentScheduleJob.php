<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Services\Agentic\AgentScheduleService;

/**
 * Queued job that triggers the execution of a scheduled agent run.
 *
 * Dispatched by the scheduler when an AgentSchedule is due to run. Delegates execution
 * to AgentScheduleService, recording any error on the schedule model before re-throwing.
 * On job failure (after retries are exhausted) it transitions the schedule back to a
 * steady state (active or paused) and advances the next_run_at timestamp based on the
 * schedule's configured cadence_minutes.
 *
 * @see \Cms\Core\Models\Agents\AgentSchedule
 * @see \Cms\Core\Services\Agentic\AgentScheduleService
 * @package Cms\Core\Jobs
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RunAgentScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param int    $scheduleId              Primary key of the AgentSchedule to execute
     * @param string $steadyStateAfterTerminal Status to set after the run completes ('active' or 'paused')
     */
    public function __construct(
        protected int $scheduleId,
        protected string $steadyStateAfterTerminal = 'active',
    )
    {
    }

    /**
     * Return the ID of the agent schedule this job will run.
     *
     * @return int AgentSchedule primary key
     */
    public function agentScheduleId(): int
    {
        return $this->scheduleId;
    }

    /**
     * Return the steady state the schedule should transition to after the run terminates.
     *
     * @return string Either 'active' or 'paused'
     */
    public function steadyStateAfterTerminal(): string
    {
        return $this->steadyStateAfterTerminal;
    }

    /**
     * Execute the job.
     *
     * Loads the schedule and delegates execution to AgentScheduleService. Records any
     * thrown exception on the schedule model before re-throwing.
     *
     * @param AgentScheduleService $scheduleService Agent schedule execution service
     * @return void
     */
    public function handle(AgentScheduleService $scheduleService): void
    {
        $schedule = AgentSchedule::query()->find($this->scheduleId);
        if (! $schedule) {
            return;
        }

        try {
            $scheduleService->execute($schedule, $this->steadyStateAfterTerminal);
        } catch (\Throwable $e) {
            $schedule->fill([
                'last_error' => $e->getMessage(),
            ])->save();

            Log::error('RunAgentScheduleJob failed', [
                'schedule_id' => $this->scheduleId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * Transitions the schedule from 'running' back to its steady state and advances
     * next_run_at based on cadence_minutes when the schedule is not paused.
     *
     * @param \Throwable $e The exception that caused the failure
     * @return void
     */
    public function failed(\Throwable $e): void
    {
        $schedule = AgentSchedule::query()->find($this->scheduleId);
        if (! $schedule || $schedule->status !== 'running') {
            return;
        }

        $schedule->fill([
            'status' => $this->steadyStateAfterTerminal === 'paused' ? 'paused' : 'active',
            'last_run_at' => now(),
            'last_outcome' => 'failed',
            'last_error' => $e->getMessage(),
            'next_run_at' => $this->steadyStateAfterTerminal === 'paused'
                ? $schedule->next_run_at
                : now()->addMinutes(max(1, (int) $schedule->cadence_minutes)),
        ])->save();
    }
}
