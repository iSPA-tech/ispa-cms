<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\PublishWidgetLayoutRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutReleaseResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/publish` (plan Chunk 2 Task 20;
 * fixture publish route entry).
 *
 * Thin HTTP layer mirroring {@see WidgetLayoutDraftController}/
 * {@see WidgetInstanceController}'s established shape: permission +
 * request-shape validation is {@see PublishWidgetLayoutRequest}'s job;
 * project resolution is {@see WidgetProjectContextResolver}'s job; every
 * transactional/idempotency/eligibility concern is entirely
 * {@see WidgetLayoutPublishService}'s job. This controller only wires them
 * together and maps the service's outcome onto the frozen HTTP contract.
 *
 * Response shape: `{release, draft, sync_status}` -- the SAME
 * one-name-for-a-multi-identity-response convention
 * {@see WidgetLayoutReleaseResource}'s own class docblock already discloses
 * ("Publish/rollback responses always include both the new Release ... and
 * the fresh Draft's identity/lock ... never only one of the two") and
 * {@see \Cms\Widget\Http\Resources\V2\Admin\WidgetInstanceDraftRepinResource}
 * already established for the sibling versions-and-repin route. This task's
 * own file list has no separate composite-resource file slot, so the
 * `release`/`draft`/`sync_status` payload is built directly here, with
 * `release` shaped field-for-field identical to
 * {@see WidgetLayoutReleaseResource}'s own frozen fields and `draft` shaped
 * identical to {@see \Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutResource}'s
 * `current_draft` nested shape (`[version, lock_version]`).
 */
final class WidgetLayoutPublishController
{
    public function __construct(
        private readonly WidgetLayoutPublishService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function publish(PublishWidgetLayoutRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->publish(
            $project->projectName,
            $uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->integer('expected_draft_id'),
            $request->integer('expected_lock_version'),
        );

        return match ($result->status) {
            WidgetLayoutPublishResult::STATUS_PUBLISHED => response()->json(
                $this->payload($result),
                (int) $result->httpStatus,
            ),
            WidgetLayoutPublishResult::STATUS_LAYOUT_NOT_FOUND => $this->notFound('Widget site layout not found.'),
            WidgetLayoutPublishResult::STATUS_DRAFT_STALE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_DRAFT_STALE,
                'The draft changed since it was loaded; refresh and try again.',
            ),
            WidgetLayoutPublishResult::STATUS_REGISTRY_DRIFT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_REGISTRY_COMPATIBILITY_DRIFT,
                'The draft references Slot, Template Context, Target Context, or provider contracts that are no longer compatible.',
                ['issues' => $result->registryIssues],
            ),
            WidgetLayoutPublishResult::STATUS_MANIFEST_APPROVAL_REQUIRED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'A Manifest referenced by this draft is pending approval.',
            ),
            WidgetLayoutPublishResult::STATUS_MANIFEST_REVOKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_REVOKED,
                'A Manifest referenced by this draft has been revoked.',
            ),
            WidgetLayoutPublishResult::STATUS_MANIFEST_BLOCKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCKED,
                'A Manifest referenced by this draft is currently emergency-blocked.',
            ),
            WidgetLayoutPublishResult::STATUS_ARTIFACT_INTEGRITY_FAILED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_ARTIFACT_INTEGRITY_FAILED,
                'An artifact referenced by this draft failed integrity verification.',
            ),
            WidgetLayoutPublishResult::STATUS_PROVIDER_UNAVAILABLE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PROVIDER_UNAVAILABLE,
                'A dependency required to publish is temporarily unavailable; try again shortly.',
            ),
            WidgetLayoutPublishResult::STATUS_IDEMPOTENCY_CONFLICT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_IDEMPOTENCY_MISMATCH,
                'The same idempotency key was used with a different request payload.',
            ),
            WidgetLayoutPublishResult::STATUS_IDEMPOTENCY_IN_PROGRESS => WidgetApiErrorResponse::jsonResponse(
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
     * dictionary -- mirrors {@see WidgetLayoutDraftController::layoutNotFound()}'s
     * own disclosed minimal-body precedent.
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
    private function payload(WidgetLayoutPublishResult $result): array
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
