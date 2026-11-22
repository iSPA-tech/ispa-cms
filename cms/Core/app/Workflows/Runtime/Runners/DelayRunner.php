<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Bus;

/**
 * Executes a delay step that pauses workflow execution for a specified duration.
 *
 * For short delays (<=1s) sleeps synchronously. For longer delays in queued contexts,
 * re-dispatches the workflow job with the appropriate delay. Fails if async execution
 * is required but not available.
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

        $isQueued = (bool) $context->getVarPath('__runtime.queued', false);
        $instanceId = $context->getVarPath('__runtime.instance_id');

        $logs = [
            ['level' => 'info', 'message' => "Delay requested: {$seconds}s"],
        ];

        if ($seconds <= 1) {
            if ($seconds > 0) {
                sleep($seconds);
            }

            return StepRunResult::success(['delayed' => $seconds], $logs);
        }

        if ($isQueued && $instanceId) {
            Bus::dispatch((new RunWorkflowInstanceJob($instanceId))->delay(now()->addSeconds($seconds)));
            $logs[] = ['level' => 'info', 'message' => "Re-dispatched with {$seconds}s delay"];

            return StepRunResult::success(['delayed' => $seconds, 'rescheduled' => true], $logs);
        }

        $logs[] = ['level' => 'error', 'message' => 'Delay requires async execution'];

        return StepRunResult::failed('Delay requires async execution', $logs);
    }
}
