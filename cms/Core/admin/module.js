/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import {
    coreCategoryRouteConfig,
    coreHubConnectRouteConfig,
    coreLanguageRouteConfig,
    coreSettingRouteContributions,
    coreUserRouteConfig,
    coreWidgetLibraryRouteConfig,
} from './route-config'
import { coreLanguageNavigationConfig, coreUserNavigationConfig, coreWidgetLibraryNavigationConfig } from './navigation-config'

/**
 * Core admin registry (key `core`). Core is always on and cannot be disabled, so neither its route groups nor its menu
 * groups carry a catalog `module` name (no `meta.module` tag, never blocked by the module activation gate).
 *
 * - `routeConfig` / `navigationConfig`: the Users group (users, roles, permissions).
 * - `additionalRouteConfigs` / `additionalNavigationConfigs`: further Core groups: Languages (languages, provinces, translations); Categories (gallery and item categories); the widget library (list and design editor); the one-tap hub connect return address; Settings as it moves in.
 * - `dashboardTiles`: headline KPI tiles of the Users dashboard (registry v2, data only; same rule as the Users menu).
 * - `routeContributions`: settings leaf pages (email, menu, upload, enumerates, meta classes/attributes/values, modules,
 *   cache, API logs) merged into the category groups the shell keeps (`setting-general`, `-content`, `-data`, `-system`); the workflows builder, agentic console and AI runtime pages join `setting-automation`.
 * - `navRuleOnRouteRoot: false`: the shell must not copy the menu root rule (`user.viewAny`) onto the route group root
 *   `user`. Before the move that root had no rule, so its rule-less children (`user-create`, `user-detail`,
 *   `user-update`) kept an empty rule; copying it would change who can open those screens.
 */
export const coreAdminModule = {
    key: 'core',
    routeConfig: coreUserRouteConfig,
    navigationConfig: coreUserNavigationConfig,
    navRuleOnRouteRoot: false,
    additionalRouteConfigs: [coreLanguageRouteConfig, coreCategoryRouteConfig, coreWidgetLibraryRouteConfig, coreHubConnectRouteConfig],
    additionalNavigationConfigs: [coreLanguageNavigationConfig, coreWidgetLibraryNavigationConfig],
    routeContributions: coreSettingRouteContributions,
    // "Sessions and tokens" tab of the user page: the account's API tokens and "revoke all of this user". The tab body checks
    // Webmaster itself; `role.viewAny` is a Webmaster-managed rule, so only the Webmaster (and role managers) see the tab.
    profileTabs: [
        {
            target: 'user',
            name: 'core.security-tokens',
            text: 'text.security_tokens.user_tab.title',
            rule: 'role.viewAny',
            load: () => import('./components/security/user-tokens-tab.vue'),
        },
    ],
    // Users dashboard KPI tiles: totals of the shared dashboard summary snapshot (people block), same rule as the Users menu.
    // Settings and Languages declare no tiles: the summary snapshot has no source for them.
    dashboardTiles: [
        { name: 'core.users-total', rule: 'user.viewAny', source: 'summary' },
        { name: 'core.users-new', rule: 'user.viewAny', source: 'summary' },
    ],
}
