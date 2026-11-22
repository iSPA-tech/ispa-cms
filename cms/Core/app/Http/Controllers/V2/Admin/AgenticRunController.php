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
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Agentic\AgenticAdhocDispatchService;
use Cms\Core\Services\Agentic\AgentRunOperationsService;

/**
 * Manage agent run records via the admin API.
 *
 * Provides endpoints to list, view, cancel, stop, and re-run agent executions.
 * Each run tracks its steps, approvals, artifacts, and tool calls, giving
 * administrators full visibility into agentic operation lifecycles.
 * List and detail only reach runs whose target record is inside the caller's
 * data scope; a run outside it is left out of the list and answers 404.
 *
 * @see AgentRun
 * @see AgentRunOperationsService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticRunController extends Controller
{
    /**
     * List agent runs with filtering and pagination.
     *
     * Runs whose target is outside the viewer's data scope are filtered in the
     * query, so the pagination total counts only visible runs.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @param  AgentApprovalService   $approvalService  Data-scope filter for run targets
     * @return AppResponse  Paginated list of agent runs
     */
    public function index(
        Request $request,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        [$page, $perPage] = $this->pagination($request, 20);

        $query = AgentRun::query()
            ->with([
                'profile:id,name,slug',
                'sourceRun:id,status',
                'workflowInstance:id,status,resume_step_id',
                'parentWorkflowStep:id,status',
                'steps:id,run_id,step_index,state,action_type,action_name,status',
                'approvals:id,run_id,artifact_id,status,apply_outcome',
                'artifacts:id,run_id,artifact_type,render_hint',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('module_scope'), fn ($q) => $q->where('module_scope', $request->string('module_scope')->toString()))
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
            ->when($request->filled('workflow_id'), fn ($q) => $q->where('workflow_instance_id', (int) $request->input('workflow_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search')->toString() . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('input_goal', 'like', $term)
                        ->orWhere('target_type', 'like', $term)
                        ->orWhere('target_id', 'like', $term)
                        ->orWhere('module_scope', 'like', $term)
                        ->orWhere('environment', 'like', $term);
                });
            })
            ->orderByDesc('id');

        $approvalService->constrainRunsToScope($query, $request->user());

        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (AgentRun $run) => $this->serializeRun($run))
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => $this->meta($page, $perPage, $total),
        ]);
    }

    /**
     * Show a single agent run with full detail (steps, approvals, artifacts, tool calls).
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  string                 $run              Run ID
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @param  AgentApprovalService   $approvalService  Data-scope check for the run target
     * @return AppResponse  Detailed run data; 404 when the target is outside the viewer's data scope
     */
    public function show(
        Request $request,
        string $run,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedRun = AgentRun::query()
            ->with([
                'profile:id,name,slug',
                'workflowInstance:id,status,resume_step_id',
                'parentWorkflowStep:id,status',
                'sourceRun:id,status',
                'stoppedBy:id,name',
                'stopRequestedBy:id,name',
                'steps',
                'approvals.artifact',
                'artifacts',
                'toolCalls',
            ])
            ->find($run);

        if (!$resolvedRun || !$approvalService->allowsTarget($resolvedRun->target_type, $resolvedRun->target_id, $request->user())) {
            return ires()->notFound(__('mgs.agentic.run-not-found'));
        }

        return ires()->data($this->serializeRun($resolvedRun, true));
    }

    /**
     * Cancel an agent run that is awaiting approval or apply.
     *
     * @param  Request                      $request        The incoming HTTP request
     * @param  string                       $run            Run ID
     * @param  AgentRunOperationsService    $operations     Run operations service
     * @param  AgenticAuthorization         $authorization  Authorization service
     * @param  AgentApprovalService         $approvalService  Data-scope check for the run target
     * @return AppResponse  Updated run data after cancellation; 404 when the target is outside the caller's data scope
     */
    public function cancel(
        Request $request,
        string $run,
        AgentRunOperationsService $operations,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canOperate($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedRun = AgentRun::query()
            ->with([
                'profile',
                'workflowInstance',
                'parentWorkflowStep',
                'sourceRun',
                'stoppedBy',
                'stopRequestedBy',
                'steps',
                'approvals.artifact',
                'artifacts',
                'toolCalls',
            ])
            ->find($run);

        if (!$resolvedRun || !$approvalService->allowsTarget($resolvedRun->target_type, $resolvedRun->target_id, $request->user())) {
            return ires()->notFound(__('mgs.agentic.run-not-found'));
        }

        $validator = Validator::make($request->all(), [
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $result = $operations->cancel(
                $resolvedRun,
                $request->user(),
                $request->string('note')->toString() ?: null,
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.run-cancelled'))
            ->data($this->serializeRun($result['run'], true));
    }

    /**
     * Request a graceful stop for a running or initialized agent run.
     *
     * @param  Request                      $request        The incoming HTTP request
     * @param  string                       $run            Run ID
     * @param  AgentRunOperationsService    $operations     Run operations service
     * @param  AgenticAuthorization         $authorization  Authorization service
     * @param  AgentApprovalService         $approvalService  Data-scope check for the run target
     * @return AppResponse  Updated run data after stop request; 404 when the target is outside the caller's data scope
     */
    public function stop(
        Request $request,
        string $run,
        AgentRunOperationsService $operations,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canOperate($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedRun = AgentRun::query()
            ->with([
                'profile',
                'workflowInstance',
                'parentWorkflowStep',
                'sourceRun',
                'stoppedBy',
                'stopRequestedBy',
                'steps',
                'approvals.artifact',
                'artifacts',
                'toolCalls',
            ])
            ->find($run);

        if (!$resolvedRun || !$approvalService->allowsTarget($resolvedRun->target_type, $resolvedRun->target_id, $request->user())) {
            return ires()->notFound(__('mgs.agentic.run-not-found'));
        }

        $validator = Validator::make($request->all(), [
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $result = $operations->stop(
                $resolvedRun,
                $request->user(),
                $request->string('note')->toString() ?: null,
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.run-stop-requested'))
            ->data($this->serializeRun($result['run'], true));
    }

    /**
     * Re-dispatch a completed or failed agent run as a new run.
     *
     * @param  Request                      $request        The incoming HTTP request
     * @param  string                       $run            Run ID to re-dispatch
     * @param  AgentRunOperationsService    $operations     Run operations service
     * @param  AgenticAuthorization         $authorization  Authorization service
     * @param  AgentApprovalService         $approvalService  Data-scope check for the run target
     * @return AppResponse  New run data; 404 when the source run's target is outside the caller's data scope
     */
    public function rerun(
        Request $request,
        string $run,
        AgentRunOperationsService $operations,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canOperate($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedRun = AgentRun::query()
            ->with([
                'profile',
                'workflowInstance',
                'parentWorkflowStep',
                'sourceRun',
                'stoppedBy',
                'stopRequestedBy',
                'steps',
                'approvals.artifact',
                'artifacts',
                'toolCalls',
            ])
            ->find($run);

        if (!$resolvedRun || !$approvalService->allowsTarget($resolvedRun->target_type, $resolvedRun->target_id, $request->user(), true)) {
            return ires()->notFound(__('mgs.agentic.run-not-found'));
        }

        try {
            $result = $operations->rerun($resolvedRun, $request->user());
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        /** @var AgentRun|null $rerun */
        $rerun = $result['run'] ?? null;
        if (!$rerun) {
            return ires()->badRequest(__('mgs.agentic.run-rerun-failed'));
        }

        $rerun->loadMissing([
            'profile',
            'workflowInstance',
                'parentWorkflowStep',
                'sourceRun',
                'stoppedBy',
                'stopRequestedBy',
                'steps',
                'approvals.artifact',
                'artifacts',
            'toolCalls',
        ]);

        return ires()
            ->message(__('mgs.agentic.run-redispatched'))
            ->data($this->serializeRun($rerun, true));
    }

    /**
     * Launch an ad-hoc agentic task.
     *
     * Resolves the first active profile that grants the requested tool,
     * then dispatches via AgenticAdhocDispatchService.
     *
     * @param  Request                    $request
     * @param  AgenticAuthorization       $authorization
     * @param  AgenticAdhocDispatchService $dispatchService
     * @return AppResponse
     */
    public function launch(
        Request $request,
        AgenticAuthorization $authorization,
        AgenticAdhocDispatchService $dispatchService,
    ): AppResponse {
        if (!$authorization->canOperate($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'tool_name' => 'required|string',
            'target_type' => 'required|string',
            'target_id' => 'nullable',
            'goal' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return ires()->badRequest()->message($validator->errors()->first());
        }

        $toolName = $request->string('tool_name')->toString();

        // Find the first active profile that grants this tool
        $profile = AgentProfile::query()
            ->where('status', 'active')
            ->whereHas('toolDefinitions', function ($query) use ($toolName) {
                $query->where('tool_name', $toolName)
                    ->where('agent_profile_tools.enabled', true);
            })
            ->first();

        if (!$profile) {
            return ires()->badRequest()->message(__('mgs.agentic.no-profile-for-tool', ['tool' => $toolName]));
        }

        try {
            $result = $dispatchService->dispatchDeterministicTool(
                profile: $profile,
                toolName: $toolName,
                toolInput: [],
                goal: $request->string('goal')->toString() ?: "Execute {$toolName}",
                targetType: $request->string('target_type')->toString(),
                targetId: $request->input('target_id'),
                actor: $request->user(),
            );

            $run = $result['run'] ?? null;

            return ires()
                ->message(__('mgs.agentic.task-launched'))
                ->data([
                    'run' => $run ? $this->serializeRun($run->fresh([
                        'profile',
                        'sourceRun',
                        'steps',
                        'approvals.artifact',
                        'artifacts',
                        'toolCalls',
                    ]), true) : null,
                    'approval_id' => $result['approval_id'] ?? null,
                ]);
        } catch (\Throwable $e) {
            return ires()->badRequest()->message($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeRun(AgentRun $run, bool $detailed = false): array
    {
        $latestStep = $run->relationLoaded('steps') ? $run->steps->sortByDesc('step_index')->first() : null;
        $latestApproval = $run->relationLoaded('approvals') ? $run->approvals->sortByDesc('id')->first() : null;
        $latestArtifact = $run->relationLoaded('artifacts') ? $run->artifacts->sortByDesc('id')->first() : null;

        $payload = [
            'id' => $run->id,
            'status' => $run->status,
            'environment' => $run->environment,
            'module_scope' => $run->module_scope,
            'target_type' => $run->target_type,
            'target_id' => $run->target_id,
            'input_goal' => $run->input_goal,
            'terminal_reason' => $run->terminal_reason,
            'post_approval_outcome' => $run->post_approval_outcome,
            'trigger_mode' => $run->trigger_mode,
            'trigger_reference_type' => $run->trigger_reference_type,
            'trigger_reference_id' => $run->trigger_reference_id,
            'source_run_id' => $run->source_run_id,
            'started_at' => optional($run->started_at)->toJSON(),
            'finished_at' => optional($run->finished_at)->toJSON(),
            'last_checkpoint_at' => optional($run->last_checkpoint_at)->toJSON(),
            'stopped_at' => optional($run->stopped_at)->toJSON(),
            'operator_request_state' => $run->operator_request_state,
            'stop_requested_at' => optional($run->stop_requested_at)->toJSON(),
            'stop_acknowledged_at' => optional($run->stop_acknowledged_at)->toJSON(),
            'budget_usage' => $run->budget_usage,
            'profile' => [
                'id' => $run->profile?->id,
                'slug' => $run->profile?->slug,
                'name' => $run->profile?->name,
            ],
            'source_run' => $run->sourceRun ? [
                'id' => $run->sourceRun->id,
                'status' => $run->sourceRun->status,
            ] : null,
            'stopped_by' => $run->relationLoaded('stoppedBy') && $run->stoppedBy ? [
                'id' => $run->stoppedBy->id,
                'name' => $run->stoppedBy->name,
            ] : null,
            'stop_requested_by' => $run->relationLoaded('stopRequestedBy') && $run->stopRequestedBy ? [
                'id' => $run->stopRequestedBy->id,
                'name' => $run->stopRequestedBy->name,
            ] : null,
            'workflow' => [
                'id' => $run->relationLoaded('workflowInstance') ? $run->workflowInstance?->id : $run->workflow_instance_id,
                'status' => $run->relationLoaded('workflowInstance') ? $run->workflowInstance?->status : null,
                'resume_step_id' => $run->relationLoaded('workflowInstance') ? $run->workflowInstance?->resume_step_id : null,
                'parent_step_status' => $run->relationLoaded('parentWorkflowStep') ? $run->parentWorkflowStep?->status : null,
            ],
            'latest_step' => $latestStep ? [
                'id' => $latestStep->id,
                'step_index' => $latestStep->step_index,
                'state' => $latestStep->state,
                'action_type' => $latestStep->action_type,
                'action_name' => $latestStep->action_name,
                'status' => $latestStep->status,
                'observation_summary' => $latestStep->observation_summary,
                'terminal_reason' => $latestStep->terminal_reason,
            ] : null,
            'latest_approval' => $latestApproval ? [
                'id' => $latestApproval->id,
                'status' => $latestApproval->status,
                'apply_outcome' => $latestApproval->apply_outcome,
            ] : null,
            'latest_artifact' => $latestArtifact ? [
                'id' => $latestArtifact->id,
                'artifact_type' => $latestArtifact->artifact_type,
                'render_hint' => $latestArtifact->render_hint,
            ] : null,
            'actions' => [
                'can_stop' => in_array($run->status, ['init', 'running'], true) && $run->operator_request_state !== 'stop_requested',
                'can_cancel' => in_array($run->status, ['awaiting_approval', 'awaiting_apply'], true),
                'can_rerun' => ! in_array($run->status, ['init', 'running', 'awaiting_approval', 'awaiting_apply', 'applied'], true),
            ],
        ];

        if (!$detailed) {
            return $payload;
        }

        $payload['steps'] = $run->steps
            ->sortBy('step_index')
            ->values()
            ->map(fn ($step) => [
                'id' => $step->id,
                'step_index' => $step->step_index,
                'state' => $step->state,
                'action_type' => $step->action_type,
                'action_name' => $step->action_name,
                'status' => $step->status,
                'reasoning_summary' => $step->reasoning_summary,
                'observation_summary' => $step->observation_summary,
                'terminal_reason' => $step->terminal_reason,
                'created_at' => optional($step->created_at)->toJSON(),
            ])
            ->all();
        $payload['approvals'] = $run->approvals
            ->sortByDesc('id')
            ->values()
            ->map(fn ($approval) => [
                'id' => $approval->id,
                'status' => $approval->status,
                'apply_outcome' => $approval->apply_outcome,
                'artifact_id' => $approval->artifact_id,
                'reviewer_note' => $approval->reviewer_note,
            ])
            ->all();
        $payload['artifacts'] = $run->artifacts
            ->sortByDesc('id')
            ->values()
            ->map(fn ($artifact) => [
                'id' => $artifact->id,
                'artifact_type' => $artifact->artifact_type,
                'render_hint' => $artifact->render_hint,
                'subject_type' => $artifact->subject_type,
                'subject_id' => $artifact->subject_id,
            ])
            ->all();
        $payload['tool_calls'] = $run->toolCalls
            ->sortByDesc('id')
            ->values()
            ->map(fn ($call) => [
                'id' => $call->id,
                'status' => $call->status,
                'retry_scope' => $call->retry_scope,
                'retry_count' => $call->retry_count,
                'error_message' => $call->error_message,
                'started_at' => optional($call->started_at)->toJSON(),
                'finished_at' => optional($call->finished_at)->toJSON(),
            ])
            ->all();

        return $payload;
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
}
