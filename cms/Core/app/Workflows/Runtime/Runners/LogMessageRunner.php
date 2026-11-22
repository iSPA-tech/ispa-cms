<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Executes a logging step that renders and records a message into the step logs.
 *
 * Uses the TemplateRenderer to resolve {{ expression }} placeholders in the message
 * and context fields, producing a structured log entry in the step result.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class LogMessageRunner implements StepRunner
{
    /**
     * Initialize the runner with a template renderer for message interpolation.
     *
     * @param TemplateRenderer $renderer Renders {{ expression }} placeholders in log messages.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected TemplateRenderer $renderer = new TemplateRenderer()
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
        $level = $config['level'] ?? 'info';
        $messageTpl = $config['message'] ?? '';
        $ctxTpl = $config['context'] ?? [];

        $logs = [];

        try {
            $renderedMessage = is_string($messageTpl) ? $this->renderer->renderString($messageTpl, $context) : '';
            $renderedContext = is_array($ctxTpl) ? $this->renderer->renderMixed($ctxTpl, $context) : [];

            $logs[] = [
                'action' => 'log_message',
                'level' => $level ?: 'info',
                'message' => $renderedMessage,
                'context' => $renderedContext,
            ];
        } catch (\Throwable $e) {
            $logs[] = [
                'action' => 'log_message',
                'level' => 'error',
                'message' => 'log_message render failed: ' . $e->getMessage(),
            ];
        }

        return StepRunResult::success(['logged' => true], $logs);
    }
}
