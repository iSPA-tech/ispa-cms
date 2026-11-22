<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentPolicy;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentDecision;
use Cms\Core\Agents\Support\ToolAuthorization;
use Cms\Core\Agents\Contracts\RunBudgetGuardInterface;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Agents\Contracts\ContextAssemblerInterface;
use Cms\Core\Agents\Contracts\ToolPolicyResolverInterface;
use Cms\Core\Agents\Contracts\AgentToolBindingResolverInterface;

/**
 * Central orchestrator for the agent execution lifecycle.
 *
 * Hydrates agent state from a persisted run, enforces budget guards, delegates
 * planning to the reasoning engine, and authorizes / resolves tool bindings
 * before each tool invocation step.
 *
 * @see ReasoningEngineInterface       — produces planning decisions
 * @see ContextAssemblerInterface      — assembles the context snapshot
 * @see RunBudgetGuardInterface        — enforces budget constraints
 * @see ToolPolicyResolverInterface    — authorizes tool usage
 * @see AgentToolBindingResolverInterface — resolves tool bindings
 *
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgentRunner
{
    /**
     * Create a new AgentRunner instance.
     *
     * @param ReasoningEngineInterface          $reasoningEngine The reasoning engine for planning.
     * @param ContextAssemblerInterface         $contextAssembler The context assembler for run snapshots.
     * @param RunBudgetGuardInterface           $budgetGuard The budget guard for resource limits.
     * @param ToolPolicyResolverInterface       $toolPolicyResolver The policy resolver for tool authorization.
     * @param AgentToolBindingResolverInterface $bindingResolver The binding resolver for tool lookups.
     * @param \Cms\Core\Agents\Tools\AgentToolRegistry $toolRegistry The tool definition registry.
     */
    public function __construct(
        protected ReasoningEngineInterface $reasoningEngine,
        protected ContextAssemblerInterface $contextAssembler,
        protected RunBudgetGuardInterface $budgetGuard,
        protected ToolPolicyResolverInterface $toolPolicyResolver,
        protected AgentToolBindingResolverInterface $bindingResolver,
        protected \Cms\Core\Agents\Tools\AgentToolRegistry $toolRegistry,
    ) {
    }

    /**
     * Hydrate a fully-populated agent state from a persisted run record.
     *
     * Loads the agent profile and its enabled tool definitions, assembles the
     * context snapshot, and pre-calculates remaining budget counters.
     *
     * @param  AgentRun $run       The persisted agent run to hydrate from.
     * @param  int      $stepIndex The current step index within the run (default 0).
     * @return AgentState The fully-hydrated agent state.
     */
    public function hydrateState(AgentRun $run, int $stepIndex = 0): AgentState
    {
        $profile = $run->profile()->with('toolDefinitions')->first();
        $availableTools = $profile
            ? $profile->toolDefinitions
                ->filter(fn ($definition) => (bool) ($definition->pivot->enabled ?? true))
                ->pluck('tool_name')
                ->values()
                ->all()
            : [];

        $state = new AgentState(
            runId: $run->id,
            workflowInstanceId: $run->workflow_instance_id,
            parentWorkflowStepId: $run->parent_workflow_step_id,
            stepIndex: $stepIndex,
            environment: $run->environment,
            tenantId: $run->tenant_id,
            moduleScope: $run->module_scope,
            goal: $run->input_goal,
            workingMemory: $run->working_memory ?? [],
            contextSnapshot: $this->contextAssembler->assemble($run),
            availableToolDefinitions: $availableTools,
            activePolicies: $this->loadActivePolicies($run, $profile),
            budgetConfig: $run->budget_config ?? [],
            budgetUsage: $run->budget_usage ?? [],
            budgetRemaining: [],
            profileCapabilityClass: $profile?->capability_class,
            lastAction: $run->steps()->latest('step_index')->value('action_name'),
            lastObservationRef: $run->steps()->latest('step_index')->value('observation_artifact_id'),
            delegatedByUserId: $run->delegated_by_user_id,
        );

        return $state->withBudgetRemaining(
            $this->budgetGuard->remaining($state->budgetConfig, $state->budgetUsage)
        );
    }

    /**
     * Plan the next agent action for a given run.
     *
     * Hydrates state, evaluates budget constraints, and delegates to the
     * reasoning engine. Returns a terminal decision if the budget is exhausted.
     *
     * @param  AgentRun      $run       The persisted agent run.
     * @param  int           $stepIndex The current step index (default 0).
     * @return AgentDecision The next action decision (tool call or terminal).
     */
    public function plan(AgentRun $run, int $stepIndex = 0): AgentDecision
    {
        $state = $this->hydrateState($run, $stepIndex);
        $budget = $this->budgetGuard->evaluate($state);

        if (!$budget->allowed) {
            return AgentDecision::terminal(
                name: 'failed',
                terminalReason: $budget->violations[0] ?? 'budget_exhausted'
            );
        }

        return $this->reasoningEngine->plan($state);
    }

    /**
     * Authorize a tool invocation against the current agent state and policies.
     *
     * Looks up the tool definition in the registry and delegates to the
     * policy resolver. Returns a denial if the definition is not found.
     *
     * @param  AgentState         $state    The current agent state.
     * @param  string             $toolName The canonical tool name to authorize.
     * @return ToolAuthorization  The authorization result (allow/deny).
     */
    public function authorizeTool(AgentState $state, string $toolName): ToolAuthorization
    {
        $definition = $this->toolRegistry->definition($toolName);
        if (!$definition) {
            return ToolAuthorization::deny('tool_definition_not_found');
        }

        return $this->toolPolicyResolver->authorize($state, $definition);
    }

    /**
     * Resolve the concrete tool binding for execution.
     *
     * Delegates to the binding resolver using the agent's current environment,
     * tenant, and module scope from the state.
     *
     * @param  AgentState                              $state       The current agent state providing scope context.
     * @param  string                                  $toolName    The canonical tool name to resolve.
     * @param  string|null                             $policyScope Optional policy scope override.
     * @return \Cms\Core\Agents\Support\AgentToolBindingData|null The resolved binding, or null if none found.
     */
    public function resolveBinding(AgentState $state, string $toolName, ?string $policyScope = null): ?\Cms\Core\Agents\Support\AgentToolBindingData
    {
        return $this->bindingResolver->resolve(
            toolName: $toolName,
            environment: $state->environment,
            tenantId: $state->tenantId,
            moduleScope: $state->moduleScope,
            policyScope: $policyScope,
        );
    }

    /**
     * Load active policies for the given run context (C-4).
     *
     * Queries agent_policies for enabled rules scoped to the profile or global,
     * and structures them into the format expected by DefaultToolPolicyResolver.
     *
     * @param  AgentRun $run     The current agent run.
     * @param  mixed    $profile The agent profile (or null).
     * @return array<string, mixed> The active policies structure.
     */
    protected function loadActivePolicies(AgentRun $run, mixed $profile): array
    {
        try {
            return $this->queryActivePolicies($run, $profile);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function queryActivePolicies(AgentRun $run, mixed $profile): array
    {
        $policies = AgentPolicy::query()
            ->where('enabled', true)
            ->where(function ($query) use ($run, $profile) {
                $query->where(function ($q) {
                    $q->where('scope_type', 'global')->whereNull('scope_id');
                });
                if ($profile) {
                    $query->orWhere(function ($q) use ($profile) {
                        $q->where('scope_type', 'profile')->where('scope_id', $profile->id);
                    });
                }
                if ($run->module_scope) {
                    $query->orWhere(function ($q) use ($run) {
                        $q->where('scope_type', 'module')->where('scope_id', $run->module_scope);
                    });
                }
            })
            ->get();

        $blockedTools = $policies
            ->where('policy_type', 'constraint')
            ->where('policy_key', 'blocked_tool')
            ->pluck('policy_value')
            ->filter()
            ->flatMap(fn ($val) => is_array($val) ? $val : [$val])
            ->values()
            ->all();

        return [
            'blocked_tools' => $blockedTools,
            'raw_policies' => $policies->toArray(),
        ];
    }
}
