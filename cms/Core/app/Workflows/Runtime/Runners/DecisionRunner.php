<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Executes a decision branch step that evaluates a boolean expression.
 *
 * Evaluates the configured expression and returns either a "yes" or "no" edge hint,
 * directing the workflow graph executor to follow the corresponding outgoing connection.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DecisionRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating decision expressions.
     *
     * @param WorkflowExpressionEngine $engine Expression engine for evaluating step config values.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine()
    ) {
    }

    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $expr = $step->stepConfig['expression'] ?? null;

        if (!is_string($expr) || trim($expr) === '') {
            return StepRunResult::failed('decision: expression is required');
        }

        try {
            $value = (bool) $this->engine->evaluate($expr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('decision: ' . $e->getMessage(), [], ['exception' => get_class($e)]);
        }

        $hint = $value ? 'yes' : 'no';
        $logs = [
            ['level' => 'info', 'message' => "Decision evaluated to {$value}", 'expression' => $expr],
        ];

        return StepRunResult::success(
            [
                'expression' => $expr,
                'value' => $value,
            ],
            $logs,
            $hint
        );
    }
}
