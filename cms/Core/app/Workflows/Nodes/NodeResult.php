<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Nodes;

/**
 * What a module node method returns instead of a plain array when it spent money: the data stored under `storeAs`
 * plus the usage {@see ModuleNodeRunner} hands to the executor (`{task_key, provider, model, tokens_in, tokens_out,
 * cost_usd}`), which adds it to the run's budget. An `ai_spend` node that returns a plain array counts as a call of
 * unknown cost, and a per-run cost cap refuses every AI step after it.
 *
 * @package Cms\Core\Workflows\Nodes
 * @since   2026-11-24
 */
final class NodeResult
{
    /**
     * @param array<string, mixed>      $data  Stored under the step's `storeAs`
     * @param array<string, mixed>|null $usage What the call spent; null when nothing was spent or measured
     */
    public function __construct(
        public readonly array $data,
        public readonly ?array $usage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $usage
     */
    public static function withUsage(array $data, array $usage): self
    {
        return new self($data, $usage);
    }
}
