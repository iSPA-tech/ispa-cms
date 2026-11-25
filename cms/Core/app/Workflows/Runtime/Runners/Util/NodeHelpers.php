<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Collection;
use Traversable;

/**
 * Shared helpers for the flow and utility nodes: expression evaluation, `storeAs` writes,
 * and safe routing of named outputs the executor does not know as branch outputs yet.
 */
trait NodeHelpers
{
    protected ?WorkflowExpressionEngine $expressionEngine = null;

    protected function engine(): WorkflowExpressionEngine
    {
        return $this->expressionEngine ??= new WorkflowExpressionEngine();
    }

    /**
     * Evaluate an expression field (strict); a missing/empty field returns $default.
     */
    protected function evalField(array $config, string $field, WorkflowContext $context, mixed $default = null): mixed
    {
        $expr = $config[$field] ?? null;
        if ($expr === null || (is_string($expr) && trim($expr) === '')) {
            return $default;
        }
        if (!is_string($expr)) {
            return $expr;
        }

        return $this->engine()->evaluate($expr, $context, true);
    }

    /**
     * Normalise a collection-like value to a plain list, or null when it is not one.
     *
     * @return array<int|string, mixed>|null
     */
    protected function toList(mixed $value): ?array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Validated `storeAs` path without the `vars.` prefix, or null when invalid/missing.
     */
    protected function storePath(array $config, ?string $fallback = null): ?string
    {
        $storeAs = $config['storeAs'] ?? $fallback;
        if (!is_string($storeAs) || !str_starts_with($storeAs, 'vars.') || strlen($storeAs) <= 5) {
            return null;
        }

        return substr($storeAs, 5);
    }

    /**
     * Ends the run quietly when the chosen output has nowhere safe to go.
     *
     * The executor treats only its built-in handles as branches; for this node's own handles (e.g. `busy`
     * next to a lone `acquired` edge) it would follow the other branch's edge. A "negative" output (busy,
     * limited, invalid, seen) may only follow its own edge or `default`; a positive one may also follow
     * an unnamed edge. Otherwise the run ends here, like an unmatched `case:` without `default`.
     *
     * @param list<string> $nodeHandles Every named output this node has.
     */
    protected function routeOutput(
        StepRunResult $result,
        StepDefinition $step,
        string $output,
        array $nodeHandles,
        bool $negative,
    ): StepRunResult {
        $result->withOutput($output);
        if ($step->id === null) {
            return $result;
        }

        // The run's version, never the draft rows (RunGraph).
        $handles = \Cms\Core\Services\Workflow\Versioning\RunGraph::edgesFrom((int) $step->id)
            ->map(fn ($edge) => self::edgeHandle($edge->condition))
            ->reject(fn ($handle) => $handle === 'error');

        if ($handles->isEmpty() || $handles->contains($output) || $handles->contains('default')
            || $handles->contains('no_proposal')) {
            return $result;
        }

        $hasUnnamed = $handles->contains(fn ($handle) => $handle === null || !in_array($handle, $nodeHandles, true));
        if (!$negative && $hasUnnamed) {
            return $result;
        }

        // End this path: `no_proposal` is a strict executor output ("nothing to do") with no edge here, so the
        // executor follows nothing and the run completes. Replace with the node's own handle once the executor
        // lists it as a strict branch output.
        $result->withOutput('no_proposal');
        $data = is_array($result->resultData) ? $result->resultData : ['value' => $result->resultData];
        $result->resultData = $data + ['output' => $output, 'ended' => true];
        $result->logs[] = ['level' => 'info', 'message' => "Output '{$output}' has no edge; the run ends here"];

        return $result;
    }

    /**
     * Same reading of an edge's handle as the executor (source_handle / equals condition / label).
     */
    protected static function edgeHandle(mixed $condition): ?string
    {
        $condition = is_array($condition) ? $condition : [];
        $type = $condition['condition_type'] ?? null;
        $value = $condition['condition_value'] ?? null;
        $source = $condition['source_handle'] ?? null;
        $label = $condition['condition'] ?? null;

        if ((!is_string($type) || trim($type) === '') && is_string($source) && trim($source) !== '') {
            return is_string($value) && trim($value) !== '' ? trim($value) : trim($source);
        }
        if ((!is_string($type) || trim($type) === '') && is_string($label) && trim($label) !== '') {
            return trim($label);
        }
        if ($type === 'equals' && is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    protected function projectKey(StepDefinition $step): int
    {
        return (int) ($step->projectId ?? 0);
    }

    protected function instanceId(WorkflowContext $context): ?int
    {
        $id = $context->getVarPath(WorkflowContext::RUNTIME_KEY . '.instance_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
