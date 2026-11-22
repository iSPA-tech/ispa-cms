<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Contracts;

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\BudgetEvaluation;

/**
 * Contract for enforcing resource budget limits on agent runs.
 *
 * The budget guard calculates remaining budget (steps, tokens, tool failures)
 * and evaluates whether the agent is allowed to continue execution or must
 * terminate due to exhausted resources.
 *
 * Implementors: {@see \Cms\Core\Agents\Runtime\DefaultRunBudgetGuard}.
 *
 * @package Cms\Core\Agents\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface RunBudgetGuardInterface
{
    /**
     * Calculate the remaining budget for each tracked resource.
     *
     * @param  array<string, mixed>    $budgetConfig Configured budget limits (e.g. max_steps, max_tokens).
     * @param  array<string, mixed>    $budgetUsage  Current usage counters (e.g. steps, tokens).
     * @return array<string, int|null> Remaining budget per resource; null means unlimited.
     */
    public function remaining(array $budgetConfig, array $budgetUsage): array;

    /**
     * Evaluate whether the agent's current budget allows continued execution.
     *
     * @param  AgentState       $state The current agent state containing budget config and usage.
     * @return BudgetEvaluation The evaluation result including violations and terminal state.
     */
    public function evaluate(AgentState $state): BudgetEvaluation;
}
