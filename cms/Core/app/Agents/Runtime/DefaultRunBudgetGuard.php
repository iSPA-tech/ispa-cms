<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\BudgetEvaluation;
use Cms\Core\Agents\Contracts\RunBudgetGuardInterface;

/**
 * Default implementation of the run budget guard for agent execution.
 *
 * Tracks and enforces resource limits (steps, tokens, tool failures) by
 * comparing configured maximums against current usage counters. Produces
 * a budget evaluation that the agent runner uses to decide whether to
 * continue or terminate.
 *
 * @see RunBudgetGuardInterface — implements this contract
 *
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DefaultRunBudgetGuard implements RunBudgetGuardInterface
{
    /**
     * Calculate the remaining budget for each tracked resource.
     *
     * @param  array<string, mixed>    $budgetConfig Configured budget limits (e.g. max_steps, max_tokens).
     * @param  array<string, mixed>    $budgetUsage  Current usage counters (e.g. steps, tokens).
     * @return array<string, int|null> Remaining budget per resource; null means unlimited.
     */
    public function remaining(array $budgetConfig, array $budgetUsage): array
    {
        return [
            'steps' => $this->remainingFor('max_steps', 'steps', $budgetConfig, $budgetUsage),
            'tokens' => $this->remainingFor('max_tokens', 'tokens', $budgetConfig, $budgetUsage),
            'tool_failures' => $this->remainingFor('max_tool_failures', 'tool_failures', $budgetConfig, $budgetUsage),
        ];
    }

    /**
     * Evaluate whether the agent's current budget allows continued execution.
     *
     * @param  AgentState       $state The current agent state containing budget config and usage.
     * @return BudgetEvaluation The evaluation result with violations list and terminal state.
     */
    public function evaluate(AgentState $state): BudgetEvaluation
    {
        $remaining = $this->remaining($state->budgetConfig, $state->budgetUsage);
        $violations = [];

        foreach ($remaining as $key => $value) {
            if ($value !== null && $value < 0) {
                $violations[] = "budget_{$key}_exhausted";
            }
        }

        return new BudgetEvaluation(
            allowed: $violations === [],
            violations: $violations,
            terminalState: $violations === [] ? null : 'failed',
            remaining: $remaining,
        );
    }

    /**
     * Calculate the remaining value for a single budget dimension.
     *
     * @param  string               $configKey   The config key (e.g. max_steps).
     * @param  string               $usageKey    The usage counter key (e.g. steps).
     * @param  array<string, mixed> $budgetConfig The full budget configuration.
     * @param  array<string, mixed> $budgetUsage  The full budget usage counters.
     * @return int|null The remaining count, or null if the limit is unconfigured (unlimited).
     */
    protected function remainingFor(string $configKey, string $usageKey, array $budgetConfig, array $budgetUsage): ?int
    {
        $limit = $budgetConfig[$configKey] ?? null;
        if ($limit === null) {
            return null;
        }

        return (int) $limit - (int) ($budgetUsage[$usageKey] ?? 0);
    }
}
