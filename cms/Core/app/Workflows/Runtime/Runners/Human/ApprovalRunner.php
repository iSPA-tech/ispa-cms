<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Carbon\CarbonImmutable;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Ai\Jev\JevJudgeResolver;
use Cms\Core\Services\Ai\Jev\JevRequest;
use Cms\Core\Services\Ai\Jev\JevVerdict;
use Cms\Core\Services\Workflow\AutoApproval\WorkflowAutoApproval;
use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

/**
 * `approval` node: shows a proposal in the Agent approvals inbox and routes `approved` / `rejected` / `expired`.
 *
 * What is reviewed (the envelope):
 * - `review`: expression to a review envelope a previous step returned (`vars.<storeAs>.review`, e.g. Content's
 *   `content.save_post_draft`): `artifact_type`, `payload`, optional `subject_type`, `subject_id`,
 *   `original_data_snapshot`, `editable_fields`. Approving applies it through its artifact applier.
 * - or `subject_model` + `subject_id` + `changes` (map field => value): a change of declared reversible fields
 *   (ReversibleFields), applied by the approval; any field off the list fails the step.
 * - or `title` + `payload` (expression): a generic approval with nothing to apply.
 *
 * Modes: `human` (default, a person decides in the inbox), `auto` (approved at once) and
 * `jev` (a JEV judge decides when it is confident enough; else a person decides, or it is rejected with
 * `jev.on_unsure: reject`). `auto`/`jev` only accept the artifact types in `core.workflow_nodes.human.auto_artifact_types`.
 * A reversible-field change skips the person (decided by the `reversible_fields` rule) unless `reversible_skip` is
 * false. Every automatic decision is an approval row with `diff_data.workflow_node.decided_by`, visible in the inbox.
 *
 * Human mode pauses the run (`awaiting_approval`) after optionally notifying `reviewer_permission` holders; the
 * reviewer may edit `editable_fields` (payload dot paths; a plain name also matches `proposed_change.<name>`) before
 * approving. `expires_after` (seconds, or `30m`, `2h`, `3d`) expires the request. `assignees` (user uuids) and `sla`
 * (target time to decide) assign the request and measure the response time (ApprovalAssignment). The decision lands in
 * `vars.<storeAs>` (default `vars.approval`): decision, decided_by, approval_id, mode, payload, edits, note, jev, round.
 *
 * Rewrite: when the author wires the `changes_requested` output (usually back to the AI node
 * that drafted the content) a person may ask for a rewrite with feedback, added information and the declared
 * `revision_options`, up to `max_rounds` drafts. The decision then also carries feedback, added_info, options and
 * previous_payload; the next visit opens a new approval (round + 1) chained to this one. `revision_model_choice: true`
 * adds a model option (the selectable "provider:model" pairs); an AI node whose `revision` reads it uses that model.
 *
 * Stored verdict (auto-publish after calibration, direction C, 2026-11-24): `jev.verdict_from` (expression to a verdict an
 * earlier step stored, e.g. `vars.jev`) makes the step reuse that verdict instead of calling a judge. In mode `human`
 * ("learning") a person decides and the verdict is recorded on the row (`held_reason: learning`). In mode `jev` the
 * item is approved automatically (`decided_by: jev`) only when the verdict is assessed, its probability reaches
 * `jev.min_confidence`, `jev.hold_if` (expression) is false and the step's recent agreement is not below the minimum
 * (WorkflowAutoApproval::paused()); otherwise a person decides with `held_reason` below_threshold | flagged |
 * jev_unsure | jev_missing | paused. This mode never rejects automatically. The row stores
 * `diff_data.workflow_node.jev` = {verdict, probability, min_confidence}. The verdict counts only when it is the one a
 * JEV judge step of the run's graph stored at that path in this run (WorkflowAutoApproval::verdictProducers(), its step
 * result matching); otherwise it is `jev_missing` and a person decides (review 2026-11-24 finding 6).
 *
 * Reject reasons (2026-11-24): `reject_reasons` [{key, label_key?, label?}] makes the reviewer pick one when rejecting;
 * it lands in `vars.<storeAs>.reason` on the `rejected` output (route on it with a decision/switch node).
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class ApprovalRunner implements StepRunner
{
    use NodeHelpers;

    public const MODES = ['human', 'auto', 'jev'];
    public const OUTPUTS = ['approved', 'rejected', 'expired', 'changes_requested'];

    public function __construct(
        protected WorkflowApprovalGate $gate,
        protected AgentApprovalService $approvals,
        protected HumanNotifier $notifier,
        protected ?ApprovalAssignment $assignment = null,
    ) {
        $this->assignment ??= app(ApprovalAssignment::class);
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $path = $this->storePath($config, 'vars.approval');
        if ($path === null) {
            return StepRunResult::failed('approval: storeAs must start with vars.');
        }

        $waiting = $this->gate->waitingApproval($step, $context);
        if ($waiting !== null) {
            return $this->afterDecision($waiting, $context, $path);
        }

        $mode = (string) ($config['mode'] ?? 'human');
        if (!in_array($mode, self::MODES, true)) {
            return StepRunResult::failed('approval: mode must be human, auto or jev');
        }

        try {
            $envelope = $this->envelope($config, $context);
            $expiresAfter = WorkflowApprovalGate::parseDuration($config['expires_after'] ?? null);
            $slaSeconds = ApprovalAssignment::slaSeconds($config['sla'] ?? null);
        } catch (Throwable $e) {
            return StepRunResult::failed('approval: ' . $e->getMessage());
        }

        $isFieldPatch = $envelope['artifact_type'] === AgentApprovalService::WORKFLOW_FIELD_PATCH_TYPE;
        if ($mode !== 'human' && !in_array($envelope['artifact_type'], $this->autoTypes(), true)) {
            return StepRunResult::failed("approval: {$mode} mode cannot decide a {$envelope['artifact_type']} proposal; use human mode");
        }

        // A stored verdict (`jev.verdict_from`): read, never judged again.
        $stored = WorkflowAutoApproval::verdictFrom($config) !== null && in_array($mode, ['human', 'jev'], true)
            ? $this->storedVerdict($step, $config, $context, $mode, (string) $envelope['artifact_type'])
            : null;

        $expiresAt = $expiresAfter !== null ? CarbonImmutable::now()->addSeconds($expiresAfter) : null;
        $title = $this->title($config, $context, $envelope);
        $previousRound = $this->previousRound($context, $path);
        $rounds = ReviewMeta::rounds($previousRound, $config['max_rounds'] ?? null);
        $meta = [
            'kind' => 'approval',
            'mode' => $mode,
            'title' => $title,
            'editable_fields' => $this->editableFields($config, $envelope),
            'expires_at' => $expiresAt?->toIso8601String(),
            'reviewer_permission' => $this->reviewerPermission($config),
            // A rewrite is offered only to a person, only when the author wired `changes_requested`, below the cap.
            'revisable' => $mode === 'human' && $rounds['round'] < $rounds['max_rounds'] && $this->hasRevisionEdge($step),
            'revision_options' => $this->revisionOptions($config),
            'reject_reasons' => ReviewMeta::rejectReasons($config['reject_reasons'] ?? []),
        ] + $rounds;
        if ($stored !== null) {
            $meta['jev'] = $stored['jev'];
            if ($stored['held_reason'] !== null) {
                $meta['held_reason'] = $stored['held_reason'];
                if ($mode === 'jev') {
                    // `handed_at` starts the response clock of the person (AgentApprovalService::stopClock()).
                    $meta += ['handed_to' => 'human', 'handed_at' => now()->toIso8601String()];
                }
            }
        }

        try {
            $approval = $this->gate->open($step, $context, $envelope, $meta, $title);
        } catch (Throwable $e) {
            return StepRunResult::failed('approval: ' . $e->getMessage());
        }
        if ($previousRound !== null) {
            ReviewMeta::linkNext($previousRound, $approval);
        }

        if ($isFieldPatch && $mode === 'human' && ($config['reversible_skip'] ?? true) !== false) {
            return $this->decided($this->approvals->decideAutomatically($approval, 'approved', 'reversible_fields'), $context, $path);
        }
        if ($mode === 'auto') {
            return $this->decided($this->approvals->decideAutomatically($approval, 'approved', 'auto'), $context, $path);
        }

        if ($stored !== null && $mode === 'jev') {
            if ($stored['held_reason'] === null) {
                $note = sprintf('JEV: approved automatically (probability %s, level %s).', $stored['jev']['probability'], $stored['jev']['min_confidence']);

                return $this->decided($this->approvals->decideAutomatically($approval, 'approved', 'jev', ['jev' => $stored['jev']], $note), $context, $path);
            }

            // Never rejected automatically in this mode: a person decides, with the reason shown in the inbox.
            return $this->pause($approval, $config, $title, $expiresAt, $slaSeconds);
        }

        $usage = null;
        if ($mode === 'jev') {
            $verdict = $this->judge($config, $envelope, $title);
            $usage = $verdict->usage;
            $min = $this->minConfidence($config);
            $jevMeta = ['jev' => $verdict->toArray() + ['min_confidence' => $min]];
            if ($verdict->decides($min)) {
                $decision = $verdict->verdict === JevVerdict::PASS ? 'approved' : 'rejected';

                return $this->withUsage($this->decided($this->approvals->decideAutomatically($approval, $decision, 'jev', $jevMeta, $this->reasonNote($verdict)), $context, $path), $usage);
            }
            if ((string) data_get($config, 'jev.on_unsure', 'human') === 'reject') {
                return $this->withUsage($this->decided($this->approvals->decideAutomatically($approval, 'rejected', 'jev', $jevMeta, $this->reasonNote($verdict)), $context, $path), $usage);
            }
            // Not sure enough: a person decides, with the verdict shown in the inbox.
            $diff = (array) $approval->diff_data;
            // `handed_at` starts the response clock of the person (AgentApprovalService::stopClock()).
            $diff['workflow_node'] = array_merge((array) ($diff['workflow_node'] ?? []), $jevMeta + ['handed_to' => 'human', 'handed_at' => now()->toIso8601String()]);
            $approval->forceFill(['diff_data' => $diff])->save();
        }

        return $this->withUsage($this->pause($approval, $config, $title, $expiresAt, $slaSeconds), $usage);
    }

    /**
     * Pause the run until a person decides; record the assignees and SLA (ApprovalAssignment), notify the assignees
     * (else the reviewer permission holders) and queue the expiry.
     *
     * @param array<string, mixed> $config
     */
    protected function pause(AgentApproval $approval, array $config, string $title, ?CarbonImmutable $expiresAt, ?int $slaSeconds = null): StepRunResult
    {
        $notified = $this->assignment->open(
            $approval,
            ApprovalAssignment::assigneeUuids($config['assignees'] ?? null),
            $slaSeconds,
            $this->reviewerPermission($config),
            'workflow.approval.requested',
            ['approval_id' => (int) $approval->id, 'title' => mb_substr($title, 0, 200)],
            ($config['notify'] ?? true) !== false,
            true,
        );
        $this->gate->scheduleExpiry($approval, $expiresAt);

        // The executor stores this result as the node's step result; the node finds the approval there on resume.
        // `rejected` is a strict output: should anything resume the run without pointing it back at this node, it can
        // only take the rejected path, never the approved one.
        return StepRunResult::paused(WorkflowApprovalGate::PAUSED_STATUS, [
            'approval_id' => (int) $approval->id,
            'mode' => data_get($approval->diff_data, 'workflow_node.mode'),
            'notified' => count($notified),
        ], [
            ['level' => 'info', 'message' => "approval [{$approval->id}] waits for a person"],
        ])->withOutput('rejected');
    }

    /**
     * The node runs again after the decision (AgentApprovalService pointed the run back at it).
     */
    protected function afterDecision(AgentApproval $approval, WorkflowContext $context, string $path): StepRunResult
    {
        $output = match (true) {
            $approval->status === 'approved' && $approval->apply_outcome === 'applied' => 'approved',
            $approval->status === 'rejected' => 'rejected',
            $approval->status === 'expired' => 'expired',
            $approval->status === 'changes_requested' => 'changes_requested',
            default => null,
        };

        if ($output === null) {
            // Still undecided (or approved but not applied): keep waiting.
            return StepRunResult::paused(WorkflowApprovalGate::PAUSED_STATUS, ['approval_id' => (int) $approval->id])->withOutput('rejected');
        }

        $context->setVarPath($path, $this->decisionVars($approval, $output));

        return StepRunResult::success(['approval_id' => (int) $approval->id, 'decision' => $output])->withOutput($output);
    }

    /**
     * Route an automatic decision made during this run.
     *
     * @param array{approval: AgentApproval, outcome: string} $result
     */
    protected function decided(array $result, WorkflowContext $context, string $path): StepRunResult
    {
        $approval = $result['approval'];
        if ($result['outcome'] === 'approval_rejected') {
            $context->setVarPath($path, $this->decisionVars($approval, 'rejected'));

            return StepRunResult::success(['approval_id' => (int) $approval->id, 'decision' => 'rejected'])->withOutput('rejected');
        }
        if ($result['outcome'] !== 'applied') {
            return StepRunResult::failed("approval: the approved change could not be applied ({$result['outcome']})", [], ['approval_id' => (int) $approval->id]);
        }

        $context->setVarPath($path, $this->decisionVars($approval, 'approved'));

        return StepRunResult::success(['approval_id' => (int) $approval->id, 'decision' => 'approved'])->withOutput('approved');
    }

    /**
     * @return array<string, mixed>
     */
    protected function decisionVars(AgentApproval $approval, string $decision): array
    {
        $approval->loadMissing('artifact');
        $meta = (array) data_get($approval->diff_data, 'workflow_node', []);
        $vars = [
            'decision' => $decision,
            'decided_by' => $meta['decided_by'] ?? null,
            'approval_id' => (int) $approval->id,
            'mode' => $meta['mode'] ?? null,
            'payload' => $approval->artifact?->payload,
            'edits' => $meta['edits'] ?? [],
            'note' => $approval->reviewer_note,
            'reviewer_id' => $approval->reviewed_by_user_id,
            'jev' => $meta['jev'] ?? null,
            'round' => ReviewMeta::roundOf($approval),
            // The declared reject reason the reviewer picked (null when none): route on it with a decision node.
            'reason' => is_string($meta['reject_reason'] ?? null) ? $meta['reject_reason'] : null,
        ];
        if ($decision !== 'changes_requested') {
            return $vars;
        }

        // What the drafting node reads through its `revision` field (e.g. `{{ vars.approval }}`).
        $revision = ReviewMeta::revision($approval);

        return $vars + [
            'feedback' => $revision['feedback'],
            'added_info' => $revision['added_info'],
            'options' => $revision['options'],
            'previous_payload' => $revision['previous_payload'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function envelope(array $config, WorkflowContext $context): array
    {
        $review = $this->evalField($config, 'review', $context);
        if ($review !== null) {
            if (!is_array($review) || !is_string($review['artifact_type'] ?? null) || !is_array($review['payload'] ?? null)) {
                throw new InvalidArgumentException('review must be an envelope with artifact_type and payload');
            }
            if (str_starts_with($review['artifact_type'], 'workflow.')) {
                throw new InvalidArgumentException('a review envelope may not use a workflow.* artifact type');
            }

            return $review;
        }

        $model = $config['subject_model'] ?? null;
        $changes = $this->evalField($config, 'changes', $context);
        if (is_string($model) && $model !== '' && $changes !== null) {
            return $this->fieldPatchEnvelope(ltrim($model, '\\'), $this->evalField($config, 'subject_id', $context), $changes);
        }

        $payload = $this->evalField($config, 'payload', $context);

        return [
            'artifact_type' => AgentApprovalService::WORKFLOW_APPROVAL_TYPE,
            'payload' => [
                'summary' => $this->renderTitle($config, $context),
                'data' => $payload,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldPatchEnvelope(string $model, mixed $id, mixed $changes): array
    {
        if (!is_array($changes) || $changes === [] || array_is_list($changes)) {
            throw new InvalidArgumentException('changes must be a map of field => value');
        }
        $fields = array_map('strval', array_keys($changes));
        if (!ReversibleFields::allows($model, $fields)) {
            throw new InvalidArgumentException('every changed field must be on the reversible-fields list; use a module node for other writes');
        }
        foreach ($changes as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('a reversible field takes a single value');
            }
        }

        /** @var Model|null $record */
        $record = is_scalar($id) && $id !== '' ? $model::query()->find($id) : null;
        if ($record === null) {
            throw new InvalidArgumentException('the record to change was not found');
        }

        $original = [];
        foreach ($fields as $field) {
            $original[$field] = $record->getAttribute($field);
        }

        return [
            'artifact_type' => AgentApprovalService::WORKFLOW_FIELD_PATCH_TYPE,
            'subject_type' => $record->getMorphClass(),
            'subject_id' => $record->getKey(),
            'original_data_snapshot' => json_decode((string) json_encode($original), true),
            'payload' => [
                'summary' => 'Change ' . implode(', ', $fields),
                'changes' => $changes,
            ],
        ];
    }

    /**
     * Editable payload paths: from the node config and the envelope; a plain name that is not a payload key but is a
     * key of `payload.proposed_change` becomes `proposed_change.<name>`.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envelope
     * @return list<string>
     */
    protected function editableFields(array $config, array $envelope): array
    {
        return ReviewMeta::editableFields(
            array_merge((array) ($config['editable_fields'] ?? []), (array) ($envelope['editable_fields'] ?? [])),
            $envelope,
        );
    }

    /**
     * Declared rewrite options plus, with `revision_model_choice: true`, the model option (currently selectable models).
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    protected function revisionOptions(array $config): array
    {
        $options = is_array($config['revision_options'] ?? null) ? array_values($config['revision_options']) : [];
        if (filter_var($config['revision_model_choice'] ?? false, FILTER_VALIDATE_BOOLEAN) && ($model = ReviewMeta::modelOption()) !== null) {
            $options[] = $model;
        }

        return ReviewMeta::revisionOptions($options);
    }

    /**
     * The approval of the previous visit when the reviewer asked for a rewrite then (`vars.<storeAs>.decision`).
     */
    protected function previousRound(WorkflowContext $context, string $path): ?AgentApproval
    {
        $previous = $context->getVarPath($path);
        if (!is_array($previous) || ($previous['decision'] ?? null) !== 'changes_requested' || !is_numeric($previous['approval_id'] ?? null)) {
            return null;
        }

        return AgentApproval::query()->find((int) $previous['approval_id']);
    }

    /**
     * Whether the author wired the `changes_requested` output (a rewrite needs somewhere to go).
     */
    protected function hasRevisionEdge(StepDefinition $step): bool
    {
        if ($step->id === null) {
            return false;
        }

        // The run's version, never the draft rows (RunGraph).
        return \Cms\Core\Services\Workflow\Versioning\RunGraph::edgesFrom((int) $step->id)
            ->contains(fn ($edge) => self::edgeHandle($edge->condition) === 'changes_requested');
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envelope
     */
    protected function judge(array $config, array $envelope, string $title): JevVerdict
    {
        $criteria = (string) data_get($config, 'jev.criteria', '');
        if (trim($criteria) === '') {
            $criteria = (string) config('core.workflow_nodes.human.jev.default_criteria', 'The proposal is accurate, safe to publish and fit for the site.');
        }

        try {
            return JevJudgeResolver::judge()->judge(new JevRequest(
                criteria: $criteria,
                content: ['title' => $title, 'payload' => $envelope['payload'], 'before' => $envelope['original_data_snapshot'] ?? null],
                lane: 'workflow_approval',
                mode: 'active',
            ));
        } catch (Throwable $e) {
            report($e);

            return JevVerdict::unavailable('unavailable', 'judge_error');
        }
    }

    /**
     * The verdict an earlier step stored (`jev.verdict_from`) and why a person decides (null = JEV may approve).
     *
     * @param array<string, mixed> $config
     * @return array{jev: array{verdict: string|null, probability: float|null, min_confidence: float}, held_reason: string|null}
     */
    protected function storedVerdict(StepDefinition $step, array $config, WorkflowContext $context, string $mode, string $artifactType): array
    {
        try {
            $raw = $this->engine()->evaluate((string) WorkflowAutoApproval::verdictFrom($config), $context, false);
            $verdict = $this->producedThisRun((string) WorkflowAutoApproval::verdictFrom($config), $raw, $context)
                ? WorkflowAutoApproval::normalizeVerdict($raw)
                : null;
        } catch (Throwable) {
            $verdict = null;
        }
        $min = WorkflowAutoApproval::minConfidence($config);
        $jev = ['verdict' => $verdict['verdict'] ?? null, 'probability' => $verdict['probability'] ?? null, 'min_confidence' => $min];

        if ($mode !== 'jev') {
            return ['jev' => $jev, 'held_reason' => 'learning'];
        }

        $reason = WorkflowAutoApproval::heldReason($verdict, $this->holds($config, $context), $min);
        if ($reason === null) {
            $service = app(WorkflowAutoApproval::class);
            $provider = $service->providerFor(null, WorkflowApprovalGate::stepCode($step), $config, [], $artifactType);
            if ($service->paused($this->projectKey($step), WorkflowApprovalGate::stepCode($step), $provider)) {
                $reason = 'paused';
            }
        }

        return ['jev' => $jev, 'held_reason' => $reason];
    }

    /**
     * Whether $raw is the verdict a JEV judge step of the run's graph stored at $verdictFrom during this run: the
     * producer ran successfully and its step result agrees with what the path holds now. Without a run (or a verified
     * version graph) nothing is trusted.
     */
    protected function producedThisRun(string $verdictFrom, mixed $raw, WorkflowContext $context): bool
    {
        $instance = ($id = $this->instanceId($context)) !== null ? \Cms\Core\Models\Workflows\WorkflowInstance::query()->find($id) : null;
        if ($instance === null) {
            return false;
        }
        try {
            // The run's sealed version graph, never the draft rows.
            $steps = \Cms\Core\Services\Workflow\Versioning\RunGraph::forInstance($instance)['steps'];
        } catch (Throwable) {
            return false;
        }
        $rows = $steps->map(static fn ($s): array => [
            'step_code' => (string) $s->step_code, 'step_type' => (string) $s->step_type,
            'step_config' => is_array($s->step_config) ? $s->step_config : [],
        ])->values()->all();

        foreach (WorkflowAutoApproval::verdictProducers($verdictFrom, $rows) as $code) {
            $state = $context->getStep($code);
            $result = $state?->getResult();
            // The executor keeps a runner's data under `payload` (next to `retry`).
            $result = is_array($result) && is_array($result['payload'] ?? null) && !array_key_exists('verdict', $result) ? $result['payload'] : $result;
            if ($state !== null && $state->getStatus() === 'success' && WorkflowAutoApproval::matchesProducerResult($raw, $result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `jev.hold_if`: truthy holds the item for a person; an expression that cannot be read holds it too.
     *
     * @param array<string, mixed> $config
     */
    protected function holds(array $config, WorkflowContext $context): bool
    {
        $expression = data_get($config, 'jev.hold_if');
        if (!is_string($expression) || trim($expression) === '') {
            return false;
        }

        try {
            return (bool) $this->engine()->evaluate($expression, $context, true);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function minConfidence(array $config): float
    {
        $value = data_get($config, 'jev.min_confidence');
        $value = is_numeric($value) ? (float) $value : (float) config('core.workflow_nodes.human.jev.min_confidence', 0.8);

        // Never below 0.5: a coin flip may not decide.
        return max(0.5, min(1.0, $value));
    }

    protected function reasonNote(JevVerdict $verdict): string
    {
        return mb_substr('JEV: ' . implode('; ', $verdict->reasons), 0, 1000);
    }

    /**
     * @return list<string>
     */
    protected function autoTypes(): array
    {
        return array_values(array_filter((array) config('core.workflow_nodes.human.auto_artifact_types', []), 'is_string'));
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function reviewerPermission(array $config): string
    {
        $permission = $config['reviewer_permission'] ?? null;

        return is_string($permission) && $permission !== '' ? $permission : (string) config('core.workflow_nodes.human.reviewer_permission', 'agentic.approve');
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $envelope
     */
    protected function title(array $config, WorkflowContext $context, array $envelope): string
    {
        $title = $this->renderTitle($config, $context);
        if ($title === '') {
            $title = is_string(data_get($envelope, 'payload.summary')) ? (string) data_get($envelope, 'payload.summary') : 'Workflow approval';
        }

        return mb_substr($title, 0, 500);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function renderTitle(array $config, WorkflowContext $context): string
    {
        $title = $config['title'] ?? '';

        return is_string($title) && $title !== '' ? trim((new TemplateRenderer())->renderStringSafe($title, $context)) : '';
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    protected function withUsage(StepRunResult $result, ?array $usage): StepRunResult
    {
        return $usage !== null ? $result->withUsage($usage) : $result;
    }
}
