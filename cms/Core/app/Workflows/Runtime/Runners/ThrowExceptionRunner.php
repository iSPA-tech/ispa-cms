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
 * Executes a step that intentionally fails the workflow with a configured error message.
 *
 * Returns a failed StepRunResult with the specified message and error code, used to
 * model explicit error-throwing within workflow graphs.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ThrowExceptionRunner implements StepRunner
{
    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $cfg = $step->stepConfig ?? [];
        $message = (string) ($cfg['message'] ?? 'Exception thrown by workflow');
        $code = (int) ($cfg['code'] ?? 0);

        return StepRunResult::failed($message, [
            [
                'action' => 'throw_exception',
                'code' => $code,
                'level' => 'error',
            ],
        ], [
            'code' => $code,
        ]);
    }
}
