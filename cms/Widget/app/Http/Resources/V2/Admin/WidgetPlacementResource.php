<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of one Layout-Version-owned Placement row (Chunk 1
 * Task 12 skeleton). `scope_identity` itself (architecture.md
 * "widget_placements") is internal/canonical and is never exposed
 * directly; this resource instead exposes the three source fields
 * (`scope_type`, `scope_key`, `target_type`/`target_id`) it was normalized
 * from. `rules` is always `{}` in MVP (non-empty rules are rejected with
 * 422 at the Draft PUT boundary, never stored).
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetPlacementResource extends JsonResource
{
    public function __construct(mixed $resource = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return list<string>
     */
    public static function frozenFields(): array
    {
        return [
            'uuid',
            'slot_key',
            'scope_type',
            'scope_key',
            'target_type',
            'target_id',
            'widget_instance_uuid',
            'widget_instance_version',
            'position',
            'enabled',
            'rules',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $output = [];

        foreach (self::frozenFields() as $field) {
            $output[$field] = data_get($this->resource, $field);
        }

        return $output;
    }
}
