<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetInstance\StoreWidgetInstanceRequest;
use Cms\Widget\Http\Requests\WidgetInstance\UpdateWidgetInstanceRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetInstanceResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Instances\WidgetInstanceMutationResult;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `/api/v2/admin/widget/instances*` (plan Chunk 2 Task 16; fixture
 * instances route entries: list/create/show/update/delete/usage/clone/
 * archive).
 *
 * Thin HTTP layer mirroring {@see WidgetManifestMaterializationController}/
 * {@see WidgetManifestApprovalController}'s established shape: permission +
 * request-shape validation for create/update is the FormRequest's job;
 * project resolution is {@see WidgetProjectContextResolver}'s job; every
 * actual mutation/allocation/idempotency/materialization-gate/barrier
 * concern is entirely {@see WidgetInstanceService}'s job. This controller
 * only wires them together, authorizes the four actions with no dedicated
 * FormRequest (index/show/usage/clone/archive/destroy), and maps the
 * service's outcome onto the frozen HTTP contract.
 *
 * ERROR ENVELOPE: every response with a KNOWN frozen code routes through
 * the shared {@see WidgetApiErrorResponse}. Two scenarios have NO code in
 * the frozen 26-code dictionary and are disclosed here rather than
 * stretching an ill-fitting existing one:
 * - 404 "Instance not found" (no generic not-found code exists — only the
 *   Manifest-specific WIDGET_MANIFEST_NOT_FOUND does) renders a minimal
 *   {status, message, correlation_id} body with no `code` field;
 * - stale `expected_current_version` on a standalone PATCH reuses
 *   {@see WidgetApiErrorResponse::WIDGET_USAGE_CHANGED} (409, retryable) —
 *   the closest fit among the 26 for "the resource state you expected has
 *   changed, re-fetch and retry" (WIDGET_DRAFT_STALE is Layout-Draft-
 *   specific; WIDGET_ATTESTATION_VERSION_STALE is Manifest-attestation-
 *   specific; neither is Instance-version-specific).
 */
final class WidgetInstanceController
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly WidgetInstanceService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->authorized($request, 'widget_instance.viewAny')) {
            return $this->permissionDenied('You do not have permission to view Widget instances.');
        }

        $project = $this->projectContext->resolve();
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)));

        $paginator = WidgetInstance::query()
            ->where('project_name', $project->projectName)
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->through(fn (WidgetInstance $instance) => $this->instancePayload($instance));

        return WidgetInstanceResource::collection($paginator)->response();
    }

    public function store(StoreWidgetInstanceRequest $request): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->create(
            $project->projectName,
            $request->user()?->id,
            $request->idempotencyKey(),
            [
                'name' => (string) $request->input('name'),
                'widget_id' => $request->integer('widget_id'),
                'widget_theme_id' => $request->integer('widget_theme_id'),
                'values' => $request->input('values', []),
                'provider_contract' => $request->input('provider_contract', []),
            ],
        );

        return $this->respondToMutation($result);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_instance.view')) {
            return $this->permissionDenied('You do not have permission to view this Widget instance.');
        }

        $project = $this->projectContext->resolve();
        $instance = $this->service->findByUuid($project->projectName, $uuid);

        if ($instance === null) {
            return $this->instanceNotFound();
        }

        return (new WidgetInstanceResource($this->instancePayload($instance)))->response()->setStatusCode(200);
    }

    public function update(UpdateWidgetInstanceRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projectContext->resolve();

        $result = $this->service->update(
            $project->projectName,
            $uuid,
            $request->user()?->id,
            $request->idempotencyKey(),
            $request->integer('expected_current_version'),
            [
                'widget_id' => $request->integer('widget_id'),
                'widget_theme_id' => $request->integer('widget_theme_id'),
                'values' => $request->input('values', []),
                'provider_contract' => $request->input('provider_contract', []),
            ],
        );

        return $this->respondToMutation($result);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_instance.delete')) {
            return $this->permissionDenied('You do not have permission to delete this Widget instance.');
        }

        $project = $this->projectContext->resolve();
        $result = $this->service->delete($project->projectName, $uuid);

        return match ($result->status) {
            WidgetInstanceMutationResult::STATUS_NOT_FOUND => $this->instanceNotFound(),
            WidgetInstanceMutationResult::STATUS_INSTANCE_IN_USE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_INSTANCE_IN_USE,
                'This Widget instance is currently placed and cannot be deleted.',
            ),
            default => response()->json(['status' => 'success'], 200),
        };
    }

    public function usage(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_instance.view')) {
            return $this->permissionDenied('You do not have permission to view this Widget instance.');
        }

        $project = $this->projectContext->resolve();
        $usage = $this->service->usage($project->projectName, $uuid);

        if ($usage === null) {
            return $this->instanceNotFound();
        }

        return response()->json(['data' => $usage], 200);
    }

    public function clone(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_instance.create')) {
            return $this->permissionDenied('You do not have permission to clone Widget instances.');
        }

        $idempotencyKey = (string) $request->header('Idempotency-Key');
        if ($idempotencyKey === '' || !is_string($request->input('name'))) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => array_filter([
                    'Idempotency-Key' => $idempotencyKey === '' ? ['The Idempotency-Key header is required.'] : null,
                    'name' => is_string($request->input('name')) ? null : ['The name field is required.'],
                ]),
            ], 422);
        }

        $project = $this->projectContext->resolve();
        $result = $this->service->cloneInstance($project->projectName, $uuid, $request->user()?->id, $idempotencyKey, (string) $request->input('name'));

        return $this->respondToMutation($result);
    }

    public function archive(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_instance.delete')) {
            return $this->permissionDenied('You do not have permission to archive this Widget instance.');
        }

        $project = $this->projectContext->resolve();
        $result = $this->service->archive($project->projectName, $uuid);

        return match ($result->status) {
            WidgetInstanceMutationResult::STATUS_NOT_FOUND => $this->instanceNotFound(),
            default => (new WidgetInstanceResource($this->instancePayload($result->instance)))->response()->setStatusCode(200),
        };
    }

    // -----------------------------------------------------------------
    // Shared response mapping
    // -----------------------------------------------------------------

    private function respondToMutation(WidgetInstanceMutationResult $result): JsonResponse
    {
        return match ($result->status) {
            WidgetInstanceMutationResult::STATUS_CREATED,
            WidgetInstanceMutationResult::STATUS_UPDATED,
            WidgetInstanceMutationResult::STATUS_REPLAYED => (new WidgetInstanceResource($this->instancePayload($result->instance)))
                ->response()
                ->setStatusCode((int) $result->httpStatus),
            WidgetInstanceMutationResult::STATUS_NOT_FOUND => $this->instanceNotFound(),
            WidgetInstanceMutationResult::STATUS_INVALID_PAIRING => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The requested widget_id/widget_theme_id pairing does not exist.',
            ),
            WidgetInstanceMutationResult::STATUS_NOT_APPLICABLE => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_SOURCE_INVALID,
                'The requested Theme is not applicable to the authorized project.',
            ),
            WidgetInstanceMutationResult::STATUS_APPROVAL_REQUIRED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_REQUIRED,
                'The Manifest for this widget_id/widget_theme_id pairing is pending approval.',
            ),
            WidgetInstanceMutationResult::STATUS_MATERIALIZATION_FAILED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZATION_FAILED,
                'Widget manifest materialization failed; this is retryable via reconciliation.',
            ),
            WidgetInstanceMutationResult::STATUS_MANIFEST_BLOCKED => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCKED,
                'The Manifest for this widget_id/widget_theme_id pairing is currently emergency-blocked.',
            ),
            WidgetInstanceMutationResult::STATUS_MODULE_CONFLICT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_MODULE_IN_USE,
                'The Widget module is currently transitioning; try again shortly.',
            ),
            WidgetInstanceMutationResult::STATUS_STALE_VERSION => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_USAGE_CHANGED,
                'The expected current version no longer matches; refresh and try again.',
            ),
            WidgetInstanceMutationResult::STATUS_IDEMPOTENCY_CONFLICT => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_IDEMPOTENCY_MISMATCH,
                'The same idempotency key was used with a different request payload.',
            ),
            WidgetInstanceMutationResult::STATUS_IDEMPOTENCY_IN_PROGRESS => WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_IDEMPOTENCY_IN_PROGRESS,
                'This request is still being processed; try again shortly.',
            ),
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
     * dictionary (only the Manifest-specific WIDGET_MANIFEST_NOT_FOUND
     * does) — see this class's own docblock for the full disclosure.
     */
    private function instanceNotFound(): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => 'Widget instance not found.',
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function instancePayload(WidgetInstance $instance): array
    {
        $instance->loadMissing('currentVersion');
        $version = $instance->currentVersion;

        return [
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
        ];
    }
}
