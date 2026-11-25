<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Workflows\Runtime\Runners\Ai\AiUsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Agents\AgentToolCall;
use Cms\Core\Agents\Runtime\AgentRunner;
use Cms\Core\Agents\Runtime\ToolExecutionTimeout;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Services\Agentic\AgentRunControlService;
use Cms\Core\Services\Agentic\AgentApprovalDiffBuilder;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Human\ApprovalAssignment;
use Cms\Core\Workflows\Runtime\Runners\Human\ReviewMeta;
use Cms\Core\Workflows\Runtime\Runners\Human\WorkflowApprovalGate;

/**
 * Executes an AI agent action step within a workflow.
 *
 * Invokes a registered agent tool via the AgentToolRegistry, manages the full agent run
 * lifecycle (creation, tool resolution, authorization, execution), handles approval flows,
 * and stores results as agent artifacts.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentActionRunner implements StepRunner
{
    /**
     * Initialize the runner with agent runtime, tool registry, and control dependencies.
     *
     * @param AgentRunner                $agentRunner         Manages agent state hydration and tool authorization.
     * @param AgentToolRegistry          $toolRegistry        Registry for discovering and resolving agent tools.
     * @param AgentRunControlService     $runControlService   Handles operator stop/cancel requests during execution.
     * @param AgentApprovalDiffBuilder   $approvalDiffBuilder Builds diff data for approval artifacts.
     * @param ConfigRepository           $config              Application configuration repository.
     * @param WorkflowExpressionEngine   $engine              Expression engine for evaluating step config values.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected AgentRunner $agentRunner,
        protected AgentToolRegistry $toolRegistry,
        protected AgentRunControlService $runControlService,
        protected AgentApprovalDiffBuilder $approvalDiffBuilder,
        protected ConfigRepository $config,
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine(),
    ) {
    }

    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $result = $this->runAction($step, $context);

        // An agent tool call makes no model call of its own. When the tool reported nothing, record zero spend so the
        // budget gate does not treat it as unknown spend (which would stop every later AI step under a cost cap).
        // Tools that call a model through AiTaskRuntime are still metered by its ledger and monthly budget.
        if ($result->usage() === null) {
            $toolName = is_string($step->stepConfig['toolName'] ?? null) ? $step->stepConfig['toolName'] : 'unknown';
            $usage = AiUsageMeter::none('agent_tool:' . $toolName);
            // A tool that spends through AiTaskRuntime may say what it spent: `usage` (same shape) or `cost_usd`.
            $output = data_get($result->resultData, 'output') ?? data_get($result->resultData, 'payload.output');
            if (is_array($output) && is_array($output['usage'] ?? null)) {
                $usage = array_replace($usage, $output['usage']);
            } elseif (is_array($output) && is_numeric($output['cost_usd'] ?? null)) {
                $usage['cost_usd'] = (float) $output['cost_usd'];
                $usage['calls'] = (float) $output['cost_usd'] > 0 ? 1 : 0;
            }
            $result->withUsage($usage);
        }

        return $result;
    }

    protected function runAction(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $cfg = $step->stepConfig ?? [];
        $profileSlug = $cfg['agentProfileSlug'] ?? null;
        $toolName = $cfg['toolName'] ?? null;
        $toolClass = $cfg['toolClass'] ?? null;
        $storeAs = $cfg['storeAs'] ?? null;

        if (!is_string($profileSlug) || $profileSlug === '') {
            return StepRunResult::failed('ai_action: agentProfileSlug is required');
        }

        if (!is_string($toolName) || $toolName === '') {
            return StepRunResult::failed('ai_action: toolName is required');
        }

        if ($storeAs !== null && (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.'))) {
            return StepRunResult::failed('ai_action: storeAs must start with vars.');
        }

        // Checked before the run starts; WorkflowContext refuses the write itself as well.
        if (is_string($storeAs) && WorkflowContext::isRuntimePath(substr($storeAs, 5))) {
            return StepRunResult::failed('ai_action: storeAs may not write __runtime');
        }

        // Back here because a reviewer rejected this step's proposal and the author wired `rejected`: route it, never
        // call the tool again.
        $rejected = $this->rejectedProposal($step, $context);
        if ($rejected !== null) {
            return $this->routeRejected($rejected, $cfg, $context);
        }

        try {
            $this->discoverTool($toolClass, $cfg['moduleScope'] ?? null);
        } catch (\Throwable $e) {
            return StepRunResult::failed('ai_action: ' . $e->getMessage());
        }

        $profile = AgentProfile::query()->where('slug', $profileSlug)->first();
        if (!$profile) {
            return StepRunResult::failed("ai_action: agent profile [{$profileSlug}] not found");
        }

        // Back here after a reviewer asked for a rewrite of this step's proposal: the tool gets the revision.
        $previousRound = $this->changesRequested($step, $context);
        $revision = $previousRound !== null ? ReviewMeta::revision($previousRound) : null;

        $workflowInstanceId = $context->getVarPath('__runtime.instance_id');
        $targetType = $this->resolveRootType($context->getRoot(), $cfg['moduleScope'] ?? null);

        $run = AgentRun::create([
            ...AgentRun::delegationFor($workflowInstanceId, $targetType),
            'agent_profile_id' => $profile->id,
            'workflow_instance_id' => $workflowInstanceId,
            'parent_workflow_step_id' => $context->getVarPath('__runtime.current_instance_step_id'),
            'source_run_id' => is_numeric($cfg['sourceRunId'] ?? null) ? (int) $cfg['sourceRunId'] : null,
            'trigger_mode' => is_string($cfg['triggerMode'] ?? null) ? $cfg['triggerMode'] : null,
            'trigger_reference_type' => is_string($cfg['triggerReferenceType'] ?? null) ? $cfg['triggerReferenceType'] : null,
            'trigger_reference_id' => isset($cfg['triggerReferenceId']) ? (string) $cfg['triggerReferenceId'] : null,
            'environment' => $cfg['environment'] ?? $this->config->get('core.agentic.discovery.default_environment'),
            'tenant_id' => $cfg['tenantId'] ?? $context->getVarPath('__runtime.tenant_id'),
            'module_scope' => $cfg['moduleScope'] ?? Str::before($toolName, '.'),
            'target_type' => $targetType,
            'target_id' => $this->resolveRootId($context->getRoot()),
            'status' => 'running',
            'input_goal' => $this->resolveGoal($cfg['goal'] ?? null, $toolName, $context),
            'context_snapshot' => $this->safeContextSnapshot($context),
            'budget_config' => $profile->budget_config ?? [],
            'budget_usage' => ['steps' => 0, 'tool_calls' => 0, 'tokens' => 0],
            'started_at' => now(),
        ]);

        $state = $this->agentRunner->hydrateState($run, 1);
        $agentStep = AgentRunStep::create([
            'run_id' => $run->id,
            'step_index' => 1,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => $toolName,
            'reasoning_summary' => 'Deterministic ai_action step requested direct tool execution.',
            'snapshot_state' => $state->toArray(),
            'status' => 'pending',
        ]);

        if ($this->runControlService->shouldStopRun($run)) {
            return $this->cancelRun(
                $run,
                $agentStep,
                'Operator stop acknowledged before tool execution.'
            );
        }

        $definitionData = $this->toolRegistry->definition($toolName);
        if (!$definitionData) {
            return $this->failRun(
                $run,
                $agentStep,
                'tool_definition_not_found',
                "ai_action: tool definition [{$toolName}] not found"
            );
        }

        $authorization = $this->agentRunner->authorizeTool($state, $toolName);
        if (!$authorization->allowed) {
            return $this->failRun(
                $run,
                $agentStep,
                $authorization->reason ?? 'tool_not_authorized',
                'ai_action: tool execution is not authorized'
            );
        }

        $bindingData = $this->agentRunner->resolveBinding(
            $state,
            $toolName,
            is_string($cfg['policyScope'] ?? null) ? $cfg['policyScope'] : null,
        );

        if (!$bindingData) {
            return $this->failRun(
                $run,
                $agentStep,
                'tool_binding_not_found',
                "ai_action: no executable binding found for [{$toolName}]"
            );
        }

        $definition = $this->toolRegistry->persistDefinition($toolName);
        $binding = collect($this->toolRegistry->persistBindings($toolName))
            ->first(fn ($candidate) => $candidate->binding_target === $bindingData->bindingTarget
                && $candidate->binding_type === $bindingData->bindingType
                && $candidate->module_scope === $bindingData->moduleScope
                && $candidate->environment === $bindingData->environment
                && $candidate->tenant_id === $bindingData->tenantId
                && $candidate->policy_scope === $bindingData->policyScope);

        if (!$definition || !$binding) {
            return $this->failRun(
                $run,
                $agentStep,
                'tool_persistence_failed',
                "ai_action: failed to persist tool metadata for [{$toolName}]"
            );
        }

        try {
            $toolInput = $this->resolveToolInput($cfg['toolInput'] ?? [], $context);
        } catch (\Throwable $e) {
            return $this->failRun(
                $run,
                $agentStep,
                'tool_input_resolution_failed',
                'ai_action: ' . $e->getMessage()
            );
        }

        $toolCall = AgentToolCall::create([
            'run_id' => $run->id,
            'run_step_id' => $agentStep->id,
            'tool_definition_id' => $definition->id,
            'tool_binding_id' => $binding->id,
            'input_payload' => $toolInput,
            'retry_scope' => 'pipeline',
            'status' => 'running',
            'retry_count' => 0,
            'idempotency_key' => $this->buildIdempotencyKey($run, $agentStep, $toolName),
            'started_at' => now(),
        ]);

        $callStartedAt = CarbonImmutable::now();

        try {
            $callable = $this->toolRegistry->resolveCallable($bindingData);
            $safeToolInput = array_diff_key($toolInput, array_flip([
                'input', 'state', 'context', 'run', 'workflowStep', 'root', 'goal', 'actor', 'revision',
            ]));
            $actor = $run->currentDelegate();
            $rawOutput = ToolExecutionTimeout::execute(
                fn () => App::call($callable, array_merge($safeToolInput, [
                    'input' => $toolInput,
                    'state' => $state,
                    'context' => $context,
                    'run' => $run,
                    'workflowStep' => $step,
                    'root' => $context->getRoot(),
                    'goal' => $run->input_goal,
                    'actor' => $actor,
                    // Only a tool whose method declares `$revision` receives it; App::call drops it for the others.
                    'revision' => $revision,
                ])),
                $definitionData->timeoutSeconds,
            );
            $output = $this->snapshot($rawOutput);
        } catch (\Throwable $e) {
            $toolCall->fill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'duration_ms' => $this->calculateDurationMs($callStartedAt, CarbonImmutable::now()),
                'finished_at' => now(),
            ])->save();

            return $this->failRun(
                $run,
                $agentStep,
                'tool_execution_failed',
                'ai_action: ' . $e->getMessage()
            );
        }

        if ($this->runControlService->shouldStopRun($run)) {
            $toolCall->fill([
                'status' => 'cancelled',
                'output_payload' => $output,
                'error_message' => 'Operator stop acknowledged after tool execution.',
                'duration_ms' => $this->calculateDurationMs($callStartedAt, CarbonImmutable::now()),
                'finished_at' => now(),
            ])->save();

            return $this->cancelRun(
                $run,
                $agentStep,
                'Operator stop acknowledged after tool execution.',
                [
                    'agent_run_id' => $run->id,
                    'agent_run_step_id' => $agentStep->id,
                    'tool_call_id' => $toolCall->id,
                    'tool_name' => $toolName,
                ]
            );
        }

        if ($authorization->requiresApproval) {
            return $this->completeAwaitingApproval(
                $step,
                $context,
                $run,
                $agentStep,
                $toolCall,
                $toolName,
                $output,
                $cfg,
                $callStartedAt,
                $previousRound,
            );
        }

        $artifact = AgentArtifact::create($this->buildReadOnlyArtifactPayload($run, $output, $cfg));

        $toolCall->fill([
            'output_artifact_id' => $artifact->id,
            'output_payload' => $output,
            'status' => 'completed',
            'duration_ms' => $this->calculateDurationMs($callStartedAt, CarbonImmutable::now()),
            'finished_at' => now(),
        ])->save();

        $agentStep->fill([
            'observation_summary' => $this->summarizeOutput($toolName, $output),
            'observation_artifact_id' => $artifact->id,
            'status' => 'completed',
        ])->save();

        $run->fill([
            'status' => 'completed',
            'working_memory' => $run->working_memory ?? [],
            'reasoning_summary' => 'Deterministic ai_action workflow step completed one read-only tool execution.',
            'budget_usage' => ['steps' => 1, 'tool_calls' => 1, 'tokens' => 0],
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();

        if (is_string($storeAs)) {
            $context->setVarPath(substr($storeAs, 5), $output);
        }

        return StepRunResult::success([
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $agentStep->id,
            'tool_call_id' => $toolCall->id,
            'artifact_id' => $artifact->id,
            'tool_name' => $toolName,
            'output' => $output,
        ], [
            ['level' => 'info', 'message' => "ai_action executed tool [{$toolName}]"],
        ]);
    }

    protected function discoverTool(?string $toolClass, ?string $moduleScope = null): void
    {
        if (!is_string($toolClass) || $toolClass === '') {
            return;
        }

        $this->toolRegistry->syncDiscoveredService(
            $toolClass,
            moduleScope: $moduleScope,
            environment: (string) $this->config->get('core.agentic.discovery.default_environment')
        );
    }

    protected function resolveGoal(mixed $goal, string $toolName, WorkflowContext $context): string
    {
        if (is_string($goal) && $goal !== '') {
            return (string) $this->engine->evaluate($goal, $context, true);
        }

        return "Execute workflow ai_action for {$toolName}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeContextSnapshot(WorkflowContext $context): array
    {
        try {
            return $context->toArray();
        } catch (\Throwable) {
            return [
                'root' => [
                    '__class' => $context->getRoot()::class,
                    '__id' => $this->resolveRootId($context->getRoot()),
                ],
                'vars' => $context->getVars(),
                'steps' => [],
                'trigger' => $context->getTrigger()->toArray(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveToolInput(mixed $mapping, WorkflowContext $context): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $resolved = [];
        foreach ($mapping as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $resolved[$key] = $this->resolveInputValue($value, $context);
        }

        return $resolved;
    }

    protected function resolveInputValue(mixed $value, WorkflowContext $context): mixed
    {
        if (is_string($value)) {
            return $this->engine->evaluate($value, $context, true);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->resolveInputValue($item, $context), $value);
        }

        return $value;
    }

    protected function failRun(
        AgentRun $run,
        AgentRunStep $agentStep,
        string $terminalReason,
        string $message,
    ): StepRunResult {
        $agentStep->fill([
            'status' => 'failed',
            'terminal_reason' => $terminalReason,
            'observation_summary' => $message,
        ])->save();

        $run->fill([
            'status' => 'failed',
            'terminal_reason' => $terminalReason,
            'budget_usage' => ['steps' => 1, 'tool_calls' => 0, 'tokens' => 0],
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();

        return StepRunResult::failed($message, [
            ['level' => 'error', 'message' => $message],
        ], ['terminal_reason' => $terminalReason, 'agent_run_id' => $run->id]);
    }

    protected function cancelRun(
        AgentRun $run,
        AgentRunStep $agentStep,
        string $message,
        array $resultData = [],
    ): StepRunResult {
        $this->runControlService->acknowledgeStop($run, $message);
        $agentStep->refresh();

        return StepRunResult::cancelled($message, array_merge([
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $agentStep->id,
        ], $resultData), [
            ['level' => 'warning', 'message' => $message],
        ]);
    }

    protected function completeAwaitingApproval(
        StepDefinition $step,
        WorkflowContext $context,
        AgentRun $run,
        AgentRunStep $agentStep,
        AgentToolCall $toolCall,
        string $toolName,
        mixed $output,
        array $cfg,
        CarbonImmutable $callStartedAt,
        ?AgentApproval $previousRound = null,
    ): StepRunResult {
        if ($this->isPlainResultWithoutProposal($output)) {
            return $this->completeWithoutProposal($context, $run, $agentStep, $toolCall, $toolName, $output, $cfg, $callStartedAt);
        }

        if (!is_array($output) || !isset($output['artifact_type'], $output['payload'])) {
            $toolCall->fill([
                'status' => 'failed',
                'error_message' => 'Approval-required tool must return an artifact envelope.',
                'duration_ms' => $this->calculateDurationMs($callStartedAt, CarbonImmutable::now()),
                'finished_at' => now(),
            ])->save();

            return $this->failRun(
                $run,
                $agentStep,
                'invalid_approval_artifact_envelope',
                'ai_action: approval-required tool must return an artifact envelope'
            );
        }

        $artifact = AgentArtifact::create([
            'run_id' => $run->id,
            'artifact_type' => (string) $output['artifact_type'],
            'subject_type' => data_get($output, 'subject_type', $run->target_type),
            'subject_id' => data_get($output, 'subject_id', $run->target_id),
            'source_snapshot_ref' => data_get($output, 'source_snapshot_ref', 'workflow-instance-step:' . $run->parent_workflow_step_id),
            'original_data_snapshot' => data_get($output, 'original_data_snapshot', Arr::get($run->context_snapshot, 'root.attributes')),
            'payload' => (array) data_get($output, 'payload', []),
            'storage_driver' => 'database',
            'version' => 1,
            'render_hint' => data_get($output, 'render_hint', $cfg['renderHint'] ?? 'json_view'),
            'apply_contract_version' => (string) data_get($output, 'apply_contract_version', 'v1'),
            'checksum' => hash('sha256', (string) json_encode($output)),
            'size_bytes' => strlen((string) json_encode($output)),
        ]);

        $diff = $this->approvalDiffBuilder->build($artifact);
        $diff['workflow_node'] = $this->proposalMeta($step, $run, $artifact, $output, $cfg, $previousRound);
        $approval = $run->approvals()->create([
            'artifact_id' => $artifact->id,
            'status' => 'pending',
            'diff_data' => $diff,
        ]);
        if ($previousRound !== null) {
            ReviewMeta::linkNext($previousRound, $approval);
        }
        $this->openAssignment($approval, $artifact, $toolName, $cfg);

        $toolCall->fill([
            'output_artifact_id' => $artifact->id,
            'output_payload' => $this->extractToolCallPayload($output),
            'status' => 'completed',
            'duration_ms' => $this->calculateDurationMs($callStartedAt, CarbonImmutable::now()),
            'finished_at' => now(),
        ])->save();

        $agentStep->fill([
            'observation_summary' => data_get($artifact->payload, 'summary', "Tool [{$toolName}] produced a staged proposal and is awaiting approval."),
            'observation_artifact_id' => $artifact->id,
            'status' => 'awaiting_approval',
        ])->save();

        $run->fill([
            'status' => 'awaiting_approval',
            'working_memory' => $run->working_memory ?? [],
            'reasoning_summary' => 'Deterministic ai_action workflow step produced a staged proposal and is awaiting approval.',
            'budget_usage' => ['steps' => 1, 'tool_calls' => 1, 'tokens' => 0],
            'last_checkpoint_at' => now(),
        ])->save();

        $result = [
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $agentStep->id,
            'tool_call_id' => $toolCall->id,
            'artifact_id' => $artifact->id,
            'approval_id' => $approval->id,
            'tool_name' => $toolName,
            'output' => $this->extractToolCallPayload($output),
        ];

        if (is_string($cfg['storeAs'] ?? null)) {
            $context->setVarPath(substr($cfg['storeAs'], 5), $result);
        }

        return StepRunResult::paused('awaiting_approval', $result, [
            ['level' => 'info', 'message' => "ai_action created approval [{$approval->id}] for tool [{$toolName}]"],
        ]);
    }

    /**
     * Assignee and SLA of the proposal (step keys `assignees`, `sla`; every round gets its own clock). Assignees are
     * notified; without assignees nobody is, as before. A step saved before the save check with an unreadable `sla`
     * measures nothing instead of failing the run.
     *
     * @param array<string, mixed> $cfg
     */
    protected function openAssignment(AgentApproval $approval, AgentArtifact $artifact, string $toolName, array $cfg): void
    {
        try {
            $slaSeconds = ApprovalAssignment::slaSeconds($cfg['sla'] ?? null);
        } catch (\InvalidArgumentException $e) {
            report($e);
            $slaSeconds = null;
        }
        $summary = data_get($artifact->payload, 'summary');

        app(ApprovalAssignment::class)->open(
            $approval,
            ApprovalAssignment::assigneeUuids($cfg['assignees'] ?? null),
            $slaSeconds,
            app(\Cms\Core\Services\Agentic\AgentApprovalService::class)->reviewerPermissionOf($approval),
            'workflow.approval.requested',
            ['approval_id' => (int) $approval->id, 'title' => mb_substr(is_string($summary) && $summary !== '' ? $summary : $toolName, 0, 200)],
            true,
            false,
        );
    }

    /**
     * Review facts of a tool proposal (`diff_data.workflow_node`, kind `proposal`): the fields the reviewer may edit and
     * whether a rewrite may be requested. A rewrite needs the tool to say `revisable: true`, the step not to set
     * `allow_revision: false`, a workflow instance to resume, and a round below `max_rounds`.
     *
     * @param array<string, mixed> $output
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    protected function proposalMeta(
        StepDefinition $step,
        AgentRun $run,
        AgentArtifact $artifact,
        array $output,
        array $cfg,
        ?AgentApproval $previousRound,
    ): array {
        $rounds = ReviewMeta::rounds($previousRound, $cfg['max_rounds'] ?? null);
        $revisable = ($output['revisable'] ?? false) === true
            && ($cfg['allow_revision'] ?? true) !== false
            && $run->workflow_instance_id !== null
            && $rounds['round'] < $rounds['max_rounds'];

        return [
            'kind' => 'proposal',
            'step_code' => WorkflowApprovalGate::stepCode($step),
            'instance_id' => is_numeric($run->workflow_instance_id) ? (int) $run->workflow_instance_id : null,
            'editable_fields' => ReviewMeta::editableFields(
                (array) ($output['editable_fields'] ?? []),
                ['artifact_type' => $artifact->artifact_type, 'payload' => (array) $artifact->payload],
            ),
            'revisable' => $revisable,
            'revision_options' => ReviewMeta::revisionOptions($output['revision_options'] ?? []),
            'reject_reasons' => ReviewMeta::rejectReasons($output['reject_reasons'] ?? []),
            'decided_by' => null,
        ] + $rounds;
    }

    /**
     * This step's previous proposal, when the run came back to the step because a reviewer requested changes.
     */
    protected function changesRequested(StepDefinition $step, WorkflowContext $context): ?AgentApproval
    {
        $previous = app(WorkflowApprovalGate::class)->waitingApproval($step, $context);

        return $previous !== null && $previous->status === 'changes_requested' ? $previous : null;
    }

    /**
     * This step's previous proposal, when the run came back to the step because a reviewer rejected it (only happens
     * when the step has a `rejected` edge, see AgentApprovalService::reject()).
     */
    protected function rejectedProposal(StepDefinition $step, WorkflowContext $context): ?AgentApproval
    {
        $previous = app(WorkflowApprovalGate::class)->waitingApproval($step, $context);

        return $previous !== null && $previous->status === 'rejected'
            && data_get($previous->diff_data, 'workflow_node.kind') === 'proposal' ? $previous : null;
    }

    /**
     * Route the `rejected` output: `vars.<storeAs>` = {decision, reason, note, approval_id}.
     *
     * @param array<string, mixed> $cfg
     */
    protected function routeRejected(AgentApproval $approval, array $cfg, WorkflowContext $context): StepRunResult
    {
        $reason = data_get($approval->diff_data, 'workflow_node.reject_reason');
        $decision = [
            'decision' => 'rejected',
            'reason' => is_string($reason) ? $reason : null,
            'note' => $approval->reviewer_note,
            'approval_id' => (int) $approval->id,
        ];
        if (is_string($cfg['storeAs'] ?? null)) {
            $context->setVarPath(substr($cfg['storeAs'], 5), $decision);
        }

        return StepRunResult::success($decision, [
            ['level' => 'info', 'message' => "ai_action proposal [{$approval->id}] was rejected"],
        ])->withOutput('rejected');
    }

    /**
     * Whether an approval-required tool returned a plain result instead of a proposal: null, a scalar,
     * or an array carrying neither `artifact_type` nor `payload`. An array with only one of the two
     * keys, or any other value, is a malformed envelope and still fails the step.
     */
    protected function isPlainResultWithoutProposal(mixed $output): bool
    {
        if ($output === null || is_scalar($output)) {
            return true;
        }

        return is_array($output)
            && !array_key_exists('artifact_type', $output)
            && !array_key_exists('payload', $output);
    }

    /**
     * Nothing to propose is not a failure: close the agent run and complete the step with the
     * `no_proposal` output, so the graph can route it (or end the branch when no edge takes it).
     */
    protected function completeWithoutProposal(
        WorkflowContext $context,
        AgentRun $run,
        AgentRunStep $agentStep,
        AgentToolCall $toolCall,
        string $toolName,
        mixed $output,
        array $cfg,
        CarbonImmutable $callStartedAt,
    ): StepRunResult {
        $toolCall->fill([
            'output_payload' => is_array($output) ? $output : ['value' => $output],
            'status' => 'completed',
            'duration_ms' => $this->calculateDurationMs($callStartedAt, CarbonImmutable::now()),
            'finished_at' => now(),
        ])->save();

        $agentStep->fill([
            'observation_summary' => "Tool [{$toolName}] had nothing to propose.",
            'status' => 'completed',
        ])->save();

        $run->fill([
            'status' => 'completed',
            'terminal_reason' => 'no_proposal',
            'working_memory' => $run->working_memory ?? [],
            'reasoning_summary' => 'Deterministic ai_action workflow step found nothing to propose.',
            'budget_usage' => ['steps' => 1, 'tool_calls' => 1, 'tokens' => 0],
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();

        $result = [
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $agentStep->id,
            'tool_call_id' => $toolCall->id,
            'tool_name' => $toolName,
            'no_proposal' => true,
            'output' => $output,
        ];

        if (is_string($cfg['storeAs'] ?? null)) {
            $context->setVarPath(substr($cfg['storeAs'], 5), $result);
        }

        return StepRunResult::success($result, [
            ['level' => 'info', 'message' => "ai_action tool [{$toolName}] had nothing to propose"],
        ])->withOutput('no_proposal');
    }

    protected function buildIdempotencyKey(AgentRun $run, AgentRunStep $agentStep, string $toolName): string
    {
        return implode(':', [$run->id, $agentStep->step_index, $toolName]);
    }

    /**
     * Resolve a human-readable target_type for the AgentRun record.
     *
     * If the root is a real model (has a key), return its FQCN.
     * Otherwise fall back to the module scope string.
     */
    protected function resolveRootType(Model $root, ?string $fallbackScope = null): string
    {
        if ($root->getKey() !== null) {
            return $root::class;
        }

        return $fallbackScope ?? 'general';
    }

    protected function resolveRootId(Model $root): ?string
    {
        $key = $root->getKey();

        return $key === null ? null : (string) $key;
    }

    protected function summarizeOutput(string $toolName, mixed $output): string
    {
        if (is_scalar($output) || $output === null) {
            return "{$toolName} returned scalar output.";
        }

        if (is_array($output)) {
            return "{$toolName} returned array output with " . count($output) . ' top-level fields.';
        }

        return "{$toolName} returned structured output.";
    }

    protected function calculateDurationMs(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $end->diffInMilliseconds($start, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReadOnlyArtifactPayload(AgentRun $run, mixed $output, array $cfg): array
    {
        return [
            'run_id' => $run->id,
            'artifact_type' => $cfg['artifactType'] ?? 'tool_result',
            'subject_type' => $run->target_type,
            'subject_id' => $run->target_id,
            'source_snapshot_ref' => 'workflow-instance-step:' . $run->parent_workflow_step_id,
            'original_data_snapshot' => Arr::get($run->context_snapshot, 'root.attributes'),
            'payload' => $output,
            'storage_driver' => 'database',
            'version' => 1,
            'render_hint' => $cfg['renderHint'] ?? 'json_view',
            'apply_contract_version' => 'v1',
            'checksum' => hash('sha256', (string) json_encode($output)),
            'size_bytes' => strlen((string) json_encode($output)),
        ];
    }

    protected function extractToolCallPayload(array $output): array
    {
        if (array_key_exists('payload', $output) && is_array($output['payload'])) {
            return $output['payload'];
        }

        return $output;
    }

    protected function snapshot(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return [
                '__class' => $value::class,
                '__id' => $value->getKey(),
                // attributesToArray() honours \$hidden, so password hashes and tokens never reach step results.
                'attributes' => $value->attributesToArray(),
            ];
        }

        if ($value instanceof Collection || $value instanceof EloquentCollection) {
            return $value->map(fn ($item) => $this->snapshot($item))->all();
        }

        return $value;
    }
}
