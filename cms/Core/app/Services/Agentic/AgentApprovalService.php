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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Services\DataScope\DataScope;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;

/**
 * Manages the approval lifecycle for agent-generated artifacts.
 *
 * Handles approving, rejecting, and applying agent approvals within
 * database transactions. Updates associated agent runs, workflow instances,
 * and workflow steps accordingly. Dispatches workflow resume jobs on
 * successful apply and synchronizes schedule runtime state.
 *
 * @see AgentArtifactApplyService
 * @see AgentFeedbackNormalizer
 * @see AgentScheduleRuntimeSyncService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentApprovalService
{
    /**
     * `apply_error` of an apply refused because the subject is outside a data scope.
     */
    public const SCOPE_DENIED = 'scope_denied';

    /**
     * Initialize the approval service with artifact apply, feedback, and sync dependencies.
     *
     * @param AgentArtifactApplyService        $artifactApplyService Dispatches artifact apply operations.
     * @param AgentFeedbackNormalizer          $feedbackNormalizer   Normalizes reviewer feedback for storage.
     * @param AgentScheduleRuntimeSyncService  $runtimeSyncService   Synchronizes schedule state after approval actions.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected AgentArtifactApplyService $artifactApplyService,
        protected AgentFeedbackNormalizer $feedbackNormalizer,
        protected AgentScheduleRuntimeSyncService $runtimeSyncService,
    ) {
    }

    /**
     * Approve a pending approval, marking it ready for apply.
     *
     * @param AgentApproval $approval    The approval to approve.
     * @param User|null     $reviewer    The user performing the review.
     * @param string|null   $reviewerNote Optional reviewer note.
     * @return array<string, mixed> Contains the refreshed approval, run, and artifact.
     *
     * @throws \InvalidArgumentException When the approval is not in pending status.
     */
    public function approve(AgentApproval $approval, ?User $reviewer = null, ?string $reviewerNote = null): array
    {
        return DB::transaction(function () use ($approval, $reviewer, $reviewerNote) {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->ensurePending($approval);
            $approval->loadMissing(['artifact', 'run.steps']);

            $approval->fill([
                'status' => 'approved',
                'reviewer_note' => $reviewerNote,
                'reviewed_by_user_id' => $reviewer?->getKey(),
                'reviewed_at' => now(),
                'apply_outcome' => 'awaiting_apply',
                'apply_error' => null,
                'apply_started_at' => null,
                'apply_finished_at' => null,
            ])->save();

            $this->recordRunProgress(
                $approval->run,
                'awaiting_apply',
                'awaiting_apply',
                'Approval granted. Awaiting explicit system apply.',
            );

            return [
                'approval' => $approval->fresh(['artifact', 'run.profile', 'reviewer']),
                'run' => $approval->run->fresh(['profile']),
                'artifact' => $approval->artifact->fresh(),
            ];
        });
    }

    /**
     * Reject a pending approval and capture rejection feedback as agent memory.
     *
     * @param AgentApproval $approval    The approval to reject.
     * @param User|null     $reviewer    The user performing the review.
     * @param string|null   $reviewerNote Optional rejection reason.
     * @return array<string, mixed> Contains the refreshed approval, run, and artifact.
     *
     * @throws \InvalidArgumentException When the approval is not in pending status.
     */
    public function reject(AgentApproval $approval, ?User $reviewer = null, ?string $reviewerNote = null): array
    {
        $result = DB::transaction(function () use ($approval, $reviewer, $reviewerNote) {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->ensurePending($approval);
            $approval->loadMissing(['artifact', 'run.steps']);

            $approval->fill([
                'status' => 'rejected',
                'reviewer_note' => $reviewerNote,
                'reviewed_by_user_id' => $reviewer?->getKey(),
                'reviewed_at' => now(),
                'apply_outcome' => 'approval_rejected',
                'apply_finished_at' => now(),
            ])->save();

            $this->feedbackNormalizer->captureRejectionFeedback($approval, $reviewerNote);

            $this->recordRunOutcome(
                $approval->run,
                'rejected',
                'approval_rejected',
                'approval_rejected',
                'rejected',
                $reviewerNote ?? 'Approval rejected by reviewer.'
            );

            return [
                'approval' => $approval->fresh(['artifact', 'run.profile', 'reviewer']),
                'run' => $approval->run->fresh(['profile']),
                'artifact' => $approval->artifact->fresh(),
            ];
        });

        $this->runtimeSyncService->syncFromRun($approval->run->fresh(['workflowInstance']));

        return $result;
    }

    /**
     * Apply an approved artifact to the target entity.
     *
     * Delegates to AgentArtifactApplyService, handles stale and failed
     * apply outcomes, and resumes workflow instances when applicable.
     * Stores the applying user on the approval (`applied_by_user_id`, left
     * untouched for system applies without a user) and writes exactly one
     * audit log entry per apply attempt with the approval and its outcome.
     *
     * Before the applier runs, the approval subject must be inside the data
     * scope of both the run's delegating user (re-read from the database) and
     * the applying user, for a write (DataScope::allowsTarget with every scoped
     * resource of the model). Otherwise the approval is
     * marked `apply_failed` with `apply_error = 'scope_denied'` and the record
     * is not touched. Fail closed: a scoped subject with no delegating user, or
     * a null applier, is denied; an unscoped subject is never denied.
     *
     * @param AgentApproval $approval The approved approval to apply.
     * @param User|null     $applier  The user running the apply; null for system applies.
     * @return array<string, mixed> Contains the refreshed approval, run, artifact, and apply result.
     *
     * @throws \InvalidArgumentException When the approval is not approved or not ready for apply.
     */
    public function apply(AgentApproval $approval, ?User $applier = null): array
    {
        $result = DB::transaction(function () use ($approval, $applier) {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $approval->loadMissing(['artifact', 'run.steps']);

            if ($approval->status !== 'approved') {
                throw new \InvalidArgumentException("Approval [{$approval->id}] is not approved.");
            }

            if (!in_array($approval->apply_outcome, [null, 'awaiting_apply', 'apply_failed', 'approval_stale'], true)) {
                throw new \InvalidArgumentException("Approval [{$approval->id}] is not ready for apply.");
            }

            if (!$this->allowsSubject($approval, $approval->run?->currentDelegate(), true)
                || !$this->allowsSubject($approval, $applier, true)) {
                $approval->fill([
                    'apply_started_at' => now(),
                    'apply_outcome' => 'apply_failed',
                    'apply_error' => self::SCOPE_DENIED,
                    'apply_finished_at' => now(),
                ]);

                if ($applier) {
                    $approval->applied_by_user_id = $applier->id;
                }

                $approval->save();
                $this->recordRunOutcome($approval->run, 'failed', 'apply_failed', 'apply_failed', 'failed', self::SCOPE_DENIED);

                return [
                    'approval' => $approval->fresh(['artifact', 'run.profile', 'reviewer']),
                    'run' => $approval->run->fresh(['profile']),
                    'artifact' => $approval->artifact->fresh(),
                    'apply_result' => ['message' => self::SCOPE_DENIED],
                    'resume_workflow_instance_id' => null,
                ];
            }

            $approval->fill([
                'apply_started_at' => now(),
                'apply_outcome' => 'applying',
                'apply_error' => null,
            ]);

            if ($applier) {
                $approval->applied_by_user_id = $applier->id;
            }

            $approval->save();

            try {
                $applyResult = $this->artifactApplyService->apply($approval->artifact, $approval);
                $resumeWorkflowInstanceId = $this->resumeWorkflowInstanceId($approval->run);
                $workflowStatus = $resumeWorkflowInstanceId ? 'applied' : 'completed';

                $approval->fill([
                    'apply_outcome' => 'applied',
                    'apply_finished_at' => now(),
                ])->save();

                $this->recordRunOutcome(
                    $approval->run,
                    'completed',
                    'applied',
                    null,
                    'completed',
                    null,
                    $workflowStatus,
                );
            } catch (ApprovalStaleException $e) {
                $resumeWorkflowInstanceId = null;
                $approval->fill([
                    'status' => 'approved',
                    'apply_outcome' => 'approval_stale',
                    'apply_error' => $e->getMessage(),
                    'apply_finished_at' => now(),
                ])->save();

                $this->recordRunOutcome($approval->run, 'failed', 'approval_stale', 'approval_stale', 'failed', $e->getMessage());
                $applyResult = ['message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $resumeWorkflowInstanceId = null;
                $approval->fill([
                    'status' => 'approved',
                    'apply_outcome' => 'apply_failed',
                    'apply_error' => $e->getMessage(),
                    'apply_finished_at' => now(),
                ])->save();

                $this->recordRunOutcome($approval->run, 'failed', 'apply_failed', 'apply_failed', 'failed', $e->getMessage());
                $applyResult = ['message' => $e->getMessage()];
            }

            return [
                'approval' => $approval->fresh(['artifact', 'run.profile', 'reviewer']),
                'run' => $approval->run->fresh(['profile']),
                'artifact' => $approval->artifact->fresh(),
                'apply_result' => $applyResult,
                'resume_workflow_instance_id' => $resumeWorkflowInstanceId,
            ];
        });

        $resumeWorkflowInstanceId = $result['resume_workflow_instance_id'] ?? null;
        unset($result['resume_workflow_instance_id']);

        if (is_numeric($resumeWorkflowInstanceId)) {
            RunWorkflowInstanceJob::dispatch((int) $resumeWorkflowInstanceId);
            $approval->refresh();
            $result['approval'] = $approval->fresh(['artifact', 'run.profile', 'reviewer']);
            $result['run'] = $approval->run->fresh(['profile']);
            $result['artifact'] = $approval->artifact->fresh();
        }

        if (($result['run'] ?? null) instanceof AgentRun) {
            $this->runtimeSyncService->syncFromRun($result['run']->fresh(['workflowInstance']));
        }

        $this->auditApply($result['approval'] ?? $approval, $applier);

        return $result;
    }

    /**
     * The record an approval acts on: the artifact subject, or the run target when the artifact names none.
     *
     * @param AgentApproval $approval The approval (artifact and run are loaded when missing).
     * @return array{0: string|null, 1: string|int|null} Stored type and id.
     */
    public function subjectOf(AgentApproval $approval): array
    {
        $approval->loadMissing(['artifact', 'run']);
        $artifact = $approval->artifact;

        if ($artifact && is_string($artifact->subject_type) && $artifact->subject_type !== '') {
            return [$artifact->subject_type, $artifact->subject_id];
        }

        return [$approval->run?->target_type, $approval->run?->target_id];
    }

    /**
     * The scoped resource registered for the approval subject's model, or null when the subject is unscoped.
     *
     * @param AgentApproval $approval The approval.
     * @return ScopedResource|null
     */
    public function subjectResource(AgentApproval $approval): ?ScopedResource
    {
        [$type] = $this->subjectOf($approval);

        if ($type === null || $type === '') {
            return null;
        }

        return app(ScopedResourceRegistry::class)->forModel($this->modelClassFor($type));
    }

    /**
     * Whether the user may reach the record the approval acts on (see subjectOf()).
     *
     * @param AgentApproval $approval The approval.
     * @param User|null     $user     Viewer, applier or delegating user; null reaches only unscoped subjects.
     * @param bool          $write    True for a change: every scoped resource of the model must reach the subject.
     * @return bool
     */
    public function allowsSubject(AgentApproval $approval, ?User $user, bool $write = false): bool
    {
        [$type, $id] = $this->subjectOf($approval);

        return $this->allowsTarget($type, $id, $user, $write);
    }

    /**
     * Whether the user may reach a stored polymorphic target, through DataScope::allowsTarget.
     *
     * An empty type is unscoped; a morph alias is resolved to its model class first.
     *
     * @param string|null     $type Stored target type (model class or morph alias).
     * @param string|int|null $id   Stored target id.
     * @param User|null       $user The user.
     * @param bool            $write True for a change: every scoped resource of the model must reach the target.
     * @return bool
     */
    public function allowsTarget(?string $type, string|int|null $id, ?User $user, bool $write = false): bool
    {
        if ($type === null || $type === '') {
            return true;
        }

        return app(DataScope::class)->allowsTarget($this->modelClassFor($type), $id, $user, $write);
    }

    /**
     * Narrow an approval query to approvals whose subject (see subjectOf()) the viewer may reach.
     *
     * Done in the query, so pagination totals stay right: for every stored subject or run target type that maps
     * to a scoped resource, the subject id must be in the id set DataScope::apply() returns for the viewer.
     * Approvals on unscoped types are untouched.
     *
     * @param Builder<AgentApproval> $query  Approval query.
     * @param User|null              $viewer The viewer.
     * @return Builder<AgentApproval>
     */
    public function constrainToScope(Builder $query, ?User $viewer): Builder
    {
        $subjectTypes = AgentArtifact::query()->distinct()->pluck('subject_type')->all();
        $targetTypes = AgentRun::query()->distinct()->pluck('target_type')->all();

        foreach ($this->visibleIdQueries($subjectTypes, $viewer) as $type => $visibleIds) {
            $query->whereDoesntHave('artifact', static function (Builder $artifact) use ($type, $visibleIds): void {
                $artifact->where('subject_type', $type)->where(static function (Builder $outside) use ($visibleIds): void {
                    $outside->whereNull('subject_id')->orWhereNotIn('subject_id', $visibleIds);
                });
            });
        }

        foreach ($this->visibleIdQueries($targetTypes, $viewer) as $type => $visibleIds) {
            // The run target counts only when the artifact names no subject.
            $query->where(static function (Builder $approval) use ($type, $visibleIds): void {
                $approval
                    ->whereHas('artifact', static fn (Builder $artifact) => $artifact->whereNotNull('subject_type')->where('subject_type', '!=', ''))
                    ->orWhereDoesntHave('run', static function (Builder $run) use ($type, $visibleIds): void {
                        $run->where('target_type', $type)->where(static function (Builder $outside) use ($visibleIds): void {
                            $outside->whereNull('target_id')->orWhereNotIn('target_id', $visibleIds);
                        });
                    });
            });
        }

        return $query;
    }

    /**
     * Narrow an agent run query to runs whose target the viewer may reach (unscoped target types are untouched).
     *
     * @param Builder<AgentRun> $query  Run query.
     * @param User|null         $viewer The viewer.
     * @return Builder<AgentRun>
     */
    public function constrainRunsToScope(Builder $query, ?User $viewer): Builder
    {
        $targetTypes = AgentRun::query()->distinct()->pluck('target_type')->all();

        foreach ($this->visibleIdQueries($targetTypes, $viewer) as $type => $visibleIds) {
            $query->where(static function (Builder $run) use ($type, $visibleIds): void {
                $run->whereNull('target_type')
                    ->orWhere('target_type', '!=', $type)
                    ->orWhereIn('target_id', $visibleIds);
            });
        }

        return $query;
    }

    /**
     * For each stored type that maps to a scoped resource, the base query selecting the record keys the viewer
     * may see (DataScope::apply() is the only row filter).
     *
     * @param array<int, mixed> $types  Stored types (model classes or morph aliases; empty values are skipped).
     * @param User|null         $viewer The viewer.
     * @return array<string, QueryBuilder>
     */
    protected function visibleIdQueries(array $types, ?User $viewer): array
    {
        $dataScope = app(DataScope::class);
        $registry = app(ScopedResourceRegistry::class);
        $queries = [];

        foreach (array_unique(array_filter($types, static fn ($type) => is_string($type) && $type !== '')) as $type) {
            $class = $this->modelClassFor($type);
            $resource = $registry->forModel($class);

            if ($resource === null) {
                continue;
            }

            $model = new $class();
            $queries[$type] = $dataScope->apply($class::query(), $resource->key(), $viewer)
                ->select($model->getQualifiedKeyName())
                ->toBase();
        }

        return $queries;
    }

    /**
     * Model class for a stored polymorphic type (a morph alias resolves through the morph map).
     *
     * @param string $type Stored type.
     * @return string
     */
    protected function modelClassFor(string $type): string
    {
        return Relation::getMorphedModel($type) ?? $type;
    }

    /**
     * Write one audit log entry for an apply attempt.
     *
     * The apply is already committed at this point, so an audit write failure is
     * reported instead of turning a finished apply into an error response.
     *
     * @param AgentApproval $approval The approval after the apply attempt.
     * @param User|null     $applier  The user who ran the apply, if any.
     * @return void
     */
    protected function auditApply(AgentApproval $approval, ?User $applier): void
    {
        try {
            event(new AuditLogEvent(
                'agentic-approval.apply',
                [
                    'approval_id' => $approval->id,
                    'apply_outcome' => $approval->apply_outcome,
                    'applied_by_user_id' => $applier?->id,
                ],
                AgentApproval::class,
                (int) $approval->id,
                'fa fa-check-square-o',
                $approval->apply_outcome === 'applied' ? 'success' : 'danger',
                $applier?->id,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function ensurePending(AgentApproval $approval): void
    {
        if ($approval->status !== 'pending') {
            throw new \InvalidArgumentException("Approval [{$approval->id}] is not pending.");
        }
    }

    protected function recordRunProgress(
        AgentRun $run,
        string $runStatus,
        ?string $postApprovalOutcome,
        ?string $observationSummary = null,
        ?string $workflowStatus = null,
    ): void {
        $run->fill([
            'status' => $runStatus,
            'post_approval_outcome' => $postApprovalOutcome,
            'terminal_reason' => null,
            'last_checkpoint_at' => now(),
            'finished_at' => null,
        ])->save();

        $step = $run->steps()->orderByDesc('step_index')->first();
        if ($step instanceof AgentRunStep) {
            $step->fill([
                'status' => $runStatus,
                'terminal_reason' => null,
                'observation_summary' => $observationSummary ?? $step->observation_summary,
            ])->save();
        }

        $this->recordWorkflowProgress($run, $workflowStatus ?? $runStatus);
    }

    protected function recordRunOutcome(
        AgentRun $run,
        string $runStatus,
        string $postApprovalOutcome,
        ?string $terminalReason,
        string $stepStatus,
        ?string $observationSummary = null,
        ?string $workflowStatus = null,
    ): void {
        $run->fill([
            'status' => $runStatus,
            'post_approval_outcome' => $postApprovalOutcome,
            'terminal_reason' => $terminalReason,
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();

        $step = $run->steps()->orderByDesc('step_index')->first();
        if ($step instanceof AgentRunStep) {
            $step->fill([
                'status' => $stepStatus,
                'terminal_reason' => $terminalReason,
                'observation_summary' => $observationSummary ?? $step->observation_summary,
            ])->save();
        }

        $this->recordWorkflowOutcome($run, $workflowStatus ?? $postApprovalOutcome, $terminalReason);
    }

    protected function recordWorkflowProgress(AgentRun $run, string $workflowStatus): void
    {
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep']);

        if ($run->workflowInstance) {
            $run->workflowInstance->fill([
                'status' => $workflowStatus,
                'finished_at' => null,
            ])->save();
        }

        if ($run->parentWorkflowStep) {
            $run->parentWorkflowStep->fill([
                'status' => $workflowStatus,
                'finished_at' => now(),
                'error_message' => null,
            ])->save();
        }
    }

    protected function recordWorkflowOutcome(AgentRun $run, string $workflowStatus, ?string $message = null): void
    {
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep']);

        if ($run->workflowInstance) {
            $run->workflowInstance->fill([
                'status' => $workflowStatus,
                'finished_at' => in_array($workflowStatus, ['awaiting_approval', 'awaiting_apply', 'applied'], true) ? null : now(),
            ])->save();
        }

        if ($run->parentWorkflowStep) {
            $run->parentWorkflowStep->fill([
                'status' => $workflowStatus,
                'finished_at' => now(),
                'error_message' => $message,
            ])->save();
        }
    }

    protected function resumeWorkflowInstanceId(AgentRun $run): ?int
    {
        $run->loadMissing('workflowInstance');
        $instance = $run->workflowInstance;

        if (!$instance || !$instance->resume_step_id) {
            return null;
        }

        return (int) $instance->getKey();
    }
}
