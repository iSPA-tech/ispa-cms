<?php

declare(strict_types=1);

namespace Cms\Core\Jobs;

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Expire a workflow node's approval that nobody decided in time (`expires_after`). Queued with a delay until the
 * expiry time; does nothing when the approval was decided meanwhile or is not due yet (for example on a sync queue,
 * which ignores the delay).
 *
 * @package Cms\Core\Jobs
 * @since   2026-11-24
 */
class ExpireWorkflowApprovalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $approvalId)
    {
    }

    public function handle(AgentApprovalService $approvals): void
    {
        $approval = AgentApproval::query()->find($this->approvalId);
        if ($approval !== null) {
            $approvals->expireIfDue($approval);
        }
    }
}
