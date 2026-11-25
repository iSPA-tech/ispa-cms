<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Cms\Core\Events\AgentApprovalRejected;
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
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Workflows\Runtime\Runners\Human\ApprovalAssignment;
use Cms\Core\Workflows\Runtime\Runners\Human\HumanNotifier;
use Cms\Core\Workflows\Runtime\Runners\Human\ReversibleFields;
use Cms\Core\Workflows\Runtime\Runners\Human\ReviewMeta;

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

    /** `agent_runs.trigger_mode` of the run behind a human workflow node (`approval`, `ask_and_wait`). */
    public const WORKFLOW_NODE_TRIGGER = 'workflow_node';

    /** Generic approval of a workflow node: nothing to apply, the decision only routes the run. */
    public const WORKFLOW_APPROVAL_TYPE = 'workflow.approval';

    /** Question of an `ask_and_wait` node: the reviewer's answer is the editable field `answer`. */
    public const WORKFLOW_QUESTION_TYPE = 'workflow.question';

    /** Change of declared reversible fields on one record (ReversibleFields); applied and undone here. */
    public const WORKFLOW_FIELD_PATCH_TYPE = 'workflow.field_patch';

    /** Workflow instance statuses after which a decision never resumes the run. */
    protected const WORKFLOW_TERMINAL_STATUSES = ['completed', 'cancelled', 'failed', 'running'];

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
        return $this->approveWithEdits($approval, $reviewer, $reviewerNote);
    }

    /**
     * Approve a pending approval after writing the reviewer's edits of the fields it declares editable
     * (`diff_data.workflow_node.editable_fields`, payload dot paths). Same rules as approve().
     *
     * @param array<string, mixed>|null $editedFields Payload path => new value.
     * @return array<string, mixed> Contains the refreshed approval, run, and artifact.
     *
     * @throws \InvalidArgumentException When the approval is not pending or an edit is not allowed.
     */
    public function approveWithEdits(AgentApproval $approval, ?User $reviewer = null, ?string $reviewerNote = null, ?array $editedFields = null): array
    {
        return DB::transaction(function () use ($approval, $reviewer, $reviewerNote, $editedFields) {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->ensurePending($approval);
            $approval->loadMissing(['artifact', 'run.steps']);
            $this->applyReviewerEdits($approval, $editedFields ?? []);
            $this->stopClock($approval, $reviewer);

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

            $this->markDecidedBy($approval, 'human');

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
     * Reject reasons (2026-11-24): an approval may declare `workflow_node.reject_reasons`; a given `$reason` must be one
     * of them (an approval without reasons refuses any) and is stored as `workflow_node.reject_reason`. The API makes the
     * reason required when reasons are declared; service callers that pass none (queue clean-up) keep working.
     *
     * Resume: an approval node routes `rejected` as before. An `ai_action` proposal resumes only when its step has an
     * outgoing `rejected` edge (the step then routes `rejected` without calling the tool again); without one the run
     * ends `rejected` exactly as before.
     *
     * @param AgentApproval $approval    The approval to reject.
     * @param User|null     $reviewer    The user performing the review.
     * @param string|null   $reviewerNote Optional rejection note.
     * @param string|null   $reason      Optional declared reject reason key.
     * @return array<string, mixed> Contains the refreshed approval, run, and artifact.
     *
     * @throws \InvalidArgumentException When the approval is not pending or the reason is not declared.
     */
    public function reject(AgentApproval $approval, ?User $reviewer = null, ?string $reviewerNote = null, ?string $reason = null): array
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $result = DB::transaction(function () use ($approval, $reviewer, $reviewerNote, $reason) {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->ensurePending($approval);
            $approval->loadMissing(['artifact', 'run.steps']);
            $meta = (array) data_get($approval->diff_data, 'workflow_node', []);
            if ($reason !== null && !in_array($reason, array_column(ReviewMeta::rejectReasons($meta['reject_reasons'] ?? []), 'key'), true)) {
                throw new \InvalidArgumentException("Reason [{$reason}] is not offered for approval [{$approval->id}].");
            }
            $resumable = $this->workflowNodeResumable($approval->run)
                || (($meta['kind'] ?? null) === 'proposal' && $this->hasRejectedEdge($approval->run)
                    && $this->workflowNodeResumable($approval->run, true));
            $this->stopClock($approval, $reviewer);

            $approval->fill([
                'status' => 'rejected',
                'reviewer_note' => $reviewerNote,
                'reviewed_by_user_id' => $reviewer?->getKey(),
                'reviewed_at' => now(),
                'apply_outcome' => 'approval_rejected',
                'apply_finished_at' => now(),
            ])->save();
            if ($reason !== null) {
                $this->mergeWorkflowMeta($approval, ['reject_reason' => $reason]);
            }

            $this->feedbackNormalizer->captureRejectionFeedback($approval, $reviewerNote, $reason);

            $this->recordRunOutcome(
                $approval->run,
                'rejected',
                'approval_rejected',
                'approval_rejected',
                'rejected',
                $reviewerNote ?? 'Approval rejected by reviewer.'
            );
            $this->markDecidedBy($approval, 'human');

            return [
                'approval' => $approval->fresh(['artifact', 'run.profile', 'reviewer']),
                'run' => $approval->run->fresh(['profile']),
                'artifact' => $approval->artifact->fresh(),
                'resume_workflow_instance_id' => $resumable ? $this->pointWorkflowNodeResume($approval->run, 'queued') : null,
            ];
        });

        if ($reason !== null) {
            event(new AgentApprovalRejected((int) $result['approval']->id, $reason));
        }

        $resumeId = $result['resume_workflow_instance_id'] ?? null;
        unset($result['resume_workflow_instance_id']);
        if (is_numeric($resumeId)) {
            // A workflow node routes its `rejected` output: the run continues from the node.
            RunWorkflowInstanceJob::dispatch((int) $resumeId);
        }

        $this->runtimeSyncService->syncFromRun($approval->run->fresh(['workflowInstance']));

        return $result;
    }

    /**
     * Ask for a rewrite of a pending approval that offers one (`diff_data.workflow_node.revisable`).
     *
     * Same rules as reject(): row lock, pending check, data scope of the reviewer and the delegating user (write), one
     * audit entry, and the workflow resumes after commit. The producing step runs again: the `approval` node routes
     * `changes_requested`, an `ai_action` step calls its tool again with the revision; either opens the next round as a
     * new approval chained to this one. Nothing resumes when the workflow instance already ended (the row keeps the
     * request).
     *
     * @param array<string, mixed> $options Declared revision option key => declared choice value.
     * @return array{approval: AgentApproval, resume_workflow_instance_id: int|null}
     *
     * @throws \InvalidArgumentException When the approval is not pending or not revisable, the round cap is reached, an
     *                                   option is not declared, or the subject is outside a data scope (SCOPE_DENIED).
     */
    public function requestChanges(AgentApproval $approval, User $reviewer, string $feedback, ?string $addedInfo, array $options): array
    {
        $result = DB::transaction(function () use ($approval, $reviewer, $feedback, $addedInfo, $options): array {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->ensurePending($approval);
            $approval->loadMissing(['artifact', 'run.steps']);

            $meta = (array) data_get($approval->diff_data, 'workflow_node', []);
            if (($meta['revisable'] ?? false) !== true) {
                throw new \InvalidArgumentException("Approval [{$approval->id}] cannot be sent back for a rewrite.");
            }
            if (ReviewMeta::roundOf($approval) >= ReviewMeta::maxRounds($meta['max_rounds'] ?? null)) {
                throw new \InvalidArgumentException("Approval [{$approval->id}] reached the last allowed draft.");
            }
            $options = $this->declaredOptions($meta, $options);
            if (!$this->allowsSubject($approval, $reviewer, true)
                || !$this->allowsSubject($approval, $approval->run?->currentDelegate(), true)) {
                throw new \InvalidArgumentException(self::SCOPE_DENIED);
            }

            $proposal = ($meta['kind'] ?? null) === 'proposal';
            $resumable = $this->workflowNodeResumable($approval->run, $proposal);
            if (!$resumable) {
                // Nothing would write the next draft: refuse instead of leaving the row "waiting for a rewrite" forever.
                throw new \InvalidArgumentException("Approval [{$approval->id}] cannot be sent back: its workflow is no longer waiting for it.");
            }
            $this->stopClock($approval, $reviewer);

            $approval->fill([
                'status' => 'changes_requested',
                'reviewer_note' => $feedback,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'apply_outcome' => 'changes_requested',
                'apply_finished_at' => now(),
            ])->save();
            $this->mergeWorkflowMeta($approval, ['revision_request' => [
                'feedback' => $feedback,
                'added_info' => $addedInfo,
                'options' => $options,
                'requested_by' => $reviewer->getKey(),
                'requested_at' => now()->toIso8601String(),
            ]]);
            $this->markDecidedBy($approval, 'human');
            $this->closeRun($approval->run, 'completed', 'changes_requested', null);

            return [
                'approval' => $approval,
                'resume_workflow_instance_id' => $resumable ? $this->pointWorkflowNodeResume($approval->run, 'queued') : null,
            ];
        });

        $resumeId = $result['resume_workflow_instance_id'];
        $this->audit('agentic-approval.request-changes', $result['approval'], [
            'options' => $result['approval']->diff_data['workflow_node']['revision_request']['options'] ?? [],
            'resumed_workflow_instance_id' => $resumeId,
        ], $reviewer, true);
        if (is_numeric($resumeId)) {
            // The producing step runs again and opens the next round.
            RunWorkflowInstanceJob::dispatch((int) $resumeId);
        }

        return [
            'approval' => $result['approval']->fresh(['artifact', 'run.profile', 'reviewer']),
            'resume_workflow_instance_id' => $resumeId,
        ];
    }

    /**
     * The rounds of a rewrite chain around an approval, oldest first (at most $limit rows), following
     * `previous_approval_id` back and `next_approval_id` forward.
     *
     * @return list<array<string, mixed>>
     */
    public function revisionHistory(AgentApproval $approval, int $limit = 10): array
    {
        $first = $approval;
        $seen = [(int) $approval->id => true];
        while (count($seen) < $limit && is_numeric($previousId = data_get($first->diff_data, 'workflow_node.previous_approval_id'))
            && !isset($seen[(int) $previousId]) && ($previous = AgentApproval::query()->find((int) $previousId)) !== null) {
            $first = $previous;
            $seen[(int) $previous->id] = true;
        }

        $chain = [];
        $current = $first;
        $visited = [];
        while ($current !== null && count($chain) < $limit && !isset($visited[(int) $current->id])) {
            $visited[(int) $current->id] = true;
            $current->loadMissing('reviewer');
            $request = (array) data_get($current->diff_data, 'workflow_node.revision_request', []);
            $chain[] = [
                'approval_id' => (int) $current->id,
                'round' => ReviewMeta::roundOf($current),
                'status' => $current->status,
                'feedback' => $request['feedback'] ?? null,
                'added_info' => $request['added_info'] ?? null,
                'options' => $request['options'] ?? [],
                'reject_reason' => is_string($reason = data_get($current->diff_data, 'workflow_node.reject_reason')) ? $reason : null,
                'reviewed_at' => optional($current->reviewed_at)->toJSON(),
                'reviewer_name' => $current->reviewer?->name,
            ];
            $nextId = data_get($current->diff_data, 'workflow_node.next_approval_id');
            $current = is_numeric($nextId) ? AgentApproval::query()->find((int) $nextId) : null;
        }

        return $chain;
    }

    /**
     * Check the chosen rewrite options against the declared ones.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    protected function declaredOptions(array $meta, array $options): array
    {
        $declared = [];
        foreach (ReviewMeta::revisionOptions($meta['revision_options'] ?? []) as $option) {
            $declared[$option['key']] = array_column($option['choices'], 'value');
        }

        $chosen = [];
        foreach ($options as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($key) || !isset($declared[$key])) {
                throw new \InvalidArgumentException("Option [{$key}] is not offered for this rewrite.");
            }
            if (!is_scalar($value) || !in_array((string) $value, $declared[$key], true)) {
                throw new \InvalidArgumentException("Option [{$key}] has no such choice.");
            }
            $chosen[$key] = (string) $value;
        }

        return $chosen;
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
        // The apply writes for the run that asked: its model events carry the run's trigger chain (loop guard).
        $instanceId = (int) AgentRun::query()->whereKey($approval->run_id)->value('workflow_instance_id');

        return \Cms\Core\Services\Workflow\Triggers\TriggerChain::within($instanceId ?: null, fn (): array => $this->applyInRunChain($approval, $applier));
    }

    /**
     * The body of {@see self::apply()}.
     *
     * @return array<string, mixed>
     */
    protected function applyInRunChain(AgentApproval $approval, ?User $applier): array
    {
        $result = DB::transaction(function () use ($approval, $applier) {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $approval->loadMissing(['artifact', 'run.steps']);
            $resumable = $this->workflowNodeResumable($approval->run);

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
                // The applier runs in its own savepoint: if it throws midway, its writes roll back before the outer
                // transaction records the failure, so nothing is half-written and a retry starts clean.
                $applyResult = DB::transaction(fn () => $this->applyArtifact($approval));
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
                $this->markDecidedBy($approval, 'human');
                if ($this->isWorkflowNodeRun($approval->run)) {
                    // A workflow node routes its `approved` output: the run continues from the node itself.
                    $resumeWorkflowInstanceId = $resumable ? $this->pointWorkflowNodeResume($approval->run, 'applied') : null;
                }
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
     * Approve a pending approval and apply it right away, for a one-click review.
     *
     * Runs the existing approve() and then apply(), each in its own locked
     * transaction, so every rule of both still holds (pending check, data
     * scope, stale detection, workflow resume, audit log). If the apply does
     * not succeed the approval stays approved and waiting (`approval_stale` or
     * `apply_failed` with `apply_error`), the state a separate apply would
     * leave, so a reviewer can retry the apply later; nothing is half-written.
     *
     * @param AgentApproval $approval The pending approval.
     * @param User          $user     The reviewer, who is also the applier.
     * @param string|null   $note     Optional reviewer note.
     * @return array{approval: AgentApproval, outcome: string} outcome is applied, approval_stale or apply_failed.
     *
     * @throws \InvalidArgumentException When the approval is not pending.
     */
    public function approveAndApply(AgentApproval $approval, User $user, ?string $note = null, ?array $editedFields = null): array
    {
        if ($editedFields === null || $editedFields === []) {
            $this->approve($approval, $user, $note);
        } else {
            $this->approveWithEdits($approval, $user, $note, $editedFields);
        }

        try {
            $result = $this->apply($approval, $user);
            $fresh = $result['approval'];
        } catch (\Throwable $e) {
            // apply() refused before touching the record. When another reviewer applied it in between, report that
            // real state; otherwise keep the approval approved and say why.
            report($e);
            $fresh = AgentApproval::query()->findOrFail($approval->id);
            if ($fresh->apply_outcome !== 'applied') {
                $fresh->fill(['apply_error' => $e->getMessage()])->save();
            }
        }

        $outcome = match ($fresh->apply_outcome) {
            'applied' => 'applied',
            'approval_stale' => 'approval_stale',
            default => 'apply_failed',
        };

        return ['approval' => $fresh, 'outcome' => $outcome];
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

    // ── Workflow human nodes (approval, ask_and_wait) ────────────────────────────────────────────────────────────

    /**
     * Whether the run behind an approval belongs to a human workflow node (WorkflowApprovalGate).
     */
    public function isWorkflowNodeRun(?AgentRun $run): bool
    {
        return $run !== null && $run->trigger_mode === self::WORKFLOW_NODE_TRIGGER;
    }

    /**
     * Decide a pending workflow-node approval without a person: `auto` mode, a JEV verdict, the reversible-fields rule
     * or a JEV `on_unsure: reject`. Applies the artifact on approval (in its own savepoint), records who decided
     * (`diff_data.workflow_node.decided_by` + `decision`), closes the run and writes an audit entry. It never touches the
     * workflow instance: the node that called it is still running and routes its output itself.
     *
     * @param array<string, mixed> $meta Extra facts merged into `diff_data.workflow_node` (e.g. the JEV verdict).
     * @return array{approval: AgentApproval, outcome: string, apply_result: array<string, mixed>|null}
     *         outcome: applied | approval_rejected | approval_stale | apply_failed | scope_denied
     */
    public function decideAutomatically(AgentApproval $approval, string $decision, string $decidedBy, array $meta = [], ?string $note = null): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Unknown automatic decision [{$decision}].");
        }

        $result = DB::transaction(function () use ($approval, $decision, $decidedBy, $meta, $note): array {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->ensurePending($approval);
            $approval->loadMissing(['artifact', 'run']);
            $this->mergeWorkflowMeta($approval, $meta + ['decided_by' => $decidedBy, 'decision' => $decision, 'decided_at' => now()->toIso8601String()]);

            $applyResult = null;
            if ($decision === 'rejected') {
                $outcome = 'approval_rejected';
                $approval->fill(['status' => 'rejected', 'reviewer_note' => $note, 'reviewed_at' => now(), 'apply_outcome' => $outcome, 'apply_finished_at' => now()])->save();
                $this->closeRun($approval->run, 'rejected', $outcome, $note);

                return ['approval' => $approval, 'outcome' => $outcome, 'apply_result' => null];
            }

            $approval->fill(['status' => 'approved', 'reviewer_note' => $note, 'reviewed_at' => now(), 'apply_started_at' => now()])->save();
            if (!$this->allowsSubject($approval, $approval->run?->currentDelegate(), true)) {
                $outcome = self::SCOPE_DENIED;
                $approval->fill(['apply_outcome' => 'apply_failed', 'apply_error' => self::SCOPE_DENIED, 'apply_finished_at' => now()])->save();
                $this->closeRun($approval->run, 'failed', 'apply_failed', self::SCOPE_DENIED);

                return ['approval' => $approval, 'outcome' => $outcome, 'apply_result' => null];
            }

            try {
                $applyResult = DB::transaction(fn () => $this->applyArtifact($approval));
                $outcome = 'applied';
                $approval->fill(['apply_outcome' => 'applied', 'apply_error' => null, 'apply_finished_at' => now()])->save();
                $this->closeRun($approval->run, 'completed', 'applied', null);
            } catch (ApprovalStaleException $e) {
                $outcome = 'approval_stale';
                $approval->fill(['apply_outcome' => 'approval_stale', 'apply_error' => $e->getMessage(), 'apply_finished_at' => now()])->save();
                $this->closeRun($approval->run, 'failed', 'approval_stale', $e->getMessage());
            } catch (\Throwable $e) {
                $outcome = 'apply_failed';
                $approval->fill(['apply_outcome' => 'apply_failed', 'apply_error' => $e->getMessage(), 'apply_finished_at' => now()])->save();
                $this->closeRun($approval->run, 'failed', 'apply_failed', $e->getMessage());
            }

            return ['approval' => $approval, 'outcome' => $outcome, 'apply_result' => $applyResult];
        });

        $result['approval'] = $result['approval']->fresh(['artifact', 'run']);
        $this->audit('agentic-approval.auto-decide', $result['approval'], [
            'decision' => $decision,
            'decided_by' => $decidedBy,
            'outcome' => $result['outcome'],
        ], null, $result['outcome'] === 'applied' || $result['outcome'] === 'approval_rejected');

        return $result;
    }

    /**
     * Expire every pending workflow-node approval past its `expires_at` (same path as ExpireWorkflowApprovalJob).
     * Run by `cms:workflow-run-scheduled`, so expiry also happens on sync queues, which ignore the job's delay.
     *
     * @return int Approvals expired
     */
    public function expireDueWorkflowApprovals(int $limit = 500): int
    {
        $expired = 0;
        AgentApproval::query()
            ->where('status', 'pending')
            ->whereNotNull('diff_data->workflow_node->expires_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (AgentApproval $approval) use (&$expired): void {
                $expiresAt = data_get($approval->diff_data, 'workflow_node.expires_at');
                if (is_string($expiresAt) && now()->gte(\Illuminate\Support\Carbon::parse($expiresAt)) && $this->expireIfDue($approval)) {
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Expire a pending workflow-node approval whose `expires_at` has passed; the run resumes and the node routes
     * `expired`. Does nothing (returns false) for any other approval.
     */
    public function expireIfDue(AgentApproval $approval): bool
    {
        $result = DB::transaction(function () use ($approval): ?array {
            $approval = AgentApproval::query()->lockForUpdate()->find($approval->id);
            if ($approval === null || $approval->status !== 'pending') {
                return null;
            }
            $approval->loadMissing(['artifact', 'run']);
            $expiresAt = data_get($approval->diff_data, 'workflow_node.expires_at');
            if (!$this->isWorkflowNodeRun($approval->run) || !is_string($expiresAt) || now()->lt(\Illuminate\Support\Carbon::parse($expiresAt))) {
                return null;
            }

            $resumable = $this->workflowNodeResumable($approval->run);
            $approval->fill(['status' => 'expired', 'reviewed_at' => now(), 'apply_outcome' => 'approval_expired', 'apply_finished_at' => now()]);
            if ($approval->sla_seconds !== null) {
                // Nobody decided: no decision time, counted as missed.
                $approval->sla_met = false;
            }
            $approval->save();
            $this->mergeWorkflowMeta($approval, ['decided_by' => 'expiry', 'decision' => 'expired', 'decided_at' => now()->toIso8601String()]);
            $this->recordRunOutcome($approval->run, 'expired', 'approval_expired', 'approval_expired', 'expired', 'Approval expired.');

            return ['resume' => $resumable ? $this->pointWorkflowNodeResume($approval->run, 'queued') : null, 'approval' => $approval];
        });

        if ($result === null) {
            return false;
        }

        $this->audit('agentic-approval.expire', $result['approval'], ['outcome' => 'approval_expired'], null, false);
        if (is_numeric($result['resume'])) {
            RunWorkflowInstanceJob::dispatch((int) $result['resume']);
        }

        return true;
    }

    // ── Assignee and SLA (2026-11-24) ────────────────────────────────────────────────────────────────────────────

    /**
     * Record who a pending approval waiting for a person is assigned to and when it is due. Assignees without the
     * reviewer permission are skipped; when assignees were named but none holds it, the approval falls back to the
     * permission holders (`diff_data.workflow_node.assignment_fallback = true`). The SLA clock starts now
     * (`due_at = now + sla`). Nothing is written when neither assignees nor an SLA are given.
     *
     * @param list<string> $assigneeUuids
     * @return array{assignee_uuids: list<string>, fallback: bool} the uuids recorded as assignees
     */
    public function openAssignment(AgentApproval $approval, array $assigneeUuids, ?int $slaSeconds, string $permission): array
    {
        $assignees = $assigneeUuids === [] ? [] : User::query()->whereIn('uuid', $assigneeUuids)->get()
            ->filter(static fn (User $user): bool => Gate::forUser($user)->allows($permission))
            ->values()
            ->all();
        $fallback = $assigneeUuids !== [] && $assignees === [];
        if (($assigneeUuids === [] && $slaSeconds === null) || !AgentApproval::hasAssignmentColumns()) {
            return ['assignee_uuids' => [], 'fallback' => false];
        }

        DB::transaction(function () use ($approval, $assignees, $slaSeconds, $fallback): void {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $now = now();
            foreach ($assignees as $user) {
                DB::table(CoreTableConstant::AGENT_APPROVAL_ASSIGNEE)->insertOrIgnore([
                    'approval_id' => $approval->id,
                    'user_id' => $user->getKey(),
                    'created_at' => $now,
                ]);
            }
            if ($slaSeconds !== null) {
                $approval->fill(['sla_seconds' => $slaSeconds, 'due_at' => $now->copy()->addSeconds($slaSeconds)]);
            }
            if ($fallback) {
                $this->mergeWorkflowMeta($approval, ['assignment_fallback' => true]);
            } else {
                $approval->save();
            }
        });

        return [
            'assignee_uuids' => array_values(array_map(static fn (User $user): string => (string) $user->uuid, $assignees)),
            'fallback' => $fallback,
        ];
    }

    /**
     * Flag pending approvals past their `due_at` (once: `sla_breached_at` is set under a row lock) and tell their
     * assignees who still hold the reviewer permission, else the permission holders, with `workflow.approval.overdue`.
     * Run by `cms:workflow-run-scheduled`; idempotent.
     *
     * @return int Approvals flagged
     */
    public function flagOverdueApprovals(int $limit = 500): int
    {
        $flagged = 0;
        if (!AgentApproval::hasAssignmentColumns()) {
            return 0;
        }
        $ids = AgentApproval::query()
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('sla_breached_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            $approval = DB::transaction(static function () use ($id): ?AgentApproval {
                $approval = AgentApproval::query()->lockForUpdate()->find($id);
                if ($approval === null || $approval->status !== 'pending' || $approval->sla_breached_at !== null
                    || $approval->due_at === null || $approval->due_at->gte(now())) {
                    return null;
                }
                $approval->forceFill(['sla_breached_at' => now()])->save();

                return $approval;
            });
            if ($approval === null) {
                continue;
            }

            $flagged++;
            try {
                $this->notifyOverdue($approval);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $flagged;
    }

    /**
     * The reviewer permission of an approval: the approval node's `reviewer_permission`, else the configured default.
     */
    public function reviewerPermissionOf(AgentApproval $approval): string
    {
        $permission = data_get($approval->diff_data, 'workflow_node.reviewer_permission');

        return is_string($permission) && $permission !== ''
            ? $permission
            : (string) config('core.workflow_nodes.human.reviewer_permission', 'agentic.approve');
    }

    /**
     * Why $user may not decide $approval (approve, approve-and-apply, reject, request changes), or null when they may
     * (review fix 7, 2026-11-25). Checked by the admin API on top of AgenticAuthorization::canApprove():
     *
     * - `reviewer_permission`: an item that names a reviewer permission (`workflow_node.reviewer_permission`: approval
     *   and ask_and_wait nodes always store one, the node's own or the configured default) needs the decider to hold it.
     * - `not_assignee`: an item with assignees is decided by one of them.
     * - `own_run` / `workflow_author`: an approval node's item (`kind: approval`) is never decided by the user the run
     *   acts for (who started it, or whose submission it judges), nor by an author of the workflow version the run
     *   executes (WorkflowAuthors::ofVersion(): its publisher and the draft editors frozen into it).
     *
     * Agent proposals (`kind: proposal`, and approvals outside workflow nodes) keep the agent flow: the person who asked
     * the agent decides its proposals. A Webmaster may decide any item (the person in charge of the site, who can grant
     * themselves any permission), as AgenticAuthorization lets them through every agentic check; this also keeps a
     * one-person site, where the Webmaster wrote the workflow, able to decide its items.
     *
     * @return array{code: string, message: string}|null
     */
    public function decisionRefusal(AgentApproval $approval, ?User $user): ?array
    {
        if ($user === null) {
            return ['code' => 'not_allowed', 'message' => 'Sign in to decide this item.'];
        }

        if ($user->isWebmaster()) {
            return null;
        }

        $meta = (array) data_get($approval->diff_data, 'workflow_node', []);
        $permission = $meta['reviewer_permission'] ?? null;
        if (is_string($permission) && $permission !== '' && !Gate::forUser($user)->allows($permission)) {
            return ['code' => 'reviewer_permission', 'message' => 'Only people with the reviewer permission of this step can decide it.'];
        }

        if (AgentApproval::hasAssignmentColumns()) {
            $assignees = $approval->relationLoaded('assignees') ? $approval->assignees : $approval->assignees()->get();
            if ($assignees->isNotEmpty() && !$assignees->contains(static fn (User $assignee): bool => (int) $assignee->getKey() === (int) $user->getKey())) {
                return ['code' => 'not_assignee', 'message' => 'This item is assigned to other reviewers.'];
            }
        }

        if (($meta['kind'] ?? null) !== 'approval') {
            return null;
        }

        $run = $approval->relationLoaded('run') ? $approval->run : $approval->run()->first();
        $starter = $run?->delegated_by_user_id ?? $run?->workflowInstance?->delegated_by_user_id;
        if (is_numeric($starter) && (int) $starter === (int) $user->getKey()) {
            return ['code' => 'own_run', 'message' => 'Someone other than the person this run acts for must decide it.'];
        }

        $instance = $run?->workflowInstance;
        $versionId = $instance?->getAttribute('version_id');
        $version = is_numeric($versionId) ? \Cms\Core\Models\Workflows\WorkflowVersion::query()->find((int) $versionId) : null;
        if (in_array((int) $user->getKey(), \Cms\Core\Services\Workflow\Versioning\WorkflowAuthors::ofVersion($version), true)) {
            return ['code' => 'workflow_author', 'message' => 'Someone who did not write this workflow must decide it.'];
        }

        return null;
    }

    protected function notifyOverdue(AgentApproval $approval): void
    {
        $permission = $this->reviewerPermissionOf($approval);
        $uuids = $approval->assignees()->get()
            ->filter(static fn (User $user): bool => Gate::forUser($user)->allows($permission))
            ->map(static fn (User $user): string => (string) $user->uuid)
            ->values()
            ->all();
        $approval->loadMissing('artifact');
        $title = data_get($approval->diff_data, 'workflow_node.title') ?? data_get($approval->artifact?->payload, 'summary');
        $payload = [
            'approval_id' => (int) $approval->id,
            'title' => is_string($title) ? mb_substr($title, 0, 200) : null,
            'due_at' => optional($approval->due_at)->toJSON(),
        ];

        $notifier = app(HumanNotifier::class);
        if ($uuids !== []) {
            $notifier->send(ApprovalAssignment::OVERDUE_TYPE, $payload, null, $uuids);

            return;
        }
        $notifier->send(ApprovalAssignment::OVERDUE_TYPE, $payload, $permission);
    }

    /**
     * Stop the SLA clock on a person's decision (approve, reject, request changes; never apply): decision time, who
     * decided, seconds since the person was asked (the SLA start, else the JEV hand-off, else creation) and, with an
     * SLA, whether it was met. Fills only; the caller saves inside its transaction. A second call keeps the first.
     */
    protected function stopClock(AgentApproval $approval, ?User $decider): void
    {
        if ($approval->decided_at !== null || !AgentApproval::hasAssignmentColumns()) {
            return;
        }

        $now = now();
        $handedAt = data_get($approval->diff_data, 'workflow_node.handed_at');
        $start = match (true) {
            $approval->due_at !== null && $approval->sla_seconds !== null => $approval->due_at->copy()->subSeconds($approval->sla_seconds),
            is_string($handedAt) && $handedAt !== '' => Carbon::parse($handedAt),
            default => $approval->created_at ?? $now,
        };
        $response = max(0, $now->getTimestamp() - $start->getTimestamp());

        $approval->fill([
            'decided_at' => $now,
            'decided_by_user_id' => $decider?->getKey(),
            'response_seconds' => $response,
            'sla_met' => $approval->sla_seconds !== null ? $response <= $approval->sla_seconds : null,
        ]);
    }

    /**
     * Undo an applied reversible-field change (artifact type workflow.field_patch): every changed field goes back to
     * its value before the change, provided nobody changed it since (else ApprovalStaleException, nothing written).
     *
     * @throws \InvalidArgumentException When the approval is not an applied, not yet undone reversible change.
     * @throws ApprovalStaleException    When a field changed after the workflow wrote it.
     */
    public function undoReversible(AgentApproval $approval, ?User $user = null): AgentApproval
    {
        $approval = DB::transaction(function () use ($approval, $user): AgentApproval {
            $approval = AgentApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $approval->loadMissing(['artifact', 'run']);
            $artifact = $approval->artifact;
            if ($artifact?->artifact_type !== self::WORKFLOW_FIELD_PATCH_TYPE || $approval->apply_outcome !== 'applied'
                || data_get($approval->diff_data, 'workflow_node.undone_at') !== null) {
                throw new \InvalidArgumentException("Approval [{$approval->id}] has no applied reversible change to undo.");
            }
            if (!$this->allowsSubject($approval, $user, true) || !$this->allowsSubject($approval, $approval->run?->currentDelegate(), true)) {
                throw new \InvalidArgumentException(self::SCOPE_DENIED);
            }

            [$record, $changes] = $this->fieldPatchTarget($artifact);
            $original = (array) $artifact->original_data_snapshot;
            foreach ($changes as $field => $value) {
                if (self::normalized($record->getAttribute($field)) !== self::normalized($value)) {
                    throw new ApprovalStaleException("Field {$field} changed after the workflow wrote it; nothing was undone.");
                }
            }
            foreach (array_keys($changes) as $field) {
                $record->setAttribute($field, $original[$field] ?? null);
            }
            $record->save();

            $this->mergeWorkflowMeta($approval, ['undone_at' => now()->toIso8601String(), 'undone_by_user_id' => $user?->getKey()]);

            return $approval;
        });

        $this->audit('agentic-approval.undo', $approval, ['fields' => array_keys((array) data_get($approval->artifact?->payload, 'changes', []))], $user, true);

        return $approval->fresh(['artifact', 'run']);
    }

    /**
     * Apply an approval's artifact: the workflow-native types here, every other type through its configured applier.
     *
     * @return array<string, mixed>
     */
    protected function applyArtifact(AgentApproval $approval): array
    {
        $artifact = $approval->artifact;

        return match ($artifact?->artifact_type) {
            self::WORKFLOW_APPROVAL_TYPE, self::WORKFLOW_QUESTION_TYPE => ['message' => 'Nothing to apply; the decision continues the workflow.'],
            self::WORKFLOW_FIELD_PATCH_TYPE => $this->applyFieldPatch($artifact),
            default => $this->artifactApplyService->apply($artifact, $approval),
        };
    }

    /**
     * Write a reversible-field change. Every field must still be on the reversible list and still hold the value the
     * change was proposed against.
     *
     * @return array<string, mixed>
     */
    protected function applyFieldPatch(AgentArtifact $artifact): array
    {
        [$record, $changes] = $this->fieldPatchTarget($artifact);
        $original = (array) $artifact->original_data_snapshot;

        foreach (array_keys($changes) as $field) {
            if (self::normalized($record->getAttribute($field)) !== self::normalized($original[$field] ?? null)) {
                throw new ApprovalStaleException("Field {$field} changed since the proposal.");
            }
        }
        foreach ($changes as $field => $value) {
            $record->setAttribute($field, $value);
        }
        $record->save();

        return ['changed' => array_keys($changes)];
    }

    /**
     * @return array{0: \Illuminate\Database\Eloquent\Model, 1: array<string, mixed>}
     */
    protected function fieldPatchTarget(AgentArtifact $artifact): array
    {
        $class = $this->modelClassFor((string) $artifact->subject_type);
        $changes = (array) data_get($artifact->payload, 'changes', []);
        if ($changes === [] || !ReversibleFields::allows($class, array_map('strval', array_keys($changes)))) {
            throw new \InvalidArgumentException('Only fields on the reversible-fields list can be changed this way.');
        }

        $record = $class::query()->find($artifact->subject_id);
        if ($record === null) {
            throw new \InvalidArgumentException('The record of this change no longer exists.');
        }

        return [$record, $changes];
    }

    /**
     * Write the reviewer's edits into the artifact payload. Only paths listed in `diff_data.workflow_node.editable_fields`
     * may change, values are scalars (text up to 20 000 characters); a question's answer must be one of its options
     * when it has any. Every edit is recorded (`workflow_node.edits`, shortened before/after) and the diff is rebuilt.
     *
     * @param array<string, mixed> $edits
     */
    protected function applyReviewerEdits(AgentApproval $approval, array $edits): void
    {
        $meta = (array) data_get($approval->diff_data, 'workflow_node', []);
        $isQuestion = ($meta['kind'] ?? null) === 'question';
        if ($edits === [] && !$isQuestion) {
            return;
        }

        $artifact = $approval->artifact;
        $editable = array_values(array_filter((array) ($meta['editable_fields'] ?? []), 'is_string'));
        if ($artifact === null || ($edits !== [] && $editable === [])) {
            throw new \InvalidArgumentException('This approval has no editable fields.');
        }

        $payload = (array) $artifact->payload;
        $recorded = (array) ($meta['edits'] ?? []);
        foreach ($edits as $path => $value) {
            if (!is_string($path) || !in_array($path, $editable, true)) {
                throw new \InvalidArgumentException("Field [{$path}] cannot be edited.");
            }
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException("Field [{$path}] takes a single value.");
            }
            if (is_string($value) && mb_strlen($value) > 20000) {
                throw new \InvalidArgumentException("Field [{$path}] is too long.");
            }

            $before = data_get($payload, $path);
            if (self::normalized($before) === self::normalized($value)) {
                continue;
            }
            data_set($payload, $path, $value);
            $recorded[$path] = ['before' => self::shorten($before), 'after' => self::shorten($value)];
        }

        if ($isQuestion) {
            $answer = data_get($payload, 'answer');
            $options = array_values(array_filter((array) data_get($payload, 'options', []), 'is_string'));
            if (!is_scalar($answer) || trim((string) $answer) === '') {
                throw new \InvalidArgumentException('Write an answer before you send it.');
            }
            if ($options !== [] && !in_array((string) $answer, $options, true)) {
                throw new \InvalidArgumentException('Pick one of the offered answers.');
            }
        }

        if ($recorded === (array) ($meta['edits'] ?? [])) {
            return;
        }

        $artifact->payload = $payload;
        $artifact->save();

        $diff = app(AgentApprovalDiffBuilder::class)->build($artifact);
        $diff['workflow_node'] = array_merge($meta, ['edits' => $recorded]);
        $approval->diff_data = $diff;
        $approval->save();
    }

    /**
     * Record who decided a workflow-node approval, once (a later call keeps the first answer).
     */
    protected function markDecidedBy(AgentApproval $approval, string $decidedBy): void
    {
        if (!is_array(data_get($approval->diff_data, 'workflow_node')) || data_get($approval->diff_data, 'workflow_node.decided_by') !== null) {
            return;
        }

        $this->mergeWorkflowMeta($approval, [
            'decided_by' => $decidedBy,
            'decision' => $approval->status,
            'decided_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function mergeWorkflowMeta(AgentApproval $approval, array $values): void
    {
        $diff = (array) $approval->diff_data;
        $diff['workflow_node'] = array_merge((array) ($diff['workflow_node'] ?? []), $values);
        $approval->diff_data = $diff;
        $approval->save();
    }

    /**
     * Whether a decision on this run may resume its workflow: a workflow-node run whose instance is still waiting on it.
     * $proposal also accepts an `ai_action` run whose approval is a proposal (`workflow_node.kind = proposal`); only a
     * requested rewrite passes it, so reject/apply/expiry of ai_action approvals keep their behaviour.
     */
    protected function workflowNodeResumable(?AgentRun $run, bool $proposal = false): bool
    {
        if ($run === null || (!$proposal && !$this->isWorkflowNodeRun($run))) {
            return false;
        }
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep']);

        return $run->workflowInstance !== null
            && $run->parentWorkflowStep?->step_id !== null
            && !in_array($run->workflowInstance->status, self::WORKFLOW_TERMINAL_STATUSES, true);
    }

    /**
     * Whether the run's workflow step has an outgoing `rejected` edge (an ai_action author opting into reject routing).
     */
    protected function hasRejectedEdge(?AgentRun $run): bool
    {
        if ($run === null) {
            return false;
        }
        $run->loadMissing(['parentWorkflowStep', 'workflowInstance']);
        $stepId = $run->parentWorkflowStep?->step_id;

        // Read the edge in the version the run executes (not the draft).
        return ReviewMeta::hasEdge(is_numeric($stepId) ? (int) $stepId : null, 'rejected', $run->workflowInstance);
    }

    /**
     * Point the instance's resume cursor back at the node that paused and leave it claimable, so the node runs again
     * and routes the decision. Returns the instance id to dispatch, or null when there is nothing to resume.
     */
    protected function pointWorkflowNodeResume(AgentRun $run, string $status): ?int
    {
        $run->loadMissing(['workflowInstance', 'parentWorkflowStep']);
        $instance = $run->workflowInstance;
        $stepId = $run->parentWorkflowStep?->step_id;
        if ($instance === null || $stepId === null) {
            return null;
        }

        $instance->fill(['status' => $status, 'finished_at' => null]);
        $instance->resume_step_id = (int) $stepId;
        $instance->save();

        return (int) $instance->getKey();
    }

    protected function closeRun(?AgentRun $run, string $status, string $outcome, ?string $reason): void
    {
        if ($run === null) {
            return;
        }

        $run->fill([
            'status' => $status,
            'post_approval_outcome' => $outcome,
            'terminal_reason' => $status === 'completed' ? null : ($reason !== null ? mb_substr($reason, 0, 191) : $outcome),
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function audit(string $action, AgentApproval $approval, array $data, ?User $user, bool $success): void
    {
        try {
            event(new AuditLogEvent(
                $action,
                ['approval_id' => $approval->id] + $data,
                AgentApproval::class,
                (int) $approval->id,
                'fa fa-check-square-o',
                $success ? 'success' : 'danger',
                $user?->id,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected static function normalized(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        $decoded = json_decode((string) json_encode($value), true);

        return is_bool($decoded) ? (string) (int) $decoded : (is_int($decoded) || is_float($decoded) ? (string) $decoded : $decoded);
    }

    protected static function shorten(mixed $value): mixed
    {
        return is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '…' : $value;
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
