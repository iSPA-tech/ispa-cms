<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

/**
 * `POST /layouts/{uuid}/draft/placements/{placement_uuid}/duplicate`: the copy gets its own
 * instance; the body carries only the draft revision.
 */
final class DuplicateWidgetPlacementRequest extends AbstractWidgetPlacementContentRequest
{
}
