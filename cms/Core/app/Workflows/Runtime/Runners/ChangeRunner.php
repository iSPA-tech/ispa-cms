<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime\Runners;

use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Cms\Core\Workflows\Security\WorkflowModelRefs;
use Illuminate\Database\Eloquent\Model;

/**
 * Executes a change step that modifies attributes on an Eloquent model.
 *
 * Evaluates the target model from a context expression, applies field-value changes
 * via expression evaluation, and optionally persists the model to the database.
 *
 * The target must be a model reference this run produced ({@see WorkflowModelRefs}): never one read from the trigger
 * payload, and of the step's `model` when the step names one. The model must pass WorkflowCallableGuard and the run's
 * delegate must be allowed `update` on the row (`create` for a model the run has not saved yet).
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ChangeRunner implements StepRunner
{
    use ResolvesModelTargets;

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

        $models = $this->resolveModelTargets('change', $targetExpr, $config, $context, false, $error);
        if ($models === null || count($models) !== 1) {
            return StepRunResult::failed($error ?? 'change: target is not a model or resolvable snapshot');
        }
        $model = $models[0];

        $changedFields = [];
        $logs = [];
        $values = [];

        foreach ($changes as $change) {
            $field = $change['field'] ?? null;
            $valueExpr = $change['valueExpr'] ?? null;
            if (!is_string($field) || $field === '' || !is_string($valueExpr)) {
                return StepRunResult::failed('change: each change requires field and valueExpr');
            }

            // Only mass-assignable attributes: $fillable / $guarded decide, like any request writing the model.
            if (!$model->isFillable($field)) {
                return StepRunResult::failed("change: field not fillable: {$field}", $logs);
            }

            try {
                $value = $this->engine->evaluate($valueExpr, $context, true);
            } catch (\Throwable $e) {
                return StepRunResult::failed("change: failed to evaluate {$field}: " . $e->getMessage(), $logs);
            }

            $values[$field] = $value;
            $changedFields[] = $field;
            $logs[] = ['level' => 'info', 'message' => "set {$field}", 'value' => $value];
        }

        // Every field is checked before any is set, so a refused field leaves the model untouched.
        $model->fill($values);

        if ($save) {
            try {
                $model->save();
                WorkflowModelRefs::remember($context, $model);
                $logs[] = ['level' => 'info', 'message' => 'model saved'];
                WorkflowAudit::recordWritten($context, $step->stepCode, $model::class, WorkflowAudit::modelKey($model), $changedFields, 'change');
            } catch (\Throwable $e) {
                return StepRunResult::failed('change: save failed: ' . $e->getMessage(), $logs);
            }
        }

        // A later commit_objects of this run saves the unsaved change from the working set.
        WorkflowModelRefs::remember($context, $model);

        return StepRunResult::success(
            [
                'updated' => true,
                'saved' => $save,
                'model' => $model::class,
                'id' => $model->getKey(),
                ...WorkflowModelRefs::ref($model, $context),
                'changed' => $changedFields,
            ],
            array_merge($logs, [[
                'action' => 'change',
                'fields' => $changedFields,
                'saved' => $save,
            ]])
        );
    }
}
