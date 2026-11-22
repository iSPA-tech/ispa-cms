<?php

declare(strict_types=1);

namespace Cms\Widget\Listeners;

use Cms\Widget\Events\WidgetLayoutPublished;
use Cms\Widget\Events\WidgetLayoutRolledBack;
use Cms\Widget\Observability\WidgetRuntimeTelemetry;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache;
use Throwable;

/**
 * Owns cache invalidation for BOTH {@see WidgetLayoutPublished} and
 * {@see WidgetLayoutRolledBack} (plan Chunk 2 Task 24, DoD 1281). Mapped to
 * both events in {@see \Cms\Widget\Providers\EventServiceProvider}.
 *
 * WidgetLayoutPublishService/WidgetLayoutRollbackService intentionally
 * dispatch the event and nothing more (their own docblocks: "This service
 * calls no cache invalidation or telemetry itself -- Task 24's listeners
 * own both") -- this listener is the EXCLUSIVE owner of the invalidation
 * side effect.
 *
 * Because {@see \Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache}
 * keys already embed the active Release VERSION NUMBER (DoD 1278), a
 * superseded key is never read again on its own -- the next resolution
 * simply computes a NEW key for the new version. This listener performs
 * ACTIVE cleanup on top of that passive key-drift: it purges every key it
 * ever wrote for the affected (project, layout uuid) pair via
 * {@see WidgetPlacementCache::forgetAllForLayout()}, so a publish/rollback
 * frees the superseded entries immediately rather than merely leaving them
 * to expire on their own TTL.
 *
 * Failure is safe and observable (DoD 1282): a broken cache backend is
 * reported, never rethrown -- this listener runs strictly AFTER the
 * publish/rollback transaction has already committed (both services only
 * call `event()` once their `DB`-transactional closure has returned), so a
 * failure here can never convert an already-committed response into an
 * error, and never runs at all if that transaction rolled back (the event
 * is simply never dispatched on a stale/rejected/failed attempt).
 */
class InvalidateWidgetPlacementCache
{
    private readonly WidgetPlacementCache $cache;

    private readonly WidgetRuntimeTelemetry $telemetry;

    public function __construct(?WidgetPlacementCache $cache = null, ?WidgetRuntimeTelemetry $telemetry = null)
    {
        $this->cache = $cache ?? new WidgetPlacementCache();
        $this->telemetry = $telemetry ?? new WidgetRuntimeTelemetry();
    }

    public function handle(WidgetLayoutPublished|WidgetLayoutRolledBack $event): void
    {
        try {
            $this->cache->forgetAllForLayout($event->projectName, $event->layoutUuid);
            $this->telemetry->recordCache($event->projectName, '*', 'invalidate');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
