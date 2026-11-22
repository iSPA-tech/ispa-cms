import { mount, type MountingOptions, type VueWrapper, config } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type RouteRecordRaw, type Router } from 'vue-router'
import { vi } from 'vitest'
import type { Component } from 'vue'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { resetPermissionIndexCache } from '@/hooks/useCan'

type RenderRouteOption = {
    path?: string
    name?: string
    params?: Record<string, any>
    query?: Record<string, any>
}

export type RenderWithAppOptions = Omit<MountingOptions<any>, 'global'> & {
    /** Optional initial route to push before mounting. */
    route?: RenderRouteOption
    /** Extra route records to register for the test router. */
    routes?: RouteRecordRaw[]
    /** Additional global stubs merged after default Ant stubs. */
    globalStubs?: Record<string, any>
    /** Additional global mocks merged after default i18n/date/number mocks. */
    globalMocks?: Record<string, any>
    /** Toggle Ant Design Vue lightweight stubs (enabled by default). */
    withAntd?: boolean
    /** Provide/dirs/config merged into the test app global config. */
    global?: Omit<NonNullable<MountingOptions<any>['global']>, 'plugins' | 'stubs' | 'mocks'>
    /** Seeded permissions for the test account (stored in CACHE_KEY_ACCOUNT_DATA). */
    permissions?:
        | string[]
        | {
              resource?: string
              codes?: string[]
              canViewAny?: boolean
              canView?: boolean
              canCreate?: boolean
              canEdit?: boolean
              canUpdate?: boolean
              canDelete?: boolean
              canImport?: boolean
          }
}

const PlaceholderView = { template: '<div />' }

const baseRoutes: RouteRecordRaw[] = [
    { path: '/', name: 'dashboard', component: PlaceholderView },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: PlaceholderView },
]

const defaultMocks = {
    $t: (key: string | string[], params?: Record<string, any>) => {
        const keyStr = Array.isArray(key) ? key.join('.') : String(key)
        if (keyStr.startsWith('nav.')) return keyStr
        if (keyStr.startsWith('text.')) return keyStr
        if (keyStr.startsWith('validation.')) return keyStr
        if (keyStr.startsWith('n.')) return keyStr.replace('n.', 'nav.')
        if (keyStr.startsWith('t.')) return keyStr.replace('t.', 'text.')
        if (keyStr.startsWith('v.')) return keyStr.replace('v.', 'validation.')
        if (keyStr.startsWith('a.')) return keyStr.replace('a.', 'validation.attributes.')
        if (!params) return keyStr
        return keyStr.replace(/\{(\w+)\}/g, (match, paramKey) => {
            return Object.prototype.hasOwnProperty.call(params, paramKey) ? String(params[paramKey]) : match
        })
    },
    $tc: (key: string | string[], params?: Record<string, any>) => {
        const keyStr = Array.isArray(key) ? key.join('.') : String(key)
        if (keyStr.startsWith('nav.')) return keyStr
        if (keyStr.startsWith('text.')) return keyStr
        if (keyStr.startsWith('validation.')) return keyStr
        if (keyStr.startsWith('n.')) return keyStr.replace('n.', 'nav.')
        if (keyStr.startsWith('t.')) return keyStr.replace('t.', 'text.')
        if (keyStr.startsWith('v.')) return keyStr.replace('v.', 'validation.')
        if (keyStr.startsWith('a.')) return keyStr.replace('a.', 'validation.attributes.')
        if (!params) return keyStr
        return keyStr.replace(/\{(\w+)\}/g, (match, paramKey) => {
            return Object.prototype.hasOwnProperty.call(params, paramKey) ? String(params[paramKey]) : match
        })
    },
    $d: (value: any) => String(value),
    $n: (value: any) => String(value),
}

// Mock useI18n for Composition API
export const useI18nMock = () => ({
    t: defaultMocks.$t,
    tc: defaultMocks.$tc,
    d: defaultMocks.$d,
    n: defaultMocks.$n,
})

vi.mock('vue-i18n', async (importOriginal) => {
    const actual = await importOriginal<any>()
    return {
        ...actual,
        useI18n: useI18nMock,
    }
})

config.global.mocks = {
    ...config.global.mocks,
    ...defaultMocks,
}

const antStubs = () => ({
    'a-config-provider': { template: '<div><slot /></div>' },
    'a-form': { template: '<form><slot /></form>' },
    'a-form-item': { template: '<div><slot /><slot name="help" /></div>' },
    'a-input': { template: '<input v-bind="$attrs" />' },
    'a-input-password': { template: '<input type="password" v-bind="$attrs" />' },
    'a-input-number': { template: '<input type="number" v-bind="$attrs" />' },
    'a-textarea': { template: '<textarea v-bind="$attrs"><slot /></textarea>' },
    'a-select': { template: '<select v-bind="$attrs"><slot /></select>' },
    'a-select-option': { template: '<option><slot /></option>' },
    'a-switch': { template: '<input type="checkbox" v-bind="$attrs" />' },
    'a-table': { template: '<table><slot /><slot name="bodyCell" /><slot name="headerCell" /></table>' },
    'a-table-column': { template: '<col><slot /></col>' },
    'a-button': { template: '<button type="button"><slot /></button>' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-modal': { template: '<div><slot /><slot name="footer" /></div>' },
    'a-tooltip': { template: '<span><slot /><slot name="title" /></span>' },
    'a-popconfirm': { template: '<span><slot /><slot name="title" /></span>' },
    'a-dropdown': { template: '<div><slot /><slot name="overlay" /></div>' },
    'a-menu': { template: '<ul><slot /></ul>' },
    'a-menu-item': { template: '<li><slot /></li>' },
    'a-pagination': { template: '<div><slot /></div>' },
    'a-spin': { template: '<div><slot /></div>' },
    'a-tabs': { template: '<div><slot /><slot name="tabBarExtraContent" /></div>' },
    'a-tab-pane': { template: '<div><slot /></div>' },
    'a-row': { template: '<div><slot /></div>' },
    'a-col': { template: '<div><slot /></div>' },
    'a-space': { template: '<div><slot /></div>' },
    'a-upload': { template: '<div><slot /></div>' },
    'a-radio-group': { template: '<div><slot /></div>' },
    'a-radio': { template: '<label><input type="radio" /><slot /></label>' },
    'a-checkbox': { template: '<label><input type="checkbox" /><slot /></label>' },
    'a-checkbox-group': { template: '<div><slot /></div>' },
    'a-date-picker': { template: '<input type="date" v-bind="$attrs" />' },
    'a-range-picker': { template: '<input type="text" v-bind="$attrs" />' },
    'a-badge': { template: '<span><slot /></span>' },
    'a-alert': { template: '<div><slot /></div>' },
})

const permissionFlagMap: Record<
    'canViewAny' | 'canView' | 'canCreate' | 'canEdit' | 'canUpdate' | 'canDelete' | 'canImport',
    string
> = {
    canViewAny: 'viewAny',
    canView: 'view',
    canCreate: 'create',
    canEdit: 'edit',
    canUpdate: 'update',
    canDelete: 'delete',
    canImport: 'import',
}

const normalizePermissionCodes = (seed: RenderWithAppOptions['permissions']): string[] => {
    if (!seed) return []
    if (Array.isArray(seed)) return seed

    const { resource = 'education.edu_subject', codes = [], ...flags } = seed
    const permissionCodes = new Set<string>(codes)

    Object.entries(permissionFlagMap).forEach(([flag, action]) => {
        if (flags[flag as keyof typeof flags]) {
            permissionCodes.add(`${resource}.${action}`)
        }
    })

    return Array.from(permissionCodes)
}

const buildAccountFromCodes = (codes: string[]) => ({
    id: 'test-account',
    roles: [
        {
            id: 'test-role',
            name: 'Test Role',
            permissions: codes.map((code, idx) => {
                const parts = String(code).split('.')
                const action = parts.pop() || ''
                return { id: idx + 1, group: parts.join('.'), name: action }
            }),
        },
    ],
})

const seedPermissionState = (seed: RenderWithAppOptions['permissions']) => {
    if (typeof window === 'undefined' || !window.localStorage) return

    const codes = normalizePermissionCodes(seed)

    if (!codes.length) {
        window.localStorage.removeItem(CACHE_KEY_ACCOUNT_DATA)
        resetPermissionIndexCache()
        return
    }

    const account = buildAccountFromCodes(codes)
    window.localStorage.setItem(CACHE_KEY_ACCOUNT_DATA, JSON.stringify(account))
    resetPermissionIndexCache()
}

const buildRoutes = (route: RenderRouteOption | undefined, extraRoutes: RouteRecordRaw[]) => {
    const routes = [...baseRoutes, ...extraRoutes]

    if (route?.name && !routes.some((item) => item.name === route.name)) {
        routes.push({
            path: route.path || `/${String(route.name)}`,
            name: route.name,
            component: PlaceholderView,
        })
    } else if (route?.path && !routes.some((item) => item.path === route.path)) {
        routes.push({
            path: route.path,
            name: route?.name,
            component: PlaceholderView,
        })
    }

    return routes
}

const resolveRouteTarget = (route?: RenderRouteOption) => {
    if (!route) return { path: '/' }
    if (route.name) {
        return { name: route.name, params: route.params, query: route.query }
    }
    return { path: route.path || '/', query: route.query }
}

export async function renderWithApp(
    component: Component,
    options: RenderWithAppOptions = {},
): Promise<{ wrapper: VueWrapper<any>; router: Router }> {
    const {
        route,
        routes = [],
        globalStubs,
        globalMocks,
        withAntd = true,
        global: userGlobal = {},
        permissions,
        ...mountOptions
    } = options

    seedPermissionState(permissions)

    const router = createRouter({
        history: createMemoryHistory(),
        routes: buildRoutes(route, routes),
    })

    await router.push(resolveRouteTarget(route))

    const wrapper = mount(component as any, {
        ...mountOptions,
        global: {
            ...userGlobal,
            plugins: [router, ...(userGlobal.plugins || [])],
            stubs: {
                ...(withAntd ? antStubs() : {}),
                ...(globalStubs || {}),
            },
            mocks: {
                ...defaultMocks,
                ...(globalMocks || {}),
            },
        },
    })

    await router.isReady()

    return { wrapper, router }
}
