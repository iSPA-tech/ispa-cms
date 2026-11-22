<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin composite response for `POST .../draft/instances/{instance_uuid}/versions-and-repin`
 * (plan Chunk 2 Task 18; architecture.md line 1069: "return both normalized
 * Resources with `sync_status: ready`").
 *
 * Wraps BOTH normalized resources this route returns -- the repinned
 * Instance (shaped exactly like {@see WidgetInstanceResource}'s own frozen
 * field set) and the updated Draft (version/lock_version plus its
 * placements, each shaped exactly like {@see WidgetPlacementResource}'s own
 * frozen field set) -- under `instance`/`draft` keys, since the frozen
 * contract fixture's `response_resource: "WidgetInstanceResource"` names
 * only the PRIMARY identity type (the same one-name-for-a-multi-identity-
 * response convention already established by
 * `WidgetLayoutReleaseResource` on the publish/rollback routes, whose own
 * fixture note discloses "response includes new Release identity and fresh
 * Draft identity/lock"). Not part of the fixture's closed `resources{}` set
 * (no route other than this one returns this composite shape), so it is not
 * in {@see \Cms\Widget\Tests\Unit\Http\WidgetSiteLayoutContractDefinitionTest}'s
 * closed resource-class map; the CONTROLLER builds `instance`/`draft`
 * payloads field-for-field identical to WidgetInstanceResource/
 * WidgetPlacementResource so the nested shapes stay in lockstep by
 * construction.
 *
 * Skeleton only: mirrors the established frozenFields()+toArray()+
 * data_get() projection pattern every other resource in this module uses.
 */
final class WidgetInstanceDraftRepinResource extends JsonResource
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
            'instance',
            'draft',
            'sync_status',
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
