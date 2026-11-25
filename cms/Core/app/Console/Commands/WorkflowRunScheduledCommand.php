<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;

/**
 * Start workflow projects whose cron schedule is due this minute, and expire workflow approvals past `expires_at`.
 *
 * Usage: php artisan cms:workflow-run-scheduled
 * Scheduled every minute (withoutOverlapping) by app/Console/Kernel.php when
 * core.workflow_schedule.enabled (WORKFLOW_SCHEDULER_ENABLED) is true; does
 * nothing while it is false.
 *
 * @see WorkflowProjectRunService::runDue()
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   2026.11 (2026-11-24)
 */
class WorkflowRunScheduledCommand extends Command
{
    protected $signature = 'cms:workflow-run-scheduled';

    protected $description = 'Start workflow projects whose cron schedule is due.';

    public function handle(WorkflowProjectRunService $runService, AgentApprovalService $approvals, WorkflowPublishService $publisher): int
    {
        // Sensitive workflow changes whose cooling-off ended go live (guardrails B3); not tied to cron schedules.
        $promoted = $publisher->promoteDue();
        if ($promoted['promoted'] !== [] || $promoted['cancelled'] !== []) {
            $this->line('Workflow changes put live: ' . count($promoted['promoted']) . '; cancelled: ' . count($promoted['cancelled']) . '.');
        }

        if (! config('core.workflow_schedule.enabled')) {
            $this->line('Workflow scheduler is disabled (WORKFLOW_SCHEDULER_ENABLED).');

            return self::SUCCESS;
        }

        // Free projects blocked by a run whose worker died, before deciding what is due.
        $stale = $runService->failStaleRunning();
        if ($stale !== []) {
            $this->line('Failed ' . count($stale) . ' stale running workflow run(s): #' . implode(', #', $stale));
        }

        // Approvals nobody decided in time (the delayed expiry job never waits on a sync queue).
        $expired = $approvals->expireDueWorkflowApprovals();
        if ($expired > 0) {
            $this->line("Expired {$expired} workflow approval(s).");
        }

        // Approvals past their SLA: flagged and their reviewers told, once each.
        $overdue = $approvals->flagOverdueApprovals();
        if ($overdue > 0) {
            $this->line("Flagged {$overdue} overdue approval(s).");
        }

        $result = $runService->runDue();

        $this->info('Started ' . count($result['started']) . ' scheduled workflow run(s).');

        foreach ($result['skipped'] as $projectId => $reason) {
            $this->line("Skipped project #{$projectId}: {$reason}");
        }

        return self::SUCCESS;
    }
}
