/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { areaNavHidden } from './area-count'

// ONE menu entry: "Khu vực chung" (the area editor, pages/placements.vue), shown only when the active theme has at
// least one shared area (`hidden`, read on every render — see area-count.js). Widgets themselves are managed in the
// Core widget library ("Thư viện widget"), which has its own entry.
//
// The entry admits anyone who can view, compose, preview, publish or restore the shared areas (permission inventory
// of cms/Widget/database/seeders/WidgetPermissionTableSeeder.php: widget_layout viewAny/view/update/preview/publish/
// rollback). The backend stays authoritative; this only gates what the menu shows.
export const AREA_EDITOR_RULE = [
    'widget_layout.viewAny',
    'widget_layout.update',
    'widget_layout.preview',
    'widget_layout.publish',
    'widget_layout.rollback',
]

export const siteLayoutNavigationConfig = {
    name: 'site-layout',
    group: 'site-layout',
    text: 'nav.a.site-layout',
    rule: AREA_EDITOR_RULE,
    module: 'Widget',
    icon: 'view-dashboard-outline',
    hidden: areaNavHidden,
}
