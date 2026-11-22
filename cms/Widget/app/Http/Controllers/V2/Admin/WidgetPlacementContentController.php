<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\DuplicateWidgetPlacementRequest;
use Cms\Widget\Http\Requests\WidgetLayout\StoreWidgetPlacementRequest;
use Cms\Widget\Http\Requests\WidgetLayout\UpdateWidgetPlacementContentRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutDraftResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Http\Support\WidgetLayoutDraftPayload;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\WidgetPlacementContentResult;
use Cms\Widget\SiteLayout\Drafts\WidgetPlacementContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Placement content routes (Unified Widget Catalog Part 2, Task 3): each placement owns its
 * instance, so the area editor never sees an instance library. Every success returns the
 * current draft in the `GET /layouts/{uuid}/draft` shape, plus `meta.placement_uuid` naming the
 * placement the call created or edited (placement uuids change on every full draft save).
 */
final class WidgetPlacementContentController
{
    public function __construct(
        private readonly WidgetPlacementContentService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function store(StoreWidgetPlacementRequest $request, string $uuid): JsonResponse
    {
        return $this->respond($this->service->create(
            $this->projectContext->resolve()->projectName,
            $uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->expectedDraftRevision(),
            [
                'slot_key' => (string) $request->input('slot_key'),
                'scope_type' => (string) $request->input('scope_type'),
                'scope_key' => $request->input('scope_key'),
                'target_type' => $request->input('target_type'),
                'target_id' => $request->input('target_id'),
                'widget_id' => $request->integer('widget_id'),
                'widget_theme_id' => $request->integer('widget_theme_id'),
                'values' => (array) $request->input('values', []),
            ],
        ));
    }

    public function updateContent(UpdateWidgetPlacementContentRequest $request, string $uuid, string $placement_uuid): JsonResponse
    {
        return $this->respond($this->service->updateContent(
            $this->projectContext->resolve()->projectName,
            $uuid,
            $placement_uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->expectedDraftRevision(),
            (array) $request->input('values', []),
            $request->themeId(),
        ));
    }

    public function duplicate(DuplicateWidgetPlacementRequest $request, string $uuid, string $placement_uuid): JsonResponse
    {
        return $this->respond($this->service->duplicate(
            $this->projectContext->resolve()->projectName,
            $uuid,
            $placement_uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->expectedDraftRevision(),
        ));
    }

    private function respond(WidgetPlacementContentResult $result): JsonResponse
    {
        if ($result->draft !== null) {
            return (new WidgetLayoutDraftResource(WidgetLayoutDraftPayload::draft($result->draft)))
                ->additional(['meta' => ['placement_uuid' => $result->placementUuid]])
                ->response()
                ->setStatusCode($result->httpStatus);
        }

        if ($result->errorCode !== null) {
            return WidgetApiErrorResponse::jsonResponse($result->errorCode, (string) $result->message);
        }

        // No envelope code exists for "not found" / "invalid placement"; same minimal body the
        // draft routes already return.
        return response()->json([
            'status' => $result->httpStatus,
            'message' => $result->message,
            'correlation_id' => (string) Str::uuid(),
        ], $result->httpStatus);
    }
}
