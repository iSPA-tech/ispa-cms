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

/**
 * Executes a delay step that pauses workflow execution for a specified duration.
 *
 * For short delays (<=1s) sleeps synchronously. For longer delays returns a rescheduled
 * result; the executor then stops the current execution and re-dispatches the run
 * to continue from the next step after the delay.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DelayRunner implements StepRunner
{
    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $seconds = (int) ($step->stepConfig['seconds'] ?? 0);
        $seconds = max(0, $seconds);

        $logs = [
            ['level' => 'info', 'message' => "Delay requested: {$seconds}s"],
        ];

        if ($seconds <= 1) {
            if ($seconds > 0) {
                sleep($seconds);
            }

            return StepRunResult::success(['delayed' => $seconds], $logs);
        }

        // The executor saves the cursor, stops this execution and re-dispatches the run with the delay.
        return StepRunResult::rescheduled($seconds, ['delayed' => $seconds, 'rescheduled' => true], $logs);
    }
}
