<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of one authorized target search/detail row (Chunk 1
 * Task 12 skeleton). Mirrors
 * {@see \App\Contracts\Widget\DTO\WidgetResolvedTarget} exactly (D-046):
 * `target_id` is an opaque string end-to-end — a production adapter (Task
 * 23's Page adapter) emits a UUID, never a numeric database ID. Controllers
 * and `base-admin` must never branch on target type or coerce this to an
 * integer.
 *
 * This single class serves both frozen shapes from the generic target
 * resolver: the `GET .../targets` search list projects the
 * {@see \App\Contracts\Widget\DTO\WidgetTargetSummary} subset of these same
 * fields (everything except `target_type`, which is redundant inside one
 * already-typed search result), and the `GET .../targets/{target_id}`
 * detail route uses the full field set below. There is no separate
 * "summary" resource class — the fixture's route documents the search
 * projection as a query-time subset, not a second frozen resource.
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetTargetResource extends JsonResource
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
            'target_id',
            'target_type',
            'label',
            'route_name',
            'route_parameters',
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
