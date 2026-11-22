/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { siteLayoutRouteConfig } from './route-config'
import { siteLayoutNavigationConfig } from './navigation-config'

export const siteLayoutAdminModule = {
    key: 'site-layout',
    routeConfig: siteLayoutRouteConfig,
    navigationConfig: siteLayoutNavigationConfig,
    // No settings: Widget has no settings screen; layouts and placements are records, not settings.
    // No tasks: nothing in Widget waits for staff review in a queue the shared attention panel could count.
    // No dashboardTiles: the health endpoint is a runtime health report, not the shared dashboard summary snapshot.
}
