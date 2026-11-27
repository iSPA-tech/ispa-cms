/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { createRouter, createWebHistory } from 'vue-router'
import { basePath } from '@/config/base'
import mainNavConfig from '@/config/navigation'
import {
    adminAdditionalRouteConfigs,
    adminModules,
    adminRouteConfigsByKey,
    adminSlottedModules,
    adminRouteContributions,
    applyContributions,
} from '@/config/admin-modules'
import { isLegacyEmailRoute, legacyEmailRedirect, refreshLegacyEmailHandover } from '@/utils/moduleBundles'
import { rule } from '@/mixins'
import { hasAccountData, isWebmasterRouteBlocked } from '@/hooks/useCan'
import { isModuleActive, useActiveModules } from '@/hooks/useActiveModules'
import { announceModuleOff, collectNavModules, isRouteModuleBlocked } from '@/utils/moduleRouteGate'
import {
    Account,
    AccountActivity,
    AccountDashboard,
    AccountPassword,
    AccountSetting,
    AccountUpdate,
    AuthAuth,
    AuthForgot,
    AuthSignIn,
    Dashboard,
    Setting,
    SettingCategoryLayout,
    SettingHub,
} from '@/config/router'

const navigations = {
    dashboard: {
        text: 'nav.dashboard',
        path: 'dashboard',
        name: 'dashboard',
        load: Dashboard,
        group: 'dashboard',
    },
    'my-tasks': {
        text: 'nav.a.my-tasks',
        path: 'my-tasks',
        name: 'my-tasks',
        load: () => import('@/pages/my-tasks.vue').then((m) => m.default),
        group: 'my-tasks',
    },
    notifications: {
        text: 'nav.a.notifications',
        path: 'notifications',
        name: 'notifications',
        load: () => import('@/pages/notifications.vue').then((m) => m.default),
        group: 'notifications',
    },
    education: adminRouteConfigsByKey.education,
    game: adminRouteConfigsByKey.game,
    account: {
        text: 'text.account',
        path: 'account',
        redirect: { name: 'account-dashboard' },
        load: Account,
        group: 'account',
        name: 'account',
        children: [
            {
                text: 'nav.a.account-dashboard',
                path: 'dashboard',
                name: 'account-dashboard',
                load: AccountDashboard,
            },
            {
                text: 'text.acc.activity-history',
                path: 'activity',
                name: 'account-activity',
                load: AccountActivity,
            },
            {
                text: 'nav.b.update',
                path: 'update',
                name: 'account-update',
                load: AccountUpdate,
            },
            {
                text: 'nav.a.account-setting',
                path: 'setting',
                name: 'account-setting',
                load: AccountSetting,
            },
            {
                text: 'nav.a.account-password',
                path: 'password',
                name: 'account-password',
                load: AccountPassword,
            },
        ],
    },
    product: adminRouteConfigsByKey.product,
    realEstate: adminRouteConfigsByKey['real-estate'],
    setting: {
        text: 'nav.a.settings',
        path: 'setting',
        name: 'setting',
        redirect: { name: 'setting-hub' },
        load: Setting,
        group: 'setting',
        children: [
            // Hub landing
            {
                text: 'nav.a.settings',
                path: 'hub',
                name: 'setting-hub',
                load: SettingHub,
            },
            // Server Dashboard (setting-dashboard) comes from the Core registry (routeContributions).
            // === Category: General ===
            {
                path: 'general',
                name: 'setting-general',
                redirect: { name: 'setting-basic' },
                load: SettingCategoryLayout,
                meta: { settingCategory: 'general' },
                // Basic, contact, social, email, homepage and ads pages come from the Core registry (routeContributions).
                children: [],
            },
            // === Category: Content ===
            {
                path: 'content',
                name: 'setting-content',
                redirect: { name: 'setting-menu' },
                load: SettingCategoryLayout,
                meta: { settingCategory: 'content' },
                // Menu and Upload pages come from the Core registry (routeContributions).
                children: [],
            },
            // === Category: Data ===
            {
                path: 'data',
                name: 'setting-data',
                redirect: { name: 'setting-enumerate' },
                load: SettingCategoryLayout,
                meta: { settingCategory: 'data' },
                // Enumerate and meta pages come from the Core registry (routeContributions).
                children: [],
            },
            // === Category: System ===
            {
                path: 'system',
                name: 'setting-system',
                redirect: { name: 'setting-advanced' },
                load: SettingCategoryLayout,
                meta: { settingCategory: 'system' },
                // Advanced, modules, backups, cache and API log pages come from the Core registry (routeContributions).
                children: [],
            },
            // === Category: Automation ===
            {
                path: 'automation',
                name: 'setting-automation',
                redirect: { name: 'setting-agentic' },
                load: SettingCategoryLayout,
                meta: { settingCategory: 'automation' },
                // Agentic console and AI runtime pages come from the Core registry (routeContributions); the workflows
                // have their own sidebar group.
                children: [],
            },
            // === Backwards-compatibility redirects (old flat URLs) ===
            { path: 'basic', redirect: { name: 'setting-basic' } },
            { path: 'social', redirect: { name: 'setting-social' } },
            { path: 'email', redirect: { name: 'setting-email' } },
            { path: 'homepage', redirect: { name: 'setting-homepage' } },
            { path: 'ads', redirect: { name: 'setting-ads' } },
            { path: 'menu', redirect: { name: 'setting-menu' } },
            // Widget management lives in the Core widget library ("Thư viện widget"); both old settings URLs land
            // there.
            { path: 'widget', redirect: { name: 'widget-library' } },
            { path: 'content/widget', redirect: { name: 'widget-library' } },
            { path: 'upload', redirect: { name: 'setting-upload' } },
            { path: 'enumerate', redirect: { name: 'setting-enumerate' } },
            { path: 'enumerate-type', redirect: { name: 'setting-enumerate-type' } },
            { path: 'meta-class', redirect: { name: 'setting-meta-class' } },
            { path: 'meta-attribute', redirect: { name: 'setting-meta-attribute' } },
            { path: 'meta-value', redirect: { name: 'setting-meta-value' } },
            { path: 'advanced', redirect: { name: 'setting-advanced' } },
            { path: 'modules', redirect: { name: 'setting-modules' } },
            { path: 'cache', redirect: { name: 'setting-cache' } },
            { path: 'api-logs', redirect: { name: 'setting-api-logs' } },
            { path: 'workflows', redirect: { name: 'workflows' } },
            { path: 'agentic', redirect: { name: 'setting-agentic' } },
            { path: 'ai-runtime', redirect: { name: 'setting-ai-runtime' } },
            { path: 'scheduler', redirect: { name: 'setting-hub' } },
        ],
    },
    cmsManagement: adminRouteConfigsByKey['cms-management'],
    content: adminRouteConfigsByKey.content,
    pipeline: adminRouteConfigsByKey.pipeline,
    showroomModeration: adminRouteConfigsByKey['showroom-moderation'],
    inventory: adminRouteConfigsByKey.inventory,
    digitalLibrary: adminRouteConfigsByKey['digital-library'],
    journal: adminRouteConfigsByKey.journal,
    affiliateRecommendation: adminRouteConfigsByKey['affiliate-recommendation'],
    saleHunting: adminRouteConfigsByKey['sale-hunting'],
    automation: adminRouteConfigsByKey.automation,
    mobileApp: adminRouteConfigsByKey['mobile-app'],
    helpdesk: adminRouteConfigsByKey.helpdesk,
    questionBank: adminRouteConfigsByKey['question-bank'],
    customAds: adminRouteConfigsByKey['custom-ads'],
    pushNotifications: adminRouteConfigsByKey['push-notifications'],
    autoDiscover: adminRouteConfigsByKey['auto-discover'],
    achievements: adminRouteConfigsByKey.achievements,
    classOnline: adminRouteConfigsByKey['class-online'],
    mailHub: adminRouteConfigsByKey['mail-hub'],
    webmail: adminRouteConfigsByKey.webmail,
    siteLayout: adminRouteConfigsByKey['site-layout'],
    // Modules that place themselves with `slot`, under their camel-cased key (the route `meta.mod`).
    ...Object.fromEntries(adminSlottedModules.map((module) => [module.key.replace(/-([a-z])/g, (_m, c) => c.toUpperCase()), module.routeConfig])),
    // Place group lives in the GeoPlaces registry (cms/GeoPlaces/admin); the key stays `place` so `meta.mod` is unchanged.
    place: adminRouteConfigsByKey.geoplaces,
    // Users group lives in the Core registry (cms/Core/admin); the key stays `user` so `meta.mod` is unchanged.
    user: adminRouteConfigsByKey.core,
    // Order group lives in the Ecommerce registry (cms/Ecommerce/admin); the key stays `order` so `meta.mod` is unchanged.
    order: adminRouteConfigsByKey.ecommerce,
    auth: {
        text: 'nav.a.authenticator',
        load: AuthAuth,
        path: 'auth',
        name: 'auth-parent',
        children: [
            {
                text: 'nav.b.sign-in',
                path: 'login',
                name: 'auth-login',
                load: AuthSignIn,
            },
            {
                text: 'text.auth.recover',
                path: 'forgot',
                name: 'auth-forgot',
                load: AuthForgot,
            },
        ],
    },
}

const collectNavRules = (items = [], inheritedRule = '') => {
    const rules = new Map()

    items.forEach((item = {}) => {
        const hasOwnRule = Object.prototype.hasOwnProperty.call(item, 'rule')
        const ownRule = hasOwnRule ? item.rule : inheritedRule
        const resolvedRule = ownRule || ''

        if (item.name) {
            rules.set(item.name, resolvedRule)
        }

        if (Array.isArray(item.children) && item.children.length > 0) {
            const childInheritedRule = hasOwnRule ? item.rule : inheritedRule
            collectNavRules(item.children, childInheritedRule).forEach((value, key) => {
                rules.set(key, value)
            })
        }
    })

    return rules
}

const routeRuleByName = collectNavRules(mainNavConfig)
const { activeModuleCatalogLoaded } = useActiveModules()
const routeModuleByName = collectNavModules(mainNavConfig)

adminModules.forEach((adminModule) => {
    const { routeConfig, navigationConfig } = adminModule || {}

    // A registry with `navRuleOnRouteRoot: false` (Core) keeps a rule-less route group root, as before its move.
    if (!routeConfig?.name || adminModule.navRuleOnRouteRoot === false) {
        return
    }

    if (Object.prototype.hasOwnProperty.call(navigationConfig || {}, 'rule')) {
        routeRuleByName.set(routeConfig.name, navigationConfig.rule || '')
    }
})

const applyPermissionRules = (routeConfig = {}, inheritedRule = '') => {
    const hasExplicitMetaRule = Object.prototype.hasOwnProperty.call(routeConfig?.meta || {}, 'rule')
    let routeRule = inheritedRule || ''

    if (hasExplicitMetaRule) {
        routeRule = routeConfig.meta.rule || ''
    } else if (routeConfig?.meta?.publicShell === true) {
        routeRule = ''
    } else if (routeConfig?.name && routeRuleByName.has(routeConfig.name)) {
        routeRule = routeRuleByName.get(routeConfig.name)
    }

    if (!routeConfig.meta) {
        routeConfig.meta = {}
    }

    routeConfig.meta.rule = routeRule

    if (Array.isArray(routeConfig.children) && routeConfig.children.length > 0) {
        routeConfig.children = routeConfig.children.map((child) => applyPermissionRules(child, routeRule))
    }

    return routeConfig
}

const navRecursive = (items = [], mod = '', parentPath = '', group = null, subGroup = null) => {
    return items.map((item) => {
        // Build route path.
        let path = `${parentPath}/${item.path}`

        const route = {
            path: path,
            name: item.name || undefined,
            component: item.load,
            children: [],
            meta: {
                title: item?.text || item.name || '',
                description: item?.description || null,
                mod,
                lang: item?.lang || undefined,
                params: item?.params || {},
                group: group || item?.group || null,
                subGroup: subGroup || item?.subGroup || null,
                ...(item.meta || {}),
            },
        }

        if (item.redirect) {
            route.redirect = item.redirect
        }
        // A module may guard a page before it renders (e.g. a prerequisite redirect).
        if (typeof item.beforeEnter === 'function') {
            route.beforeEnter = item.beforeEnter
        }

        if (item.children && item.children.length) {
            route.children = navRecursive(
                item.children,
                mod,
                path,
                group,
                subGroup || item?.subGroup
            )
        }

        return route
    })
}

const registerRoute = (navConfig = {}) => {
    const routes = []

    Object.keys(navConfig).forEach((mod) => {
        const moduleConfig = navConfig[mod]

        if (!moduleConfig) {
            return
        }

        // Build the module root route.
        const moduleRoute = {
            path: `${basePath}${moduleConfig.path || mod}`,
            children: [],
            name: moduleConfig.name || mod,
            meta: {
                title: moduleConfig?.text || moduleConfig.name || '',
                description: moduleConfig?.description || null,
                mod,
                lang: moduleConfig?.lang || undefined,
                params: moduleConfig?.params || {},
                group: moduleConfig?.group || null,
            },
        }

        if (moduleConfig.load) {
            moduleRoute.component = moduleConfig.load
        }
        // Attach redirect when configured.
        if (moduleConfig.redirect) {
            moduleRoute.redirect = moduleConfig.redirect
        }

        // Build child routes when configured.
        if (moduleConfig.children && Array.isArray(moduleConfig.children)) {
            moduleRoute.children = navRecursive(
                moduleConfig.children,
                mod,
                moduleRoute.path,
                moduleConfig.group
            )
        }

        routes.push(moduleRoute)
    })

    // Register routes that do not belong to a module.
    routes.push({
        path: basePath,
        redirect: {
            name: 'dashboard',
        },
    })
    routes.push({
        path: '/',
        redirect: {
            name: 'dashboard',
        },
    })
    routes.push({
        path: basePath + ':pathMatch(.*)*',
        redirect: {
            name: 'dashboard',
        },
    })

    return routes
}

/**
 * Tag every route with `meta.module` (catalog module name) from the navigation tree; children inherit the parent's.
 *
 * @param {object} routeConfig - Route record.
 * @param {string} inheritedModule - Module name inherited from the parent route.
 * @returns {object} The same route record.
 */
const applyModuleTags = (routeConfig = {}, inheritedModule = '') => {
    const routeModule = (routeConfig?.name && routeModuleByName.get(routeConfig.name)) || inheritedModule || ''

    if (routeModule) {
        routeConfig.meta = { ...(routeConfig.meta || {}), module: routeModule }
    }

    if (Array.isArray(routeConfig.children)) {
        routeConfig.children = routeConfig.children.map((child) => applyModuleTags(child, routeModule))
    }

    return routeConfig
}

/**
 * Collect the names of every route in a list of route configs, children included.
 *
 * @param {object[]} configs Route configs (module root routes or child routes).
 * @returns {string[]} Route names.
 */
const collectRouteNames = (configs = []) =>
    configs.flatMap((config) => [
        ...(config?.name ? [config.name] : []),
        ...collectRouteNames(config?.children || []),
    ])

// Routes a registry adds (extra groups, contributions into another group) belong to the OWNING module, not the parent.
adminAdditionalRouteConfigs.forEach(({ module, routeConfig }) => {
    collectRouteNames([routeConfig]).forEach((name) => {
        if (module && !routeModuleByName.has(name)) routeModuleByName.set(name, module)
    })
})
adminRouteContributions.forEach(({ module, routes }) => {
    collectRouteNames(routes).forEach((name) => {
        if (module && !routeModuleByName.has(name)) routeModuleByName.set(name, module)
    })
})

/**
 * Compose the final route map: contributed child routes are merged into their parent groups and additional route
 * groups are appended. Runs before permission rules and module tags are applied. The missing-parent warning is
 * left to navigation.js, which checks the same parents.
 *
 * @param {Record<string, object>} base Route configs keyed by module key.
 * @returns {Record<string, object>} Composed route configs.
 */
const composeNavigations = (base) => {
    const keys = Object.keys(base)
    const merged = applyContributions(
        keys.map((key) => base[key]),
        adminRouteContributions,
        'routes',
        () => {}
    )
    const composed = Object.fromEntries(keys.map((key, index) => [key, merged[index]]))
    adminAdditionalRouteConfigs.forEach(({ moduleKey, routeConfig }, index) => {
        composed[`${moduleKey}-extra-${index}`] = routeConfig
    })
    return composed
}

let route = registerRoute(composeNavigations(navigations))
route = route.map((routeConfig) => applyModuleTags(applyPermissionRules(routeConfig, ''), ''))

export const router = createRouter({
    history: createWebHistory(),
    routes: route,
})

/**
 * Where a navigation may go: true, or the route to send it to instead.
 *
 * @param {object} to Target route.
 * @returns {true|object}
 */
const routeDecision = (to) => {
    // A screen of a module that is off on this site does not open by URL. While the catalog is unknown this stays
    // open; App re-checks once it arrives.
    if (isRouteModuleBlocked(to.meta?.module, activeModuleCatalogLoaded.value, isModuleActive)) {
        announceModuleOff()
        return { name: 'dashboard' }
    }

    // Webmaster-only screens (e.g. Backups) refuse a known non-Webmaster account.
    if (isWebmasterRouteBlocked(to.meta)) {
        return { name: 'dashboard' }
    }

    if (!to.meta?.rule) {
        return true
    }

    // No cached account yet (first load after sign-in or after a new build): let the page open; App re-checks the
    // rule once the account arrives and sends the user to the dashboard if it is not allowed.
    if (!hasAccountData()) {
        return true
    }

    if (rule(to.meta.rule)) {
        return true
    }

    return { name: 'dashboard' }
}

if (typeof router.beforeEach === 'function') {
    router.beforeEach((to) => {
        // Legacy Contact -> Email pages hand over to Webmail once it is known to receive contact-form mail. The answer is asked before the
        // page renders, so it never flashes; while it is unknown, or when the request fails, the legacy page opens.
        if (isLegacyEmailRoute(to.name)) {
            return refreshLegacyEmailHandover(true).then((handedOver) =>
                handedOver ? { path: legacyEmailRedirect(to.name, true) } : routeDecision(to)
            )
        }
        // The first answer of this page load keeps the Contact menu right; it never delays the navigation.
        refreshLegacyEmailHandover()

        return routeDecision(to)
    })
}

export default {
    router,
}
