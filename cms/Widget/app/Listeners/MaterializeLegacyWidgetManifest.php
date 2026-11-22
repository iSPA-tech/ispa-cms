<?php

declare(strict_types=1);

namespace Cms\Widget\Listeners;

use App\Events\Widget\LegacyWidgetDefinitionChanged;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After-commit listener for {@see LegacyWidgetDefinitionChanged} (plan
 * Chunk 2 Task 15a). Registered explicitly (not auto-discovered) in
 * {@see \Cms\Widget\Providers\EventServiceProvider::$listen}.
 *
 * Runs synchronously the moment the event fires -- which is already
 * guaranteed to be after the triggering database mutation committed
 * (WidgetDefinitionSyncService only ever dispatches the event from inside
 * sync(), itself only ever called by its Core controllers after
 * `DB::commit()`). No queue/ShouldQueue is used: the event is dispatched
 * post-commit by construction, so there is no open transaction left to wait
 * on.
 *
 * IDEMPOTENT BY CANONICAL SOURCE FINGERPRINT: for every (widget, theme)
 * pair currently applicable to an affected project, this listener always
 * calls {@see WidgetLegacyManifestMaterializer::materialize()} with no
 * expected fingerprint -- the materializer itself recomputes the live
 * fingerprint and reuses an existing exact-hash Manifest row rather than
 * creating a duplicate, so re-running this listener for unchanged content
 * is always a cheap no-op read.
 *
 * FAN-OUT: re-derives affected (widget, theme) pairs from
 * {@see LegacyWidgetArtifactSetPublisher::buildProjectThemeMap()} --
 * exactly the same grouping the legacy byte generator itself uses, so
 * "fan out one Manifest per named project" always matches the CURRENT
 * database state rather than trusting anything precomputed on the event.
 * Each affected project name yields only the themes CURRENTLY applicable
 * to it (map is built from live rows), so a theme whose `project_name`
 * itself just changed is materialized only for its current applicability,
 * never a stale prior one.
 *
 * One bad pair never aborts the batch or bubbles into the HTTP request
 * that triggered it: every per-pair failure is caught and reported, never
 * rethrown. Never approves a Manifest.
 */
final class MaterializeLegacyWidgetManifest
{
    public function __construct(
        private readonly LegacyWidgetArtifactSetPublisher $publisher,
        private readonly WidgetLegacyManifestMaterializer $materializer,
    ) {
    }

    public function handle(LegacyWidgetDefinitionChanged $event): void
    {
        $map = $this->publisher->buildProjectThemeMap();

        foreach ($event->affectedProjects as $projectName) {
            foreach ($map[$projectName] ?? [] as $theme) {
                try {
                    $this->materializer->materialize((int) $theme->widget_id, (int) $theme->id, (string) $projectName);
                } catch (Throwable $e) {
                    // Never let one pair's failure abort the after-commit
                    // listener or escape into the request that triggered it;
                    // the reconciliation command is the retry owner.
                    report($e);

                    // Correlate this listener-side failure with the legacy
                    // publish incident that immediately preceded it, exactly
                    // as the event's incidentId docblock advertises. Safe
                    // context only -- the exception CLASS NAME, never its
                    // message (which could echo source content).
                    Log::warning('Legacy widget manifest materialization failed for one pair; reconciliation owns retry.', [
                        'incident_id' => $event->incidentId,
                        'sync_status' => $event->syncStatus,
                        'widget_id' => (int) $theme->widget_id,
                        'widget_theme_id' => (int) $theme->id,
                        'project_name' => (string) $projectName,
                        'exception_class' => $e::class,
                    ]);
                }
            }
        }
    }
}
