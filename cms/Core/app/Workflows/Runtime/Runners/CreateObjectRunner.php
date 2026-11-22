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
 * Executes a step that creates a new Eloquent model instance with configured field values.
 *
 * Instantiates the specified model class, sets attributes via expression evaluation,
 * optionally saves to the database, and stores the model in a context variable.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CreateObjectRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating field values.
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
        $modelClass = $cfg['model'] ?? null;
        $fields = $cfg['fields'] ?? [];
        $save = (bool) ($cfg['save'] ?? true);
        $storeAs = $cfg['storeAs'] ?? null;

        if (!is_string($modelClass) || !class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            return StepRunResult::failed('create_object: invalid model class');
        }

        if (!is_array($fields)) {
            return StepRunResult::failed('create_object: fields must be array');
        }

        if ($storeAs && (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.'))) {
            return StepRunResult::failed('create_object: storeAs must start with vars.');
        }

        /** @var Model $model */
        $model = new $modelClass();
        $logs = [];
        $fieldNames = [];

        foreach ($fields as $fieldCfg) {
            $field = $fieldCfg['field'] ?? null;
            $valueExpr = $fieldCfg['valueExpr'] ?? null;

            if (!is_string($field) || !is_string($valueExpr)) {
                continue;
            }

            try {
                $value = $this->engine->evaluate($valueExpr, $context, true);
            } catch (\Throwable $e) {
                return StepRunResult::failed("create_object: failed to evaluate {$field}: " . $e->getMessage(), $logs);
            }

            $model->setAttribute($field, $value);
            $fieldNames[] = $field;
        }

        $saved = false;
        $id = null;

        if ($save) {
            try {
                $model->save();
                $saved = true;
                $id = $model->getKey();
            } catch (\Throwable $e) {
                return StepRunResult::failed('create_object: save failed: ' . $e->getMessage(), $logs);
            }
        }

        if (is_string($storeAs)) {
            $context->setVarPath(substr($storeAs, 5), $model);
        }

        $logs[] = [
            'action' => 'create_object',
            'model' => $modelClass,
            'saved' => $saved,
            'fields' => $fieldNames,
        ];

        return StepRunResult::success([
            'model' => $modelClass,
            'saved' => $saved,
            'storedAs' => $storeAs,
            'id' => $id,
        ], $logs);
    }
}
