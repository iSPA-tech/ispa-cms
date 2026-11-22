<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use Cms\Core\Models\Widget;

/** Outcome of {@see WidgetCloneService::cloneForSite()}. */
final readonly class WidgetCloneResult
{
    /**
     * @param Widget $clone The new site-owned widget.
     * @param array<int, int> $themeIdMap Hub theme id => clone theme id.
     * @param int $pagesUpdated Number of `posts` rows whose content was rewritten to the clone.
     */
    public function __construct(
        public Widget $clone,
        public array $themeIdMap,
        public int $pagesUpdated,
    ) {
    }
}
