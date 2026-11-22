<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

/**
 * Read-only health/drift report over every currently-known legacy
 * (Widget, Theme, project) triple (plan Chunk 2 Task 15a checklist item 8).
 *
 * Never persists, mutates, approves, or blocks anything -- it only tells an
 * operator/health endpoint WHICH live source fingerprints currently have no
 * matching Manifest row ("drift": content changed since the last
 * materialization run, or a pair that was never materialized at all) and,
 * for pairs that DO have one, its safe pending/approved/revoked
 * `eligibility_state`. Output is deliberately safe/minimal: widget id,
 * theme id, project name, whether a current Manifest exists, its
 * `manifest_hash`, and its `eligibility_state` only -- never Theme
 * content/CSS/JS, artifact bytes, or any storage path
 * (checklist: "without leaking source/values/paths").
 *
 * "Build incident correlation": a per-pair computation failure (e.g. a
 * transient database/filesystem error while rendering bytes to compute the
 * live fingerprint) is caught and reported as a safe, correlatable
 * `incident` row instead of aborting the whole report -- one bad pair never
 * hides drift information for every other pair.
 */
final class WidgetLegacyManifestDriftReader
{
    public function __construct(
        private readonly LegacyWidgetArtifactSetPublisher $publisher,
        private readonly WidgetLegacyManifestMaterializer $materializer,
    ) {
    }

    /**
     * @return array{
     *     drifted: list<array{widget_id: int, theme_id: int, project_name: string}>,
     *     current: list<array{widget_id: int, theme_id: int, project_name: string, manifest_hash: string, eligibility_state: string, attestation_version: int}>,
     *     incidents: list<array{widget_id: int, theme_id: int, project_name: string, message: string}>
     * }
     */
    public function report(): array
    {
        $drifted = [];
        $current = [];
        $incidents = [];

        foreach ($this->publisher->buildProjectThemeMap() as $projectName => $themes) {
            foreach ($themes as $theme) {
                $widgetId = (int) $theme->widget_id;
                $themeId = (int) $theme->id;

                try {
                    $inspection = $this->materializer->inspect($widgetId, $themeId, (string) $projectName);
                } catch (\Throwable $e) {
                    report($e);
                    $incidents[] = [
                        'widget_id' => $widgetId,
                        'theme_id' => $themeId,
                        'project_name' => (string) $projectName,
                        // Safe: the exception's class name only, never its
                        // message (which could echo source content).
                        'message' => $e::class,
                    ];
                    continue;
                }

                if (!$inspection['applicable']) {
                    continue;
                }

                if (!$inspection['has_current_manifest']) {
                    $drifted[] = [
                        'widget_id' => $widgetId,
                        'theme_id' => $themeId,
                        'project_name' => (string) $projectName,
                    ];
                    continue;
                }

                $current[] = [
                    'widget_id' => $widgetId,
                    'theme_id' => $themeId,
                    'project_name' => (string) $projectName,
                    'manifest_hash' => (string) $inspection['manifest_hash'],
                    'eligibility_state' => (string) $inspection['eligibility_state'],
                    'attestation_version' => (int) $inspection['attestation_version'],
                ];
            }
        }

        return [
            'drifted' => $drifted,
            'current' => $current,
            'incidents' => $incidents,
        ];
    }
}
