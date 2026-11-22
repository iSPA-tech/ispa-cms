<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Services\Agentic\AgentScheduleService;
use Cms\Core\Services\Agentic\AgenticAuthorization;

/**
 * Manage agent schedule records via the admin API.
 *
 * Provides endpoints to list, create, update, and dispatch recurring agent
 * schedules. Each schedule links a profile and tool definition to a cadence,
 * allowing periodic automated runs of agentic workflows.
 *
 * @see AgentSchedule
 * @see AgentScheduleService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticScheduleController extends Controller
{
    /**
     * List agent schedules with filtering and pagination.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  AgenticAuthorization   $authorization  Authorization service
     * @return AppResponse  Paginated list of schedules
     */
    public function index(Request $request, AgenticAuthorization $authorization): AppResponse
    {
        if (! $authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        [$page, $perPage] = $this->pagination($request, 20);

        $query = AgentSchedule::query()
            ->with([
                'profile:id,name,slug,capability_class',
                'toolDefinition:id,tool_name,tool_family,capability_class',
                'workflowProject:id,name,slug',
                'lastRun:id,status,post_approval_outcome',
                'creator:id,name',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('profile'), function ($q) use ($request) {
                $profile = $request->string('profile')->toString();
                $q->whereHas('profile', function ($profileQuery) use ($profile) {
                    if (ctype_digit($profile)) {
                        $profileQuery->where(function ($matchingProfileQuery) use ($profile) {
                            $matchingProfileQuery->whereKey((int) $profile)
                                ->orWhere('slug', $profile);
                        });

                        return;
                    }

                    $profileQuery->where('slug', $profile);
                });
            })
            ->when($request->filled('tool_name'), function ($q) use ($request) {
                $toolName = $request->string('tool_name')->toString();
                $q->whereHas('toolDefinition', fn ($definitionQuery) => $definitionQuery->where('tool_name', 'like', '%' . $toolName . '%'));
            })
            ->when($request->filled('module_scope'), fn ($q) => $q->where('module_scope', $request->string('module_scope')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search')->toString() . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('goal', 'like', $term);
                });
            })
            ->orderBy('next_run_at')
            ->orderByDesc('id');

        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (AgentSchedule $schedule) => $this->serializeSchedule($schedule))
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => $this->meta($page, $perPage, $total),
        ]);
    }

    /**
     * Create a new agent schedule.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  AgentScheduleService   $scheduleService  Schedule management service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Created schedule data
     */
    public function store(
        Request $request,
        AgentScheduleService $scheduleService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (! $authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'agent_profile_id' => ['required', 'integer'],
            'tool_definition_id' => ['required', 'integer'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,paused'],
            'environment' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'string'],
            'module_scope' => ['nullable', 'string'],
            'target_type' => ['nullable', 'string'],
            'target_id' => ['nullable'],
            'goal' => ['nullable', 'string'],
            'tool_input' => ['nullable', 'array'],
            'cadence_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $schedule = $scheduleService->create($validator->validated(), $request->user());
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.schedule-created'))
            ->data($this->serializeSchedule($schedule->load(['profile', 'toolDefinition', 'workflowProject', 'lastRun', 'creator'])));
    }

    /**
     * Update an existing agent schedule.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $schedule         Schedule ID
     * @param  AgentScheduleService   $scheduleService  Schedule management service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Updated schedule data
     */
    public function update(
        Request $request,
        string $schedule,
        AgentScheduleService $scheduleService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (! $authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolved = AgentSchedule::query()->find($schedule);
        if (! $resolved) {
            return ires()->notFound(__('mgs.agentic.schedule-not-found'));
        }

        $validator = Validator::make($request->all(), [
            'agent_profile_id' => ['sometimes', 'integer'],
            'tool_definition_id' => ['sometimes', 'integer'],
            'name' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,paused'],
            'environment' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'string'],
            'module_scope' => ['nullable', 'string'],
            'target_type' => ['nullable', 'string'],
            'target_id' => ['nullable'],
            'goal' => ['nullable', 'string'],
            'tool_input' => ['nullable', 'array'],
            'cadence_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $updated = $scheduleService->update($resolved, $validator->validated());
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.schedule-updated'))
            ->data($this->serializeSchedule($updated));
    }

    /**
     * Queue an active schedule for immediate execution.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $schedule         Schedule ID
     * @param  AgentScheduleService   $scheduleService  Schedule management service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Queued schedule data
     */
    public function queueRun(
        Request $request,
        string $schedule,
        AgentScheduleService $scheduleService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (! $authorization->canOperate($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolved = AgentSchedule::query()->find($schedule);
        if (! $resolved) {
            return ires()->notFound(__('mgs.agentic.schedule-not-found'));
        }

        try {
            $queued = $scheduleService->markQueued($resolved, ['active']);
            \Cms\Core\Jobs\RunAgentScheduleJob::dispatch($queued->id, 'active');
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.schedule-queued'))
            ->data($this->serializeSchedule($queued->fresh(['profile', 'toolDefinition', 'workflowProject', 'lastRun', 'creator'])));
    }

    /**
     * Queue a paused schedule for a one-off execution without resuming it.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $schedule         Schedule ID
     * @param  AgentScheduleService   $scheduleService  Schedule management service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Queued schedule data
     */
    public function queueRunOnce(
        Request $request,
        string $schedule,
        AgentScheduleService $scheduleService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (! $authorization->canOperate($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolved = AgentSchedule::query()->find($schedule);
        if (! $resolved) {
            return ires()->notFound(__('mgs.agentic.schedule-not-found'));
        }

        try {
            $queued = $scheduleService->markQueued($resolved, ['paused']);
            \Cms\Core\Jobs\RunAgentScheduleJob::dispatch($queued->id, 'paused');
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.schedule-run-once'))
            ->data($this->serializeSchedule($queued->fresh(['profile', 'toolDefinition', 'workflowProject', 'lastRun', 'creator'])));
    }

    /**
     * Resume a paused schedule and queue it for immediate execution.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $schedule         Schedule ID
     * @param  AgentScheduleService   $scheduleService  Schedule management service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Resumed and queued schedule data
     */
    public function resumeAndQueueRun(
        Request $request,
        string $schedule,
        AgentScheduleService $scheduleService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (! $authorization->canProvision($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolved = AgentSchedule::query()->find($schedule);
        if (! $resolved) {
            return ires()->notFound(__('mgs.agentic.schedule-not-found'));
        }

        try {
            $queued = $scheduleService->markQueued($resolved, ['paused']);
            \Cms\Core\Jobs\RunAgentScheduleJob::dispatch($queued->id, 'active');
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.schedule-resumed'))
            ->data($this->serializeSchedule($queued->fresh(['profile', 'toolDefinition', 'workflowProject', 'lastRun', 'creator'])));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSchedule(AgentSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'description' => $schedule->description,
            'status' => $schedule->status,
            'environment' => $this->resolveScheduleEnvironment($schedule),
            'tenant_id' => $schedule->tenant_id,
            'module_scope' => $this->resolveScheduleModuleScope($schedule),
            'target_type' => $schedule->target_type,
            'target_id' => $schedule->target_id,
            'goal' => $schedule->goal,
            'tool_input' => $schedule->tool_input,
            'cadence_minutes' => $schedule->cadence_minutes,
            'next_run_at' => optional($schedule->next_run_at)->toJSON(),
            'last_run_at' => optional($schedule->last_run_at)->toJSON(),
            'last_outcome' => $schedule->last_outcome,
            'last_error' => $schedule->last_error,
            'profile' => [
                'id' => $schedule->profile?->id,
                'slug' => $schedule->profile?->slug,
                'name' => $schedule->profile?->name,
                'capability_class' => $schedule->profile?->capability_class,
            ],
            'tool_definition' => [
                'id' => $schedule->toolDefinition?->id,
                'tool_name' => $schedule->toolDefinition?->tool_name,
                'tool_family' => $schedule->toolDefinition?->tool_family,
                'capability_class' => $schedule->toolDefinition?->capability_class,
                'description' => $schedule->toolDefinition?->description,
                'side_effect_class' => $schedule->toolDefinition?->side_effect_class,
                'approval_required' => (bool) ($schedule->toolDefinition?->approval_required ?? false),
            ],
            'workflow_project' => [
                'id' => $schedule->workflowProject?->id,
                'name' => $schedule->workflowProject?->name,
                'slug' => $schedule->workflowProject?->slug,
            ],
            'last_run' => [
                'id' => $schedule->lastRun?->id,
                'status' => $schedule->lastRun?->status,
                'post_approval_outcome' => $schedule->lastRun?->post_approval_outcome,
            ],
            'actions' => [
                'can_dispatch' => $schedule->status === 'active',
                'can_pause' => $schedule->status === 'active',
                'can_resume' => $schedule->status === 'paused',
                'can_run_once' => $schedule->status === 'paused',
                'can_resume_and_dispatch' => $schedule->status === 'paused',
            ],
            'creator' => [
                'id' => $schedule->creator?->id,
                'name' => $schedule->creator?->name,
            ],
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function pagination(Request $request, int $defaultPerPage = 20): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(200, max(1, (int) $request->input('per_page', $defaultPerPage)));

        return [$page, $perPage];
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    protected function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    /**
     * Resolve the effective environment for a schedule, falling back to config default.
     *
     * @param  AgentSchedule  $schedule  The schedule to resolve for
     * @return string|null  The resolved environment string
     */
    protected function resolveScheduleEnvironment(AgentSchedule $schedule): ?string
    {
        if (is_string($schedule->environment) && trim($schedule->environment) !== '') {
            return trim($schedule->environment);
        }

        return config('core.agentic.discovery.default_environment');
    }

    /**
     * Resolve the effective module scope for a schedule, falling back to tool family/steward.
     *
     * @param  AgentSchedule  $schedule  The schedule to resolve for
     * @return string|null  The resolved module scope
     */
    protected function resolveScheduleModuleScope(AgentSchedule $schedule): ?string
    {
        if (is_string($schedule->module_scope) && trim($schedule->module_scope) !== '') {
            return trim($schedule->module_scope);
        }

        foreach ([
            $schedule->toolDefinition?->tool_family,
            $schedule->toolDefinition?->contract_steward,
        ] as $fallback) {
            if (is_string($fallback) && trim($fallback) !== '') {
                return trim($fallback);
            }
        }

        return null;
    }
}
