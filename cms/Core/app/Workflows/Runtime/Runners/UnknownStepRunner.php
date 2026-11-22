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
 * Fallback runner for unrecognized workflow step types.
 *
 * Returns a failed result listing the unknown step type and all known step types,
 * serving as the default handler in the StepRunnerRegistry.
 *
 * @see StepRunner
 * @see StepRunnerRegistry
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UnknownStepRunner implements StepRunner
{
    /**
     * @param array<string, bool> $available
     */
    public function __construct(protected array $available = [])
    {
    }

    /**
     * {@inheritdoc}
     * @param  StepDefinition $step
     * @param  WorkflowContext $context
     * @return StepRunResult
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $message = sprintf(
            'Unknown step_type: %s. Known: %s',
            $step->stepType,
            implode(', ', array_keys($this->available))
        );

        return StepRunResult::failed($message, [
            ['level' => 'error', 'message' => $message],
        ]);
    }
}
