<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\ToolAuthorization;
use Cms\Core\Agents\Support\AgentToolDefinitionData;

/**
 * Contract for authorizing tool invocations based on agent state and policy rules.
 *
 * Checks whether a given tool is permitted for the current agent profile,
 * capability class, and active policies — and whether human approval is required
 * before execution.
 *
 * Implementors: {@see \Cms\Core\Agents\Tools\DefaultToolPolicyResolver}.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface ToolPolicyResolverInterface
{
    /**
     * Authorize a tool invocation for the given agent state.
     *
     * @param  AgentState              $state      The current agent state with profile and policy data.
     * @param  AgentToolDefinitionData $definition The tool definition to authorize.
     * @return ToolAuthorization       The authorization result (allow/deny with optional approval flag).
     */
    public function authorize(AgentState $state, AgentToolDefinitionData $definition): ToolAuthorization;
}
