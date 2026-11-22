<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of a reusable Widget Instance (Chunk 1 Task 12
 * skeleton). `WidgetInstance` is stable identity only and never carries
 * `widget_id`/`widget_theme_id` itself (D-007) — those live on the
 * immutable `current_version` snapshot pinned by this Instance's current
 * pointer (architecture.md "widget_instances" / "widget_instance_versions").
 *
 * Skeleton only: no Eloquent model exists yet (Chunk 1 adds no Site Layout
 * table). `toArray()` mechanically projects the frozen field list via
 * `data_get()`, so it works against a plain array/stdClass fixture today
 * and an Eloquent model once Task 13a/16 land, without the two ever
 * drifting apart. Field list is cross-checked against
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json by
 * {@see \Cms\Widget\Tests\Unit\Http\WidgetSiteLayoutContractDefinitionTest}.
 */
final class WidgetInstanceResource extends JsonResource
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
            'project_name',
            'name',
            'status',
            'current_version',
            'sync_status',
            'created_at',
            'updated_at',
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
