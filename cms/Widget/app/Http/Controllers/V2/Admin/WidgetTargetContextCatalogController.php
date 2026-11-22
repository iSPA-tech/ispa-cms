<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Resources\V2\Admin\WidgetTargetContextResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/admin/widget/target-contexts` (plan Chunk 2 Task 22; fixture
 * `/target-contexts` route entry; adr-widget-project-authorization.md
 * decision 2).
 *
 * Authorized read-only projection of every currently CODE-registered Target
 * Context type ({@see WidgetTargetContextRegistry}, Task 15's in-memory
 * multiplexer registry -- never a database table). Lists registered
 * *types* only (e.g. "page.static"); target *search/detail* rows are a
 * separate route/controller ({@see WidgetTargetCatalogController}).
 *
 * `resolver_service` is DELIBERATELY never projected -- it is internal
 * registry wiring (an FQCN), never a safe response field
 * ({@see WidgetTargetContextResource}'s own `excluded_fields` note, D-032/
 * D-046). This controller never even reads that property off the
 * definition; only `targetType`/`labelKey`/`templateContextKey` are copied.
 *
 * `project_applicable` is always `true`: {@see \App\Contracts\Widget\DTO\WidgetTargetContextDefinition}
 * carries no project-scoping field at all (Target Context registration is
 * global, not per-project), and MVP is single-project-per-deployment
 * (docs/architecture/adr-widget-project-authorization.md decision 1) -- so
 * every registered Target Context is, by construction, applicable to the
 * one authorized project this endpoint ever serves. There is no live data
 * source that could make this field false today; the field stays in the
 * frozen response shape for forward compatibility with a future
 * multi-project topology.
 */
final class WidgetTargetContextCatalogController
{
    public function __construct(
        private readonly WidgetTargetContextRegistry $targetContexts,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('widget_layout.viewAny') !== true) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to view the Widget Target Context catalog.',
            );
        }

        $rows = [];

        foreach ($this->targetContexts->types() as $type) {
            $definition = $this->targetContexts->definitionFor($type);

            if ($definition !== null) {
                $rows[] = [
                    'target_type' => $definition->targetType,
                    'label_key' => $definition->labelKey,
                    'template_context_key' => $definition->templateContextKey,
                    'project_applicable' => true,
                ];
            }
        }

        return WidgetTargetContextResource::collection(collect($rows))->response();
    }
}
