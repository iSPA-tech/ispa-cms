<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\DataScope\ScopeSummary;

/**
 * Manage agentic approval records via the admin API.
 *
 * Provides endpoints to list, view, approve, reject, and apply agent-generated
 * approval requests. Each approval is linked to an artifact and an agent run,
 * enabling human-in-the-loop oversight of agentic operations.
 *
 * Data scope: an approval whose subject record (artifact subject, else run
 * target) is outside the caller's data scope is left out of the list and
 * answers 404 everywhere else, so its existence does not leak. Responses name
 * the user the agent acted for and that user's scope at run start and now.
 *
 * @see AgentApproval
 * @see AgentApprovalService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticApprovalController extends Controller
{
    /**
     * Scope summaries computed in this request, keyed by "<user id>|<permission key>".
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $scopeSummaries = [];

    /**
     * List approval records with filtering and pagination.
     *
     * Approvals outside the viewer's data scope are filtered in the query, so
     * the pagination total counts only visible approvals.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @param  AgentApprovalService   $approvalService  Approval service (data-scope filter)
     * @return AppResponse  Paginated list of approvals
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

        $query = AgentApproval::query()
            ->with(['artifact', 'run.profile', 'run.workflowInstance', 'run.delegatedBy', 'reviewer', 'applier'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('apply_outcome'), fn ($q) => $q->where('apply_outcome', $request->string('apply_outcome')->toString()))
            ->when($request->filled('artifact_type'), function ($q) use ($request) {
                $artifactType = $request->string('artifact_type')->toString();
                $q->whereHas('artifact', fn ($artifactQuery) => $artifactQuery->where('artifact_type', $artifactType));
            })
            ->when($request->filled('profile'), function ($q) use ($request) {
                $profile = $request->string('profile')->toString();
                $q->whereHas('run.profile', function ($profileQuery) use ($profile) {
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
            ->when($request->filled('subject_type'), function ($q) use ($request) {
                $subjectType = $request->string('subject_type')->toString();
                $q->whereHas('artifact', fn ($artifactQuery) => $artifactQuery->where('subject_type', $subjectType));
            })
            ->orderByRaw("case when status = 'pending' then 0 when status = 'approved' then 1 else 2 end")
            ->orderByDesc('updated_at');

        $approvalService->constrainToScope($query, $request->user());

        $includeRecordData = $authorization->canApprove($request->user());
        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (AgentApproval $approval) => $this->serializeApproval($approval, $includeRecordData, $approvalService))
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => $this->meta($page, $perPage, $total),
        ]);
    }

    /**
     * Show a single approval record by ID.
     *
     * @param  Request                $request        The incoming HTTP request
     * @param  string                 $approval         Approval ID
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @param  AgentApprovalService   $approvalService  Approval service (data-scope check)
     * @return AppResponse  Serialized approval data; 404 when outside the viewer's data scope
     */
    public function show(
        Request $request,
        string $approval,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedApproval = $this->resolveApproval($approval, $request->user(), $approvalService);
        if (! $resolvedApproval) {
            return ires()->notFound(__('mgs.agentic.approval-not-found'));
        }

        return ires()->data($this->serializeApproval(
            $resolvedApproval,
            $authorization->canApprove($request->user()),
            $approvalService,
        ));
    }

    /**
     * Approve a pending approval record.
     *
     * Answers 404 when the approval subject is outside the caller's data scope.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $approval         Approval ID
     * @param  AgentApprovalService   $approvalService  Approval processing service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Updated approval data
     * @throws \Throwable  When approval processing fails
     */
    public function approve(
        Request $request,
        string $approval,
        AgentApprovalService $approvalService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canApprove($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedApproval = $this->resolveApproval($approval, $request->user(), $approvalService);
        if (! $resolvedApproval) {
            return ires()->notFound(__('mgs.agentic.approval-not-found'));
        }

        $validator = Validator::make($request->all(), [
            'reviewer_note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $result = $approvalService->approve(
                $resolvedApproval,
                $request->user(),
                $request->string('reviewer_note')->toString() ?: null,
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.approval-approved'))
            ->data($this->serializeApproval($result['approval'], true, $approvalService));
    }

    /**
     * Reject a pending approval record.
     *
     * Answers 404 when the approval subject is outside the caller's data scope.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $approval         Approval ID
     * @param  AgentApprovalService   $approvalService  Approval processing service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Updated approval data
     * @throws \Throwable  When rejection processing fails
     */
    public function reject(
        Request $request,
        string $approval,
        AgentApprovalService $approvalService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canApprove($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedApproval = $this->resolveApproval($approval, $request->user(), $approvalService);
        if (! $resolvedApproval) {
            return ires()->notFound(__('mgs.agentic.approval-not-found'));
        }

        $validator = Validator::make($request->all(), [
            'reviewer_note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $result = $approvalService->reject(
                $resolvedApproval,
                $request->user(),
                $request->string('reviewer_note')->toString() ?: null,
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.approval-rejected'))
            ->data($this->serializeApproval($result['approval'], true, $approvalService));
    }

    /**
     * Apply an approved approval record to execute the underlying changes.
     *
     * Answers 404 when the approval subject is outside the caller's data scope.
     * The service re-checks the delegating user's and the caller's scope.
     *
     * @param  Request                $request          The incoming HTTP request
     * @param  string                 $approval         Approval ID
     * @param  AgentApprovalService   $approvalService  Approval processing service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Updated approval data with apply outcome
     * @throws \Throwable  When apply operation fails
     */
    public function apply(
        Request $request,
        string $approval,
        AgentApprovalService $approvalService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canApprove($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $resolvedApproval = $this->resolveApproval($approval, $request->user(), $approvalService);
        if (! $resolvedApproval) {
            return ires()->notFound(__('mgs.agentic.approval-not-found'));
        }

        try {
            $result = $approvalService->apply($resolvedApproval, $request->user());
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        return ires()
            ->message(__('mgs.agentic.approval-applied'))
            ->data($this->serializeApproval($result['approval'], true, $approvalService));
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
     * Serialize an approval for the admin API.
     *
     * Record data (artifact `payload`, `original_data_snapshot`, `diff_data`)
     * and free text that can quote it (`run.input_goal`, `reviewer_note`,
     * `apply_error`) are returned only when $includeRecordData is true, i.e. the
     * caller passes AgenticAuthorization::canApprove. For view-only callers the
     * keys stay in the response with a null value, so the shape is stable and
     * only metadata (ids, status, types, timestamps, reviewer, applier) is sent.
     *
     * `run.delegated_by` ({uuid, name}, null when none) names the user the agent
     * acted for; `scope.at_start` is the run's scope snapshot and `scope.now`
     * that user's current scope summary for the subject's scoped resource (null
     * when there is no delegating user or the subject is unscoped). Neither
     * carries node ids or user ids.
     *
     * @param  AgentApproval         $approval           The approval with relations loaded
     * @param  bool                  $includeRecordData  Whether to include record data
     * @param  AgentApprovalService  $approvalService    Approval service (subject resource lookup)
     * @return array<string, mixed>
     */
    protected function serializeApproval(
        AgentApproval $approval,
        bool $includeRecordData,
        AgentApprovalService $approvalService,
    ): array {
        $delegator = $approval->run?->delegatedBy;

        return [
            'id' => $approval->id,
            'status' => $approval->status,
            'apply_outcome' => $approval->apply_outcome,
            'apply_error' => $includeRecordData ? $approval->apply_error : null,
            'reviewer_note' => $includeRecordData ? $approval->reviewer_note : null,
            'apply_started_at' => optional($approval->apply_started_at)->toJSON(),
            'apply_finished_at' => optional($approval->apply_finished_at)->toJSON(),
            'reviewed_at' => optional($approval->reviewed_at)->toJSON(),
            'created_at' => optional($approval->created_at)->toJSON(),
            'updated_at' => optional($approval->updated_at)->toJSON(),
            'run' => [
                'id' => $approval->run?->id,
                'status' => $approval->run?->status,
                'input_goal' => $includeRecordData ? $approval->run?->input_goal : null,
                'post_approval_outcome' => $approval->run?->post_approval_outcome,
                'delegated_by' => $delegator ? [
                    'uuid' => $delegator->uuid,
                    'name' => $delegator->name,
                ] : null,
            ],
            'scope' => [
                'at_start' => $approval->run?->scope_snapshot,
                'now' => $this->currentScopeSummary($approval, $delegator, $approvalService),
            ],
            'workflow' => [
                'id' => $approval->run?->workflowInstance?->id,
                'status' => $approval->run?->workflowInstance?->status,
                'resume_step_id' => $approval->run?->workflowInstance?->resume_step_id,
            ],
            'profile' => [
                'id' => $approval->run?->profile?->id,
                'slug' => $approval->run?->profile?->slug,
                'name' => $approval->run?->profile?->name,
            ],
            'artifact' => [
                'id' => $approval->artifact?->id,
                'artifact_type' => $approval->artifact?->artifact_type,
                'render_hint' => $approval->artifact?->render_hint,
                'subject_type' => $approval->artifact?->subject_type,
                'subject_id' => $approval->artifact?->subject_id,
                'payload' => $includeRecordData ? $approval->artifact?->payload : null,
                'original_data_snapshot' => $includeRecordData ? $approval->artifact?->original_data_snapshot : null,
            ],
            'diff_data' => $includeRecordData ? $approval->diff_data : null,
            'reviewer' => [
                'id' => $approval->reviewer?->id,
                'name' => $approval->reviewer?->name,
            ],
            'applied_by' => [
                'id' => $approval->applier?->id,
                'name' => $approval->applier?->name,
            ],
        ];
    }

    /**
     * The delegating user's current scope summary for the approval subject's scoped resource (memoised per request).
     *
     * @param  AgentApproval         $approval         The approval.
     * @param  User|null             $delegator        The user the run acted for.
     * @param  AgentApprovalService  $approvalService  Approval service (subject resource lookup).
     * @return array<string, mixed>|null  Null without a delegating user or for an unscoped subject.
     */
    protected function currentScopeSummary(
        AgentApproval $approval,
        ?User $delegator,
        AgentApprovalService $approvalService,
    ): ?array {
        $resource = $delegator ? $approvalService->subjectResource($approval) : null;

        if (! $delegator || ! $resource) {
            return null;
        }

        $key = $delegator->getKey() . '|' . $resource->permission();

        return $this->scopeSummaries[$key] ??= app(ScopeSummary::class)->for($delegator, $resource->permission());
    }

    /**
     * Resolve an approval record by ID with eager-loaded relationships, within the viewer's data scope.
     *
     * @param  string                $approval         Approval ID to resolve
     * @param  User|null             $viewer           The caller
     * @param  AgentApprovalService  $approvalService  Approval service (data-scope check)
     * @return AgentApproval|null  The resolved approval, or null when unknown or outside the viewer's scope
     */
    protected function resolveApproval(string $approval, ?User $viewer, AgentApprovalService $approvalService): ?AgentApproval
    {
        $resolved = AgentApproval::query()
            ->with(['artifact', 'run.profile', 'run.workflowInstance', 'run.delegatedBy', 'reviewer', 'applier'])
            ->find($approval);

        if (! $resolved || ! $approvalService->allowsSubject($resolved, $viewer)) {
            return null;
        }

        return $resolved;
    }
}
