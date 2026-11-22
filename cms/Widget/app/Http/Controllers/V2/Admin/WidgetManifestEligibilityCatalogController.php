<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Resources\V2\Admin\WidgetArtifactManifestResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/admin/widget/catalog/manifests` (plan Chunk 2 Task 15b
 * checklist; docs/contracts/widget-site-layout-api.md "Catalog (split
 * semantics)"; Task 12 fixture note: "separate approval inventory; safe
 * pending/approved/revoked metadata only inside authorized project").
 *
 * THIS IS NOT the placement-selectable Definition catalog (`GET
 * catalog/definitions`, approved-eligible-Manifests-only, a different
 * resource/controller a later task owns). This is the approval INVENTORY:
 * every pending/approved/revoked Manifest inside the authorized project,
 * safe metadata only (hashes/state/attestation_version/approver/time --
 * never source, values, or storage paths), gated by
 * `widget_template.approve` -- the same permission {@see WidgetManifestApprovalController}
 * requires, since browsing the approval inventory is part of the same
 * authority as approving/revoking, per architecture.md decision 7.
 *
 * No FormRequest exists for this GET route (this task's allowed_paths lists
 * none for it -- only ApproveWidgetManifestRequest/RevokeWidgetManifestRequest).
 * The permission check is inline, rendered through the SAME shared
 * {@see WidgetApiErrorResponse::envelope()} builder every other 403 in this
 * module uses, so the response shape stays identical regardless of whether
 * a FormRequest mediates it.
 */
final class WidgetManifestEligibilityCatalogController
{
    public function __construct(
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('widget_template.approve') !== true) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to view the Widget manifest approval inventory.',
            );
        }

        $project = $this->projectContext->resolve();

        // Same default/max as the frozen pagination contract
        // (cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
        // "pagination": default_per_page 20, max_per_page 100).
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        // Collection path: through() maps each row to the SAME centralized
        // safe projection (WidgetArtifactManifestResource::manifestRowFields,
        // shared with the single-response controllers) -- a collection wraps
        // arrays, not resources, so this uses the array projector directly
        // rather than the fromManifestRow() resource factory.
        $paginator = WidgetArtifactManifestModel::query()
            ->where('project_name', $project->projectName)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(static fn (WidgetArtifactManifestModel $row): array => WidgetArtifactManifestResource::manifestRowFields($row));

        return WidgetArtifactManifestResource::collection($paginator)->response();
    }
}
