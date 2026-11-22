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
use Illuminate\Support\Collection;
use Traversable;

/**
 * Executes an aggregate operation (sum, count, avg, min, max) on a collection.
 *
 * Evaluates a collection expression from the workflow context, applies the specified
 * aggregate operation over a given field, and stores the result in a context variable.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AggregateListRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating collection expressions.
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
        $collectionExpr = $config['collectionExpr'] ?? null;
        $operation = strtolower((string)($config['operation'] ?? ''));
        $field = $config['field'] ?? null;
        $storeAs = $config['storeAs'] ?? null;

        $allowedOps = ['sum', 'count', 'avg', 'min', 'max'];

        if (!is_string($collectionExpr) || $collectionExpr === '') {
            return StepRunResult::failed('aggregate_list: collectionExpr is required');
        }

        if (!in_array($operation, $allowedOps, true)) {
            return StepRunResult::failed('aggregate_list: invalid operation');
        }

        if (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.')) {
            return StepRunResult::failed('aggregate_list: storeAs must start with vars.');
        }

        if ($operation !== 'count' && !is_string($field)) {
            return StepRunResult::failed('aggregate_list: field is required for this operation');
        }

        try {
            $collection = $this->engine->evaluate($collectionExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('aggregate_list: evaluate collection failed: ' . $e->getMessage());
        }

        if ($collection instanceof Collection) {
            $items = $collection->all();
        } elseif (is_array($collection)) {
            $items = $collection;
        } elseif ($collection instanceof Traversable) {
            $items = iterator_to_array($collection);
        } else {
            return StepRunResult::failed('aggregate_list: collectionExpr must resolve to array or collection');
        }

        $valueCount = 0;
        $values = [];

        if ($operation === 'count') {
            $valueCount = count($items);
            $value = $valueCount;
        } else {
            foreach ($items as $item) {
                $val = data_get($item, $field);
                if ($val === null || !is_numeric($val)) {
                    continue;
                }
                $values[] = (float)$val;
            }

            $valueCount = count($values);
            $value = match ($operation) {
                'sum' => array_sum($values),
                'avg' => $valueCount > 0 ? array_sum($values) / $valueCount : null,
                'min' => $valueCount > 0 ? min($values) : null,
                'max' => $valueCount > 0 ? max($values) : null,
                default => null,
            };
        }

        $context->setVarPath(substr($storeAs, 5), $value);

        return StepRunResult::success(
            [
                'operation' => $operation,
                'value' => $value,
                'count' => $valueCount,
                'storedAs' => $storeAs,
            ],
            [[
                'action' => 'aggregate_list',
                'operation' => $operation,
                'field' => $operation === 'count' ? null : $field,
                'count' => $valueCount,
            ]]
        );
    }
}
