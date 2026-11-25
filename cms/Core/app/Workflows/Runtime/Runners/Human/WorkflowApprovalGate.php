<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Carbon\CarbonImmutable;
use Cms\Core\Jobs\ExpireWorkflowApprovalJob;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Services\Agentic\AgentApprovalDiffBuilder;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opens the inbox item behind a human workflow node (`approval`, `ask_and_wait`) and finds it again when the run
 * resumes.
 *
 * One node = one AgentRun (trigger_mode `workflow_node`, no agent profile) + AgentArtifact + AgentApproval, so the
 * item appears in the Agent approvals inbox and AgentApprovalService decides it. The node's own facts (kind, mode, who
 * decided, editable fields, expiry, JEV verdict, reviewer edits) live in `diff_data.workflow_node`.
 *
 * Resume contract: the node pauses (`awaiting_approval`); when the approval is applied, rejected or expired,
 * AgentApprovalService points the instance's resume cursor back at this node and queues the run; the node runs again,
 * sees its decided approval in its previous step result and leaves through `approved` / `rejected` / `expired`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class WorkflowApprovalGate
{
    public const PAUSED_STATUS = 'awaiting_approval';

    public function __construct(protected AgentApprovalDiffBuilder $diffBuilder)
    {
    }

    public static function stepCode(StepDefinition $step): string
    {
        return $step->stepCode ?: ('step_' . $step->id);
    }

    /**
     * The approval this node is waiting on, when the run comes back to it after a pause.
     */
    public function waitingApproval(StepDefinition $step, WorkflowContext $context): ?AgentApproval
    {
        $previous = $context->getStep(self::stepCode($step));
        if ($previous === null || $previous->getStatus() !== self::PAUSED_STATUS) {
            return null;
        }

        // The executor may wrap a step result as {payload, retry}.
        $result = $previous->getResult();
        $id = data_get($result, 'approval_id') ?? data_get($result, 'payload.approval_id');

        $approval = is_numeric($id) ? AgentApproval::query()->with(['artifact', 'run'])->find((int) $id) : null;
        if ($approval === null) {
            return null;
        }

        // Review fix 33: only an item this run opened at this step resumes it (never another run's or step's decision).
        $instanceId = $context->getVarPath(WorkflowContext::RUNTIME_KEY . '.instance_id');
        $sameRun = is_numeric($instanceId) && (int) $instanceId > 0
            && (int) ($approval->run?->workflow_instance_id ?? 0) === (int) $instanceId;
        $sameStep = data_get($approval->diff_data, 'workflow_node.step_code') === self::stepCode($step);

        return $sameRun && $sameStep ? $approval : null;
    }

    /**
     * Create the run, artifact and pending approval for this node.
     *
     * @param array<string, mixed> $envelope `artifact_type`, `payload`, optional `subject_type`, `subject_id`,
     *                                       `original_data_snapshot`, `render_hint`
     * @param array<string, mixed> $meta     stored as `diff_data.workflow_node`
     */
    public function open(StepDefinition $step, WorkflowContext $context, array $envelope, array $meta, string $goal): AgentApproval
    {
        if (!is_string($envelope['artifact_type'] ?? null) || $envelope['artifact_type'] === '' || !is_array($envelope['payload'] ?? null)) {
            throw new InvalidArgumentException('the review envelope needs artifact_type and payload');
        }

        $instanceId = $context->getVarPath(WorkflowContext::RUNTIME_KEY . '.instance_id');
        $subjectType = is_string($envelope['subject_type'] ?? null) && $envelope['subject_type'] !== '' ? $envelope['subject_type'] : null;
        $subjectId = isset($envelope['subject_id']) && is_scalar($envelope['subject_id']) ? (string) $envelope['subject_id'] : null;

        return DB::transaction(function () use ($step, $context, $envelope, $meta, $goal, $instanceId, $subjectType, $subjectId): AgentApproval {
            $run = AgentRun::create([
                ...AgentRun::delegationFor($instanceId, $subjectType),
                'workflow_instance_id' => is_numeric($instanceId) ? (int) $instanceId : null,
                'parent_workflow_step_id' => $context->getVarPath(WorkflowContext::RUNTIME_KEY . '.current_instance_step_id'),
                'trigger_mode' => AgentApprovalService::WORKFLOW_NODE_TRIGGER,
                'trigger_reference_type' => 'workflow_step',
                'trigger_reference_id' => $step->id !== null ? (string) $step->id : null,
                'module_scope' => 'workflow',
                'target_type' => $subjectType,
                'target_id' => $subjectId,
                'status' => 'awaiting_approval',
                'input_goal' => mb_substr($goal, 0, 1000),
                'budget_usage' => ['steps' => 0, 'tool_calls' => 0, 'tokens' => 0],
                'started_at' => now(),
            ]);

            $json = (string) json_encode($envelope);
            $artifact = AgentArtifact::create([
                'run_id' => $run->id,
                'artifact_type' => (string) $envelope['artifact_type'],
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'source_snapshot_ref' => 'workflow-step:' . self::stepCode($step),
                'original_data_snapshot' => $envelope['original_data_snapshot'] ?? null,
                'payload' => $envelope['payload'],
                'storage_driver' => 'database',
                'version' => 1,
                'render_hint' => is_string($envelope['render_hint'] ?? null) ? $envelope['render_hint'] : 'json_view',
                'apply_contract_version' => 'v1',
                'checksum' => hash('sha256', $json),
                'size_bytes' => strlen($json),
            ]);

            $diff = $this->diffBuilder->build($artifact);
            $diff['workflow_node'] = $meta + [
                'step_code' => self::stepCode($step),
                'instance_id' => is_numeric($instanceId) ? (int) $instanceId : null,
                'decided_by' => null,
            ];

            return $run->approvals()->create([
                'artifact_id' => $artifact->id,
                'status' => 'pending',
                'diff_data' => $diff,
            ])->load(['artifact', 'run']);
        });
    }

    /**
     * Queue the expiry check of a pending approval (`expires_at` in `diff_data.workflow_node`).
     */
    public function scheduleExpiry(AgentApproval $approval, ?CarbonImmutable $expiresAt): void
    {
        if ($expiresAt === null) {
            return;
        }

        ExpireWorkflowApprovalJob::dispatch((int) $approval->id)->delay($expiresAt);
    }

    /**
     * Seconds from an `expires_after` value: an integer of seconds, or `<n>m`, `<n>h`, `<n>d`. Null = never expires.
     * Other durations (the approval `sla`) pass their own bounds and field name; the result is clamped to the bounds.
     */
    public static function parseDuration(mixed $value, ?int $min = null, ?int $max = null, string $field = 'expires_after'): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $seconds = (int) $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d+)\s*([mhd])\s*$/i', $value, $m)) {
            $seconds = (int) $m[1] * ['m' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
        } else {
            throw new InvalidArgumentException($field . ' must be seconds or a number followed by m, h or d');
        }

        $max ??= (int) config('core.workflow_nodes.human.max_expires_after_seconds', 30 * 86400);

        return max($min ?? 60, min($max, $seconds));
    }
}
