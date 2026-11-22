<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use Cms\Widget\Http\Resources\V2\Admin\WidgetTargetResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `GET /api/v2/admin/widget/target-contexts/{type}/targets` (search) and
 * `GET /api/v2/admin/widget/target-contexts/{type}/targets/{target_id}`
 * (detail) (plan Chunk 2 Task 22, DoD line 1200; fixture route entries).
 *
 * Pure dispatch to the Task 7 typed resolver methods through
 * {@see WidgetTargetContextRegistry} -- this controller never branches on
 * Page/Post/any target type, never converts/coerces the opaque string
 * `target_id`, and never invents metadata beyond what the resolver's own
 * DTOs (`WidgetTargetPage`/`WidgetTargetSummary`/`WidgetResolvedTarget`)
 * already carry. Project/read authorization, "absent-in-authorized-scope"
 * vs "denied" (404 vs 403), and safe-label projection are ALL the
 * registered resolver's own responsibility (D-032/D-046); this controller
 * only adds the permission gate, capped pagination bounds, and the
 * unregistered-type 404.
 */
final class WidgetTargetCatalogController
{
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private readonly WidgetTargetContextRegistry $targetContexts,
        private readonly WidgetProjectContextResolver $projectContext,
    ) {
    }

    public function search(Request $request, string $type): JsonResponse
    {
        if ($request->user()?->can('widget_layout.viewAny') !== true) {
            return $this->permissionDenied();
        }

        if (!$this->targetContexts->isRegistered($type)) {
            return $this->targetTypeNotFound();
        }

        // per_page/page are only required to be well-formed positive
        // integers here -- an out-of-range per_page is CLAMPED, never
        // rejected, since WidgetTargetSearchQuery's own constructor already
        // guarantees "a resolver can never be asked to return an unbounded
        // page" (its own docblock); 422 is reserved for genuinely malformed
        // input (non-numeric), not merely an over-generous numeric request.
        $validator = Validator::make($request->query(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $project = $this->projectContext->resolve();
        $query = new WidgetTargetSearchQuery(
            targetType: $type,
            keywords: (string) $request->query('search', ''),
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', self::DEFAULT_PER_PAGE),
        );

        try {
            $page = $this->targetContexts->search($project, $query);
        } catch (InvalidArgumentException) {
            // Unregistered type is already handled above; a resolver-level
            // InvalidArgumentException has no other defined meaning here.
            return $this->targetTypeNotFound();
        }

        $rows = array_map(static fn ($summary): array => [
            'target_id' => $summary->targetId,
            'label' => $summary->label,
            'route_name' => $summary->routeName,
            'route_parameters' => $summary->routeParameters,
        ], $page->items);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
            ],
        ]);
    }

    public function show(Request $request, string $type, string $target_id): JsonResponse
    {
        if ($request->user()?->can('widget_layout.viewAny') !== true) {
            return $this->permissionDenied();
        }

        if (!$this->targetContexts->isRegistered($type)) {
            return $this->targetTypeNotFound();
        }

        $project = $this->projectContext->resolve();

        try {
            $resolved = $this->targetContexts->resolve($type, $project, $target_id);
        } catch (ModelNotFoundException) {
            return $this->targetNotFound();
        } catch (AuthorizationException $e) {
            return WidgetApiErrorResponse::jsonResponse(WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED, $e->getMessage());
        } catch (InvalidArgumentException) {
            return $this->targetTypeNotFound();
        }

        return (new WidgetTargetResource([
            'target_id' => $resolved->targetId,
            'target_type' => $resolved->targetType,
            'label' => $resolved->label,
            'route_name' => $resolved->routeName,
            'route_parameters' => $resolved->routeParameters,
        ]))->response()->setStatusCode(200);
    }

    private function permissionDenied(): JsonResponse
    {
        return WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to browse Widget targets.',
        );
    }

    /**
     * @param array<string, mixed> $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Invalid target search parameters.',
            'correlation_id' => (string) Str::uuid(),
            'details' => $errors,
        ], 422);
    }

    /**
     * No generic "resource not found" code exists in the frozen 26-code
     * dictionary -- mirrors {@see WidgetLayoutBootstrapController::layoutNotFound()}'s
     * own disclosed minimal-body precedent. Used for both an unregistered
     * target TYPE and an absent-in-authorized-scope target ID -- neither is
     * ever distinguishable from the other in the response body, so a caller
     * can never learn which one failed (no metadata leakage).
     */
    private function targetTypeNotFound(): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => 'Widget target context not found.',
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }

    private function targetNotFound(): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => 'Widget target not found in the authorized project scope.',
            'correlation_id' => (string) Str::uuid(),
        ], 404);
    }
}
