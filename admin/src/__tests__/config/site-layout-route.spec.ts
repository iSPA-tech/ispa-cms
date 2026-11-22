import { beforeEach, describe, expect, it, vi } from 'vitest'

const createRouterMock = vi.fn((options) => ({
    options,
}))

vi.mock('vue-router', () => ({
    createRouter: createRouterMock,
    createWebHistory: vi.fn(() => ({})),
}))

vi.mock('@/config', () => ({
    basePath: '/iPanel/',
}))

vi.mock('@/mixins', () => ({
    perm: (moduleName: string) => moduleName,
}))

const findRouteByName = (routes: any[], name: string): any => {
    for (const route of routes) {
        if (route?.name === name) {
            return route
        }

        if (Array.isArray(route?.children)) {
            const childRoute = findRouteByName(route.children, name)
            if (childRoute) {
                return childRoute
            }
        }
    }

    return undefined
}

const findNavItem = (items: any[], name: string) => items.find((item) => item.name === name)
const findChildItem = (items: any[], parentName: string, childName: string) =>
    findNavItem(items, parentName)?.children?.find((item: any) => item.name === childName)

describe('site layout admin registration', () => {
    beforeEach(() => {
        vi.resetModules()
        createRouterMock.mockClear()
    })

    it('registers the site layout route tree with a redirect to the area editor', async () => {
        const routeModule = await import('../../config/route')
        const routes = routeModule.router.options.routes

        const parent = findRouteByName(routes, 'site-layout')
        expect(parent?.path).toBe('/iPanel/site-layout')
        expect(parent?.redirect).toEqual({ name: 'site-layout-placements' })
        expect(parent?.meta.title).toBe('nav.a.site-layout')

        expect(findRouteByName(routes, 'site-layout-placements')?.meta.title).toBe('nav.a.site-layout-placements')
        // The Slots page was dissolved: no named slots route remains (old URL redirects).
        expect(findRouteByName(routes, 'site-layout-slots')).toBeUndefined()
        // The widget list and the "allowed designs" console moved to the Core widget library: both names stay as redirects.
        expect(findRouteByName(routes, 'site-layout-widgets')?.redirect).toEqual({ name: 'widget-library' })
        expect(findRouteByName(routes, 'site-layout-templates')?.redirect).toEqual({ name: 'widget-library' })
    })

    it('gives the area editor route its own explicit permission rule', async () => {
        const routeModule = await import('../../config/route')
        const routes = routeModule.router.options.routes

        // Anyone who can view, compose, preview, publish or restore the shared areas (a viewer opens it read-only).
        expect(findRouteByName(routes, 'site-layout-placements')?.meta.rule).toEqual([
            'widget_layout.viewAny',
            'widget_layout.update',
            'widget_layout.preview',
            'widget_layout.publish',
            'widget_layout.rollback',
        ])
        // The redirects carry the library's own rule, not the area editor's.
        expect(findRouteByName(routes, 'site-layout-widgets')?.meta.rule).toBe('widget.viewAny')
        expect(findRouteByName(routes, 'site-layout-templates')?.meta.rule).toBe('widget.viewAny')
    })

    it('registers ONE site layout navigation entry, tagged module: Widget, hidden while the theme has no shared area', async () => {
        const mainNav = (await import('../../config/navigation')).default

        const entry = findNavItem(mainNav, 'site-layout')
        expect(entry).toMatchObject({
            name: 'site-layout',
            group: 'site-layout',
            text: 'nav.a.site-layout',
            module: 'Widget',
        })
        expect(entry.children).toBeUndefined()
        expect(typeof entry.hidden).toBe('function')
        expect(findChildItem(mainNav, 'site-layout', 'site-layout-placements')).toBeUndefined()
    })
})
