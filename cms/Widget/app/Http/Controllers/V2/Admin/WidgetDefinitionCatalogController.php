<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Http\Resources\V2\Admin\WidgetDefinitionCatalogResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `GET /api/v2/admin/widget/catalog/definitions` and
 * `GET /api/v2/admin/widget/catalog/definitions/{widget}` (plan Chunk 2 Task
 * 22; fixture route entries, note: "approved eligible manifests only
 * (checklist item 4 split-catalog semantics)").
 *
 * THIS IS NOT the `GET /catalog/manifests` approval inventory
 * ({@see WidgetManifestEligibilityCatalogController}, which lists EVERY
 * pending/approved/revoked Manifest for `widget_template.approve` holders).
 * This is the PLACEMENT-SELECTABLE catalog. Unified Widget Catalog Part 2
 * dropped the approval step: every applicable (Widget, Theme) pair is listed
 * whether its current Manifest is approved, pending, revoked or not yet
 * materialized, because placing it approves the Manifest automatically
 * ({@see \Cms\Widget\Rendering\Eligibility\WidgetManifestAutoApprover}).
 * Only blocked pairs are omitted: a Core-blocked widget (`Widget::isBlocked()`)
 * or a current Manifest under an emergency runtime block.
 *
 * Safe gateways only, no raw Core queries beyond the same read-only
 * (Widget, Theme, project) enumeration {@see WidgetLegacyManifestDriftReader}
 * already performs: {@see LegacyWidgetArtifactSetPublisher::buildProjectThemeMap()}
 * enumerates Theme rows scoped to the authorized project, and
 * {@see WidgetLegacyManifestMaterializer::inspect()} (Task 15a's own
 * fingerprint-computation gateway) reports whether a CURRENT Manifest
 * exists and its safe `eligibility_state`/`attestation_version` -- never
 * touching Theme content/CSS/JS or artifact bytes directly. The `blocked`
 * emergency-control flag is read the same direct, safe way
 * {@see WidgetManifestEligibilityCatalogController} already reads Manifest
 * rows: project_name + manifest_hash only, never a wider projection.
 */
final class WidgetDefinitionCatalogController
{
    public function __construct(
        private readonly LegacyWidgetArtifactSetPublisher $publisher,
        private readonly WidgetLegacyManifestMaterializer $materializer,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('widget_instance.viewAny') !== true) {
            return $this->permissionDenied();
        }

        $project = $this->projectContext->resolve();
        $rows = $this->eligibleRowsForProject($project->projectName);

        return WidgetDefinitionCatalogResource::collection(collect($rows))->response();
    }

    public function show(Request $request, string $widget): JsonResponse
    {
        if ($request->user()?->can('widget_instance.viewAny') !== true) {
            return $this->permissionDenied();
        }

        $project = $this->projectContext->resolve();
        $widgetId = (int) $widget;

        $rows = array_values(array_filter(
            $this->eligibleRowsForProject($project->projectName),
            static fn (array $row): bool => $row['widget_id'] === $widgetId,
        ));

        if ($rows === []) {
            return response()->json([
                'status' => 404,
                'message' => 'Widget definition not found in the authorized project scope.',
                'correlation_id' => (string) Str::uuid(),
            ], 404);
        }

        return WidgetDefinitionCatalogResource::collection(collect($rows))->response();
    }

    private function permissionDenied(): JsonResponse
    {
        return WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to view the Widget Definition catalog.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligibleRowsForProject(string $projectName): array
    {
        $themes = $this->publisher->buildProjectThemeMap()[$projectName] ?? [];
        $rows = [];

        foreach ($themes as $theme) {
            /** @var WidgetTheme $theme */
            $widgetId = (int) $theme->widget_id;
            $themeId = (int) $theme->id;

            // A Core-blocked widget is never offered for placing (placing it returns 409).
            if ($theme->widget?->isBlocked() === true) {
                continue;
            }

            $inspection = $this->materializer->inspect($widgetId, $themeId, $projectName);

            if (!$inspection['applicable']) {
                continue;
            }

            // No approval step any more: a pending, revoked or not yet materialized manifest is
            // approved automatically when the widget is placed (WidgetManifestAutoApprover), so
            // new widgets, clones and deploy-changed hub widgets are listed. Only a manifest under
            // an emergency runtime block stays hidden.
            if ($inspection['has_current_manifest']) {
                $blocked = (bool) WidgetArtifactManifestModel::query()
                    ->where('project_name', $projectName)
                    ->where('manifest_hash', $inspection['manifest_hash'])
                    ->value('blocked');

                if ($blocked) {
                    continue;
                }
            }

            $rows[] = [
                'widget_id' => $widgetId,
                'widget_theme_id' => $themeId,
                'name' => (string) ($theme->widget?->name ?? ''),
                'theme_name' => (string) $theme->name,
                // Names in the request locale; `label` uses them.
                'display_name' => (string) ($theme->widget?->localizedName() ?? ''),
                'theme_display_name' => $theme->localizedName(),
                'label' => sprintf('%s: %s', (string) ($theme->widget?->localizedName() ?? ''), $theme->localizedName()),
                'eligible' => true,
                'manifest_hash' => $inspection['manifest_hash'],
                'attestation_version' => $inspection['attestation_version'],
                'applicability' => $this->applicableProjectNames($theme->project_name),
                // Additive (fixture updated in lockstep): the pair's typed
                // input schema, so the Instance editor can rebuild its
                // content fields the moment a definition switch is
                // confirmed instead of keeping the OLD pair's fields on
                // screen until a save round-trips.
                'canonical_schema' => (new CanonicalWidgetSchema())->resolve($widgetId, $themeId),
            ];
        }

        return $rows;
    }

    /**
     * Mirrors {@see \Cms\Widget\Definition\WidgetDefinitionGateway}'s private
     * `applicableProjectNames()` normalization rule verbatim (documented
     * there as "the exact legacy fan-out semantics" already established by
     * {@see \Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer::applicableProjectNames()}):
     * a non-array, non-empty scalar wraps to one name; a non-array empty
     * value falls back to `['Default']`; an array, including empty, is used
     * as-is. Duplicated here (not called on the gateway) because the
     * gateway exposes no PUBLIC accessor for the full list, only a single-
     * project boolean check -- this task's file list does not touch that
     * gateway class.
     *
     * @return list<string>
     */
    private function applicableProjectNames(mixed $projectName): array
    {
        if (!is_array($projectName)) {
            return !empty($projectName) ? [(string) $projectName] : ['Default'];
        }

        return array_values(array_map('strval', $projectName));
    }
}
