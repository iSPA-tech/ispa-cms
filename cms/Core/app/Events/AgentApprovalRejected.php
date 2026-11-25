<?php

declare(strict_types=1);

namespace Cms\Core\Events;

/**
 * A reviewer rejected an agent approval with a declared reject reason (dispatched after the rejection committed).
 * Modules that own the proposal react to their own reasons, e.g. a `skip_item` reason.
 *
 * @package Cms\Core\Events
 * @since   2026-11-24
 */
final class AgentApprovalRejected
{
    public function __construct(
        public readonly int $approvalId,
        public readonly string $reason,
    ) {
    }
}
