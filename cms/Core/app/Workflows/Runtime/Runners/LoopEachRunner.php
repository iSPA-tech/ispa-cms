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
 * Executes a loop-each step that iterates over a collection one item at a time.
 *
 * Manages loop state via step metadata, assigns the current item and index to context
 * variables, and returns "loop" or "done" edge hints to control graph traversal.
 * Enforces a configurable maximum iteration limit.
 *
 * @see StepRunner
 * @package Cms\Core\Workflows\Runtime\Runners
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class LoopEachRunner implements StepRunner
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
        $config = $step->stepConfig ?? [];
        $collectionExpr = $config['collectionExpr'] ?? null;
        $itemVar = $config['itemVar'] ?? null;
        $indexVar = $config['indexVar'] ?? null;
        $maxItemsCfg = $config['maxItems'] ?? null;

        if (!is_string($collectionExpr) || trim($collectionExpr) === '') {
            return StepRunResult::failed('loop_each: collectionExpr is required');
        }

        if (!is_string($itemVar) || !str_starts_with($itemVar, 'vars.')) {
            return StepRunResult::failed('loop_each: itemVar must start with vars.');
        }

        $maxItems = $maxItemsCfg !== null ? (int) $maxItemsCfg : (int) config('core.workflow.max_loop_items', 100);

        try {
            $collection = $this->engine->evaluate($collectionExpr, $context, true);
        } catch (\Throwable $e) {
            return StepRunResult::failed('loop_each: failed to evaluate collectionExpr: ' . $e->getMessage());
        }

        if ($collection instanceof Collection) {
            $collection = $collection->all();
        } elseif ($collection instanceof Traversable) {
            $collection = iterator_to_array($collection);
        }

        if (!is_array($collection)) {
            return StepRunResult::failed('loop_each: collectionExpr must resolve to array or Collection');
        }

        $total = count($collection);
        if ($total > $maxItems) {
            return StepRunResult::failed("loop_each: collection size {$total} exceeds limit {$maxItems}");
        }

        $stepCode = $step->stepCode ?: ($step->id ? 'step_' . $step->id : 'loop_step');
        $state = $context->getStep($stepCode);
        $meta = $state?->getMeta() ?? [];
        $currentIndex = (int) ($meta['loop_index'] ?? 0);

        $logs = [
            ['level' => 'info', 'message' => "Loop start over {$total} item(s)"],
        ];

        if ($total === 0 || $currentIndex >= $total) {
            $logs[] = ['level' => 'info', 'message' => 'Loop finished'];

            return StepRunResult::success(
                [
                    'state' => ['index' => $currentIndex, 'total' => $total],
                    'next' => 'done',
                    'iterations' => $total,
                ],
                array_merge($logs, [[
                    'action' => 'loop_each',
                    'index' => $currentIndex,
                    'total' => $total,
                ]]),
                'done'
            );
        }

        $item = $collection[$currentIndex];
        $context->setVarPath(substr($itemVar, 5), $item);

        if (is_string($indexVar) && str_starts_with($indexVar, 'vars.')) {
            $context->setVarPath(substr($indexVar, 5), $currentIndex);
        }

        $meta['loop_index'] = $currentIndex + 1;
        $meta['loop_total'] = $total;

        $logs[] = ['level' => 'info', 'message' => "Loop iteration {$currentIndex}"];

        $nextHint = ($currentIndex + 1) < $total ? 'loop' : 'done';
        $result = StepRunResult::success([
            'state' => ['index' => $currentIndex, 'total' => $total],
            'next' => $nextHint,
            'iterations' => $total,
            'itemVar' => $itemVar,
            'indexVar' => $indexVar,
        ], array_merge($logs, [[
            'action' => 'loop_each',
            'index' => $currentIndex,
            'total' => $total,
        ]]), $nextHint);

        // Attach meta so executor/context can persist progress
        $result->logs[] = ['level' => 'info', 'message' => "Loop progress {$currentIndex}+1/{$total}"];
        $context->setStepResult($stepCode, $result->resultData, 'success', $meta);

        return $result;
    }
}
