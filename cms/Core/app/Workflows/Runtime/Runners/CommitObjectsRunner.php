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
use Cms\Core\Workflows\Security\WorkflowModelRefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Traversable;

/**
 * Executes a step that persists (saves) one or more Eloquent models to the database.
 *
 * Evaluates the target from a context expression, normalizes it into a list of models,
 * and saves each with optional event suppression.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CommitObjectsRunner implements StepRunner
{
    use ResolvesModelTargets;

    /**
     * Initialize the runner with an expression engine for evaluating target expressions.
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
        $cfg = $step->stepConfig ?? [];
        $targetExpr = $cfg['targetExpr'] ?? null;
        $withEvents = (bool) ($cfg['withEvents'] ?? true);

        if (!is_string($targetExpr) || trim($targetExpr) === '') {
            return StepRunResult::failed('commit_objects: targetExpr is required');
        }

        // Only references this run produced (retrieve rows, created/cloned/changed models, the root); anything else,
        // or one row the run's delegate may not commit, fails the whole step before any row is touched.
        $models = $this->resolveModelTargets('commit_objects', $targetExpr, $cfg, $context, true, $error);
        if ($models === null) {
            return StepRunResult::failed($error);
        }

        $count = 0;
        $logs = [];

        foreach ($models as $model) {
            $saved = $this->saveModel($model, $withEvents);
            if ($saved) {
                WorkflowModelRefs::remember($context, $model);
                $count++;
            } else {
                return StepRunResult::failed('commit_objects: save failed', $logs);
            }
        }

        $logs[] = [
            'action' => 'commit_objects',
            'count' => $count,
            'withEvents' => $withEvents,
        ];

        return StepRunResult::success(['committedCount' => $count], $logs);
    }

    protected function saveModel(Model $model, bool $withEvents): bool
    {
        if ($withEvents) {
            return (bool) $model->save();
        }

        if (method_exists($model, 'withoutEvents')) {
            return (bool) $model::withoutEvents(fn() => $model->save());
        }

        return (bool) $model->save();
    }
}
