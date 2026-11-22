<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Support;

/**
 * Immutable DTO representing a single planning decision from the reasoning engine.
 *
 * Carries the decision type (tool_call or terminal), the action name, optional
 * input parameters, and flags for approval gating. Produced during the planning
 * phase and consumed by the agent runner to dispatch the next step.
 *
 * @package Cms\Core\Agents\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class AgentDecision
{
    /**
     * Create a new AgentDecision instance.
     *
     * @param string              $type             The decision type: "tool_call" or "terminal".
     * @param string              $name             The action or terminal state name.
     * @param array<string, mixed> $input           Input parameters for tool calls.
     * @param bool                $requiresApproval Whether the decision requires human approval.
     * @param string|null         $terminalReason   Reason for terminal decisions (null for tool calls).
     */
    public function __construct(
        public string $type,
        public string $name,
        public array $input = [],
        public bool $requiresApproval = false,
        public ?string $terminalReason = null,
    ) {
    }

    /**
     * Create a tool-call decision.
     *
     * @param  string               $name             The tool name to invoke.
     * @param  array<string, mixed> $input            Input parameters for the tool.
     * @param  bool                 $requiresApproval Whether human approval is required.
     * @return self A new tool_call decision.
     */
    public static function tool(string $name, array $input = [], bool $requiresApproval = false): self
    {
        return new self('tool_call', $name, $input, $requiresApproval);
    }

    /**
     * Create a terminal decision that ends the agent run.
     *
     * @param  string      $name           The terminal state name (e.g. "complete", "failed").
     * @param  string|null $terminalReason The reason for termination.
     * @return self A new terminal decision.
     */
    public static function terminal(string $name = 'complete', ?string $terminalReason = null): self
    {
        return new self('terminal', $name, [], false, $terminalReason);
    }
}
