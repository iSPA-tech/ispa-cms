<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Starts the error workflow of a failed run. Queued (after commit) so the executor has written the failure row
 * whose message the error workflow receives.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class StartErrorWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $failedInstanceId)
    {
        $this->afterCommit();
    }

    public function handle(ErrorWorkflowLauncher $launcher): void
    {
        $launcher->instanceFailed($this->failedInstanceId);
    }
}
