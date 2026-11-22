<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetManifest\ApproveWidgetManifestRequest;
use Cms\Widget\Http\Requests\WidgetManifest\RevokeWidgetManifestRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetArtifactManifestResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;

/**
 * `POST|DELETE /api/v2/admin/widget/catalog/manifests/{manifest_hash}/approval`
 * (plan Chunk 2 Task 15b checklist; docs/contracts/widget-site-layout-api.md
 * "Catalog (split semantics)").
 *
 * Thin HTTP layer, mirroring {@see WidgetManifestMaterializationController}'s
 * established shape (Task 15a): permission + request-shape validation is
 * the FormRequest's job (403-before-422 precedent copied verbatim); project
 * resolution is {@see WidgetProjectContextResolver}'s job; the actual
 * pending->approved / approved->revoked / revoked->approved state machine
 * and its compare-and-swap is entirely
 * {@see WidgetManifestApprovalService}'s job. This controller only wires
 * the three together and maps the service's outcome onto the frozen HTTP
 * contract.
 *
 * ERROR ENVELOPE (per cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * approval route entries; see {@see WidgetApiErrorResponse}'s class
 * docblock "TASK 15b CONTRACT EVOLUTION" for the two codes this task adds):
 * - 403 {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} -- from the
 *   FormRequest's failedAuthorization();
 * - 422 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED}
 *   -- request-shape validation, from the FormRequest's failedValidation();
 * - 404 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_NOT_FOUND} -- no
 *   Manifest row for this hash inside the authorized project (unknown hash,
 *   OR a hash belonging to a different project -- both render identically,
 *   never leaking which case it was);
 * - 409 {@see WidgetApiErrorResponse::WIDGET_ATTESTATION_VERSION_STALE} --
 *   the row is not in the state this action accepts, and/or
 *   `expected_attestation_version` no longer matches (reused for both,
 *   see the service's own docblock for why one code covers both).
 */
final class WidgetManifestApprovalController
{
    public function __construct(
        private readonly WidgetManifestApprovalService $approvalService,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function approve(ApproveWidgetManifestRequest $request, string $manifest_hash): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->approvalService->approve(
            projectName: $project->projectName,
            manifestHash: $manifest_hash,
            expectedAttestationVersion: $request->integer('expected_attestation_version'),
            actorId: (int) $request->user()->id,
            reason: $request->input('reason'),
        );

        return $this->respond($result);
    }

    public function revoke(RevokeWidgetManifestRequest $request, string $manifest_hash): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->approvalService->revoke(
            projectName: $project->projectName,
            manifestHash: $manifest_hash,
            expectedAttestationVersion: $request->integer('expected_attestation_version'),
            actorId: (int) $request->user()->id,
            reason: $request->input('reason'),
        );

        return $this->respond($result);
    }

    /**
     * @param array{outcome: string, row: array<string, mixed>|null} $result
     */
    private function respond(array $result): JsonResponse
    {
        if ($result['outcome'] === WidgetManifestApprovalService::OUTCOME_SUCCESS) {
            /** @var array<string, mixed> $row */
            $row = $result['row'];

            // The safe raw-column -> frozen-field projection (including the
            // trust_policy_version -> policy_version rename) lives in ONE
            // place now: WidgetArtifactManifestResource::fromManifestRow(),
            // shared with WidgetManifestMaterializationController.
            return WidgetArtifactManifestResource::fromManifestRow($row)
                ->response()
                ->setStatusCode(200);
        }

        if ($result['outcome'] === WidgetManifestApprovalService::OUTCOME_NOT_FOUND) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_NOT_FOUND,
                'No Manifest was found for this hash inside the authorized project.',
            );
        }

        return WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_ATTESTATION_VERSION_STALE,
            'The Manifest is not in the expected state or attestation version; refresh and try again.',
        );
    }
}
