<?php

declare(strict_types=1);

namespace Cms\Widget\Console\Commands;

use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializationResult;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Illuminate\Console\Command;
use Throwable;

/**
 * `cms:widget-manifests-reconcile` (plan Chunk 2 Task 15a checklist items
 * 4-5).
 *
 * Operator retry/reconciliation tool -- the "missed-event" safety net for
 * {@see \Cms\Widget\Listeners\MaterializeLegacyWidgetManifest}: scans EVERY
 * currently-known legacy (Widget, Theme, project) triple via
 * {@see LegacyWidgetArtifactSetPublisher::buildProjectThemeMap()} (no event
 * filtering, unlike the listener) and materializes each one, so a source
 * mutation whose event was somehow never dispatched or never delivered
 * (deployment gap, a crashed process, a manually seeded/imported row, ...)
 * still gets a pending Manifest on the next scheduled or manual run.
 *
 * Repeatable: re-running against unchanged content is a cheap no-op for
 * every pair (idempotent by canonical source fingerprint, the same
 * guarantee the materializer itself provides). Never approves a Manifest --
 * that is exclusively Task 15b's scope.
 *
 * Exit code is always SUCCESS: this command is an operator retry/health
 * tool, not a deployment gate like `cms:widget-registry-preflight`. A
 * per-pair failure is caught, reported in the command output, and counted
 * as an incident, but never turns the command itself into a failure --
 * mirroring WidgetDefinitionSyncService's own "a failure becomes a
 * reported/logged incident, never a hard abort" philosophy. This also
 * keeps the command safe to run unattended on a schedule.
 */
final class WidgetLegacyManifestReconciliationCommand extends Command
{
    protected $signature = 'cms:widget-manifests-reconcile';

    protected $description = 'Materialize (or idempotently reuse) a pending Widget Artifact Manifest for every currently-known legacy Widget/Theme/project triple.';

    public function __construct(
        private readonly LegacyWidgetArtifactSetPublisher $publisher,
        private readonly WidgetLegacyManifestMaterializer $materializer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $created = 0;
        $reused = 0;
        $skipped = 0;
        $incidents = [];

        foreach ($this->publisher->buildProjectThemeMap() as $projectName => $themes) {
            foreach ($themes as $theme) {
                $widgetId = (int) $theme->widget_id;
                $themeId = (int) $theme->id;

                try {
                    $result = $this->materializer->materialize($widgetId, $themeId, (string) $projectName);
                } catch (Throwable $e) {
                    report($e);
                    $incidents[] = sprintf(
                        'widget=%d theme=%d project=%s: %s',
                        $widgetId,
                        $themeId,
                        (string) $projectName,
                        $e::class
                    );
                    continue;
                }

                match ($result->status) {
                    WidgetLegacyManifestMaterializationResult::STATUS_CREATED => $created++,
                    WidgetLegacyManifestMaterializationResult::STATUS_REUSED => $reused++,
                    default => $skipped++,
                };
            }
        }

        $this->info(sprintf(
            'Widget Manifest reconciliation complete: %d created, %d reused, %d skipped, %d incident(s).',
            $created,
            $reused,
            $skipped,
            count($incidents)
        ));

        foreach ($incidents as $incident) {
            $this->error($incident);
        }

        return self::SUCCESS;
    }
}
