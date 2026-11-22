<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Resources\V2\Admin\WidgetLayoutReleaseResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutBootstrapService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `GET /api/v2/admin/widget/layouts/{uuid}/releases` (plan Chunk 2 Task 22;
 * fixture route entry).
 *
 * Paginated Release history for one Layout -- every immutable
 * `state = STATE_RELEASE` row, newest first, projected through the SAME
 * frozen {@see WidgetLayoutReleaseResource} shape the publish/rollback
 * mutation responses already use ({@see WidgetLayoutPublishController},
 * {@see WidgetLayoutRollbackController}). Never lists the current Draft
 * (`state = STATE_DRAFT`) or a superseded intermediate row -- Release
 * history is retained-promotion history only.
 *
 * Layout lookup reuses {@see WidgetLayoutBootstrapService::findByUuid()}
 * verbatim (the same public method {@see WidgetLayoutBootstrapController::show()}
 * already calls) rather than a second copy of the identical
 * project-scoped-uuid query.
 */
final class WidgetLayoutReleaseController
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly WidgetLayoutBootstrapService $bootstrapService,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        if ($request->user()?->can('widget_layout.view') !== true) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to view this Widget site layout.',
            );
        }

        $project = $this->projectContext->resolve();
        $layout = $this->bootstrapService->findByUuid($project->projectName, $uuid);

        if ($layout === null) {
            return response()->json([
                'status' => 404,
                'message' => 'Widget site layout not found.',
                'correlation_id' => (string) Str::uuid(),
            ], 404);
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)));

        $paginator = WidgetLayoutVersion::query()
            ->where('layout_id', $layout->id)
            ->where('state', WidgetLayoutVersion::STATE_RELEASE)
            ->with(['basedOn', 'rollbackOf', 'placements'])
            ->orderByDesc('version')
            ->paginate($perPage);

        // withTrashed: a removed account still names who published.
        $publisherNames = User::withTrashed()
            ->whereIn('id', $paginator->getCollection()->pluck('published_by')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $paginator->through(fn (WidgetLayoutVersion $release): array => [
            'version' => $release->version,
            'based_on_version' => $release->basedOn?->version,
            'rollback_of_version' => $release->rollbackOf?->version,
            'published_at' => $release->published_at,
            'published_by' => $release->published_by,
            'placements_count' => $release->placements->count(),
            'registry_contract_hash' => $release->registry_contract_hash,
            // History screen (Part 2 Task 5): the client formats published_at locally.
            'published_by_name' => $release->published_by === null ? null : $publisherNames->get($release->published_by),
            'is_live' => (int) $release->id === (int) $layout->current_published_version_id,
        ]);

        return WidgetLayoutReleaseResource::collection($paginator)->response();
    }
}
