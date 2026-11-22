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
 * Executes a step that sets one or more variables in the workflow context.
 *
 * Processes a list of key-value assignments, evaluating each value expression against
 * the context and storing the result at the specified variable path.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SetVariableRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating assignment values.
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
        $assignments = $step->stepConfig['assignments'] ?? [];
        if (!is_array($assignments) || empty($assignments)) {
            return StepRunResult::failed('set_variable: assignments are required');
        }

        $assigned = [];
        $logs = [];

        foreach ($assignments as $assignment) {
            $key = $assignment['key'] ?? null;
            $expr = $assignment['valueExpr'] ?? null;

            if (!is_string($key) || !str_starts_with($key, 'vars.')) {
                return StepRunResult::failed('set_variable: assignment key must start with vars.', $logs);
            }

            if (!is_string($expr) || trim($expr) === '') {
                return StepRunResult::failed('set_variable: valueExpr is required', $logs);
            }

            $path = substr($key, 5); // remove vars.
            try {
                $value = $this->engine->evaluate($expr, $context, true);
                $context->setVarPath($path, $value);
                $assigned[] = ['key' => $key, 'value' => $value];
            } catch (\Throwable $e) {
                return StepRunResult::failed(
                    "set_variable: failed to assign {$key}: " . $e->getMessage(),
                    $logs
                );
            }
        }

        $logs[] = [
            'action' => 'set_variable',
            'assigned' => array_column($assigned, 'key'),
        ];

        return StepRunResult::success([
            'assigned' => $assigned,
            'count' => count($assigned),
        ], $logs);
    }
}
