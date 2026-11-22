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

        try {
            $target = $this->engine->evaluate($targetExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('commit_objects: failed to evaluate targetExpr: ' . $e->getMessage());
        }

        $models = $this->normalizeTargets($target);
        $count = 0;
        $logs = [];

        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }

            $saved = $this->saveModel($model, $withEvents);
            if ($saved) {
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

    /**
     * @return array<int, Model>
     */
    protected function normalizeTargets(mixed $target): array
    {
        if ($target === null) {
            return [];
        }

        if ($target instanceof Model) {
            return [$target];
        }

        if ($target instanceof Collection) {
            return $target->all();
        }

        if ($target instanceof Traversable) {
            return iterator_to_array($target);
        }

        if (is_array($target)) {
            return $target;
        }

        return [];
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
