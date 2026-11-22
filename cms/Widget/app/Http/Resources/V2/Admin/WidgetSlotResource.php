<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of one code-owned Slot declaration (Chunk 1 Task 12
 * skeleton). Mirrors {@see \App\Contracts\Widget\DTO\WidgetSlotDefinition}
 * plus the additional registry-only fields architecture.md's "Slot and
 * Scope Contract" names (supported scope types, fallback behavior, allowed
 * renderer/trust lane, availability/health metadata). `label_key` is a
 * translation key reference only — Slot definitions never carry a
 * hard-coded single-locale label.
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetSlotResource extends JsonResource
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
            'key',
            'label_key',
            'cardinality',
            'supported_scope_types',
            'fallback',
            'trust_lane',
            'availability',
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
