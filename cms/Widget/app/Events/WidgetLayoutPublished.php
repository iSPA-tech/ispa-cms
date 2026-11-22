<?php

declare(strict_types=1);

namespace Cms\Widget\Events;

/**
 * Dispatched by {@see \Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService}
 * ONLY after the publish transaction has committed (plan Chunk 2 Task 20,
 * lines 1126-1153; architecture.md "Publish" step 10: "After commit, emit
 * cache/telemetry notifications").
 *
 * This event carries identity ONLY -- no HTML, Input Values, or provider
 * payloads (mirrors {@see \Cms\Widget\Audit\WidgetAuditWriter}'s own banned-
 * field discipline). Task 24's listeners own cache invalidation and
 * telemetry; this event, and the service that dispatches it, own neither.
 *
 * Never dispatched on an idempotent REPLAY (the same committed transaction
 * this event represents already fired it once) and never dispatched for any
 * failed/stale/rejected publish attempt (nothing committed, nothing to
 * announce).
 */
final class WidgetLayoutPublished
{
    public function __construct(
        public readonly string $projectName,
        public readonly string $layoutUuid,
        public readonly int $releaseVersion,
        public readonly int $freshDraftVersion,
        public readonly ?int $actorId,
        public readonly string $correlationId,
    ) {
    }
}
