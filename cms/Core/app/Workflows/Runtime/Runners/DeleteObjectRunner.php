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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Traversable;

/**
 * Executes a step that deletes one or more Eloquent models from the database.
 *
 * Evaluates the target from a context expression, normalizes it into a model list,
 * and deletes each model with optional force-delete support for soft-deletable models.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DeleteObjectRunner implements StepRunner
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
        $force = (bool) ($cfg['force'] ?? false);

        if (!is_string($targetExpr) || trim($targetExpr) === '') {
            return StepRunResult::failed('delete_object: targetExpr is required');
        }

        try {
            $target = $this->engine->evaluate($targetExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('delete_object: failed to evaluate targetExpr: ' . $e->getMessage());
        }

        $models = $this->normalizeTargets($target);
        $count = 0;
        $logs = [];

        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }

            $deleted = $this->deleteModel($model, $force);
            if ($deleted) {
                $count++;
            }
        }

        $logs[] = [
            'action' => 'delete_object',
            'count' => $count,
            'force' => $force,
        ];

        return StepRunResult::success([
            'deletedCount' => $count,
            'force' => $force,
        ], $logs);
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

    protected function deleteModel(Model $model, bool $force): bool
    {
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

        if ($force && $usesSoftDeletes && method_exists($model, 'forceDelete')) {
            return (bool) $model->forceDelete();
        }

        if (method_exists($model, 'delete')) {
            return (bool) $model->delete();
        }

        return false;
    }
}
