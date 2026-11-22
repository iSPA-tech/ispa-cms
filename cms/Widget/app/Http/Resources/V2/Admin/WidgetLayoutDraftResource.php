<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Resources\V2\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin read projection of a Layout's CURRENT Draft (`GET
 * /layouts/{uuid}/draft`; disclosed post-freeze contract evolution, routes
 * 30->31 -- see docs/contracts/widget-site-layout-api.md).
 *
 * Pure-read companion to `PUT /layouts/{uuid}/draft`
 * ({@see \Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutDraftController::update()}):
 * exposes the SAME `version`/`lock_version` identity already frozen on
 * {@see WidgetLayoutResource}'s `current_draft` nested shape, plus the
 * Draft's full `placements[]` array. Each `placements[]` entry is a plain
 * array carrying exactly {@see WidgetPlacementResource}'s own frozen field
 * set (this route reuses that field list rather than duplicating it, so the
 * read and write shapes can never drift independently) -- the read path
 * does not wrap items through `WidgetPlacementResource` itself, mirroring
 * how {@see WidgetLayoutResource}'s own `current_draft`/`current_published`
 * nested fields are plain arrays too.
 *
 * `instance_fingerprints` (disclosed post-freeze evolution) is a
 * `map<widget_instance_uuid, usage_fingerprint>` -- one server-issued repin
 * token per Instance any Placement in this Draft references, each computed
 * through the shared {@see \Cms\Widget\SiteLayout\Drafts\WidgetDraftUsageFingerprint}
 * primitive that `POST .../versions-and-repin` validates against, so the
 * admin canvas can echo the value back on repin with no false-positive
 * `WIDGET_USAGE_CHANGED`. The controller populates it; this resource only
 * projects it.
 *
 * `contents` (Unified Widget Catalog Part 2, Task 9) is a
 * `map<"<widget_instance_uuid>:<widget_instance_version>", content>` -- what each
 * placement shows (`widget_id, widget_theme_id, widget_name, schema, values,
 * is_blocked`), so the area editor reads placement content without the
 * `/instances` routes.
 *
 * Skeleton only: no model coupling. See {@see WidgetInstanceResource} for
 * the shared frozenFields()/toArray() projection pattern rationale.
 */
final class WidgetLayoutDraftResource extends JsonResource
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
            'lock_version',
            'placements',
            'instance_fingerprints',
            'contents',
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
