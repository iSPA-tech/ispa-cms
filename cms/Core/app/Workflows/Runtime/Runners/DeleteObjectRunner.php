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
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
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
        $force = (bool) ($cfg['force'] ?? false);

        if (!is_string($targetExpr) || trim($targetExpr) === '') {
            return StepRunResult::failed('delete_object: targetExpr is required');
        }

        // Only references this run produced (retrieve rows, created/cloned/changed models, the root); anything else,
        // or one row the run's delegate may not delete, fails the whole step before any row is touched.
        $models = $this->resolveModelTargets('delete_object', $targetExpr, $cfg, $context, true, $error, static fn (): string => 'delete');
        if ($models === null) {
            return StepRunResult::failed($error);
        }

        $count = 0;
        $logs = [];

        foreach ($models as $model) {
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
