<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Arr;

/**
 * Executes a step that removes one or more variables from the workflow context.
 *
 * Accepts a list of dot-notation variable paths (prefixed with "vars.") and removes
 * each from the context's variable store.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ClearVariableRunner implements StepRunner
{
    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig ?? [];
        $targets = $config['targets'] ?? [];

        if (!is_array($targets) || empty($targets)) {
            return StepRunResult::success(['cleared' => []]);
        }

        // The executor-owned runtime vars may not be cleared; refuse before clearing anything.
        foreach ($targets as $target) {
            if (is_string($target) && str_starts_with($target, 'vars.') && WorkflowContext::isRuntimePath(substr($target, 5))) {
                return StepRunResult::failed('clear_variable: may not clear __runtime');
            }
        }

        $cleared = [];
        $logs = [];

        foreach ($targets as $target) {
            if (!is_string($target) || !str_starts_with($target, 'vars.')) {
                continue;
            }

            $path = substr($target, 5); // remove vars.
            $vars = $context->getVars();
            Arr::forget($vars, $path);
            $context->setVars($vars);

            $cleared[] = $target;
            $logs[] = ['level' => 'info', 'message' => "Cleared {$target}"];
        }

        return StepRunResult::success(['cleared' => $cleared], $logs);
    }
}
