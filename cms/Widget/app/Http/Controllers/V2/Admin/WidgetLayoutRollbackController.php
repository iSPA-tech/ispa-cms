<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\RollbackWidgetLayoutRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutReleaseResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/rollback` (plan Chunk 2 Task 21;
 * fixture rollback route entry).
 *
 * Thin HTTP layer mirroring {@see WidgetLayoutPublishController}'s
 * established shape exactly (Task 20's own sibling): permission +
 * request-shape validation is {@see RollbackWidgetLayoutRequest}'s job;
 * project resolution is {@see WidgetProjectContextResolver}'s job; every
 * transactional/idempotency/eligibility concern is entirely
 * {@see WidgetLayoutRollbackService}'s job. This controller only wires them
 * together and maps the service's outcome onto the frozen HTTP contract
 * (statuses `[200, 403, 404, 409, 422, 503]` per the fixture).
 *
 * Response shape: `{release, draft, sync_status}` -- the SAME
 * one-name-for-a-multi-identity-response convention
 * {@see WidgetLayoutPublishController}'s own docblock already discloses.
 * `release.rollback_of_version` is non-null for every rollback-produced
 * Release (never true for a publish-produced one), giving the client
 * visibility into what this Release was rolled back to without a separate
 * top-level response field.
 */
final class WidgetLayoutRollbackController
{
    public function __construct(
        private readonly WidgetLayoutRollbackService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function rollback(RollbackWidgetLayoutRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->rollback(
            $project->projectName,
            $uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->integer('source_release_version'),
            $request->integer('expected_draft_id'),
            $request->integer('expected_lock_version'),
        );

        return match ($result->status) {
            WidgetLayoutRollbackResult::STATUS_ROLLED_BACK => response()->json(
                $this->payload($result),
                (int) $result->httpStatus,
            ),
            WidgetLayoutRollbackResult::STATUS_LAYOUT_NOT_FOUND => $this->notFound('Widget site layout not found.'),
            WidgetLayoutRollbackResult::STATUS_SOURCE_RELEASE_NOT_FOUND => $this->notFound('Source release not found.'),
            WidgetLayoutRollbackResult::STATUS_DRAFT_STALE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_DRAFT_STALE,
                'The draft changed since it was loaded; refresh and try again.',
            ),
            WidgetLayoutRollbackResult::STATUS_REGISTRY_DRIFT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_REGISTRY_COMPATIBILITY_DRIFT,
                'The source release references Slot, Template Context, Target Context, or provider contracts that are no longer compatible.',
                ['issues' => $result->registryIssues],
            ),
            WidgetLayoutRollbackResult::STATUS_MANIFEST_APPROVAL_REQUIRED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'A Manifest referenced by the source release is pending approval.',
            ),
            WidgetLayoutRollbackResult::STATUS_MANIFEST_REVOKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_REVOKED,
                'A Manifest referenced by the source release has been revoked.',
            ),
            WidgetLayoutRollbackResult::STATUS_MANIFEST_BLOCKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCKED,
                'A Manifest referenced by the source release is currently emergency-blocked.',
            ),
            WidgetLayoutRollbackResult::STATUS_ARTIFACT_INTEGRITY_FAILED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_ARTIFACT_INTEGRITY_FAILED,
                'An artifact referenced by the source release failed integrity verification.',
            ),
            WidgetLayoutRollbackResult::STATUS_PROVIDER_UNAVAILABLE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PROVIDER_UNAVAILABLE,
                'A dependency required to roll back is temporarily unavailable; try again shortly.',
            ),
            WidgetLayoutRollbackResult::STATUS_IDEMPOTENCY_CONFLICT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_IDEMPOTENCY_MISMATCH,
                'The same idempotency key was used with a different request payload.',
            ),
            WidgetLayoutRollbackResult::STATUS_IDEMPOTENCY_IN_PROGRESS => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_IDEMPOTENCY_IN_PROGRESS,
                'This request is still being processed; try again shortly.',
            ),
            default => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_ARTIFACT_INTEGRITY_FAILED,
                'An unexpected integrity failure occurred.',
            ),
        };
    }

    /**
     * No generic "resource not found" code exists in the frozen 26-code
     * dictionary -- mirrors {@see WidgetLayoutPublishController::notFound()}'s
     * own disclosed minimal-body precedent. Reused here for BOTH "layout not
     * found" (URL path identity) and "source release not found" (request
     * body identity) since neither has a dedicated frozen code.
     */
    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => $message,
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WidgetLayoutRollbackResult $result): array
    {
        /** @var WidgetLayoutVersion $release */
        $release = $result->release;
        $release->loadMissing('basedOn', 'rollbackOf', 'placements');

        /** @var WidgetLayoutVersion $draft */
        $draft = $result->draft;

        return [
            'release' => (new WidgetLayoutReleaseResource([
                'version' => $release->version,
                'based_on_version' => $release->basedOn?->version,
                'rollback_of_version' => $release->rollbackOf?->version,
                'published_at' => $release->published_at,
                'published_by' => $release->published_by,
                'placements_count' => $release->placements->count(),
                'registry_contract_hash' => $release->registry_contract_hash,
            ]))->toArray(request()),
            'draft' => [
                'version' => $draft->version,
                'lock_version' => $draft->lock_version,
            ],
            'sync_status' => 'ready',
        ];
    }
}
