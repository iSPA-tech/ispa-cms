<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Requests\WidgetLayout\BootstrapWidgetLayoutRequest;
use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `/api/v2/admin/widget/layouts*` identity surface (plan Chunk 2 Task 17;
 * fixture `/layouts` (list), `/layouts/by-key/{layout_key}` (bootstrap), and
 * `/layouts/{uuid}` (detail) route entries).
 *
 * Bundles the Layout resource's READS (`index`/`show`) alongside the
 * bootstrap PUT that is the only way a Layout row ever comes into existence
 * -- mirroring {@see WidgetInstanceController}'s established precedent of
 * bundling a resource's reads with its primary mutation in one controller.
 * `GET` never creates a row (architecture.md "Layout bootstrap": "List/
 * detail GETs never create records") -- both read methods below are plain
 * queries with no side effect, proven by
 * `WidgetLayoutBootstrapApiTest::test_index_never_creates_a_layout()` /
 * `test_show_returns_404_for_an_unknown_uuid_and_never_creates_a_layout()`.
 *
 * Draft mutation (`PUT /layouts/{uuid}/draft`) is a SEPARATE controller
 * ({@see WidgetLayoutDraftController}) -- this class owns Layout identity
 * only, never a Draft's Placement document.
 */
final class WidgetLayoutBootstrapController
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly WidgetLayoutBootstrapService $service,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->authorized($request, 'widget_layout.viewAny')) {
            return $this->permissionDenied('You do not have permission to view Widget site layouts.');
        }

        $project = $this->projectContext->resolve();
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)));

        $paginator = WidgetLayout::query()
            ->where('project_name', $project->projectName)
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->through(fn (WidgetLayout $layout) => $this->layoutPayload($layout));

        return WidgetLayoutResource::collection($paginator)->response();
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized($request, 'widget_layout.view')) {
            return $this->permissionDenied('You do not have permission to view this Widget site layout.');
        }

        $project = $this->projectContext->resolve();
        $layout = $this->service->findByUuid($project->projectName, $uuid);

        if ($layout === null) {
            return $this->layoutNotFound();
        }

        return (new WidgetLayoutResource($this->layoutPayload($layout)))->response()->setStatusCode(200);
    }

    public function bootstrap(BootstrapWidgetLayoutRequest $request): JsonResponse
    {
        $project = $this->projectContext->resolve();
        $result = $this->service->bootstrap($project->projectName, $request->layoutKey());

        return (new WidgetLayoutResource($this->layoutPayload($result->layout)))
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
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
        return response()->json([
            'status' => 404,
            'message' => 'Widget site layout not found.',
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutPayload(WidgetLayout $layout): array
    {
        $layout->loadMissing('currentDraft', 'currentPublished');
        $draft = $layout->currentDraft;
        $published = $layout->currentPublished;

        return [
            'uuid' => $layout->uuid,
            'project_name' => $layout->project_name,
            'key' => $layout->key,
            'current_draft' => $draft === null ? null : [
                'version' => $draft->version,
                'lock_version' => $draft->lock_version,
            ],
            'current_published' => $published === null ? null : [
                'version' => $published->version,
            ],
            'created_at' => $layout->created_at,
            'updated_at' => $layout->updated_at,
        ];
    }
}
