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
 * Executes a retrieve step that queries the database for Eloquent model records.
 *
 * Builds a query with configurable where clauses, ordering, and limits using expression
 * evaluation, and stores the result (single model or collection) in a context variable.
 * Supports soft-delete inclusion.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RetrieveRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating where-clause values.
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
        $modelClass = $config['model'] ?? null;
        $storeAs = $config['storeAs'] ?? null;
        $includeTrashed = (bool) ($config['includeTrashed'] ?? false);

        if (!is_string($modelClass) || !class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            return StepRunResult::failed('retrieve: invalid model class');
        }

        if (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.')) {
            return StepRunResult::failed('retrieve: storeAs must start with vars.');
        }

        $query = $modelClass::query();
        $supportsTrashed = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);

        if ($includeTrashed && $supportsTrashed) {
            $query->withTrashed();
        }
        $logs = [];

        foreach (($config['where'] ?? []) as $clause) {
            $field = $clause['field'] ?? null;
            $op = $clause['op'] ?? '==';
            $valueExpr = $clause['valueExpr'] ?? null;
            if (!$field || !$valueExpr) {
                continue;
            }
            try {
                $value = $this->engine->evaluate($valueExpr, $context, true);
            } catch (\Throwable $e) {
                return StepRunResult::failed("retrieve: where {$field} evaluation failed: " . $e->getMessage(), $logs);
            }

            $this->applyWhere($query, $field, $op, $value);
            $logs[] = ['level' => 'info', 'message' => "where {$field} {$op}", 'value' => $value];
        }

        foreach (($config['orderBy'] ?? []) as $order) {
            $field = $order['field'] ?? null;
            $dir = strtolower($order['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            if ($field) {
                $query->orderBy($field, $dir);
                $logs[] = ['level' => 'info', 'message' => "orderBy {$field} {$dir}"];
            }
        }

        if (isset($config['limit']) && is_numeric($config['limit'])) {
            $query->limit((int) $config['limit']);
        }

        $first = (bool) ($config['first'] ?? false);

        if ($first) {
            $model = $query->first();
            $snapshot = $model ? $this->snapshot($model) : null;
            $context->setVarPath(substr($storeAs, 5), $snapshot);

            return StepRunResult::success(
                [
                    'storedAs' => $storeAs,
                    'found' => (bool) $snapshot,
                    'count' => $snapshot ? 1 : 0,
                    'record' => $snapshot,
                ],
                array_merge($logs, [[
                    'action' => 'retrieve',
                    'model' => $modelClass,
                    'where_fields' => collect($config['where'] ?? [])->pluck('field')->filter()->values()->all(),
                    'first' => true,
                    'count' => $snapshot ? 1 : 0,
                ]])
            );
        }

        $collection = $query->get();
        $snapshots = $collection->map(fn($m) => $this->snapshot($m))->all();
        $context->setVarPath(substr($storeAs, 5), $snapshots);

        return StepRunResult::success(
            [
                'storedAs' => $storeAs,
                'found' => (bool) count($snapshots),
                'count' => count($snapshots),
                'records' => $snapshots,
            ],
            array_merge($logs, [[
                'action' => 'retrieve',
                'model' => $modelClass,
                'where_fields' => collect($config['where'] ?? [])->pluck('field')->filter()->values()->all(),
                'first' => false,
                'count' => count($snapshots),
            ]])
        );
    }

    protected function applyWhere($query, string $field, string $op, mixed $value): void
    {
        $op = strtolower($op);

        $map = [
            '==' => '=',
            '!=' => '!=',
            '>' => '>',
            '>=' => '>=',
            '<' => '<',
            '<=' => '<=',
        ];

        if (isset($map[$op])) {
            $query->where($field, $map[$op], $value);
            return;
        }

        if ($op === 'like') {
            $query->where($field, 'like', $value);
            return;
        }

        if ($op === 'in') {
            $query->whereIn($field, is_array($value) ? $value : [$value]);
            return;
        }

        if ($op === 'not_in') {
            $query->whereNotIn($field, is_array($value) ? $value : [$value]);
            return;
        }

        if ($op === 'null') {
            $query->whereNull($field);
            return;
        }

        if ($op === 'not_null') {
            $query->whereNotNull($field);
            return;
        }
    }

    protected function snapshot(Model $model): array
    {
        return [
            '__class' => $model::class,
            '__id' => $model->getKey(),
            'attributes' => $model->getAttributes(),
        ];
    }
}
