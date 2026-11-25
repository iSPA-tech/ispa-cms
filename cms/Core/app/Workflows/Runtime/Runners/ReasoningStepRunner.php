<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Agents\AgentToolCall;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Agents\Runtime\AgentRunner;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Services\Agentic\AgentRunControlService;
use Cms\Core\Services\Agentic\AgentApprovalDiffBuilder;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiUsageMeter;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;

/**
 * Multi-step reasoning loop runner for agentic workflow steps.
 *
 * Executes a bounded plan-authorize-execute-observe loop driven by the
 * reasoning engine. Each iteration asks the LLM to plan the next action,
 * authorizes the chosen tool, executes it, records observations, and
 * updates working memory and budget counters. Terminates on budget
 * exhaustion, approval requirement, operator stop, or LLM terminal decision.
 *
 * @see AgentRunner
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0
 */
class ReasoningStepRunner implements StepRunner
{
    protected const MAX_ITERATIONS = 20;

    public function __construct(
        protected AgentRunner $agentRunner,
        protected AgentToolRegistry $toolRegistry,
        protected AgentRunControlService $runControlService,
        protected AgentApprovalDiffBuilder $approvalDiffBuilder,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Reports the reasoning engine's AI usage of this step (tokens and cost of every plan() call) through
     * withUsage(), so per-run budget caps see what `ai_reasoning` spent. An engine that cannot report usage leaves
     * the step without usage (the executor then counts it as unknown spend).
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $engine = app(ReasoningEngineInterface::class);
        $meters = method_exists($engine, 'takeUsage');
        if ($meters) {
            $engine->takeUsage(); // drop usage left over from earlier callers in this process
        }

        $result = $this->runLoop($step, $context);

        $usage = $meters ? $engine->takeUsage() : null;
        if ($meters) {
            $result->withUsage($usage ?? AiUsageMeter::none('agentic_reasoning'));
        }

        return $result;
    }

    protected function runLoop(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $cfg = $step->stepConfig ?? [];
        $profileSlug = $cfg['agentProfileSlug'] ?? null;
        $maxIterations = min((int) ($cfg['maxIterations'] ?? self::MAX_ITERATIONS), self::MAX_ITERATIONS);

        if (!is_string($profileSlug) || $profileSlug === '') {
            return StepRunResult::failed('ai_reasoning: agentProfileSlug is required');
        }

        $profile = AgentProfile::query()->where('slug', $profileSlug)->first();
        if (!$profile) {
            return StepRunResult::failed("ai_reasoning: agent profile [{$profileSlug}] not found");
        }

        $workflowInstanceId = $context->getVarPath('__runtime.instance_id');

        $run = AgentRun::create([
            ...AgentRun::delegationFor($workflowInstanceId, $context->getRoot()::class),
            'agent_profile_id' => $profile->id,
            'workflow_instance_id' => $workflowInstanceId,
            'parent_workflow_step_id' => $context->getVarPath('__runtime.current_instance_step_id'),
            'trigger_mode' => 'reasoning_loop',
            'environment' => $cfg['environment'] ?? $this->config->get('core.agentic.discovery.default_environment'),
            'tenant_id' => $cfg['tenantId'] ?? $context->getVarPath('__runtime.tenant_id'),
            'module_scope' => $cfg['moduleScope'] ?? null,
            'target_type' => $context->getRoot()::class,
            'target_id' => $this->resolveRootId($context->getRoot()),
            'status' => 'running',
            'input_goal' => $cfg['goal'] ?? 'Execute reasoning loop',
            'context_snapshot' => $this->safeContextSnapshot($context),
            'budget_config' => $profile->budget_config ?? [],
            'budget_usage' => ['steps' => 0, 'tool_calls' => 0, 'tokens' => 0],
            'started_at' => now(),
        ]);

        $logs = [];
        $lastOutput = null;

        // L-7: Resume from last checkpoint if the run was previously interrupted
        $startIteration = $this->resolveStartIteration($run);

        for ($iteration = $startIteration; $iteration <= $maxIterations; $iteration++) {
            // Check operator stop
            if ($this->runControlService->shouldStopRun($run)) {
                $this->runControlService->acknowledgeStop($run, 'Operator stop acknowledged during reasoning loop.');
                return StepRunResult::cancelled('Operator stop during reasoning loop.', [
                    'agent_run_id' => $run->id,
                    'iterations_completed' => $iteration - 1,
                ]);
            }

            // Plan: ask the reasoning engine what to do next
            $decision = $this->agentRunner->plan($run, $iteration);

            // Terminal decision — the agent wants to stop
            if ($decision->type === 'terminal') {
                $run->fill([
                    'status' => $decision->name === 'complete' ? 'completed' : 'failed',
                    'terminal_reason' => $decision->terminalReason,
                    'reasoning_summary' => "Reasoning loop terminated after {$iteration} iteration(s): {$decision->terminalReason}",
                    'last_checkpoint_at' => now(),
                    'finished_at' => now(),
                ])->save();

                $status = $decision->name === 'complete' ? 'success' : 'failed';
                return new StepRunResult($status, [
                    'agent_run_id' => $run->id,
                    'iterations' => $iteration,
                    'terminal_reason' => $decision->terminalReason,
                    'last_output' => $lastOutput,
                ], $logs);
            }

            // Tool call decision — authorize and execute
            $toolName = $decision->name;
            $toolInput = $decision->input;

            // Authorize
            $authorization = $this->agentRunner->authorizeTool(
                $this->agentRunner->hydrateState($run, $iteration),
                $toolName,
            );

            if (!$authorization->allowed) {
                $this->recordFailedStep($run, $iteration, $toolName, $authorization->reason ?? 'tool_not_authorized');
                $logs[] = ['level' => 'warning', 'message' => "Tool [{$toolName}] denied: {$authorization->reason}"];
                // Continue loop — let the engine try a different tool
                $this->updateWorkingMemory($run, [
                    'last_error' => "Tool {$toolName} denied: {$authorization->reason}",
                ]);
                continue;
            }

            // If approval required, pause the loop
            if ($authorization->requiresApproval) {
                return $this->pauseForApproval(
                    $run, $step, $context, $iteration, $toolName, $toolInput, $cfg, $logs
                );
            }

            // Resolve binding and execute
            $state = $this->agentRunner->hydrateState($run, $iteration);
            $bindingData = $this->agentRunner->resolveBinding($state, $toolName);

            if (!$bindingData) {
                $this->recordFailedStep($run, $iteration, $toolName, 'tool_binding_not_found');
                $this->updateWorkingMemory($run, ['last_error' => "No binding for {$toolName}"]);
                $logs[] = ['level' => 'warning', 'message' => "No binding for [{$toolName}]"];
                $this->incrementBudget($run, 'tool_failures');
                continue;
            }

            // Execute the tool
            $agentStep = AgentRunStep::create([
                'run_id' => $run->id,
                'step_index' => $iteration,
                'state' => 'ACTION',
                'action_type' => 'tool_call',
                'action_name' => $toolName,
                'reasoning_summary' => "Reasoning engine selected {$toolName}",
                'status' => 'running',
            ]);

            $callStartedAt = CarbonImmutable::now();

            try {
                $callable = $this->toolRegistry->resolveCallable($bindingData);
                $safeToolInput = array_diff_key($toolInput, array_flip([
                    'input', 'state', 'context', 'run', 'workflowStep', 'root', 'goal', 'actor',
                ]));
                $actor = $run->currentDelegate();
                $rawOutput = App::call($callable, array_merge($safeToolInput, [
                    'input' => $toolInput,
                    'state' => $state,
                    'context' => $context,
                    'run' => $run,
                    'workflowStep' => $step,
                    'root' => $context->getRoot(),
                    'goal' => $run->input_goal,
                    'actor' => $actor,
                ]));
                $output = is_array($rawOutput) ? $rawOutput : ['result' => $rawOutput];
            } catch (\Throwable $e) {
                $agentStep->fill([
                    'status' => 'failed',
                    'terminal_reason' => 'tool_execution_failed',
                    'observation_summary' => $e->getMessage(),
                ])->save();

                $this->incrementBudget($run, 'tool_failures');
                $this->updateWorkingMemory($run, ['last_error' => "{$toolName} failed: {$e->getMessage()}"]);
                $logs[] = ['level' => 'error', 'message' => "Tool [{$toolName}] failed: {$e->getMessage()}"];
                continue;
            }

            $durationMs = CarbonImmutable::now()->diffInMilliseconds($callStartedAt, true);

            // Record the successful step with checkpoint state (L-7)
            $agentStep->fill([
                'status' => 'completed',
                'observation_summary' => "{$toolName} returned " . count($output) . ' fields.',
                'snapshot_state' => [
                    'iteration' => $iteration,
                    'tool_name' => $toolName,
                    'working_memory' => $run->working_memory ?? [],
                    'budget_usage' => $run->budget_usage ?? [],
                ],
            ])->save();

            // Update budget
            $this->incrementBudget($run, 'steps');
            $this->incrementBudget($run, 'tool_calls');

            // Update working memory with observation (C-6)
            $this->updateWorkingMemory($run, [
                'last_action' => $toolName,
                'last_output_summary' => array_keys($output),
                'last_error' => null,
                'iteration' => $iteration,
            ]);

            // Save checkpoint on the run (L-7)
            $run->fill(['last_checkpoint_at' => now()])->save();

            $lastOutput = $output;
            $logs[] = ['level' => 'info', 'message' => "Iteration {$iteration}: executed [{$toolName}] ({$durationMs}ms)"];
        }

        // Max iterations reached
        $run->fill([
            'status' => 'completed',
            'terminal_reason' => 'max_iterations_reached',
            'reasoning_summary' => "Reasoning loop completed after {$maxIterations} iterations (limit reached).",
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();

        return StepRunResult::success([
            'agent_run_id' => $run->id,
            'iterations' => $maxIterations,
            'terminal_reason' => 'max_iterations_reached',
            'last_output' => $lastOutput,
        ], $logs);
    }

    protected function pauseForApproval(
        AgentRun $run,
        StepDefinition $step,
        WorkflowContext $context,
        int $iteration,
        string $toolName,
        array $toolInput,
        array $cfg,
        array $logs,
    ): StepRunResult {
        // Execute the tool to get the proposal
        $state = $this->agentRunner->hydrateState($run, $iteration);
        $bindingData = $this->agentRunner->resolveBinding($state, $toolName);

        if (!$bindingData) {
            return $this->failRun($run, 'tool_binding_not_found', "No binding for [{$toolName}]", $logs);
        }

        $agentStep = AgentRunStep::create([
            'run_id' => $run->id,
            'step_index' => $iteration,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => $toolName,
            'reasoning_summary' => "Reasoning engine selected {$toolName} (requires approval)",
            'status' => 'running',
        ]);

        try {
            $callable = $this->toolRegistry->resolveCallable($bindingData);
            $safeToolInput = array_diff_key($toolInput, array_flip([
                'input', 'state', 'context', 'run', 'workflowStep', 'root', 'goal', 'actor',
            ]));
            $actor = $run->currentDelegate();
            $rawOutput = App::call($callable, array_merge($safeToolInput, [
                'input' => $toolInput,
                'state' => $state,
                'context' => $context,
                'run' => $run,
                'workflowStep' => $step,
                'root' => $context->getRoot(),
                'goal' => $run->input_goal,
                'actor' => $actor,
            ]));
            $output = is_array($rawOutput) ? $rawOutput : ['result' => $rawOutput];
        } catch (\Throwable $e) {
            return $this->failRun($run, 'tool_execution_failed', $e->getMessage(), $logs);
        }

        if (!isset($output['artifact_type'], $output['payload'])) {
            return $this->failRun($run, 'invalid_approval_artifact', 'Approval-required tool must return artifact envelope', $logs);
        }

        $artifact = AgentArtifact::create([
            'run_id' => $run->id,
            'artifact_type' => (string) $output['artifact_type'],
            'subject_type' => data_get($output, 'subject_type', $run->target_type),
            'subject_id' => data_get($output, 'subject_id', $run->target_id),
            'source_snapshot_ref' => 'reasoning-loop-step:' . $iteration,
            'original_data_snapshot' => data_get($output, 'original_data_snapshot'),
            'payload' => (array) data_get($output, 'payload', []),
            'storage_driver' => 'database',
            'version' => 1,
            'render_hint' => data_get($output, 'render_hint', $cfg['renderHint'] ?? 'json_view'),
            'apply_contract_version' => 'v1',
            'checksum' => hash('sha256', (string) json_encode($output)),
            'size_bytes' => strlen((string) json_encode($output)),
        ]);

        $approval = $run->approvals()->create([
            'artifact_id' => $artifact->id,
            'status' => 'pending',
            'diff_data' => $this->approvalDiffBuilder->build($artifact),
        ]);

        $agentStep->fill([
            'status' => 'awaiting_approval',
            'observation_summary' => "Tool [{$toolName}] produced staged proposal. Awaiting approval.",
            'observation_artifact_id' => $artifact->id,
        ])->save();

        $run->fill([
            'status' => 'awaiting_approval',
            'reasoning_summary' => "Reasoning loop paused at iteration {$iteration} for approval on [{$toolName}].",
            'budget_usage' => $run->budget_usage,
            'last_checkpoint_at' => now(),
        ])->save();

        $this->incrementBudget($run, 'steps');
        $this->incrementBudget($run, 'tool_calls');

        return StepRunResult::paused('awaiting_approval', [
            'agent_run_id' => $run->id,
            'iteration' => $iteration,
            'tool_name' => $toolName,
            'artifact_id' => $artifact->id,
            'approval_id' => $approval->id,
        ], $logs);
    }

    protected function failRun(AgentRun $run, string $reason, string $message, array $logs): StepRunResult
    {
        $run->fill([
            'status' => 'failed',
            'terminal_reason' => $reason,
            'last_checkpoint_at' => now(),
            'finished_at' => now(),
        ])->save();

        return StepRunResult::failed($message, array_merge($logs, [
            ['level' => 'error', 'message' => $message],
        ]), ['agent_run_id' => $run->id, 'terminal_reason' => $reason]);
    }

    protected function recordFailedStep(AgentRun $run, int $iteration, string $toolName, string $reason): void
    {
        AgentRunStep::create([
            'run_id' => $run->id,
            'step_index' => $iteration,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => $toolName,
            'reasoning_summary' => "Tool [{$toolName}] denied or not found: {$reason}",
            'status' => 'failed',
            'terminal_reason' => $reason,
        ]);
    }

    /**
     * Increment a budget usage counter on the run.
     */
    protected function incrementBudget(AgentRun $run, string $key): void
    {
        $usage = $run->budget_usage ?? [];
        $usage[$key] = ($usage[$key] ?? 0) + 1;
        $run->budget_usage = $usage;
        $run->save();
    }

    /**
     * Merge new entries into the run's working memory (C-6).
     */
    protected function updateWorkingMemory(AgentRun $run, array $entries): void
    {
        $memory = $run->working_memory ?? [];
        $run->working_memory = array_merge($memory, $entries);
        $run->save();
    }

    protected function resolveRootId(\Illuminate\Database\Eloquent\Model $root): ?string
    {
        $key = $root->getKey();
        return $key === null ? null : (string) $key;
    }

    protected function safeContextSnapshot(WorkflowContext $context): array
    {
        try {
            return $context->toArray();
        } catch (\Throwable) {
            return [
                'root' => ['__class' => $context->getRoot()::class, '__id' => $this->resolveRootId($context->getRoot())],
                'vars' => $context->getVars(),
            ];
        }
    }

    /**
     * Resolve the starting iteration for the loop (L-7: checkpointable resume).
     *
     * If the run has completed steps from a previous execution, resume from
     * the next iteration after the last completed checkpoint. Otherwise start at 1.
     *
     * @param  AgentRun $run The agent run to check for checkpoints.
     * @return int The iteration number to start from.
     */
    protected function resolveStartIteration(AgentRun $run): int
    {
        $lastCompletedStep = AgentRunStep::query()
            ->where('run_id', $run->id)
            ->where('status', 'completed')
            ->orderByDesc('step_index')
            ->first();

        if (!$lastCompletedStep || !is_array($lastCompletedStep->snapshot_state)) {
            return 1;
        }

        $lastIteration = $lastCompletedStep->snapshot_state['iteration'] ?? $lastCompletedStep->step_index;

        // Restore working memory from checkpoint
        if (isset($lastCompletedStep->snapshot_state['working_memory'])) {
            $run->working_memory = $lastCompletedStep->snapshot_state['working_memory'];
            $run->save();
        }

        // Restore budget usage from checkpoint
        if (isset($lastCompletedStep->snapshot_state['budget_usage'])) {
            $run->budget_usage = $lastCompletedStep->snapshot_state['budget_usage'];
            $run->save();
        }

        return (int) $lastIteration + 1;
    }
}
