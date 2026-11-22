<?php

declare(strict_types=1);

namespace Cms\Widget\Providers;

use App\Events\Widget\LegacyWidgetDefinitionChanged;
use App\Events\Widget\WidgetCloned;
use Cms\Widget\Events\WidgetLayoutPublished;
use Cms\Widget\Events\WidgetLayoutRolledBack;
use Cms\Widget\Listeners\InvalidateWidgetPlacementCache;
use Cms\Widget\Listeners\MaterializeLegacyWidgetManifest;
use Cms\Widget\Listeners\RecordWidgetLayoutTelemetry;
use Cms\Widget\Listeners\RepinPlacementsToClone;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Event service provider for the Widget module.
 *
 * Chunk 2 Task 15a: registers the module's after-commit materialization
 * listener against the stable root event via the explicit `$listen`
 * mapping below -- which is the ONLY mechanism that registers it.
 *
 * Auto-discovery does NOT and CANNOT apply here (an earlier docblock
 * claimed otherwise; corrected): Laravel's
 * {@see ServiceProvider::shouldDiscoverEvents()} returns
 * `get_class($this) === __CLASS__ && static::$shouldDiscoverEvents === true`,
 * and `__CLASS__` there is the framework's own base
 * `Illuminate\...\EventServiceProvider`. Because this class is a SUBCLASS,
 * `get_class($this)` never equals that base, so `shouldDiscoverEvents()`
 * is structurally always false regardless of the `$shouldDiscoverEvents`
 * flag below -- discovery simply never runs for a subclassed provider. Even
 * if it did, the base discovers listeners under the ROOT `app/Listeners`
 * directory (its own `discoverEventsWithin()` base path), not this module's
 * `cms/Widget/app/Listeners`, so it would never find
 * {@see MaterializeLegacyWidgetManifest} anyway. The `$listen` array is
 * therefore the sole, frozen, human-auditable source of truth for this
 * module's event wiring; the inherited `$shouldDiscoverEvents` property is
 * left at its default only to match the framework scaffold and is inert.
 *
 * @package Cms\Widget\Providers
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        LegacyWidgetDefinitionChanged::class => [
            MaterializeLegacyWidgetManifest::class,
        ],

        // Chunk 2 Task 24: cache invalidation + telemetry, mapped to BOTH
        // Layout lifecycle events (DoD 1281). WidgetLayoutPublishService/
        // WidgetLayoutRollbackService dispatch the event only after their
        // own transaction has committed and never on replay -- these
        // listeners exclusively own invalidation/telemetry; the publishing
        // services themselves call neither directly (see each service's
        // own docblock).
        WidgetLayoutPublished::class => [
            InvalidateWidgetPlacementCache::class,
            RecordWidgetLayoutTelemetry::class,
        ],
        WidgetLayoutRolledBack::class => [
            InvalidateWidgetPlacementCache::class,
            RecordWidgetLayoutTelemetry::class,
        ],

        // Part 2 Task 2: area placements in the current drafts follow a site clone of a hub
        // widget. Synchronous; the root event is dispatched after the clone commits.
        WidgetCloned::class => [
            RepinPlacementsToClone::class,
        ],
    ];

    /**
     * Inert for a subclassed provider (see the class docblock): kept only
     * to mirror the framework scaffold. `shouldDiscoverEvents()` is always
     * false here regardless of this value.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;
}
