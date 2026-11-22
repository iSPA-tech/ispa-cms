import { shallowRef } from 'vue'
import { basePath } from '@/config/base'
import { resourcePage } from '@/components/resource/load-resource-page'

// Optional modules are discovered with wildcard globs, so a new `cms/<Module>/admin/module.js` needs no edit here.
// Root precedence (first match wins per module folder): workspace `ispa-cms/cms`, release `cms`, release-root `cms`.
const workspaceModuleFiles = import.meta.glob('../../../ispa-cms/cms/*/admin/module.js', { eager: true })
const releaseAdminModuleFiles = import.meta.glob('../../../cms/*/admin/module.js', { eager: true })
const releaseRootModuleFiles = import.meta.glob('../../cms/*/admin/module.js', { eager: true })
// The Game admin lives in this app (`src/modules/game`) and is not part of the open-source export, so it is discovered
// with a glob too: the build works when the folder is absent and `gameAdminModule` is then undefined.
const gameModuleFiles = import.meta.glob('../modules/game/admin/module.js', { eager: true })

// Known open module keys in their historical sidebar order. Another module places itself with
// `slot: { after: '<key>' }` in its admin definition (client modules are discovered, never named here);
// modules with neither follow, sorted by key.
const MODULE_ORDER = [
    'question-bank',
    'custom-ads',
    'affiliate-recommendation',
    'journal',
    'pipeline',
    'showroom-moderation',
    'sale-hunting',
    'automation',
    'mobile-app',
    'helpdesk',
    'push-notifications',
    'auto-discover',
    'achievements',
    'class-online',
    'mail-hub',
    'webmail',
    // Core (always on): its groups keep their own slots through route.js and NAV_ORDER; listed last so extra Core
    // groups (additionalRouteConfigs) are appended after every optional module's.
    'core',
]

/**
 * Read the module folder name (`<Module>`) from a glob path like `.../cms/<Module>/admin/module.js`.
 *
 * @param {string} path Glob match path.
 * @returns {string} Folder name of the module.
 */
const moduleFolderFromPath = (path) => path.split('/').slice(-3, -2)[0]

/**
 * Pick the admin module definition from a loaded module file: the first export that is an object
 * carrying both `key` and `routeConfig`.
 *
 * @param {Record<string, unknown>} moduleFile Eager-imported module namespace.
 * @returns {object|undefined} The admin module definition, if any.
 */
const adminModuleFromFile = (moduleFile) =>
    Object.values(moduleFile ?? {}).find(
        (value) => value !== null && typeof value === 'object' && value.key && value.routeConfig
    )

/**
 * Collect optional admin modules from all roots. The first root that provides a folder wins,
 * then known keys are ordered by MODULE_ORDER and the rest are sorted by key.
 *
 * @param {Array<Record<string, object>>} roots Glob results in precedence order.
 * @returns {object[]} Ordered optional admin modules.
 */
const collectOptionalAdminModules = (roots) => {
    const byFolder = new Map()
    for (const files of roots) {
        for (const [path, moduleFile] of Object.entries(files)) {
            const folder = moduleFolderFromPath(path)
            if (byFolder.has(folder)) continue
            const adminModule = adminModuleFromFile(moduleFile)
            if (adminModule) byFolder.set(folder, adminModule)
        }
    }
    const slotted = new Map([...byFolder.values()].filter((m) => m.slot?.after).map((m) => [m.key, m.slot.after]))
    const rank = (key, seen = new Set()) => {
        const index = MODULE_ORDER.indexOf(key)
        if (index !== -1) return index
        // A slotted module sits right after its anchor (several modules after one anchor keep key order).
        const after = slotted.get(key)
        if (after && !seen.has(key)) {
            seen.add(key)
            const anchor = rank(after, seen)
            if (anchor !== Number.MAX_SAFE_INTEGER) return anchor + 0.5
        }
        return Number.MAX_SAFE_INTEGER
    }
    return [...byFolder.values()].sort(
        (a, b) => rank(a.key) - rank(b.key) || (rank(a.key) === rank(b.key) ? a.key.localeCompare(b.key) : 0)
    )
}

const gameAdminModule = adminModuleFromFile(Object.values(gameModuleFiles)[0])

const optionalAdminModules = collectOptionalAdminModules([
    workspaceModuleFiles,
    releaseAdminModuleFiles,
    releaseRootModuleFiles,
])

// Education stays first in the registry (it historically led the sidebar and route list ahead of the static modules).
const LEADING_MODULE_KEYS = ['education']
// CmsManagement, Inventory, DigitalLibrary and Widget site-layout are glob-discovered but keep their historical slots right after the game module (in this
// order), so they are picked out by key here and placed explicitly below instead of landing in the trailing optional modules.
const AFTER_GAME_MODULE_KEYS = ['cms-management', 'inventory', 'digital-library', 'site-layout']
const leadingAdminModules = optionalAdminModules.filter((m) => LEADING_MODULE_KEYS.includes(m.key))
const afterGameAdminModules = AFTER_GAME_MODULE_KEYS.map((key) => optionalAdminModules.find((m) => m.key === key)).filter(Boolean)
const trailingAdminModules = optionalAdminModules.filter(
    (m) => !LEADING_MODULE_KEYS.includes(m.key) && !AFTER_GAME_MODULE_KEYS.includes(m.key)
)

export const adminModules = [
    ...leadingAdminModules,
    gameAdminModule,
    ...afterGameAdminModules,
    ...trailingAdminModules,
].filter(Boolean)

/**
 * Modules that place themselves with `slot`: base-admin's route map and sidebar take them from here instead
 * of naming them.
 */
export const adminSlottedModules = adminModules.filter((module) => module.slot)

/** Post types modules add to the post editor's type list: [{ rule, value, labelKey, prefix }]. */
export const adminPostTypes = adminModules.flatMap((module) => module.postTypes || [])

export const adminRouteConfigsByKey = Object.fromEntries(
    adminModules.map((module) => [module.key, module.routeConfig])
)

export const adminNavigationConfigsByKey = Object.fromEntries(
    adminModules.map((module) => [module.key, module.navigationConfig])
)

/**
 * Extra route groups registries own beyond their main `routeConfig` (`additionalRouteConfigs: [routeConfig]`),
 * each tagged with the owning registry key and the catalog module name of its navigation root.
 *
 * @param {object[]} registries Module registries.
 * @returns {Array<{moduleKey: string, module: string, routeConfig: object}>} Flat list of tagged route groups.
 */
export const collectAdditionalRouteConfigs = (registries) =>
    registries.flatMap((m) =>
        (m.additionalRouteConfigs || []).map((routeConfig) => ({
            moduleKey: m.key,
            module: m.navigationConfig?.module || '',
            routeConfig,
        }))
    )

/**
 * Extra menu groups registries own (`additionalNavigationConfigs: [navConfig]`). A group without its own `module`
 * inherits the catalog module of the registry's main navigation root.
 *
 * @param {object[]} registries Module registries.
 * @returns {object[]} Flat list of navigation groups.
 */
export const collectAdditionalNavigationConfigs = (registries) =>
    registries.flatMap((m) =>
        (m.additionalNavigationConfigs || []).map((nav) => {
            const module = nav.module || m.navigationConfig?.module
            return module ? { ...nav, module } : nav
        })
    )

/**
 * Contributions into another module's group (`routeContributions: [{ parent, after?, routes, navigation }]`), tagged
 * with the owning registry key (`moduleKey`) and its catalog module name (`module`). Contributed menu items carry the
 * contributing module, so module gating follows the contributor and not the parent group.
 *
 * @param {object[]} registries Module registries.
 * @returns {object[]} Flat list of tagged contributions.
 */
export const collectRouteContributions = (registries) =>
    registries.flatMap((m) =>
        (m.routeContributions || []).map((c) => {
            const module = m.navigationConfig?.module || ''
            return {
                ...c,
                moduleKey: m.key,
                module,
                routes: c.routes || [],
                navigation: (c.navigation || []).map((item) => (module && !item.module ? { ...item, module } : item)),
            }
        })
    )

/**
 * Insert contributed children into their parent nodes (matched by `name` at any depth), without mutating the input.
 * A contribution lands after its `after` child, or last when `after` is absent or unknown. A contribution whose parent
 * does not exist is ignored (dev-only warning, only when it carries items of this `kind`).
 *
 * @param {object[]} nodes Route configs or navigation items.
 * @param {object[]} contributions Output of collectRouteContributions.
 * @param {'routes'|'navigation'} kind Which contribution list to merge.
 * @param {(message: string) => void} [warn] Warning sink, defaults to a dev-only console.warn.
 * @returns {object[]} New node list with contributions merged.
 */
export const applyContributions = (
    nodes,
    contributions,
    kind,
    warn = (message) => {
        if (import.meta.env.DEV) console.warn(message)
    }
) => {
    const names = new Set()
    const walk = (list) =>
        list.forEach((node) => {
            if (node?.name) names.add(node.name)
            if (Array.isArray(node?.children)) walk(node.children)
        })
    walk(nodes)

    contributions.forEach((c) => {
        // Only contributions that carry items of this kind need a parent here (routes and menu items may target
        // differently named groups, e.g. route group 'post' vs menu group 'post-dashboard').
        if ((c[kind] || []).length > 0 && !names.has(c.parent)) {
            warn(`[admin-modules] "${c.moduleKey}" contributes to missing parent "${c.parent}"; ignored.`)
        }
    })

    const merge = (list) =>
        list.map((node) => {
            if (!node) return node
            const mine = contributions.filter((c) => c.parent === node.name && (c[kind] || []).length > 0)
            if (mine.length === 0 && !Array.isArray(node.children)) return node
            let children = (node.children || []).map((child) => child)
            mine.forEach((c) => {
                const at = c.after ? children.findIndex((child) => child?.name === c.after) : -1
                const items = c[kind]
                children = at === -1 ? [...children, ...items] : [...children.slice(0, at + 1), ...items, ...children.slice(at + 1)]
            })
            const merged = Array.isArray(node.children) || mine.length > 0 ? { ...node, children: merge(children) } : node
            return merged
        })

    return merge(nodes)
}

export const adminAdditionalRouteConfigs = collectAdditionalRouteConfigs(adminModules)
export const adminAdditionalNavigationConfigs = collectAdditionalNavigationConfigs(adminModules)
export const adminRouteContributions = collectRouteContributions(adminModules)

export const adminPostEditorExtensions = adminModules.flatMap(
    (module) => module.postEditorExtensions || []
)

export const adminContentBlockExtensions = adminModules.flatMap(
    (module) => module.contentBlockExtensions || []
)

// Sidebar counters a module offers (see src/components/nav-badge.vue): { name, rule, label, load, intervalSeconds }.
export const adminNavBadges = adminModules.flatMap((module) => module.navBadges || [])

/**
 * Profile tabs modules contribute to shared profile pages (`profileTabs: [{ target, name, text, module, rule, load }]`),
 * each tagged with the owning registry key as `moduleKey`. `load` is a lazy import; the tab receives `{ uuid }` only.
 * Entries without `target`, `name` or `load` are skipped (see src/components/profile/profile-tabs.vue).
 */
export const adminProfileTabs = adminModules.flatMap((m) =>
    (m.profileTabs || []).filter((t) => t && t.target && t.name && t.load).map((t) => ({ ...t, moduleKey: m.key }))
)

/**
 * Notification types modules declare (`notifications: [{ type, text, open(payload) }]`), each tagged with the owning
 * registry key as `moduleKey`. `open` returns a route location from the payload (uuids only); `text` is an optional
 * translation key for the row label (see src/components/notification-bell.vue).
 */
export const adminNotificationTypes = adminModules.flatMap((m) =>
    (m.notifications || []).map((n) => ({ ...n, moduleKey: m.key }))
)

/**
 * Settings-hub entries modules declare via `settings: [{ name, category, text, rule, module }]`,
 * each tagged with the owning registry key as `moduleKey`.
 */
export const adminModuleSettings = adminModules.flatMap((m) => (m.settings || []).map((s) => ({ ...s, moduleKey: m.key })))

/**
 * Background task types modules label via `backgroundTasks: [{ type, text }]`, each tagged with the owning registry key as
 * `moduleKey`. The task record itself comes from the backend; the module only supplies the label.
 */
export const adminBackgroundTaskTypes = [
    ...adminModules.flatMap((m) => (m.backgroundTasks || []).map((task) => ({ ...task, moduleKey: m.key }))),
    // Core shell labels: task types the Core module queues (not a registry module).
    { type: 'core.backup', text: 'text.background_tasks.type.core_backup', moduleKey: 'core' },
    { type: 'core.module_install', text: 'text.background_tasks.type.core_module_install', moduleKey: 'core' },
    { type: 'core.upgrade', text: 'text.background_tasks.type.core_upgrade', moduleKey: 'core' },
    { type: 'core.starter_pack.apply', text: 'text.starter_pack.task', moduleKey: 'core' },
    { type: 'config.package.export', text: 'text.background_tasks.type.config_package_export', moduleKey: 'core' },
    { type: 'config.package.dry_run', text: 'text.background_tasks.type.config_package_dry_run', moduleKey: 'core' },
    { type: 'config.package.apply', text: 'text.background_tasks.type.config_package_apply', moduleKey: 'core' },
]

/**
 * Resource widgets modules declare via `widgets: { '<module>.<widget>': () => import('...vue') }`, merged into one map.
 * Names are expected to carry the module prefix, so keys do not collide.
 */
export const adminResourceWidgets = adminModules.reduce((widgets, m) => Object.assign(widgets, m.widgets || {}), {})

/**
 * Menu groups of resource-only modules (no admin bundle) that the backend registry returned. Reactive so the sidebar
 * re-renders once `applyBackendRegistry` has run after sign-in.
 */
export const registryNavigation = shallowRef([])

/**
 * Turn one registry module into a menu group and a route group. Only entries that name a `resource` become
 * screens; an entry without one has nothing to render and is skipped.
 *
 * @param {{name: string, key: string, label?: string, navigation?: object[]}} registryModule Module from the registry payload.
 * @returns {{nav: object, route: object}|null} Menu group and route record, or null when no entry is usable.
 */
const buildRegistryModule = (registryModule) => {
    const key = String(registryModule.key).toLowerCase()
    const entries = (registryModule.navigation || []).filter((entry) => entry?.name && entry?.resource)
    if (entries.length === 0) return null

    const module = registryModule.name
    const label = registryModule.label || module
    const groupPath = `${basePath}${key}`
    const children = entries.map((entry) => ({
        path: `${groupPath}/${entry.name}`,
        name: entry.name,
        component: resourcePage({ resource: entry.resource, title: entry.text || '' }),
        meta: {
            title: entry.text || entry.name,
            mod: key,
            group: key,
            module,
            ...(entry.rule ? { rule: entry.rule } : {}),
        },
    }))

    return {
        nav: {
            name: key,
            group: key,
            text: label,
            module,
            icon: entries[0].icon || 'puzzle-outline',
            children: entries.map((entry) => ({
                name: entry.name,
                text: [entry.text || entry.name],
                module,
                ...(entry.icon ? { icon: entry.icon } : {}),
                ...(entry.rule ? { rule: entry.rule } : {}),
            })),
        },
        route: {
            path: groupPath,
            name: key,
            redirect: { name: children[0].name },
            meta: { title: label, mod: key, group: key, module },
            children,
        },
    }
}

/**
 * Merge a backend registry payload with the bundle modules. A registry module whose `key` a bundle already owns is
 * ignored (the bundle wins); every other module yields a menu group and a route group. Pure: no router, no network.
 *
 * @param {Array<{key: string}>} bundles Bundle modules (`adminModules`).
 * @param {{modules?: object[]}|null|undefined} payload Response of `GET /api/v2/admin/registry`.
 * @returns {{navigation: object[], routes: object[]}} Menu groups and route records for registry-only modules.
 */
export const mergeBackendRegistry = (bundles, payload) => {
    const bundleKeys = new Set(bundles.map((m) => String(m.key).toLowerCase()))
    const navigation = []
    const routes = []
    const modules = Array.isArray(payload?.modules) ? payload.modules : []

    modules.forEach((registryModule) => {
        if (!registryModule?.key || !registryModule?.name) return
        if (bundleKeys.has(String(registryModule.key).toLowerCase())) return
        const built = buildRegistryModule(registryModule)
        if (!built) return
        navigation.push(built.nav)
        routes.push(built.route)
    })

    return { navigation, routes }
}

/**
 * Fetch the backend registry and add its resource-only modules to the running admin: routes go into the router (once
 * per route name), menu groups into `registryNavigation`. Call after sign-in. Never throws: when the fetch fails the
 * bundle menu stays as it is.
 *
 * @param {{addRoute: Function, hasRoute: Function}} router The app router.
 * @param {{load?: () => Promise<object>, bundles?: object[]}} [options] Registry loader and bundle modules (tests inject both).
 * @returns {Promise<{navigation: object[], routes: object[]}>} What was merged (empty on failure).
 */
export const applyBackendRegistry = async (router, { load, bundles = adminModules } = {}) => {
    try {
        const fetchRegistry = load || (await import('@/libs/registry')).getBackendRegistry
        const merged = mergeBackendRegistry(bundles, await fetchRegistry())
        merged.routes.forEach((record) => {
            if (!router.hasRoute(record.name)) router.addRoute(record)
        })
        registryNavigation.value = merged.navigation
        return merged
    } catch (error) {
        if (import.meta.env.DEV) console.warn('[admin-modules] backend registry unavailable; bundle menu kept.', error)
        return { navigation: [], routes: [] }
    }
}
