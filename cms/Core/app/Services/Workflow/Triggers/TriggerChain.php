<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowInstance;

/**
 * Loop and depth guard for runs that start other runs.
 *
 * Every run stores `trigger.meta.chain`: the ids of the projects whose runs led to it, itself last. A run started
 * from inside another run (a sub-workflow, a model event a run's write raised, an error workflow) gets the parent's
 * chain plus its own project. A start is refused when the project is already in the chain (a loop: a workflow
 * writing the model it listens to, A calls B calls A) or when the chain would get longer than `max_chain_depth`.
 *
 * "Inside a run" is known from the workflow job being processed (WorkflowTriggerBootstrap pushes the instance on
 * JobProcessing of RunWorkflowInstanceJob and pops it after), so code a run reaches indirectly is covered too.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
final class TriggerChain
{
    /** @var list<int> Instance ids being executed in this process, innermost last. */
    private static array $running = [];

    public static function enter(int $instanceId): void
    {
        self::$running[] = $instanceId;
    }

    public static function leave(int $instanceId): void
    {
        $index = array_search($instanceId, self::$running, true);
        if ($index !== false) {
            array_splice(self::$running, (int) $index, 1);
        }
    }

    /**
     * Run $callback as part of a workflow run: model events raised inside it see the run's chain (loop guard), like
     * writes inside the run's own queued job. Null runs it unchanged. Used by the executor (inline executions) and by
     * approval apply over HTTP (review finding 29).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function within(?int $instanceId, callable $callback): mixed
    {
        if ($instanceId === null || $instanceId <= 0) {
            return $callback();
        }

        self::enter($instanceId);
        try {
            return $callback();
        } finally {
            self::leave($instanceId);
        }
    }

    public static function reset(): void
    {
        self::$running = [];
    }

    /**
     * Instance id executing in this process right now, if any.
     */
    public static function currentInstanceId(): ?int
    {
        return self::$running === [] ? null : self::$running[array_key_last(self::$running)];
    }

    /**
     * Chain of the run executing in this process (empty outside a run).
     *
     * @return list<int>
     */
    public static function current(): array
    {
        $id = self::currentInstanceId();
        if ($id === null) {
            return [];
        }

        $instance = WorkflowInstance::query()->find($id);

        return $instance ? self::of($instance) : [];
    }

    /**
     * Chain stored on an instance (its own project last; runs from before chains existed count as [project]).
     *
     * @return list<int>
     */
    public static function of(WorkflowInstance $instance): array
    {
        $chain = data_get($instance->context_snapshot, 'trigger.meta.chain');
        $chain = is_array($chain) ? array_values(array_map('intval', $chain)) : [];

        if ($chain === [] || end($chain) !== (int) $instance->project_id) {
            $chain[] = (int) $instance->project_id;
        }

        return $chain;
    }

    /**
     * Why $projectId may not start from $parentChain, or null when it may.
     *
     * @param list<int> $parentChain
     */
    public static function refusal(int $projectId, array $parentChain): ?string
    {
        if (in_array($projectId, $parentChain, true)) {
            return 'trigger_loop';
        }

        if (count($parentChain) + 1 > max(1, (int) WorkflowTriggers::setting('max_chain_depth', 5))) {
            return 'trigger_depth_exceeded';
        }

        return null;
    }

    /**
     * @param list<int> $parentChain
     * @return list<int>
     */
    public static function extend(array $parentChain, int $projectId): array
    {
        return array_values(array_merge($parentChain, [$projectId]));
    }
}
