<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Events\Widget;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A hub widget was cloned into a site-owned copy and every stored page use moved to the clone.
 *
 * Root event with primitives only, dispatched after the clone transaction commits, so listeners
 * (for example a module that keeps its own widget placements) always see the committed clone.
 */
final readonly class WidgetCloned implements ShouldDispatchAfterCommit
{
    /**
     * @param string $hubWidgetKey widget_key of the hub widget that was cloned.
     * @param int $cloneWidgetId id of the new site-owned widget.
     * @param array<int, int> $themeIdMap Hub theme id => clone theme id.
     * @param int $userId User who triggered the clone.
     */
    public function __construct(
        public string $hubWidgetKey,
        public int $cloneWidgetId,
        public array $themeIdMap,
        public int $userId,
    ) {
    }
}
