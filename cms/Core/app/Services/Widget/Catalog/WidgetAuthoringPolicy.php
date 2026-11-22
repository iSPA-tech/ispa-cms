<?php

namespace Cms\Core\Services\Widget\Catalog;

use Cms\Core\Models\Widget;

/**
 * Decides where hub widgets may be edited in place.
 *
 * Hub widgets are catalog-owned, so in-place editing is allowed only on a local authoring environment
 * (`ispa-cms.widget.catalog_authoring`), never in production. Site widgets are always editable.
 */
final class WidgetAuthoringPolicy
{
    public function authoringEnabled(): bool
    {
        return (bool) config('ispa-cms.widget.catalog_authoring', false)
            && ! app()->environment('production');
    }

    public function canEditInPlace(Widget $widget): bool
    {
        return ! $widget->isHub() || $this->authoringEnabled();
    }
}
