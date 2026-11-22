<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Placement-selectable Widget Definition/Theme catalog row (Chunk 1 Task 12
 * skeleton). This is a read-only projection of authorized legacy
 * Definition/Theme/Input data through a gateway and contains **approved
 * eligible Manifests only** — it does not transfer legacy mutation
 * ownership and is a different endpoint/permission from the separate
 * `GET /catalog/manifests` approval inventory (architecture.md "Frozen API,
 * Error, and Status Contract" > "Module API surface" paragraph; checklist
 * item 4 split-catalog semantics).
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetDefinitionCatalogResource extends JsonResource
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
            'widget_id',
            'widget_theme_id',
            'name',
            'theme_name',
            'display_name',
            'theme_display_name',
            'label',
            'eligible',
            'manifest_hash',
            'attestation_version',
            'applicability',
            'canonical_schema',
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
