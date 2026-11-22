<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Carbon\CarbonInterface;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Agents\Attributes\AgentTool;

/**
 * Provides operational monitoring tools for the agentic subsystem.
 *
 * Exposes agent tools (via AgentTool attributes) that summarize recent
 * agentic failures, pending approvals, and schedule incidents within
 * a configurable time window. Designed for autonomous maintenance
 * profiles to generate health digests.
 *
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticOpsToolService
{
    /**
     * Summarize recent agentic health: failures, pending approvals, and schedule incidents.
     *
     * @param int $hours Time window in hours (1-168).
     * @param int $limit Maximum number of items per section (1-20).
     * @return array<string, mixed> Health overview payload.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'core.agentic_health_overview',
        family: 'ops',
        moduleScope: 'core',
        contractSteward: 'core',
        sideEffectClass: 'read_only',
        approvalRequired: false,
        capabilityClass: 'class_3_autonomous_maintenance',
        description: 'Summarize recent agentic failures, pending approvals, and schedule incidents for operators.'
    )]
    public function agentic_health_overview_tool(
        int $hours = 24,
        int $limit = 5,
    ): array {
        $hours = max(1, min($hours, 168));
        $limit = max(1, min($limit, 20));
        $since = now()->subHours($hours);

        return [
            'window_hours' => $hours,
            'generated_at' => now()->toISOString(),
            'run_summary' => [
                'failed' => AgentRun::query()->where('created_at', '>=', $since)->where('status', 'failed')->count(),
                'cancelled' => AgentRun::query()->where('created_at', '>=', $since)->where('status', 'cancelled')->count(),
                'awaiting_approval' => AgentRun::query()->where('created_at', '>=', $since)->where('status', 'awaiting_approval')->count(),
                'awaiting_apply' => AgentRun::query()->where('created_at', '>=', $since)->where('status', 'awaiting_apply')->count(),
                'completed' => AgentRun::query()->where('created_at', '>=', $since)->where('status', 'completed')->count(),
            ],
            'pending_approvals' => AgentApproval::query()
                ->with(['run.profile:id,slug'])
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (AgentApproval $approval) => [
                    'id' => $approval->id,
                    'run_id' => $approval->run_id,
                    'profile' => $approval->run?->profile?->slug,
                    'created_at' => optional($approval->created_at)->toISOString(),
                ])->all(),
            'failed_runs' => AgentRun::query()
                ->with(['profile:id,slug'])
                ->where('created_at', '>=', $since)
                ->whereIn('status', ['failed', 'cancelled'])
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (AgentRun $run) => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'terminal_reason' => $run->terminal_reason,
                    'profile' => $run->profile?->slug,
                    'created_at' => $this->toIso($run->created_at),
                ])->all(),
            'schedule_incidents' => AgentSchedule::query()
                ->with(['profile:id,slug', 'toolDefinition:id,tool_name'])
                ->where(function ($query) {
                    $query->whereNotNull('last_error')
                        ->orWhere('last_outcome', 'failed');
                })
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->map(fn (AgentSchedule $schedule) => [
                    'id' => $schedule->id,
                    'name' => $schedule->name,
                    'status' => $schedule->status,
                    'last_outcome' => $schedule->last_outcome,
                    'last_error' => $schedule->last_error,
                    'profile' => $schedule->profile?->slug,
                    'tool_name' => $schedule->toolDefinition?->tool_name,
                    'updated_at' => $this->toIso($schedule->updated_at),
                ])->all(),
        ];
    }

    protected function toIso(?CarbonInterface $value): ?string
    {
        return $value?->toISOString();
    }
}
