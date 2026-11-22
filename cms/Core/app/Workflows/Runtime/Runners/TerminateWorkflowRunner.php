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
 * Executes a terminate step that ends the workflow with a specified status.
 *
 * Signals the graph executor to stop processing further steps by returning a result
 * with the "terminated" flag and an optional status message.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TerminateWorkflowRunner implements StepRunner
{
    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $status = $step->stepConfig['status'] ?? 'failed';
        $message = $step->stepConfig['message'] ?? null;

        $logs = [
            ['action' => 'terminate_workflow', 'status' => $status, 'level' => 'info', 'message' => "Terminating workflow with status {$status}"],
        ];

        $result = StepRunResult::success([
            'terminated' => true,
            'status' => $status,
        ], $logs, 'terminate');

        if ($message) {
            $result->resultData['message'] = $message;
            $result->logs[] = ['level' => 'info', 'message' => $message];
        }

        return $result;
    }
}
