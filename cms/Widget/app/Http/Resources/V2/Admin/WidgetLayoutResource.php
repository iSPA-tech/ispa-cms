<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of a project-owned Layout identity (Chunk 1 Task 12
 * skeleton). `widget_layouts` carries only identity plus the two pointers
 * (`current_draft_version_id`, `current_published_version_id`); it has no
 * `uuid` on the version rows themselves, so `current_draft`/
 * `current_published` project `version`/`lock_version` numbers, never an
 * invented version UUID (architecture.md "widget_layouts" /
 * "widget_layout_versions").
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetLayoutResource extends JsonResource
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
            'key',
            'current_draft',
            'current_published',
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
