<?php

declare(strict_types=1);

namespace Cms\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A workflow version failed its integrity check (B2). The project has already been turned off
 * (`paused_reason = integrity`). Listeners alert people (lane 2); the payload carries ids and a reason code only.
 *
 * @package Cms\Core\Events
 * @since   2026-11-24
 */
class WorkflowIntegrityViolated
{
    use Dispatchable;

    /**
     * @param string $reason   hash_mismatch | seal_mismatch | version_missing | project_mismatch
     * @param string $detected executor | start | integrity_check
     */
    public function __construct(
        public readonly int $projectId,
        public readonly ?int $versionId,
        public readonly ?int $instanceId,
        public readonly string $reason,
        public readonly string $detected,
    ) {
    }
}
