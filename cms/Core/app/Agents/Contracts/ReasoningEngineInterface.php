<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

use Cms\Core\Agents\Support\AgentDecision;
use Cms\Core\Agents\Support\AgentState;

/**
 * Contract for the reasoning engine that produces planning decisions.
 *
 * Given the current agent state (goal, context, budget, available tools), the
 * reasoning engine determines the next action: either a tool call or a terminal
 * decision (complete / failed).
 *
 * Implementors: {@see \Cms\Core\Agents\Runtime\NullReasoningEngine}, vendor-specific engines.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface ReasoningEngineInterface
{
    /**
     * Produce the next agent decision based on the current state.
     *
     * @param  AgentState   $state The fully-hydrated agent state for the current step.
     * @return AgentDecision The decision indicating a tool call or terminal outcome.
     */
    public function plan(AgentState $state): AgentDecision;
}
