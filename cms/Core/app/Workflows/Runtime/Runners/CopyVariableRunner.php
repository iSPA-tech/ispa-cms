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
 * Executes a step that copies a value from one expression path to a context variable.
 *
 * Evaluates the source expression and stores the result at the specified destination
 * path within the workflow context variables.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CopyVariableRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating the source expression.
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
        $config = $step->stepConfig ?? [];
        $from = $config['fromExpr'] ?? null;
        $to = $config['to'] ?? null;

        if (!is_string($from) || trim($from) === '') {
            return StepRunResult::failed('copy_variable: fromExpr is required');
        }

        if (!is_string($to) || !str_starts_with($to, 'vars.')) {
            return StepRunResult::failed('copy_variable: to must start with vars.');
        }

        try {
            $value = $this->engine->evaluate($from, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('copy_variable: failed to evaluate fromExpr: ' . $e->getMessage());
        }

        $path = substr($to, 5); // remove vars.
        $context->setVarPath($path, $value);

        $logs = [
            ['level' => 'info', 'message' => "Copied {$from} to {$to}"],
        ];

        return StepRunResult::success([
            'from' => $from,
            'to' => $to,
        ], $logs);
    }
}
