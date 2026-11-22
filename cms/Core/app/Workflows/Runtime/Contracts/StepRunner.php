<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Contracts;

use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Contract for executing a single workflow step within the runtime engine.
 *
 * Each implementation handles a specific step type (decision, set_variable, retrieve, etc.)
 * and returns a StepRunResult indicating success, failure, or pause.
 *
 * @package Cms\Core\Workflows\Runtime\Contracts
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface StepRunner
{
    /**
     * Execute a workflow step and return the outcome.
     *
     * @param  StepDefinition  $step    The step definition containing type, config, and metadata.
     * @param  WorkflowContext $context The current workflow execution context.
     * @return StepRunResult The result of the step execution.
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult;
}
