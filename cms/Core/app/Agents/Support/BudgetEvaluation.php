<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Support;

/**
 * Immutable DTO representing the result of a budget evaluation check.
 *
 * Produced by the RunBudgetGuard after comparing current usage against configured
 * limits. Carries the allow/deny verdict, any violation reasons, the terminal
 * state to apply if denied, and the per-resource remaining counters.
 *
 * @package Cms\Core\Agents\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class BudgetEvaluation
{
    /**
     * Create a new BudgetEvaluation instance.
     *
     * @param bool                    $allowed       Whether the agent is allowed to continue.
     * @param array<int, string>      $violations    List of budget violation reasons (e.g. "budget_steps_exhausted").
     * @param string|null             $terminalState The terminal state to apply if denied (e.g. "failed").
     * @param array<string, int|null> $remaining     Remaining budget per resource; null means unlimited.
     */
    public function __construct(
        public bool $allowed,
        public array $violations = [],
        public ?string $terminalState = null,
        public array $remaining = [],
    ) {
    }
}
