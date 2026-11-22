<?php

declare(strict_types=1);

namespace App\Events\Widget;

/**
 * Stable root event announcing that a committed aggregate/direct legacy
 * Widget, Theme, or Input mutation just finished its post-commit
 * synchronization attempt (plan Chunk 2 Task 15a; 
 * lines 886-920).
 *
 * Dispatched from {@see \Cms\Core\Services\Widget\WidgetDefinitionSyncService::sync()}
 * for BOTH outcomes -- `ready` and `pending` -- because the underlying
 * database mutation already committed either way; a legacy export/publish
 * failure never blocks Site Layout materialization, it only changes
 * `$syncStatus`/`$incidentId`. Root/Core event: primitives only. This
 * comment deliberately omits the namespace of the module that listens for
 * this event: the module-boundary regression suite scans root/app files
 * for that namespace token to keep the dependency direction one-way
 * (docs/architecture/adr-widget-module-boundary.md) -- no such class is
 * imported or referenced anywhere in this file.
 *
 * PAYLOAD SHAPE (disclosed deviation from the coordinator's illustrative
 * "widgetId int, themeIds int[]?" sketch): WidgetDefinitionSyncService::sync()
 * is called by every Core controller (WidgetController, WidgetThemeController,
 * WidgetInputController) strictly AFTER DB::commit(), and its ONLY
 * parameter is `array<int, string> $affectedProjects` -- the post-commit
 * project fan-out, never a specific widget/theme id. Those three
 * controllers are read-only for this task (Core controllers NOT modifiable
 * here), so this event carries exactly what the emission site actually
 * knows: the affected project names plus the sync outcome. The module
 * listener never trusts a precomputed identity anyway ("the module
 * listener re-derives everything server-side anyway") -- it re-derives
 * every (Widget, Theme) pair currently applicable to each named project via
 * {@see \Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher::buildProjectThemeMap()}
 * and recomputes each pair's own canonical source fingerprint from
 * scratch, so no precomputed widget/theme id would have added trustworthy
 * information here.
 */
final readonly class LegacyWidgetDefinitionChanged
{
    /**
     * @param array<int, string> $affectedProjects Project names whose
     *        complete legacy Widget/Theme artifact set was just (re)synced
     *        as a result of the mutation that committed. Mirrors exactly
     *        the fan-out WidgetDefinitionSyncService::sync() itself
     *        received -- the same list already used to rebuild the legacy
     *        generated-artifact set.
     * @param string $syncStatus WidgetDefinitionSyncResult::$status verbatim
     *        ('ready'|'pending'). Observability context only: the module
     *        listener materializes pending Manifests regardless of this
     *        value, since the Widget/Theme/Input row mutation this event
     *        reports already committed either way.
     * @param string|null $incidentId WidgetDefinitionSyncResult::$incidentId
     *        verbatim, present only when $syncStatus is 'pending'. Lets a
     *        listener-side materialization run be correlated in logs with
     *        the legacy publish incident that immediately preceded it.
     */
    public function __construct(
        public array $affectedProjects,
        public string $syncStatus,
        public ?string $incidentId = null,
    ) {
    }
}
