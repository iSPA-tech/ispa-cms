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
use Cms\Core\Workflows\Runtime\Runners\Human\ReviewMeta;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\DataScope\ScopeSummary;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /** Whether the assignee/SLA columns exist (memoised per request; see AgentApproval::hasAssignmentColumns()). */
    protected ?bool $assignmentReady = null;

    protected function assignmentReady(): bool
    {
        return $this->assignmentReady ??= AgentApproval::hasAssignmentColumns();
    }

    /**
     * Relations every serialized approval needs.
     *
     * @return list<string>
     */
    protected function relations(): array
    {
        $relations = ['artifact', 'run.profile', 'run.workflowInstance', 'run.delegatedBy', 'reviewer', 'applier'];

        return $this->assignmentReady() ? [...$relations, 'assignees', 'decider'] : $relations;
    }

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
            ->with($this->relations())
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
            // `assigned=me`: approvals a workflow step assigned to the caller.
            ->when($request->input('assigned') === 'me' && $this->assignmentReady(), function ($q) use ($request) {
                $q->whereHas('assignees', fn ($users) => $users->whereKey((int) $request->user()?->getKey()));
            })
            // `overdue=1`: pending approvals past their SLA due time.
            ->when($request->boolean('overdue') && $this->assignmentReady(), function ($q) {
                $q->where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<', now());
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

        return ires()->data($this->serializeWithHistory(
            $resolvedApproval,
            $authorization->canApprove($request->user()),
            $approvalService,
        ));
    }

    /**
     * Send a pending approval back for a rewrite: the producing step runs again with the reviewer's feedback, added
     * information and chosen options (declared in `diff_data.workflow_node.revision_options`).
     *
     * Same permission and data scope as approve. 422 when the approval is not pending or not revisable, the round cap is
     * reached, an option is not declared, or the feedback is missing.
     *
     * @param  Request                $request          `feedback` (3..5000), `added_info` (..10000), `options` (key => value)
     * @param  string                 $approval         Approval ID
     * @param  AgentApprovalService   $approvalService  Approval processing service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  The approval (same shape as show)
     */
    public function requestChanges(
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
        if ($refused = $this->decisionRefused($resolvedApproval, $request->user(), $approvalService)) {
            return $refused;
        }

        $validator = Validator::make($request->all(), [
            'feedback' => ['required', 'string', 'min:3', 'max:5000'],
            'added_info' => ['nullable', 'string', 'max:10000'],
            'options' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return ires()->error()->code(422)->errorCode('invalid_request')->message($validator->errors()->first());
        }

        $addedInfo = trim($request->string('added_info')->toString());
        try {
            $result = $approvalService->requestChanges(
                $resolvedApproval,
                $request->user(),
                trim($request->string('feedback')->toString()),
                $addedInfo !== '' ? $addedInfo : null,
                (array) $request->input('options', []),
            );
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === AgentApprovalService::SCOPE_DENIED) {
                return ires()->forbidden(__('mgs.no.permission'));
            }

            return ires()->error()->code(422)->errorCode('changes_not_allowed')->message($e->getMessage());
        }

        $fresh = $this->resolveApproval((string) $result['approval']->id, $request->user(), $approvalService) ?? $result['approval'];

        return ires()->data($this->serializeWithHistory($fresh, true, $approvalService));
    }

    /**
     * The show payload: the serialized approval plus `revision_history`, the rounds of its rewrite chain oldest first.
     * Reviewer text (feedback, added information) is sent only to callers who may approve.
     *
     * @return array<string, mixed>
     */
    protected function serializeWithHistory(AgentApproval $approval, bool $includeRecordData, AgentApprovalService $approvalService): array
    {
        $history = array_map(static fn (array $round): array => $includeRecordData
            ? $round
            : array_merge($round, ['feedback' => null, 'added_info' => null]), $approvalService->revisionHistory($approval));

        return $this->serializeApproval($approval, $includeRecordData, $approvalService) + ['revision_history' => $history];
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
        if ($refused = $this->decisionRefused($resolvedApproval, $request->user(), $approvalService)) {
            return $refused;
        }

        $validator = Validator::make($request->all(), [
            'reviewer_note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $result = $approvalService->approveWithEdits(
                $resolvedApproval,
                $request->user(),
                $request->string('reviewer_note')->toString() ?: null,
                $this->editedFields($request),
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
        if ($refused = $this->decisionRefused($resolvedApproval, $request->user(), $approvalService)) {
            return $refused;
        }

        $validator = Validator::make($request->all(), [
            'reviewer_note' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        // Declared reject reasons make one required; an approval without reasons refuses any (the service re-checks).
        $reason = trim($request->string('reason')->toString());
        $declared = ReviewMeta::rejectReasons(data_get($resolvedApproval->diff_data, 'workflow_node.reject_reasons'));
        if ($declared !== [] && $reason === '') {
            return ires()->error()->code(422)->errorCode('reason_required')->message('Pick a reason for the rejection.');
        }

        try {
            $result = $approvalService->reject(
                $resolvedApproval,
                $request->user(),
                $request->string('reviewer_note')->toString() ?: null,
                $reason !== '' ? $reason : null,
            );
        } catch (\InvalidArgumentException $e) {
            if (str_starts_with($e->getMessage(), 'Reason [')) {
                return ires()->error()->code(422)->errorCode('invalid_reason')->message($e->getMessage());
            }

            return ires()->badRequest($e->getMessage());
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
     * Approve a pending approval and apply it in one call.
     *
     * Same permission (canApprove) and data-scope lookup as approve and apply.
     * Answers 200 with the approval plus `outcome` (applied, approval_stale or
     * apply_failed); a stale or failed apply leaves the approval approved and
     * waiting, with `apply_error` saying why.
     *
     * @param  Request                $request          The incoming HTTP request (reviewer_note)
     * @param  string                 $approval         Approval ID
     * @param  AgentApprovalService   $approvalService  Approval processing service
     * @param  AgenticAuthorization   $authorization    Authorization service
     * @return AppResponse  Updated approval data with `outcome`
     */
    public function approveAndApply(
        Request $request,
        string $approval,
        AgentApprovalService $approvalService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canApprove($request->user())) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $resolvedApproval = $this->resolveApproval($approval, $request->user(), $approvalService);
        if (! $resolvedApproval) {
            return ires()->notFound(__('mgs.agentic.approval-not-found'));
        }
        if ($refused = $this->decisionRefused($resolvedApproval, $request->user(), $approvalService)) {
            return $refused;
        }

        $validator = Validator::make($request->all(), [
            'reviewer_note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return ires()->badRequest($validator->errors()->first());
        }

        try {
            $result = $approvalService->approveAndApply(
                $resolvedApproval,
                $request->user(),
                $request->string('reviewer_note')->toString() ?: null,
                $this->editedFields($request),
            );
        } catch (\Throwable $e) {
            return ires()->badRequest($e->getMessage());
        }

        $fresh = $this->resolveApproval((string) $result['approval']->id, $request->user(), $approvalService)
            ?? $result['approval'];

        return ires()
            ->message(__($result['outcome'] === 'applied' ? 'mgs.agentic.approval-applied' : 'mgs.agentic.approval-approved'))
            ->data(array_merge(
                $this->serializeApproval($fresh, true, $approvalService),
                ['outcome' => $result['outcome']],
            ));
    }

    /**
     * Reviewer edits of the fields a workflow approval declares editable (`edited_fields`: payload path => value).
     *
     * @param  Request $request The incoming HTTP request
     * @return array<string, mixed>|null
     */
    protected function editedFields(Request $request): ?array
    {
        $edits = $request->input('edited_fields');

        return is_array($edits) && $edits !== [] ? $edits : null;
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
            // Auto-publish after calibration: why a person decides, JEV's verdict {verdict, probability,
            // min_confidence} and who decided (`jev` marks an automatic approval).
            'held_reason' => is_string($held = data_get($approval->diff_data, 'workflow_node.held_reason')) ? $held : null,
            'jev' => \Cms\Core\Services\Workflow\AutoApproval\WorkflowAutoApproval::jevOf($approval),
            'decided_by' => is_string($by = data_get($approval->diff_data, 'workflow_node.decided_by')) ? $by : null,
            'reviewer' => [
                'id' => $approval->reviewer?->id,
                'name' => $approval->reviewer?->name,
            ],
            'applied_by' => [
                'id' => $approval->applier?->id,
                'name' => $approval->applier?->name,
            ],
            'assignment' => $this->serializeAssignment($approval, request()->user()),
        ];
    }

    /**
     * Assignee and SLA facts of an approval (null when it has no assignees, no SLA, no fallback and no decision
     * timing, e.g. approvals created before the feature). Users are named by uuid and name only.
     *
     * @return array<string, mixed>|null
     */
    protected function serializeAssignment(AgentApproval $approval, ?User $viewer): ?array
    {
        if (!$this->assignmentReady()) {
            return null;
        }
        $assignees = $approval->assignees;
        $fallback = data_get($approval->diff_data, 'workflow_node.assignment_fallback') === true;
        if ($assignees->isEmpty() && $approval->sla_seconds === null && $approval->decided_at === null && !$fallback) {
            return null;
        }

        $decider = $approval->decider;

        return [
            'assignees' => $assignees->map(static fn (User $user): array => ['uuid' => $user->uuid, 'name' => $user->name])->values()->all(),
            'assigned_to_me' => $viewer !== null && $assignees->contains(static fn (User $user): bool => (int) $user->getKey() === (int) $viewer->getKey()),
            'due_at' => optional($approval->due_at)->toJSON(),
            'sla_seconds' => $approval->sla_seconds,
            'decided_at' => optional($approval->decided_at)->toJSON(),
            'response_seconds' => $approval->response_seconds,
            'sla_met' => $approval->sla_met,
            'overdue' => $approval->status === 'pending' && $approval->due_at !== null && $approval->due_at->lt(now()),
            'breached_at' => optional($approval->sla_breached_at)->toJSON(),
            'decided_by' => $decider ? ['uuid' => $decider->uuid, 'name' => $decider->name] : null,
            'fallback' => $fallback,
        ];
    }

    /**
     * SLA summary of assigned approvals created in a date range (`from`, `to` as YYYY-MM-DD; default the last 30 days,
     * at most 366 days), inside the caller's data scope like the list. Approvals count when they have assignees, an SLA
     * or fell back to the permission holders; at most 5 000 (newest first, `truncated: true` above). Per reviewer: one
     * row per assignee with how many they got, decided themselves or someone else decided, within / late (expired
     * counts late) / overdue now, and the median and average response time of the ones they decided.
     *
     * @param  Request                $request          `from`, `to`
     * @param  AgenticAuthorization   $authorization    Same permission as the list
     * @param  AgentApprovalService   $approvalService  Data-scope filter
     * @return AppResponse  `{totals: {...}, reviewers: [...], from, to, truncated}`
     */
    public function slaSummary(
        Request $request,
        AgenticAuthorization $authorization,
        AgentApprovalService $approvalService,
    ): AppResponse {
        if (!$authorization->canView($request->user())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $validator = Validator::make($request->all(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            return ires()->error()->code(422)->errorCode('invalid_request')->message($validator->errors()->first());
        }

        $to = $request->filled('to') ? Carbon::createFromFormat('Y-m-d', $request->string('to')->toString())->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::createFromFormat('Y-m-d', $request->string('from')->toString())->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to) || $from->copy()->addDays(366)->lte($to->copy()->startOfDay())) {
            return ires()->error()->code(422)->errorCode('invalid_range')->message('The range must run forward and cover at most 366 days.');
        }

        if (!$this->assignmentReady()) {
            return ires()->error()->code(409)->errorCode('not_migrated')->message('Run the database migrations first.');
        }

        $cap = 5000;
        $query = AgentApproval::query()
            ->whereBetween('created_at', [$from, $to])
            ->where(static function ($measured): void {
                $measured->whereHas('assignees')
                    ->orWhereNotNull('sla_seconds')
                    ->orWhere('diff_data->workflow_node->assignment_fallback', true);
            });
        $approvalService->constrainToScope($query, $request->user());
        $rows = $query->orderByDesc('id')->limit($cap + 1)
            ->get(['id', 'status', 'due_at', 'sla_seconds', 'decided_at', 'response_seconds', 'sla_met', 'decided_by_user_id']);
        $truncated = $rows->count() > $cap;
        $rows = $rows->take($cap)->keyBy('id');

        $assigned = [];
        foreach ($rows->keys()->chunk(1000) as $ids) {
            DB::table(CoreTableConstant::AGENT_APPROVAL_ASSIGNEE . ' as a')
                ->join(CoreTableConstant::USERS . ' as u', 'u.id', '=', 'a.user_id')
                ->whereIn('a.approval_id', $ids->all())
                ->get(['a.approval_id', 'a.user_id', 'u.uuid', 'u.name'])
                ->each(static function ($row) use (&$assigned): void {
                    $assigned[(int) $row->user_id]['user'] ??= ['uuid' => $row->uuid, 'name' => $row->name];
                    $assigned[(int) $row->user_id]['approvals'][] = (int) $row->approval_id;
                });
        }

        $reviewers = [];
        foreach ($assigned as $userId => $entry) {
            $mine = $rows->only($entry['approvals']);
            $byThem = $mine->filter(static fn (AgentApproval $a): bool => $a->decided_at !== null && (int) $a->decided_by_user_id === $userId);
            $times = $byThem->pluck('response_seconds')->filter(static fn ($v): bool => $v !== null)->map(static fn ($v): int => (int) $v)->all();
            $reviewers[] = $entry['user'] + [
                'assigned' => $mine->count(),
                'decided_by_them' => $byThem->count(),
                'decided_by_others' => $mine->filter(static fn (AgentApproval $a): bool => $a->decided_at !== null && (int) $a->decided_by_user_id !== $userId)->count(),
            ] + $this->slaCounts($mine) + [
                'median_response_seconds' => self::median($times),
                'avg_response_seconds' => $times === [] ? null : (int) round(array_sum($times) / count($times)),
            ];
        }
        usort($reviewers, static fn (array $a, array $b): int => [$b['assigned'], $a['name']] <=> [$a['assigned'], $b['name']]);

        $times = $rows->filter(static fn (AgentApproval $a): bool => $a->decided_at !== null && $a->response_seconds !== null)
            ->map(static fn (AgentApproval $a): int => (int) $a->response_seconds)->values()->all();

        return ires()->data([
            'totals' => [
                'assigned' => $rows->count(),
                'decided' => $rows->filter(static fn (AgentApproval $a): bool => $a->decided_at !== null)->count(),
            ] + $this->slaCounts($rows) + ['median_response_seconds' => self::median($times)],
            'reviewers' => $reviewers,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'truncated' => $truncated,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AgentApproval> $approvals
     * @return array{within_sla: int, late: int, overdue_now: int}
     */
    protected function slaCounts($approvals): array
    {
        $now = now();

        return [
            'within_sla' => $approvals->filter(static fn (AgentApproval $a): bool => $a->sla_met === true)->count(),
            'late' => $approvals->filter(static fn (AgentApproval $a): bool => $a->sla_met === false)->count(),
            'overdue_now' => $approvals->filter(static fn (AgentApproval $a): bool => $a->status === 'pending' && $a->due_at !== null && $a->due_at->lt($now))->count(),
        ];
    }

    /**
     * @param list<int> $values
     */
    protected static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
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
     * Undo a reversible-field change a workflow applied without a person (`decided_by: reversible_fields`).
     * Needs the approve permission; 422 when there is nothing to undo, 409 when a field changed since.
     */
    public function undo(
        Request $request,
        string $approval,
        AgentApprovalService $approvalService,
        AgenticAuthorization $authorization,
    ): AppResponse {
        if (!$authorization->canApprove($request->user())) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $resolvedApproval = $this->resolveApproval($approval, $request->user(), $approvalService);
        if (! $resolvedApproval) {
            return ires()->notFound(__('mgs.agentic.approval-not-found'));
        }

        try {
            $undone = $approvalService->undoReversible($resolvedApproval, $request->user());
        } catch (\Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException $e) {
            return ires()->error()->code(409)->errorCode('approval_stale')->message($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return ires()->error()->code(422)->errorCode('nothing_to_undo')->message($e->getMessage());
        }

        return ires()->data($this->serializeApproval($undone, true, $approvalService));
    }

    /**
     * 403 with the refusal code when the caller may not decide this item (AgentApprovalService::decisionRefusal():
     * reviewer permission of the step, assignees, the run's own user, the workflow's authors), else null.
     */
    protected function decisionRefused(AgentApproval $approval, ?User $user, AgentApprovalService $approvalService): ?AppResponse
    {
        $refusal = $approvalService->decisionRefusal($approval, $user);

        return $refusal === null
            ? null
            : ires()->error()->code(403)->errorCode($refusal['code'])->message($refusal['message']);
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
            ->with($this->relations())
            ->find($approval);

        if (! $resolved || ! $approvalService->allowsSubject($resolved, $viewer)) {
            return null;
        }

        return $resolved;
    }
}
