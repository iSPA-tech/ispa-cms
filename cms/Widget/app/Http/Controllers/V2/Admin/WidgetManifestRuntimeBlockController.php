<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetManifest\SetWidgetManifestRuntimeBlockRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetArtifactManifestResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Rendering\Eligibility\WidgetManifestRuntimeBlockService;
use Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;

/**
 * `POST|DELETE /api/v2/admin/widget/catalog/manifests/{manifest_hash}/runtime-block`
 * (plan Chunk 2 Task 15c checklist; docs/contracts/widget-site-layout-api.md
 * "Catalog (split semantics)").
 *
 * TOPOLOGY PREFLIGHT BEFORE ANYTHING ELSE (checklist item 3: "the endpoint
 * checks this guard before opening the mutation transaction"; checklist
 * item 4: production stays unavailable-by-default until Task 24/24b):
 * {@see WidgetRuntimeControlTopologyGuard::isAvailable()} is checked FIRST
 * in both {@see self::block()} and {@see self::clear()}, before the
 * Manifest row is even looked up. When unavailable, this controller
 * returns 503 unconditionally and NEVER calls
 * {@see WidgetManifestRuntimeBlockService} at all -- so an unknown
 * `manifest_hash` under an unavailable topology still returns 503, not
 * 404, proving the gate is a blanket endpoint-level precondition, not a
 * per-resource check (see WidgetManifestRuntimeBlockTopologyTest). The
 * production default {@see WidgetRuntimeControlTopologyGuard} hardcodes
 * `false`; only a test binding a proven adapter observes the CAS/200 path
 * below.
 *
 * PERMISSION-BEFORE-VALIDATION-BEFORE-TOPOLOGY: permission
 * (`widget_template.emergencyDisable`, 403) and request-shape validation
 * (422) both happen inside {@see SetWidgetManifestRuntimeBlockRequest}
 * BEFORE Laravel ever invokes this controller's methods (403-before-422
 * precedent copied from Task 15a/15b). The topology check therefore only
 * ever runs for an authenticated, permitted, shape-valid request --
 * matching the established ordering 403 -> 422 -> 503 -> 404 -> 409 -> 200.
 *
 * ERROR ENVELOPE (per cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * runtime-block route entries; see {@see WidgetApiErrorResponse}'s class
 * docblock "TASK 15c CONTRACT EVOLUTION" for the new 422 code this task
 * adds):
 * - 403 {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} -- from the
 *   FormRequest's failedAuthorization();
 * - 422 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED}
 *   -- request-shape validation, from the FormRequest's failedValidation();
 * - 503 {@see WidgetApiErrorResponse::WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE}
 *   -- pre-mutation topology preflight failure, from this controller,
 *   BEFORE any row lookup or write;
 * - 404 {@see WidgetApiErrorResponse::WIDGET_MANIFEST_NOT_FOUND} -- no
 *   Manifest row for this hash inside the authorized project (unknown
 *   hash, or a hash belonging to a different project -- both render
 *   identically, never leaking which case it was, same rule as Task 15b);
 * - 409 {@see WidgetApiErrorResponse::WIDGET_RUNTIME_CONTROL_VERSION_STALE}
 *   -- `expected_control_version` no longer matches the row's current
 *   `control_version` (the independent emergency-control CAS counter, never
 *   `attestation_version`).
 */
final class WidgetManifestRuntimeBlockController
{
    public function __construct(
        private readonly WidgetManifestRuntimeBlockService $blockService,
        private readonly WidgetRuntimeControlTopologyGuard $topologyGuard,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function block(SetWidgetManifestRuntimeBlockRequest $request, string $manifest_hash): JsonResponse
    {
        if (!$this->topologyGuard->isAvailable()) {
            return $this->topologyUnavailableResponse();
        }

        $project = $this->projectContext->resolve();

        $result = $this->blockService->block(
            projectName: $project->projectName,
            manifestHash: $manifest_hash,
            expectedControlVersion: $request->integer('expected_control_version'),
            actorId: (int) $request->user()->id,
            reason: (string) $request->input('reason'),
        );

        return $this->respond($result);
    }

    public function clear(SetWidgetManifestRuntimeBlockRequest $request, string $manifest_hash): JsonResponse
    {
        if (!$this->topologyGuard->isAvailable()) {
            return $this->topologyUnavailableResponse();
        }

        $project = $this->projectContext->resolve();

        $result = $this->blockService->clear(
            projectName: $project->projectName,
            manifestHash: $manifest_hash,
            expectedControlVersion: $request->integer('expected_control_version'),
            actorId: (int) $request->user()->id,
            reason: (string) $request->input('reason'),
        );

        return $this->respond($result);
    }

    private function topologyUnavailableResponse(): JsonResponse
    {
        return WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE,
            'Emergency runtime control is unavailable: the shared read-after-write and permanent response-cache-bypass topology is not yet proven for this deployment.',
        );
    }

    /**
     * @param array{outcome: string, row: array<string, mixed>|null} $result
     */
    private function respond(array $result): JsonResponse
    {
        if ($result['outcome'] === WidgetManifestRuntimeBlockService::OUTCOME_SUCCESS) {
            /** @var array<string, mixed> $row */
            $row = $result['row'];

            return WidgetArtifactManifestResource::fromManifestRow($row)
                ->response()
                ->setStatusCode(200);
        }

        if ($result['outcome'] === WidgetManifestRuntimeBlockService::OUTCOME_NOT_FOUND) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_NOT_FOUND,
                'No Manifest was found for this hash inside the authorized project.',
            );
        }

        return WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_RUNTIME_CONTROL_VERSION_STALE,
            'The Manifest emergency control version is stale; refresh and try again.',
        );
    }
}
