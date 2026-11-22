<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Attributes\AgentTool;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Provides read-only workflow context inspection as an agent tool.
 *
 * Exposes the current workflow context (goal, run ID, variables, root
 * entity, available tools) to agent reasoning loops. Useful for agents
 * that need to introspect their execution environment.
 *
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowContextToolService
{
    /**
     * Inspect the current workflow context in a read-only manner.
     *
     * @param array<string, mixed>   $input   Input parameters; supports 'requested_var'.
     * @param WorkflowContext|null   $context The current workflow context.
     * @param AgentState|null        $state   The current agent state.
     * @return array<string, mixed> The inspection result.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    #[AgentTool(
        name: 'core.inspect_workflow_context',
        family: 'core',
        moduleScope: 'core',
        contractSteward: 'core',
        capabilityClass: 'class_0_read_only',
        description: 'Inspect the current workflow context in a read-only way.'
    )]
    public function inspect_workflow_context_tool(
        array $input = [],
        ?WorkflowContext $context = null,
        ?AgentState $state = null,
    ): array {
        $requestedVar = $input['requested_var'] ?? null;
        $root = $context?->getRoot();

        return [
            'goal' => $state?->goal,
            'run_id' => $state?->runId,
            'workflow_instance_id' => $state?->workflowInstanceId,
            'parent_workflow_step_id' => $state?->parentWorkflowStepId,
            'requested_var' => $requestedVar,
            'requested_var_value' => is_string($requestedVar) && $context
                ? $context->getVarPath($requestedVar)
                : null,
            'available_tools' => $state?->availableToolDefinitions ?? [],
            'context_keys' => $context ? array_keys($context->getVars()) : [],
            'root_class' => $root ? $root::class : null,
            'root_id' => $root?->getKey(),
        ];
    }
}
