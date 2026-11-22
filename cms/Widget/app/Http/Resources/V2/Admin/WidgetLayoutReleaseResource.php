<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of one immutable Layout Release (Chunk 1 Task 12
 * skeleton). Backs `GET .../releases` plus the publish/rollback mutation
 * responses. Publish/rollback responses always include both the new
 * Release (this resource) and the fresh Draft's identity/lock (projected
 * separately via {@see WidgetLayoutResource}'s `current_draft` shape) —
 * never only one of the two (architecture.md "Publish", "Rollback";
 * checklist item 11).
 *
 * `registry_contract_hash` is exposed for diagnostics/compatibility
 * display; the full `registry_contracts` JSON body is intentionally not a
 * frozen field here (internal compatibility-comparison payload, not an
 * admin-facing projection).
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetLayoutReleaseResource extends JsonResource
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
            'version',
            'based_on_version',
            'rollback_of_version',
            'published_at',
            'published_by',
            'placements_count',
            'registry_contract_hash',
        ];
    }

    /**
     * Added by `GET .../releases` only: publisher display name and whether the row is the live release.
     *
     * @return list<string>
     */
    public static function historyFields(): array
    {
        return ['published_by_name', 'is_live'];
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

        // Release list only (Part 2 Task 5); publish/rollback responses keep the frozen shape.
        foreach (self::historyFields() as $field) {
            if (is_array($this->resource) && array_key_exists($field, $this->resource)) {
                $output[$field] = $this->resource[$field];
            }
        }

        return $output;
    }
}
