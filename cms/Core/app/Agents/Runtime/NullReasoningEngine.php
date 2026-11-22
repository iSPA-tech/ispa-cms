<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentDecision;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;

/**
 * Null-object reasoning engine that immediately returns a terminal decision.
 *
 * Used as a safe fallback when no real reasoning engine (e.g. LLM-backed) is
 * configured. Always completes with reason "reasoning_engine_not_configured"
 * so the run is cleanly terminated rather than left hanging.
 *
 * @see ReasoningEngineInterface — implements this contract
 *
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class NullReasoningEngine implements ReasoningEngineInterface
{
    /**
     * Return an immediate terminal decision indicating no reasoning engine is configured.
     *
     * @param  AgentState   $state The current agent state (unused by this implementation).
     * @return AgentDecision A terminal decision with reason "reasoning_engine_not_configured".
     */
    public function plan(AgentState $state): AgentDecision
    {
        return AgentDecision::terminal(
            name: 'complete',
            terminalReason: 'reasoning_engine_not_configured'
        );
    }
}
