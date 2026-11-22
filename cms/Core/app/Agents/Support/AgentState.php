<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Support;

/**
 * Immutable DTO encapsulating the full state of an agent at a given execution step.
 *
 * Carries run identity, workflow context, environment/tenant/module scoping, the
 * agent's goal, working memory, assembled context snapshot, available tools,
 * active policies, and budget tracking. Constructed by the AgentRunner during
 * the hydration phase and passed to the reasoning engine for planning.
 *
 * @package Cms\Core\Agents\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class AgentState
{
    /**
     * Create a new AgentState instance.
     *
     * @param int                    $runId                   The agent run ID.
     * @param int|null               $workflowInstanceId      The parent workflow instance ID, if any.
     * @param int|null               $parentWorkflowStepId    The parent workflow step ID, if any.
     * @param int                    $stepIndex               The current step index in this run.
     * @param string|null            $environment             The runtime environment (e.g. production).
     * @param string|null            $tenantId                The tenant identifier for multi-tenant scoping.
     * @param string|null            $moduleScope             The CMS module scope (e.g. core, education).
     * @param string|null            $goal                    The input goal for this agent run.
     * @param array<string, mixed>   $workingMemory
     * @param array<string, mixed> $contextSnapshot
     * @param array<int, string>      $availableToolDefinitions Enabled tool names for this profile.
     * @param array<string, mixed>    $activePolicies           Active policy constraints.
     * @param array<string, mixed>    $budgetConfig             Configured budget limits.
     * @param array<string, mixed>    $budgetUsage              Current budget usage counters.
     * @param array<string, int|null> $budgetRemaining          Pre-calculated remaining budget per resource.
     * @param string|null             $profileCapabilityClass   The capability class from the agent profile.
     * @param string|null             $lastAction               The name of the last executed action.
     * @param int|null                $lastObservationRef       The artifact ID of the last observation.
     * @param int|null                $delegatedByUserId        The user who delegated this run, if any.
     */
    public function __construct(
        public int $runId,
        public ?int $workflowInstanceId,
        public ?int $parentWorkflowStepId,
        public int $stepIndex,
        public ?string $environment,
        public ?string $tenantId,
        public ?string $moduleScope,
        public ?string $goal,
        public array $workingMemory = [],
        public array $contextSnapshot = [],
        public array $availableToolDefinitions = [],
        public array $activePolicies = [],
        public array $budgetConfig = [],
        public array $budgetUsage = [],
        public array $budgetRemaining = [],
        public ?string $profileCapabilityClass = null,
        public ?string $lastAction = null,
        public ?int $lastObservationRef = null,
        public ?int $delegatedByUserId = null,
    ) {
    }

    /**
     * Return a new state instance with updated budget remaining values.
     *
     * @param  array<string, int|null> $budgetRemaining The recalculated remaining budget values.
     * @return self A new AgentState with the updated budget remaining.
     */
    public function withBudgetRemaining(array $budgetRemaining): self
    {
        return new self(
            runId: $this->runId,
            workflowInstanceId: $this->workflowInstanceId,
            parentWorkflowStepId: $this->parentWorkflowStepId,
            stepIndex: $this->stepIndex,
            environment: $this->environment,
            tenantId: $this->tenantId,
            moduleScope: $this->moduleScope,
            goal: $this->goal,
            workingMemory: $this->workingMemory,
            contextSnapshot: $this->contextSnapshot,
            availableToolDefinitions: $this->availableToolDefinitions,
            activePolicies: $this->activePolicies,
            budgetConfig: $this->budgetConfig,
            budgetUsage: $this->budgetUsage,
            budgetRemaining: $budgetRemaining,
            profileCapabilityClass: $this->profileCapabilityClass,
            lastAction: $this->lastAction,
            lastObservationRef: $this->lastObservationRef,
            delegatedByUserId: $this->delegatedByUserId,
        );
    }

    /**
     * Convert the agent state to a plain associative array.
     *
     * @return array<string, mixed> The state as a serializable array.
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'workflow_instance_id' => $this->workflowInstanceId,
            'parent_workflow_step_id' => $this->parentWorkflowStepId,
            'step_index' => $this->stepIndex,
            'environment' => $this->environment,
            'tenant_id' => $this->tenantId,
            'module_scope' => $this->moduleScope,
            'goal' => $this->goal,
            'working_memory' => $this->workingMemory,
            'context_snapshot' => $this->contextSnapshot,
            'available_tool_definitions' => $this->availableToolDefinitions,
            'active_policies' => $this->activePolicies,
            'budget_config' => $this->budgetConfig,
            'budget_usage' => $this->budgetUsage,
            'budget_remaining' => $this->budgetRemaining,
            'profile_capability_class' => $this->profileCapabilityClass,
            'last_action' => $this->lastAction,
            'last_observation_ref' => $this->lastObservationRef,
            'delegated_by_user_id' => $this->delegatedByUserId,
        ];
    }
}
