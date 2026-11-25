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
 * Executes list transformation operations (filter, map, sort, take) on a collection.
 *
 * Evaluates a collection expression from the workflow context, applies the specified
 * operation with expression-based conditions, and stores the resulting list in a context variable.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ListOperationRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating collection and condition expressions.
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
        $operation = strtolower((string) ($config['operation'] ?? ''));
        $storeAs = $config['storeAs'] ?? null;

        if (!is_string($collectionExpr) || $collectionExpr === '') {
            return StepRunResult::failed('list_operation: collectionExpr is required');
        }

        if (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.')) {
            return StepRunResult::failed('list_operation: storeAs must start with vars.');
        }

        $allowedOps = ['filter', 'map', 'sort', 'take', 'unique', 'flatten', 'pluck', 'split_out'];
        if (!in_array($operation, $allowedOps, true)) {
            return StepRunResult::failed('list_operation: invalid operation');
        }

        // The engine returns data only (models as snapshots), so the item reads below never reach a live model.
        try {
            $collection = $this->engine->evaluate($collectionExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('list_operation: evaluate collection failed: ' . $e->getMessage());
        }

        if ($collection instanceof Collection) {
            $items = $collection->all();
        } elseif (is_array($collection)) {
            $items = $collection;
        } elseif ($collection instanceof Traversable) {
            $items = iterator_to_array($collection);
        } else {
            return StepRunResult::failed('list_operation: collectionExpr must resolve to array or collection');
        }

        $resultItems = match ($operation) {
            'filter' => $this->applyFilter($items, $context, $config),
            'map' => $this->applyMap($items, $context, $config),
            'sort' => $this->applySort($items, $config),
            'take' => $this->applyTake($items, $config),
            'unique' => $this->applyUnique($items, $config),
            'flatten' => $this->applyFlatten($items),
            'pluck' => $this->applyPluck($items, $config),
            'split_out' => $this->applySplitOut($items, $config),
            default => $items,
        };

        // If operation was not take but take is provided, apply slice after primary op
        if ($operation !== 'take' && isset($config['take']) && is_numeric($config['take'])) {
            $resultItems = array_slice($resultItems, 0, (int) $config['take']);
        }

        $context->setVarPath(substr($storeAs, 5), $resultItems);

        return StepRunResult::success(
            [
                'operation' => $operation,
                'count' => is_countable($resultItems) ? count($resultItems) : null,
                'storedAs' => $storeAs,
            ],
            [[
                'action' => 'list_operation',
                'operation' => $operation,
                'count' => is_countable($resultItems) ? count($resultItems) : null,
            ]]
        );
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applyFilter(array $items, WorkflowContext $context, array $config): array
    {
        $expr = $config['conditionExpr'] ?? null;
        if (!is_string($expr) || $expr === '') {
            return [];
        }

        $filtered = [];
        foreach ($items as $item) {
                        try {
                $ok = $this->engine->evaluate($expr, $context, true, ['item' => $item]);
            } catch (\Throwable $e) {
                continue;
            }
            if ($ok) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applyMap(array $items, WorkflowContext $context, array $config): array
    {
        $expr = $config['mapExpr'] ?? null;
        if (!is_string($expr) || $expr === '') {
            return [];
        }

        $mapped = [];
        foreach ($items as $item) {
                        try {
                $mapped[] = $this->engine->evaluate($expr, $context, true, ['item' => $item]);
            } catch (\Throwable $e) {
                // Skip item on failure
            }
        }

        return $mapped;
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applySort(array $items, array $config): array
    {
        $field = $config['sortField'] ?? null;
        $dir = strtolower((string) ($config['sortDir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if (!is_string($field) || $field === '') {
            return $items;
        }

        usort($items, function ($a, $b) use ($field, $dir) {
            $va = data_get($a, $field);
            $vb = data_get($b, $field);

            if ($va == $vb) {
                return 0;
            }

            if ($dir === 'asc') {
                return $va <=> $vb;
            }

            return $vb <=> $va;
        });

        return $items;
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applyTake(array $items, array $config): array
    {
        $take = $config['take'] ?? null;
        if (!is_numeric($take)) {
            return $items;
        }

        return array_slice($items, 0, (int) $take);
    }

    /**
     * Keep the first item per value of `field` (or per whole item when no field is given).
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applyUnique(array $items, array $config): array
    {
        $field = is_string($config['field'] ?? null) && $config['field'] !== '' ? $config['field'] : null;
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $value = $field !== null ? data_get($item, $field) : $item;
            $key = is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * One level: list items that are lists are spread into the result.
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applyFlatten(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) && array_is_list($item)) {
                array_push($out, ...$item);
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * The value of `field` from every item.
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applyPluck(array $items, array $config): array
    {
        $field = $config['field'] ?? null;
        if (!is_string($field) || $field === '') {
            return [];
        }

        return array_map(fn ($item) => data_get($item, $field), array_values($items));
    }

    /**
     * Each element of the list held in `field` becomes its own item.
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    protected function applySplitOut(array $items, array $config): array
    {
        $field = $config['field'] ?? null;
        if (!is_string($field) || $field === '') {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            foreach ((array) data_get($item, $field, []) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }
}
