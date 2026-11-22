/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { SiteLayoutIndex, SiteLayoutPlacements } from './page-loaders'
import { AREA_EDITOR_RULE } from './navigation-config'

// The Widget module's admin is ONE screen: "Khu vực chung" (pages/placements.vue). The parent uses a bare
// `<router-view />` shell (index.vue) and redirects to it. Every older route of this group keeps its name and path as
// a redirect, so bookmarks and links land somewhere useful: the area editor, or the Core widget library
// ("Thư viện widget", cms/Core/admin) for everything that was about widgets and their designs.
export const siteLayoutRouteConfig = {
    text: 'nav.a.site-layout',
    path: 'site-layout',
    // Named-target redirect (object form, matching route.js's own catch-all/
    // root `redirect: { name: 'dashboard' }` convention). A bare string here
    // is resolved by Vue Router as a PATH, misses, falls through to the
    // catch-all, and its `pathMatch` param triggers the "Discarded invalid
    // param(s) pathMatch" warning; the named object resolves straight to the
    // child instead.
    redirect: { name: 'site-layout-placements' },
    load: SiteLayoutIndex,
    group: 'site-layout',
    name: 'site-layout',
    children: [
        {
            text: 'nav.a.site-layout-placements',
            path: 'placements',
            name: 'site-layout-placements',
            load: SiteLayoutPlacements,
            // Explicit: the menu is a single entry without children, so no child rule can be inherited from it.
            meta: { rule: AREA_EDITOR_RULE },
        },
        // The "Slots" page was dissolved (user decision): the areas are listed in the editor itself.
        // Old bookmarks land on the editor.
        {
            path: 'slots',
            redirect: { name: 'site-layout-placements' },
        },
        // The widget list and the "allowed designs" console are gone: a widget is managed in the widget library, and
        // a design needs no approval step any more. The rule stays the library's own.
        {
            path: 'widgets',
            name: 'site-layout-widgets',
            redirect: { name: 'widget-library' },
            meta: { rule: 'widget.viewAny' },
        },
        {
            path: 'templates',
            name: 'site-layout-templates',
            redirect: { name: 'widget-library' },
            meta: { rule: 'widget.viewAny' },
        },
        // The widget gallery and its source editor moved to the Core widget library ("Thư viện widget",
        // cms/Core/admin). Both route names stay so existing links and bookmarks land on the library; the
        // source editor keeps its `?edit=<widget id>` deep link. The rule stays the library's own.
        {
            path: 'widget-templates',
            name: 'site-layout-widget-templates',
            redirect: { name: 'widget-library' },
            meta: { rule: 'widget.viewAny' },
        },
        {
            path: 'widget-templates/sources',
            name: 'site-layout-widget-sources',
            redirect: (to) => ({ name: 'widget-library-sources', query: to.query }),
            meta: { rule: 'widget.viewAny' },
        },
    ],
}
