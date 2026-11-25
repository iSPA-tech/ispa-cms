<?php

namespace Cms\Core\Services\Workflow\Triggers;

use RuntimeException;

/**
 * A trigger did not start a run: loop or depth guard, no scheduler allowed to act, or a start node of another type.
 * `reason` is machine-readable (trigger_loop, trigger_depth_exceeded, scheduler_not_allowed, wrong_trigger, ...).
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class WorkflowTriggerRefused extends RuntimeException
{
    public const CODE = 'workflow_trigger_refused';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The workflow trigger was refused: ' . $reason);
    }
}
