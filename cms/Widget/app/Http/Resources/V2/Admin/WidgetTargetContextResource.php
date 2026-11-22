<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of one code-registered Target Context type (Chunk 1
 * Task 12 skeleton). Mirrors
 * {@see \App\Contracts\Widget\DTO\WidgetTargetContextDefinition} minus its
 * internal `resolverService` FQCN, which is registry wiring and is never a
 * safe projection field (D-032, D-046). This resource lists registered
 * *types* (e.g. "page"); target *search/detail* rows use
 * {@see WidgetTargetResource} instead.
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetTargetContextResource extends JsonResource
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
            'target_type',
            'label_key',
            'template_context_key',
            'project_applicable',
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
