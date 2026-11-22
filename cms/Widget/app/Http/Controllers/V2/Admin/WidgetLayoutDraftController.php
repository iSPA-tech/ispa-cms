<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\UpdateWidgetLayoutDraftRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutDraftResource;
use Cms\Widget\Http\Resources\V2\Admin\WidgetPlacementResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Http\Support\WidgetLayoutDraftPayload;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftSaveResult;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftChanges;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `PUT /api/v2/admin/widget/layouts/{uuid}/draft` (plan Chunk 2 Task 17;
 * fixture `/layouts/{uuid}/draft` route entry) plus its `GET` read
 * counterpart (disclosed post-freeze contract evolution, routes 30->31 --
 * see docs/contracts/widget-site-layout-api.md).
 *
 * Thin HTTP layer mirroring {@see WidgetInstanceController}'s established
 * shape: request-SHAPE validation is
 * {@see UpdateWidgetLayoutDraftRequest}'s job, project resolution is
 * {@see WidgetProjectContextResolver}'s job, every domain
 * validation/locking/persistence/registry-recompute concern is entirely
 * {@see WidgetLayoutDraftService}'s job. This controller only wires them
 * together and maps the service's outcome onto the frozen HTTP contract
 * (statuses `[200, 403, 404, 409, 422]` for the write, `[200, 403, 404]`
 * for the read, per the fixture).
 *
 * `show()` is a PURE read (no side effect, never creates a Layout or
 * Draft) authorized inline via `$request->user()?->can(...)`, mirroring
 * {@see WidgetLayoutBootstrapController::show()}'s identical simple-GET
 * precedent rather than the write path's FormRequest-based authorization --
 * there is no request body to shape-validate for a parameterless GET.
 */
final class WidgetLayoutDraftController
{
    public function __construct(
        private readonly WidgetLayoutDraftService $service,
        private readonly WidgetProjectContextResolver $projectContext,
        private readonly WidgetLayoutDraftChanges $changes,
    ) {
    }

    /**
     * `GET /layouts/{uuid}/draft/changes`: what publishing the draft would change, compared with the
     * live release ({@see WidgetLayoutDraftChanges} for the matching rule). Pure read.
     */
    public function changes(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_layout.view')) {
            return $this->permissionDenied('You do not have permission to view this Widget site layout draft.');
        }

        $rows = $this->changes->forLayout($this->projectContext->resolve()->projectName, $uuid);

        return $rows === null ? $this->layoutNotFound() : response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_layout.view')) {
            return $this->permissionDenied('You do not have permission to view this Widget site layout draft.');
        }

        $project = $this->projectContext->resolve();
        $draft = $this->service->findCurrentDraft($project->projectName, $uuid);

        if ($draft === null) {
            return $this->layoutNotFound();
        }

        return (new WidgetLayoutDraftResource(WidgetLayoutDraftPayload::draft($draft)))->response()->setStatusCode(200);
    }

    public function update(UpdateWidgetLayoutDraftRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->save(
            $project->projectName,
            $uuid,
            $request->expectedCurrentDraftId(),
            $request->expectedLockVersion(),
            $request->placements(),
        );

        return match ($result->status) {
            WidgetLayoutDraftSaveResult::STATUS_SAVED => WidgetPlacementResource::collection(
                WidgetLayoutDraftPayload::placements($result->draft)
            )->response()->setStatusCode(200),
            WidgetLayoutDraftSaveResult::STATUS_LAYOUT_NOT_FOUND => $this->layoutNotFound(),
            WidgetLayoutDraftSaveResult::STATUS_STALE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_DRAFT_STALE,
                'The draft changed since it was loaded; refresh and try again.',
            ),
            WidgetLayoutDraftSaveResult::STATUS_TARGET_DENIED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to reference one of the requested targets.',
            ),
            WidgetLayoutDraftSaveResult::STATUS_TARGET_NOT_FOUND => $this->genericNotFound('One of the requested targets was not found.'),
            WidgetLayoutDraftSaveResult::STATUS_VALIDATION_FAILED => $this->validationFailed((string) $result->message),
            default => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_ARTIFACT_INTEGRITY_FAILED,
                'An unexpected integrity failure occurred.',
            ),
        };
    }

    private function authorized(Request $request, string $permission): bool
    {
        return $request->user()?->can($permission) === true;
    }

    private function permissionDenied(string $message): JsonResponse
    {
        return WidgetApiErrorResponse::jsonResponse(WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED, $message);
    }

    /**
     * No generic "resource not found" code exists in the frozen 26-code
     * dictionary -- mirrors {@see WidgetInstanceController::instanceNotFound()}'s
     * own disclosed minimal-body precedent.
     */
    private function layoutNotFound(): JsonResponse
    {
        return $this->genericNotFound('Widget site layout not found.');
    }

    private function genericNotFound(string $message): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => $message,
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }

    /**
     * DISCLOSED CHOICE: no code in the frozen 26-code dictionary fits "the
     * submitted Draft document itself is domain-invalid" (unregistered
     * Slot/scope, cardinality conflict, non-empty rules, unresolvable
     * Instance Version) -- mirrors
     * {@see \Cms\Widget\Http\Requests\WidgetInstance\StoreWidgetInstanceRequest}'s
     * own disclosed reasoning for not stretching an ill-fitting existing
     * code. This is DOMAIN-detected (by the service), not request-SHAPE, so
     * it does not go through the FormRequest's own `failedValidation()`
     * either; it reuses the SAME minimal `{status, message, correlation_id}`
     * shape this controller already uses for "not found".
     */
    private function validationFailed(string $message): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => $message,
            'correlation_id' => (string) Str::uuid(),
        ], 422);
    }
}
