<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Agents\AgentToolBinding;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\RootModelLoader;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Cms\Core\Workflows\Runtime\Runners\AgentActionRunner;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispatches ad-hoc (non-scheduled) agent tool executions.
 *
 * Creates ephemeral workflow instances and steps for one-off deterministic
 * tool calls, reruns of previous agent runs, and schedule-based dispatches.
 * Resolves tool bindings, normalizes input, and persists execution state
 * including instance/step outcomes and context snapshots.
 *
 * @see AgentActionRunner
 * @see AgentRunOperationsService
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticAdhocDispatchService
{
    /**
     * Initialize the dispatch service with the agent action runner and root model loader.
     *
     * @param AgentActionRunner $runner     Runner that executes agent tool calls within workflow steps.
     * @param RootModelLoader   $rootLoader Loader for resolving root Eloquent models by type and ID.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected AgentActionRunner $runner,
        protected RootModelLoader $rootLoader,
        protected AgentToolRegistry $toolRegistry,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Ensure all configured bootstrap services are discovered in the registry.
     *
     * Must be called before any binding resolution so the in-memory registry
     * contains tool definitions and bindings from the service classes.
     */
    protected function ensureToolsDiscovered(): void
    {
        $services = (array) $this->config->get('core.agentic.bootstrap_services', []);
        $defaultEnv = (string) $this->config->get('core.agentic.discovery.default_environment', 'local');

        foreach ($services as $service) {
            $class = is_array($service) ? ($service['class'] ?? '') : $service;
            $moduleScope = is_array($service) ? ($service['module_scope'] ?? null) : null;

            if ($class && class_exists($class)) {
                try {
                    $this->toolRegistry->discoverService($class, $moduleScope, $defaultEnv);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Dispatch a deterministic tool execution as an ad-hoc workflow.
     *
     * @param AgentProfile              $profile          The agent profile to execute under.
     * @param string                    $toolName         The tool name to invoke.
     * @param array<string, mixed>      $toolInput        Input parameters for the tool.
     * @param string|null               $goal             Optional goal description.
     * @param string|null               $moduleScope      Optional module scope.
     * @param string|null               $environment      Optional environment override.
     * @param string|null               $tenantId         Optional tenant ID.
     * @param string|null               $targetType       Optional target entity type.
     * @param int|string|null           $targetId         Optional target entity ID.
     * @param array<string, mixed>|null $contextSnapshot  Optional restored context.
     * @param array<string, mixed>      $meta             Additional metadata (source_run_id, trigger_mode, etc.).
     * @param User|null                 $actor            User the instance and its run act for.
     * @return array{instance: WorkflowInstance, instance_step: WorkflowInstanceStep, run: AgentRun|null, result: StepRunResult}
     */
    public function dispatchDeterministicTool(
        AgentProfile $profile,
        string $toolName,
        array $toolInput = [],
        ?string $goal = null,
        ?string $moduleScope = null,
        ?string $environment = null,
        ?string $tenantId = null,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?array $contextSnapshot = null,
        array $meta = [],
        ?User $actor = null,
    ): array {
        $this->ensureToolsDiscovered();

        // A target model WorkflowCallableGuard refuses is dropped (no root, no injected id), never loaded.
        if (is_string($targetType) && $targetType !== '') {
            $resolvedType = self::$targetTypeModelMap[$targetType] ?? $targetType;
            if (class_exists($resolvedType) && !app(WorkflowCallableGuard::class)->allowsModel($resolvedType)) {
                $targetType = null;
                $targetId = null;
            }
        }

        // Auto-inject targetId into toolInput when a target is provided
        if ($targetId !== null && $toolInput === []) {
            $paramName = $this->resolveTargetParameterName($toolName, $targetType);
            if ($paramName !== null) {
                $toolInput[$paramName] = (int) $targetId;
            }
        }

        $root = $this->resolveRootModel($targetType, $targetId);

        $instance = WorkflowInstance::query()->create([
            'project_id' => null,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'status' => 'running',
            'context_snapshot' => $contextSnapshot,
            'resume_step_id' => null,
            'started_at' => now(),
            'delegated_by_user_id' => $actor?->getKey(),
        ]);

        $instanceStep = WorkflowInstanceStep::query()->create([
            'instance_id' => $instance->id,
            'step_id' => null,
            'status' => 'running',
            'started_at' => now(),
            'input_data' => [
                'tool_name' => $toolName,
                'module_scope' => $moduleScope,
                'goal' => $goal,
            ],
        ]);

        $context = $this->restoreContext($contextSnapshot, $root, $meta);
        $context->setRuntime('instance_id', $instance->id);
        $context->setRuntime('current_instance_step_id', $instanceStep->id);
        $context->setRuntime('queued', false);

        $definition = new StepDefinition(
            id: null,
            projectId: null,
            stepType: 'ai_action',
            stepCode: 'agentic_adhoc_dispatch',
            stepKey: 'agentic_adhoc_dispatch',
            stepName: 'Agentic Adhoc Dispatch',
            stepConfig: [
                'agentProfileSlug' => $profile->slug,
                'toolClass' => $this->resolveToolClass($toolName, $moduleScope, $environment),
                'toolName' => $toolName,
                'moduleScope' => $moduleScope ?? \Illuminate\Support\Str::before($toolName, '.'),
                'environment' => $environment,
                'tenantId' => $tenantId,
                'goal' => $this->normalizeGoalForStepConfig($goal),
                'toolInput' => $this->normalizeToolInputForStepConfig($toolInput),
                'sourceRunId' => $meta['source_run_id'] ?? null,
                'triggerMode' => $meta['trigger_mode'] ?? null,
                'triggerReferenceType' => $meta['trigger_reference_type'] ?? null,
                'triggerReferenceId' => $meta['trigger_reference_id'] ?? null,
            ],
            order: 1,
        );

        $result = $this->runner->run($definition, $context);
        $this->persistExecutionState($instance, $instanceStep, $context, $result);

        $run = null;
        $runId = data_get($result->resultData, 'agent_run_id')
            ?? data_get($result->resultData, 'payload.agent_run_id');
        if (! is_numeric($runId)) {
            $runId = data_get($result->error, 'agent_run_id');
        }
        if (is_numeric($runId)) {
            $run = AgentRun::query()
                ->with(['profile', 'steps', 'approvals', 'artifacts', 'toolCalls'])
                ->find((int) $runId);
        }

        if (! $run && isset($meta['source_run_id'])) {
            $run = AgentRun::query()
                ->with(['profile', 'steps', 'approvals', 'artifacts', 'toolCalls'])
                ->where('source_run_id', $meta['source_run_id'])
                ->orderByDesc('id')
                ->first();
        }

        return [
            'instance' => $instance->fresh(),
            'instance_step' => $instanceStep->fresh(),
            'run' => $run,
            'result' => $result,
        ];
    }

    /**
     * Rerun a previous agent run with the same tool and parameters.
     *
     * @param AgentRun  $sourceRun The source run to re-execute.
     * @param User|null $actor     The operator triggering the rerun.
     * @return array{instance: WorkflowInstance, instance_step: WorkflowInstanceStep, run: AgentRun|null, result: StepRunResult}
     *
     * @throws \InvalidArgumentException When the source run has no profile or executable tool.
     */
    public function rerun(AgentRun $sourceRun, ?User $actor = null): array
    {
        $this->ensureToolsDiscovered();
        $sourceRun->loadMissing(['profile', 'steps', 'toolCalls']);

        $profile = $sourceRun->profile;
        if (! $profile) {
            throw new \InvalidArgumentException("Agent run [{$sourceRun->id}] has no profile.");
        }

        $toolName = $sourceRun->steps()
            ->orderByDesc('step_index')
            ->value('action_name');

        if (! is_string($toolName) || $toolName === '') {
            throw new \InvalidArgumentException("Agent run [{$sourceRun->id}] has no executable tool.");
        }

        $latestToolCall = $sourceRun->toolCalls()
            ->orderByDesc('id')
            ->first();
        $toolInput = $latestToolCall?->input_payload;

        if (is_string($toolInput)) {
            $decoded = json_decode($toolInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $toolInput = $decoded;
            }
        }

        return $this->dispatchDeterministicTool(
            profile: $profile,
            toolName: $toolName,
            toolInput: is_array($toolInput) ? $toolInput : [],
            goal: $sourceRun->input_goal,
            moduleScope: $sourceRun->module_scope,
            environment: $sourceRun->environment,
            tenantId: $sourceRun->tenant_id,
            targetType: $sourceRun->target_type,
            targetId: $sourceRun->target_id,
            contextSnapshot: is_array($sourceRun->context_snapshot) ? $sourceRun->context_snapshot : null,
            meta: [
                'actor_id' => $actor?->getKey(),
                'source_run_id' => $sourceRun->id,
                'trigger_mode' => 'rerun',
                'trigger_reference_type' => 'agent_run',
                'trigger_reference_id' => (string) $sourceRun->id,
            ],
            actor: $actor,
        );
    }

    /**
     * Dispatch a schedule-based agent execution as an ad-hoc workflow.
     *
     * @param AgentSchedule $schedule The schedule to dispatch.
     * @return array{instance: WorkflowInstance, instance_step: WorkflowInstanceStep, run: AgentRun|null, result: StepRunResult}
     *
     * @throws \InvalidArgumentException When the schedule is missing runtime references.
     */
    public function dispatchSchedule(AgentSchedule $schedule): array
    {
        $this->ensureToolsDiscovered();
        $schedule->loadMissing(['profile', 'toolDefinition']);

        if (! $schedule->profile || ! $schedule->toolDefinition) {
            throw new \InvalidArgumentException("Agent schedule [{$schedule->id}] is missing runtime references.");
        }

        return $this->dispatchDeterministicTool(
            profile: $schedule->profile,
            toolName: $schedule->toolDefinition->tool_name,
            toolInput: $schedule->tool_input ?? [],
            goal: $schedule->goal,
            moduleScope: $schedule->module_scope,
            environment: $schedule->environment,
            tenantId: $schedule->tenant_id,
            targetType: $schedule->target_type,
            targetId: $schedule->target_id,
            contextSnapshot: null,
            meta: [
                'schedule_id' => $schedule->id,
                'trigger_mode' => 'schedule',
                'trigger_reference_type' => 'agent_schedule',
                'trigger_reference_id' => (string) $schedule->id,
            ],
            actor: $schedule->creator,
        );
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed> $meta
     */
    protected function restoreContext(?array $snapshot, Model $root, array $meta = []): WorkflowContext
    {
        if (is_array($snapshot) && ! empty($snapshot)) {
            return WorkflowContext::fromArray($snapshot, $root);
        }

        return new WorkflowContext(
            $root,
            trigger: [
                'event' => 'agentic.adhoc.dispatch',
                'at' => now()->toISOString(),
                'meta' => $meta,
            ],
            vars: [],
            steps: [],
        );
    }

    /**
     * Map module scope to the tool parameter name for the target entity ID.
     */
    protected static array $targetParameterMap = [
        'content' => 'post_id',
        'product' => 'product_id',
    ];

    /**
     * Resolve the tool input parameter name for the target ID based on tool name.
     */
    protected function resolveTargetParameterName(string $toolName, ?string $targetType): ?string
    {
        $module = \Illuminate\Support\Str::before($toolName, '.');

        return self::$targetParameterMap[$module] ?? ($targetType ? $targetType . '_id' : null);
    }

    /**
     * Map short module target types to their Eloquent model classes.
     */
    protected static array $targetTypeModelMap = [
        'content' => \Cms\Content\Models\Post::class,
        'product' => \Cms\Product\Models\Product::class,
        'education' => \Cms\Education\Models\AiEvaluation::class,
    ];

    protected function resolveRootModel(?string $targetType, int|string|null $targetId): Model
    {
        if (is_string($targetType) && $targetType !== '' && $targetId !== null) {
            // Resolve short alias (e.g. "content") to FQCN
            $resolvedType = self::$targetTypeModelMap[$targetType] ?? $targetType;

            try {
                return $this->rootLoader->load($resolvedType, $targetId, true);
            } catch (\Throwable) {
            }
        }

        return new class extends Model {
            protected $table = 'workflow_dummy_root';
            protected $guarded = [];
        };
    }

    protected function persistExecutionState(
        WorkflowInstance $instance,
        WorkflowInstanceStep $instanceStep,
        WorkflowContext $context,
        StepRunResult $result,
    ): void {
        $instanceStep->fill([
            'status' => $result->status,
            'started_at' => $result->startedAt,
            'finished_at' => $result->finishedAt,
            'result_data' => [
                'result' => $result->resultData,
                'logs' => $result->logs,
                'next_edge_hint' => $result->nextEdgeHint,
            ],
            'error_message' => $result->error['message'] ?? null,
        ])->save();

        $instance->fill([
            'status' => $result->paused ? $result->status : ($result->status === 'failed' ? 'failed' : 'completed'),
            'context_snapshot' => $context->toArray(),
            'resume_step_id' => null,
            'finished_at' => $result->paused ? null : now(),
        ])->save();
    }

    protected function resolveToolClass(string $toolName, ?string $moduleScope, ?string $environment): ?string
    {
        $binding = AgentToolBinding::query()
            ->whereHas('definition', fn ($query) => $query->where('tool_name', $toolName))
            ->when($moduleScope, fn ($query) => $query->where('module_scope', $moduleScope))
            ->when($environment, fn ($query) => $query->where('environment', $environment))
            ->where('binding_type', 'service_method')
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->first();

        $target = $binding?->binding_target;
        if (! is_string($target) || ! str_contains($target, '@')) {
            return null;
        }

        return explode('@', $target, 2)[0] ?: null;
    }

    /**
     * @param array<string, mixed> $toolInput
     * @return array<string, mixed>
     */
    protected function normalizeToolInputForStepConfig(array $toolInput): array
    {
        $normalized = [];

        foreach ($toolInput as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $this->normalizeInputValue($value);
        }

        return $normalized;
    }

    protected function normalizeInputValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeInputValue($item), $value);
        }

        return $value;
    }

    protected function normalizeGoalForStepConfig(?string $goal): ?string
    {
        if (! is_string($goal) || trim($goal) === '') {
            return null;
        }

        return json_encode($goal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
