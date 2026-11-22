<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Controllers\V2\Admin;

use Cms\Widget\Http\Resources\V2\Admin\WidgetSlotResource;
use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/admin/widget/slots` (plan Chunk 2 Task 22; fixture `/slots`
 * route entry; architecture.md "Slot and Scope Contract").
 *
 * Authorized read-only projection of every currently CODE-registered Slot
 * declaration ({@see WidgetSlotRegistry}, Task 15's in-memory registry --
 * never a database table). `WidgetSlotDeclaration::toArray()` already
 * produces exactly the seven frozen {@see WidgetSlotResource} fields (key,
 * label_key, cardinality, supported_scope_types, fallback, trust_lane,
 * availability), so this controller only enumerates and wraps -- it never
 * re-derives or filters any field. Zero registrations is a valid, expected
 * empty list (no project has registered a Slot until a later pilot task
 * wires one), never an error.
 */
final class WidgetSlotCatalogController
{
    public function __construct(
        private readonly WidgetSlotRegistry $slots,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('widget_layout.viewAny') !== true) {
            return WidgetApiErrorResponse::jsonResponse(
                WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
                'You do not have permission to view the Widget Slot catalog.',
            );
        }

        $rows = [];

        foreach ($this->slots->keys() as $key) {
            $declaration = $this->slots->declarationFor($key);

            if ($declaration !== null) {
                $rows[] = $declaration->toArray();
            }
        }

        // `area_count`: how many areas the active theme declares; the admin shows "Khu vực chung" only when > 0.
        return WidgetSlotResource::collection(collect($rows))
            ->additional(['meta' => ['area_count' => count($rows)]])
            ->response();
    }
}
