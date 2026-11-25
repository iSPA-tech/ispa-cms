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

/**
 * Executes a decision branch step that evaluates a boolean expression.
 *
 * Evaluates the configured expression and returns either a "yes" or "no" edge hint,
 * directing the workflow graph executor to follow the corresponding outgoing connection.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DecisionRunner implements StepRunner
{
    /**
     * Initialize the runner with an expression engine for evaluating decision expressions.
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
        $config = $step->stepConfig;
        $expr = $config['expression'] ?? null;
        $conditions = $config['conditions'] ?? null;

        if (is_array($conditions) && $conditions !== []) {
            try {
                $value = $this->evaluateConditions($conditions, strtolower((string) ($config['combinator'] ?? 'and')), $context);
            } catch (\Throwable $e) {
                return StepRunResult::failed('decision: ' . $e->getMessage(), [], ['exception' => get_class($e)]);
            }
            $expr = 'conditions';
        } elseif (!is_string($expr) || trim($expr) === '') {
            return StepRunResult::failed('decision: expression is required');
        } else {
            try {
                $value = (bool) $this->engine->evaluate($expr, $context, true);
            } catch (\Throwable $e) {
                return StepRunResult::failed('decision: ' . $e->getMessage(), [], ['exception' => get_class($e)]);
            }
        }

        $hint = $value ? 'yes' : 'no';
        $logs = [
            ['level' => 'info', 'message' => "Decision evaluated to {$value}", 'expression' => $expr],
        ];

        return StepRunResult::success(
            [
                'expression' => $expr,
                'value' => $value,
            ],
            $logs,
            $hint
        );
    }

    /** Operators the condition builder offers. */
    public const OPERATORS = [
        'eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'not_contains', 'starts_with', 'ends_with',
        'in', 'not_in', 'empty', 'not_empty', 'is_true', 'is_false',
    ];

    /**
     * Condition rows `{left, operator, right | rightExpr}` joined by `and` / `or`. `left` and `rightExpr` are
     * expressions; `right` is a literal (a list for in / not_in).
     *
     * @param array<int, mixed> $rows
     */
    protected function evaluateConditions(array $rows, string $combinator, WorkflowContext $context): bool
    {
        if (!in_array($combinator, ['and', 'or'], true)) {
            throw new \InvalidArgumentException('combinator must be and or or');
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['left'] ?? null) || trim($row['left']) === '') {
                throw new \InvalidArgumentException('every condition needs a left expression');
            }
            $operator = (string) ($row['operator'] ?? 'eq');
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new \InvalidArgumentException("unknown operator {$operator}");
            }
            $left = $this->engine->evaluate($row['left'], $context, false);
            $right = is_string($row['rightExpr'] ?? null) && trim($row['rightExpr']) !== ''
                ? $this->engine->evaluate($row['rightExpr'], $context, false)
                : ($row['right'] ?? null);

            $ok = $this->compare($operator, $left, $right);
            if ($combinator === 'and' && !$ok) {
                return false;
            }
            if ($combinator === 'or' && $ok) {
                return true;
            }
        }

        return $combinator === 'and';
    }

    protected function compare(string $operator, mixed $left, mixed $right): bool
    {
        $text = static fn (mixed $v): string => is_scalar($v) ? (string) $v : (string) json_encode($v);
        $numeric = is_numeric($left) && is_numeric($right);

        return match ($operator) {
            'eq' => $numeric ? (float) $left === (float) $right : $text($left) === $text($right),
            'ne' => $numeric ? (float) $left !== (float) $right : $text($left) !== $text($right),
            'gt' => $numeric ? $left > $right : $text($left) > $text($right),
            'gte' => $numeric ? $left >= $right : $text($left) >= $text($right),
            'lt' => $numeric ? $left < $right : $text($left) < $text($right),
            'lte' => $numeric ? $left <= $right : $text($left) <= $text($right),
            'contains' => is_array($left) ? in_array($text($right), array_map($text, $left), true) : str_contains($text($left), $text($right)),
            'not_contains' => !$this->compare('contains', $left, $right),
            'starts_with' => str_starts_with($text($left), $text($right)),
            'ends_with' => str_ends_with($text($left), $text($right)),
            'in' => in_array($text($left), array_map($text, (array) $right), true),
            'not_in' => !in_array($text($left), array_map($text, (array) $right), true),
            'empty' => $left === null || $left === '' || $left === [] || $left === false,
            'not_empty' => !($left === null || $left === '' || $left === [] || $left === false),
            'is_true' => $left === true || in_array(strtolower($text($left)), ['1', 'true', 'yes'], true),
            'is_false' => $left === false || in_array(strtolower($text($left)), ['0', 'false', 'no', ''], true),
        };
    }
}
