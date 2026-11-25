<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Exceptions;

/**
 * Raised by WorkflowProjectRunService::start() when a safety limit of the project is reached (guardrails B7,
 * WorkflowCircuitBreaker::check()); the breaker has paused the project.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @since   2026-11-24
 */
class WorkflowBreakerTripped extends WorkflowProjectInactive
{
    public const CODE = 'breaker_tripped';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("The workflow went over a safety limit ({$reason}) and was paused.");
    }
}
