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
 * Executes a change step that modifies attributes on an Eloquent model.
 *
 * Evaluates the target model from a context expression, applies field-value changes
 * via expression evaluation, and optionally persists the model to the database.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ChangeRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating change values.
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
        $config = $step->stepConfig ?? [];
        $targetExpr = $config['targetExpr'] ?? null;
        $changes = $config['changes'] ?? [];
        $save = (bool) ($config['save'] ?? true);

        if (!is_string($targetExpr) || trim($targetExpr) === '') {
            return StepRunResult::failed('change: targetExpr is required');
        }

        if (!is_array($changes) || empty($changes)) {
            return StepRunResult::failed('change: changes are required');
        }

        try {
            $target = $this->engine->evaluate($targetExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('change: failed to evaluate targetExpr: ' . $e->getMessage());
        }

        $model = $this->resolveModel($target);
        if (!$model) {
            return StepRunResult::failed('change: target is not a model or resolvable snapshot');
        }

        $changedFields = [];
        $logs = [];

        foreach ($changes as $change) {
            $field = $change['field'] ?? null;
            $valueExpr = $change['valueExpr'] ?? null;
            if (!is_string($field) || $field === '' || !is_string($valueExpr)) {
                return StepRunResult::failed('change: each change requires field and valueExpr');
            }

            try {
                $value = $this->engine->evaluate($valueExpr, $context, true);
            } catch (\Throwable $e) {
                return StepRunResult::failed("change: failed to evaluate {$field}: " . $e->getMessage(), $logs);
            }

            $model->setAttribute($field, $value);
            $changedFields[] = $field;
            $logs[] = ['level' => 'info', 'message' => "set {$field}", 'value' => $value];
        }

        if ($save) {
            try {
                $model->save();
                $logs[] = ['level' => 'info', 'message' => 'model saved'];
            } catch (\Throwable $e) {
                return StepRunResult::failed('change: save failed: ' . $e->getMessage(), $logs);
            }
        }

        return StepRunResult::success(
            [
                'updated' => true,
                'saved' => $save,
                'model' => $model::class,
                'id' => $model->getKey(),
                '__class' => $model::class,
                '__id' => $model->getKey(),
                'changed' => $changedFields,
            ],
            array_merge($logs, [[
                'action' => 'change',
                'fields' => $changedFields,
                'saved' => $save,
            ]])
        );
    }

    protected function resolveModel(mixed $target): ?Model
    {
        if ($target instanceof Model) {
            return $target;
        }

        if (is_array($target) && isset($target['__class'], $target['__id'])) {
            $class = $target['__class'];
            $id = $target['__id'];
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return $class::query()->find($id);
            }
        }

        return null;
    }
}
