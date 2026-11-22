<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin\Concerns;

use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;

/**
 * Typed 409 for writes that would change a hub (catalog) widget on a site.
 *
 * Hub widgets are edited on a site only through the widget save, which clones them first; direct
 * deletes and theme/input child writes on a hub widget are refused here, before any write.
 */
trait RefusesHubWidgetEdits
{
    /** @return mixed A ready-to-return 409 AppResponse, or null when the widget may be written. */
    protected function hubReadonlyConflict(?Widget $widget): mixed
    {
        if ($widget === null || app(WidgetAuthoringPolicy::class)->canEditInPlace($widget)) {
            return null;
        }

        return ires()->error()
            ->code(409)
            ->errorCode('WIDGET_HUB_READONLY')
            ->message(__('mgs.widget_hub_readonly'));
    }
}
