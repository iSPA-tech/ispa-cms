<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

/**
 * Outcome of an AI budget check.
 */
final readonly class AiBudgetDecision
{
    public const REASON_KILL_SWITCH = 'kill_switch';

    public const REASON_BUDGET_EXCEEDED = 'budget_exceeded';

    /**
     * @param bool $allowed Whether the AI call may proceed.
     * @param string|null $reason Refusal reason constant when not allowed.
     * @param string $month Budget month `Y-m` (UTC).
     * @param float $spentUsd Estimated spend of the month so far.
     * @param float $capUsd Monthly cap in USD (0 = no cap).
     */
    public function __construct(
        public bool $allowed,
        public ?string $reason,
        public string $month,
        public float $spentUsd,
        public float $capUsd,
    ) {
    }
}
