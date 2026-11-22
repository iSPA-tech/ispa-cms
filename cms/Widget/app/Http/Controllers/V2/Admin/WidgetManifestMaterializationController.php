<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetManifest\MaterializeWidgetManifestRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetArtifactManifestResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializationResult;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `POST /api/v2/admin/widget/catalog/manifests/materialize` (plan Chunk 2
 * Task 15a checklist item 6; docs/contracts/widget-site-layout-api.md
 * "Catalog (split semantics)").
 *
 * The FIRST controller in this module. Accepts only
 * `widget_id`/`widget_theme_id`/`expected_source_fingerprint` -- no project
 * selector, project/schema/provider contracts are derived server-side via
 * {@see WidgetProjectContextResolver}. Returns 201 for a newly-created
 * pending Manifest, 200 for exact reuse, both projecting through the frozen
 * {@see WidgetArtifactManifestResource} skeleton.
 *
 * PERMISSION-BEFORE-VALIDATION (precedent for every 15b 403 surface): the
 * `widget_template.approve` check lives in
 * {@see MaterializeWidgetManifestRequest::authorize()}, which Laravel runs
 * BEFORE rules() validation -- so 403 wins over 422 for a caller lacking
 * the permission, and this controller method only runs for a permitted,
 * shape-valid request. The controller never re-checks permission.
 *
 * ERROR ENVELOPE: every error response routes through the one shared
 * {@see WidgetApiErrorResponse::envelope()} builder -- no controller-local
 * envelope construction. The failure modes map onto the frozen contract
 * (the materialize route's own `error_codes` mapping in
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json, frozen
 * by the Task 15a contract evolution -- see WidgetApiErrorResponse's class
 * docblock for why the enum grew from 20 to 23):
 * - 403 {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} -- from the
 *   FormRequest's failedAuthorization();
 * - 422 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED}
 *   -- request-shape validation, from the FormRequest's failedValidation();
 * - 422 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID} --
 *   domain validation: unknown/mismatched Widget/Theme pairing, Theme not
 *   applicable to the authorized project, or a stale
 *   `expected_source_fingerprint`;
 * - 503 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZATION_FAILED}
 *   -- genuine build/storage failure, OR a vanished-row race on read-back
 *   (retryable; reconciliation owns retry).
 */
final class WidgetManifestMaterializationController
{
    public function __construct(
        private readonly WidgetLegacyManifestMaterializer $materializer,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    /**
     * Route 32 (disclosed addendum, routes 31->32): read-only CURRENT source
     * fingerprint for one widget/theme pairing, so the admin UI can echo it
     * as expected_source_fingerprint on the materialize POST instead of
     * materializing blind (the optimistic check stays meaningful end to end).
     */
    public function sourceFingerprint(Request $request): JsonResponse
    {
        if ($request->user()?->can('widget_template.approve') !== true) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to materialize Widget manifests.',
            );
        }

        $widgetId = (int) $request->query('widget_id');
        $themeId = (int) $request->query('widget_theme_id');

        if ($widgetId < 1 || $themeId < 1) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'widget_id and widget_theme_id are required.',
                ['widget_id' => ['widget_id and widget_theme_id must be positive integers.']]
            );
        }

        $project = $this->projectContext->resolve();
        $inspection = $this->materializer->inspectSourceFingerprint($widgetId, $themeId, $project->projectName);

        if (!$inspection['ok']) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                $inspection['reason'] === 'not_applicable'
                    ? 'The requested Theme is not applicable to the authorized project.'
                    : 'The requested widget_id/widget_theme_id pairing does not exist.',
                ['widget_theme_id' => [
                    $inspection['reason'] === 'not_applicable'
                        ? 'This Theme does not apply to the current authorized project.'
                        : 'The specified Theme does not belong to the specified Widget, or one of them does not exist.',
                ]]
            );
        }

        return response()->json([
            'widget_id' => $widgetId,
            'widget_theme_id' => $themeId,
            'source_fingerprint' => $inspection['source_fingerprint'],
        ]);
    }

    public function materialize(MaterializeWidgetManifestRequest $request): JsonResponse
    {
        $project = $this->projectContext->resolve();
        $widgetId = $request->integer('widget_id');
        $themeId = $request->integer('widget_theme_id');

        try {
            $result = $this->materializer->materialize(
                widgetId: $widgetId,
                themeId: $themeId,
                projectName: $project->projectName,
                expectedSourceFingerprint: (string) $request->input('expected_source_fingerprint'),
            );
        } catch (Throwable $e) {
            // Real correlation id: mint it FIRST, record it server-side with
            // safe context (never source bytes), and hand the SAME id to the
            // client -- so a 503 the caller reports can be found in the logs.
            $correlationId = (string) Str::uuid();
            Log::error('Widget manifest materialization failed.', [
                'correlation_id' => $correlationId,
                'widget_id' => $widgetId,
                'widget_theme_id' => $themeId,
                'project_name' => $project->projectName,
                'exception' => $e,
            ]);
            report($e);

            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZATION_FAILED,
                'Widget manifest materialization failed; this is retryable via reconciliation.',
                correlationId: $correlationId,
            );
        }

        return match ($result->status) {
            WidgetLegacyManifestMaterializationResult::STATUS_CREATED => $this->manifestResponse($result, 201),
            WidgetLegacyManifestMaterializationResult::STATUS_REUSED => $this->manifestResponse($result, 200),
            WidgetLegacyManifestMaterializationResult::STATUS_INVALID_PAIRING => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The requested widget_id/widget_theme_id pairing does not exist.',
                ['widget_theme_id' => ['The specified Theme does not belong to the specified Widget, or one of them does not exist.']]
            ),
            WidgetLegacyManifestMaterializationResult::STATUS_NOT_APPLICABLE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The requested Theme is not applicable to the authorized project.',
                ['widget_theme_id' => ['This Theme does not apply to the current authorized project.']]
            ),
            WidgetLegacyManifestMaterializationResult::STATUS_SOURCE_FINGERPRINT_STALE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The source has changed since expected_source_fingerprint was read.',
                ['expected_source_fingerprint' => ['The current source fingerprint no longer matches the expectation; refresh and try again.']]
            ),
        };
    }

    private function manifestResponse(WidgetLegacyManifestMaterializationResult $result, int $status): JsonResponse
    {
        // The materializer already read the row back once and carried the
        // safe column snapshot on the result -- no re-query here. The
        // persistence-agnostic Rendering\Artifacts VO (Task 10) carries only
        // canonical provenance, so attestation/control fields
        // (eligibility_state, attestation_version, control_version, blocked,
        // approved_by/at, revoked_by/at) come from that snapshot.
        $row = $result->manifestRow;

        if ($row === null) {
            // Vanished-row race: the builder persisted the Manifest but it
            // was no longer readable on read-back (concurrent delete). Render
            // through the frozen envelope, never a raw 404.
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZATION_FAILED,
                'The materialized Manifest could not be read back; this is retryable via reconciliation.'
            );
        }

        // The safe raw-column -> frozen-field projection (including the
        // trust_policy_version -> policy_version rename) is centralized in
        // WidgetArtifactManifestResource::fromManifestRow(), shared with
        // WidgetManifestApprovalController.
        return WidgetArtifactManifestResource::fromManifestRow($row)
            ->response()
            ->setStatusCode($status);
    }
}
