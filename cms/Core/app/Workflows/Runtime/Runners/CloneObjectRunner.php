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
use Illuminate\Database\Eloquent\Model;

/**
 * Executes a step that clones an Eloquent model and optionally persists the copy.
 *
 * Evaluates the source model from a context expression, replicates it with configurable
 * excluded attributes, and stores the clone in a context variable.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CloneObjectRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating source model expressions.
     *
     * @param WorkflowExpressionEngine $engine Expression engine for evaluating step config values.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected WorkflowExpressionEngine $engine = new WorkflowExpressionEngine(),
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
        $cfg = $step->stepConfig ?? [];
        $sourceExpr = $cfg['sourceExpr'] ?? null;
        $exclude = $cfg['exclude'] ?? ['id', 'created_at', 'updated_at'];
        $save = (bool) ($cfg['save'] ?? false);
        $storeAs = $cfg['storeAs'] ?? null;

        if (!is_string($sourceExpr) || $sourceExpr === '') {
            return StepRunResult::failed('clone_object: sourceExpr is required');
        }

        if (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.')) {
            return StepRunResult::failed('clone_object: storeAs must start with vars.');
        }

        try {
            $source = $this->engine->evaluate($sourceExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('clone_object: evaluate source failed: ' . $e->getMessage());
        }

        if (!$source instanceof Model) {
            return StepRunResult::failed('clone_object: sourceExpr must resolve to a model');
        }

        $except = is_array($exclude) ? $exclude : ['id', 'created_at', 'updated_at'];
        $clone = $source->replicate($except);
        $saved = false;
        $id = null;

        if ($save) {
            try {
                $clone->save();
                $saved = true;
                $id = $clone->getKey();
            } catch (\Throwable $e) {
                return StepRunResult::failed('clone_object: save failed: ' . $e->getMessage());
            }
        }

        $context->setVarPath(substr($storeAs, 5), $clone);

        $logs = [[
            'action' => 'clone_object',
            'saved' => $saved,
            'excluded' => $except,
        ]];

        return StepRunResult::success([
            'cloned' => true,
            'saved' => $saved,
            'storedAs' => $storeAs,
            'id' => $id,
        ], $logs);
    }
}
