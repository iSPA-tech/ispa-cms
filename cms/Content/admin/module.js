/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import {
    contentCalendarRouteConfig,
    contentCommentRouteConfig,
    contentContactRouteConfig,
    contentMediaRouteConfig,
    contentRouteConfig,
} from './route-config'
import {
    contentCalendarNavigationConfig,
    contentContactNavigationConfig,
    contentMediaNavigationConfig,
    contentNavigationConfig,
} from './navigation-config'

// `navRuleOnRouteRoot: false` keeps the route group root rule-less, as before the move: the menu root rule
// (post.viewAny) must not be copied onto the `post` route root.
// The route group is named 'post' while the menu group is named 'post-dashboard'; contributions from other modules
// (for example a client module) match their parents by those names.
export const contentAdminModule = {
    key: 'content',
    navRuleOnRouteRoot: false,
    routeConfig: contentRouteConfig,
    navigationConfig: contentNavigationConfig,
    // Comments moderation page (/comments), a separate route group tagged with this module.
    additionalRouteConfigs: [
        contentCommentRouteConfig,
        contentMediaRouteConfig,
        contentContactRouteConfig,
        contentCalendarRouteConfig,
    ],
    // Media, Contacts and Calendar menu entries (tagged with this module).
    additionalNavigationConfigs: [
        contentMediaNavigationConfig,
        contentContactNavigationConfig,
        contentCalendarNavigationConfig,
    ],
    // Items waiting for staff, listed in "my tasks"; the counts come from the backend attention registry (summary).
    tasks: [
        { name: 'content.comments-pending', rule: 'comment.viewAny', source: 'summary' },
        { name: 'content.posts-pending', rule: 'post.viewAny', source: 'summary' },
    ],
    // Headline KPI cards of the Content dashboard (totals of the summary `content` block, same rule as the posts page).
    dashboardTiles: [
        { name: 'content.posts-total', rule: 'post.viewAny', source: 'summary' },
        { name: 'content.posts-published', rule: 'post.viewAny', source: 'summary' },
    ],
}
