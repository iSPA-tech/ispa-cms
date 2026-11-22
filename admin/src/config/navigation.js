/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */
import {
    adminAdditionalNavigationConfigs,
    adminModuleSettings,
    adminNavigationConfigsByKey,
    adminSlottedModules,
    adminRouteContributions,
    applyContributions,
} from '@/config/admin-modules'
import { hideLegacyEmailNav } from '@/utils/moduleBundles'

const moduleNavigation = (key) => adminNavigationConfigsByKey[key]

/**
 * Sidebar order of the top-level groups, identified by the top-level item `name` (unique per group; `group` is not
 * guaranteed to be). Known names follow this order; unknown groups (e.g. a new module's) come after, sorted by name.
 */
const BASE_NAV_ORDER = [
    'dashboard',
    'calendar',
    'education-dashboard',
    'education-question-bank',
    'custom-ads-ads',
    'game-authority-dashboard',
    'pipeline-dashboard',
    'showroom-moderation-review',
    'inventory-dashboard',
    'digital-library-dashboard',
    'journal-dashboard',
    'affiliate-recommendation-dashboard',
    'sale-hunting-dashboard',
    'automation-runs',
    'mobile-app-apps',
    'helpdesk',
    'push-notifications',
    'auto-discover',
    'achievements',
    'class-online-courses',
    'mail-hub-overview',
    'webmail-dashboard',
    // Core widget library ("Thư viện widget"), right above the Widget module's shared-area group.
    'widget-library',
    'site-layout',
    'post-dashboard',
    'contact-dashboard',
    'product-dashboard',
    'order-dashboard',
    'real-estate-dashboard',
    'media-dashboard',
    'user-dashboard',
    'place-manage',
    'language-manage',
    'cms-management-dashboard',
    // PlatformHub (hub runtime only): package registry for the owner, beside the CMS domain system.
    'platform-hub',
    // Publisher portal: partners see it through the publisher permission, beside the Webmaster group.
    'platform-publisher',
    'setting-hub',
]

/**
 * The sidebar order: the open groups above, with each slotted module's group placed right after its `slot.navAfter`
 *.
 */
export const NAV_ORDER = adminSlottedModules.reduce((order, module) => {
    const name = module.navigationConfig?.name
    const index = order.indexOf(module.slot?.navAfter)
    if (!name || order.includes(name)) return order
    return index === -1 ? [...order, name] : [...order.slice(0, index + 1), name, ...order.slice(index + 1)]
}, BASE_NAV_ORDER)

/**
 * Order top-level navigation groups by NAV_ORDER; groups not listed follow, sorted by name.
 *
 * @param {object[]} items Top-level navigation items.
 * @returns {object[]} New, ordered list.
 */
export const orderTopLevelNav = (items) => {
    const rank = (item) => {
        const index = NAV_ORDER.indexOf(item?.name)
        return index === -1 ? Number.MAX_SAFE_INTEGER : index
    }
    return [...items].sort(
        (a, b) => rank(a) - rank(b) || (rank(a) === Number.MAX_SAFE_INTEGER ? String(a?.name).localeCompare(String(b?.name)) : 0)
    )
}

/**
 * Drop the entries flagged `webmasterOnly: true` (top level and children, recursively) unless the signed-in account
 * is a Webmaster. Pure: the caller passes the flag (see `isWebmaster()` in hooks/useCan), so module navigation
 * bundles only carry data and stay free of runtime imports.
 *
 * @param {object[]} items Navigation items.
 * @param {boolean} webmaster Whether the account is a Webmaster.
 * @returns {object[]} New list; the input is not mutated.
 */
export const filterWebmasterOnlyNav = (items = [], webmaster = false) =>
    items
        .filter((nav) => webmaster || !nav?.webmasterOnly)
        .map((nav) =>
            Array.isArray(nav?.children) && nav.children.length > 0
                ? { ...nav, children: filterWebmasterOnlyNav(nav.children, webmaster) }
                : nav
        )

const mainNav = [
    {
        name: 'dashboard',
        group: 'dashboard',
        text: 'nav.dashboard',
        rule: '',
        icon: 'home',
    },
    ...[
        moduleNavigation('education'),
        moduleNavigation('question-bank'),
        moduleNavigation('custom-ads'),
        moduleNavigation('game'),
        moduleNavigation('pipeline'),
        moduleNavigation('showroom-moderation'),
        moduleNavigation('inventory'),
        moduleNavigation('digital-library'),
        moduleNavigation('journal'),
        moduleNavigation('affiliate-recommendation'),
        moduleNavigation('sale-hunting'),
        moduleNavigation('automation'),
        moduleNavigation('mobile-app'),
        moduleNavigation('helpdesk'),
        // Nested inside the MobileApp group when that module is present; otherwise keep it reachable.
        ...(adminNavigationConfigsByKey['mobile-app'] ? [] : [moduleNavigation('push-notifications')]),
        moduleNavigation('auto-discover'),
        moduleNavigation('achievements'),
        moduleNavigation('class-online'),
        moduleNavigation('mail-hub'),
        moduleNavigation('webmail'),
        moduleNavigation('site-layout'),
        // Modules that place themselves with `slot`.
        ...adminSlottedModules.map((module) => module.navigationConfig),
    ].filter(Boolean),
    ...[moduleNavigation('content'), moduleNavigation('product')].filter(Boolean),
    ...[moduleNavigation('ecommerce')].filter(Boolean),
    ...[moduleNavigation('real-estate')].filter(Boolean),
    ...[moduleNavigation('core')].filter(Boolean),
    ...[moduleNavigation('geoplaces')].filter(Boolean),
    ...[moduleNavigation('cms-management')].filter(Boolean),
    {
        name: 'setting-hub',
        group: 'setting',
        text: 'nav.a.settings',
        // Holders of a module settings permission (e.g. helpdesk.admin) must reach the hub too.
        rule: [
            'setting.viewAny',
            ...new Set(adminModuleSettings.map((entry) => entry.rule).filter(Boolean).flat()),
        ],
        icon: 'cog-sync',
    },
    ...adminAdditionalNavigationConfigs,
]

export default hideLegacyEmailNav(
    orderTopLevelNav(applyContributions(mainNav, adminRouteContributions, 'navigation'))
)
