<?php

namespace Cms\Core\Workflows\Runtime\Runners\Flow;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Hands out a list in batches: each visit stores the next `batch_size` items at `storeAs` and leaves by
 * `batch`; once the list is used up it leaves by `done` and resets its cursor (the cursor lives in the
 * step state, so it survives continuations). Wire the batch body back into this node.
 */
class SplitInBatchesRunner implements StepRunner
{
    use NodeHelpers;

    public const HANDLES = ['batch', 'done'];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $path = $this->storePath($config, 'vars.batch');
        if ($path === null) {
            return StepRunResult::failed('split_in_batches: storeAs must start with vars.');
        }

        $size = (int) ($config['batch_size'] ?? 10);
        if ($size < 1 || $size > 1000) {
            return StepRunResult::failed('split_in_batches: batch_size must be between 1 and 1000');
        }

        try {
            $items = $this->toList($this->evalField($config, 'collectionExpr', $context));
        } catch (\Throwable $e) {
            return StepRunResult::failed('split_in_batches: ' . $e->getMessage());
        }
        if ($items === null) {
            return StepRunResult::failed('split_in_batches: collectionExpr must resolve to a list');
        }

        $cap = (int) config('core.workflow.max_batch_items', 10000);
        $items = array_values($items);
        $total = count($items);
        if ($total > $cap) {
            return StepRunResult::failed("split_in_batches: list size {$total} exceeds limit {$cap}");
        }

        $stepCode = $step->stepCode ?: ($step->id ? 'step_' . $step->id : 'split_in_batches');
        $meta = $context->getStep($stepCode)?->getMeta() ?? [];
        $cursor = (int) ($meta['batch_cursor'] ?? 0);

        if ($cursor >= $total) {
            $meta['batch_cursor'] = 0;
            $context->setStepResult($stepCode, null, 'success', $meta);
            $result = StepRunResult::success(['total' => $total, 'batches' => (int) ceil($total / $size)]);

            return $this->routeOutput($result, $step, 'done', self::HANDLES, false);
        }

        $batch = array_slice($items, $cursor, $size);
        $context->setVarPath($path, $batch);
        $meta['batch_cursor'] = $cursor + count($batch);
        $data = ['offset' => $cursor, 'count' => count($batch), 'total' => $total];
        $context->setStepResult($stepCode, $data, 'success', $meta);

        return $this->routeOutput(StepRunResult::success($data), $step, 'batch', self::HANDLES, false);
    }
}
