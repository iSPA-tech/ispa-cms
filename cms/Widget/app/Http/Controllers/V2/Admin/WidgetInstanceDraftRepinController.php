<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\UpdateInstanceAndRepinDraftRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetInstanceDraftRepinResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft;
use Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraftResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin`
 * (plan Chunk 2 Task 18; fixture versions-and-repin route entry).
 *
 * Thin HTTP layer mirroring {@see WidgetInstanceController}/
 * {@see WidgetLayoutDraftController}'s established shape: permission +
 * request-shape validation is {@see UpdateInstanceAndRepinDraftRequest}'s
 * job; project resolution is {@see WidgetProjectContextResolver}'s job;
 * every mutation/lock/idempotency/registry-recompute concern is entirely
 * {@see UpdateInstanceAndRepinDraft}'s job. This controller only wires them
 * together and maps the service's outcome onto the frozen HTTP contract
 * (statuses `[200, 403, 404, 409, 422, 503]` per the fixture -- no 201,
 * this route only ever updates an existing Instance/Draft pair).
 */
final class WidgetInstanceDraftRepinController
{
    public function __construct(
        private readonly UpdateInstanceAndRepinDraft $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function repin(UpdateInstanceAndRepinDraftRequest $request, string $uuid, string $instance_uuid): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->handle(
            $project->projectName,
            $uuid,
            $instance_uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->integer('expected_current_instance_version'),
            $request->integer('expected_draft_id'),
            $request->integer('expected_draft_lock_version'),
            (string) $request->input('usage_fingerprint'),
            [
                'widget_id' => $request->integer('widget_id'),
                'widget_theme_id' => $request->integer('widget_theme_id'),
                'values' => $request->input('values', []),
                'provider_contract' => $request->input('provider_contract', []),
            ],
        );

        return match ($result->status) {
            UpdateInstanceAndRepinDraftResult::STATUS_REPINNED => (new WidgetInstanceDraftRepinResource($this->payload($result)))
                ->response()
                ->setStatusCode((int) $result->httpStatus),
            UpdateInstanceAndRepinDraftResult::STATUS_LAYOUT_NOT_FOUND => $this->notFound('Widget site layout not found.'),
            UpdateInstanceAndRepinDraftResult::STATUS_INSTANCE_NOT_FOUND => $this->notFound('Widget instance not found.'),
            UpdateInstanceAndRepinDraftResult::STATUS_DRAFT_STALE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_DRAFT_STALE,
                'The draft changed since it was loaded; refresh and try again.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_USAGE_CHANGED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_USAGE_CHANGED,
                'The instance or draft placement state changed since it was loaded; refresh and try again.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_INVALID_PAIRING => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The requested widget_id/widget_theme_id pairing does not exist.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_NOT_APPLICABLE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The requested Theme is not applicable to the authorized project.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_APPROVAL_REQUIRED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'The Manifest for this widget_id/widget_theme_id pairing is pending approval.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_MATERIALIZATION_FAILED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZATION_FAILED,
                'Widget manifest materialization failed; this is retryable via reconciliation.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_MANIFEST_BLOCKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCKED,
                'The Manifest for this widget_id/widget_theme_id pairing is currently emergency-blocked.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_MODULE_CONFLICT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MODULE_IN_USE,
                'The Widget module is currently transitioning; try again shortly.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_IDEMPOTENCY_CONFLICT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_IDEMPOTENCY_MISMATCH,
                'The same idempotency key was used with a different request payload.',
            ),
            UpdateInstanceAndRepinDraftResult::STATUS_IDEMPOTENCY_IN_PROGRESS => WidgetApiErrorResponse::jsonResponse(
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
     * dictionary -- mirrors {@see WidgetInstanceController::instanceNotFound()}/
     * {@see WidgetLayoutDraftController::layoutNotFound()}'s own disclosed
     * minimal-body precedent.
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
     * Builds the composite `{instance, draft, sync_status}` payload, with
     * `instance`/`draft.placements.*` shaped field-for-field identical to
     * {@see WidgetInstanceController::instancePayload()}/
     * {@see WidgetLayoutDraftController::placementPayload()} so the flat placement
     * shapes stay in lockstep with every other Instance/Placement-returning
     * route despite this controller not being able to call those private
     * methods directly.
     *
     * @return array<string, mixed>
     */
    private function payload(UpdateInstanceAndRepinDraftResult $result): array
    {
        /** @var WidgetInstance $instance */
        $instance = $result->instance;
        $instance->loadMissing('currentVersion');
        $version = $instance->currentVersion;

        /** @var WidgetLayoutVersion $draft */
        $draft = $result->draft;
        $draft->loadMissing('placements.instanceVersion.instance');

        return [
            'instance' => [
                'uuid' => $instance->uuid,
                'project_name' => $instance->project_name,
                'name' => $instance->name,
                'status' => $instance->status,
                'current_version' => $version === null ? null : [
                    'version' => $version->version,
                    'widget_id' => $version->widget_id,
                    'widget_theme_id' => $version->widget_theme_id,
                    'renderer_key' => $version->renderer_key,
                    'renderer_version' => $version->renderer_version,
                    'trust_classification' => $version->trust_classification,
                    'schema' => $version->schema,
                    'values' => $version->values,
                    'provider_contract' => $version->provider_refs,
                    'created_at' => $version->created_at,
                ],
                'sync_status' => 'ready',
                'created_at' => $instance->created_at,
                'updated_at' => $instance->updated_at,
            ],
            'draft' => [
                'version' => $draft->version,
                'lock_version' => $draft->lock_version,
                'placements' => $draft->placements
                    ->sortBy('position')
                    ->values()
                    ->map(fn (WidgetPlacement $placement): array => $this->placementPayload($placement))
                    ->all(),
            ],
            'sync_status' => 'ready',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placementPayload(WidgetPlacement $placement): array
    {
        $instanceVersion = $placement->instanceVersion;

        return [
            'uuid' => $placement->uuid,
            'slot_key' => $placement->slot_key,
            'scope_type' => $placement->scope_type,
            'scope_key' => $placement->scope_key,
            'target_type' => $placement->target_type,
            'target_id' => $placement->target_id,
            'widget_instance_uuid' => $instanceVersion?->instance?->uuid,
            'widget_instance_version' => $instanceVersion?->version,
            'position' => $placement->position,
            'enabled' => $placement->enabled,
            'rules' => $placement->rules ?? [],
        ];
    }
}
