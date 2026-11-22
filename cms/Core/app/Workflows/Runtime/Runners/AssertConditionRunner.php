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
 * Executes an assertion step that validates a boolean expression.
 *
 * Evaluates the configured expression against the workflow context and fails the step
 * if the assertion is falsy, providing a configurable failure message.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AssertConditionRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating assertion expressions.
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
        $message = $step->stepConfig['message'] ?? 'Assertion failed';

        if (!is_string($expr) || trim($expr) === '') {
            return StepRunResult::failed('assert_condition: expression is required');
        }

        try {
            $value = $this->engine->evaluate($expr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('assert_condition: evaluation failed: ' . $e->getMessage());
        }

        if (!$value) {
            return StepRunResult::failed('assert_condition: ' . $message, [
                ['action' => 'assert_condition', 'passed' => false, 'level' => 'error', 'message' => $message],
            ], ['asserted' => false, 'message' => $message]);
        }

        return StepRunResult::success(['asserted' => true], [
            ['action' => 'assert_condition', 'passed' => true, 'level' => 'info', 'message' => 'Assertion passed'],
        ]);
    }
}
