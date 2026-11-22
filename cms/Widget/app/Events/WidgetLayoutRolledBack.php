<?php

declare(strict_types=1);

namespace Cms\Widget\Events;

/**
 * Dispatched by {@see \Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackService}
 * ONLY after the rollback transaction has committed (plan Chunk 2 Task 21,
 * lines 1155-1179; architecture.md "Rollback" step 6).
 *
 * This event carries identity ONLY -- no HTML, Input Values, or provider
 * payloads (mirrors {@see \Cms\Widget\Audit\WidgetAuditWriter}'s own banned-
 * field discipline, and {@see WidgetLayoutPublished}'s identical shape).
 * Task 24's listeners own cache invalidation and telemetry; this event, and
 * the service that dispatches it, own neither.
 *
 * `sourceReleaseVersion` is the historical Release identity that was rolled
 * back TO (distinct from `releaseVersion`, the NEW Release this rollback
 * minted) -- a Task 24 listener needing to explain "why did the layout
 * change" needs both.
 *
 * Never dispatched on an idempotent REPLAY (the same committed transaction
 * this event represents already fired it once) and never dispatched for any
 * failed/stale/rejected rollback attempt (nothing committed, nothing to
 * announce).
 */
final class WidgetLayoutRolledBack
{
    public function __construct(
        public readonly string $projectName,
        public readonly string $layoutUuid,
        public readonly int $sourceReleaseVersion,
        public readonly int $releaseVersion,
        public readonly int $freshDraftVersion,
        public readonly ?int $actorId,
        public readonly string $correlationId,
    ) {
    }
}
