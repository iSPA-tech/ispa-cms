import { describe, expect, it, vi } from 'vitest'

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

describe('calendar route and navigation', () => {
    it('keeps the Calendar admin route visible in main navigation', async () => {
        const mainNav = (await import('../../config/navigation')).default
        const routeModule = await import('../../config/route')

        expect(mainNav.find((item) => item.name === 'calendar')).toMatchObject({
            name: 'calendar',
            group: 'calendar',
            text: 'nav.a.calendars',
            rule: 'calendar.viewAny',
            icon: 'calendar-multiple',
        })
        expect(findRouteByName(routeModule.router.options.routes, 'calendar')).toMatchObject({
            path: '/iPanel/calendar',
            name: 'calendar',
            meta: {
                rule: 'calendar.viewAny',
            },
        })
    })
})
