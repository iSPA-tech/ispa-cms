import { describe, expect, it } from 'vitest'

const findByName = (routes, name) => {
    for (const route of routes) {
        if (route?.name === name) return route
        if (Array.isArray(route?.children)) {
            const child = findByName(route.children, name)
            if (child) return child
        }
    }

    return undefined
}

describe('Content routes', () => {
    it('keeps the posts list on the old posts page and the other post type lists too', async () => {
        const routeConfig = await import('../../../../cms/Content/admin/route-config.js')
        const pageLoaders = await import('../../../../cms/Content/admin/page-loaders.js')

        expect(routeConfig).not.toHaveProperty('CONTENT_RESOURCE_PAGE')
        for (const name of ['post-post', 'post-audio', 'post-video', 'post-document']) {
            expect(findByName(routeConfig.contentRouteConfig.children, name).load).toBe(pageLoaders.PostManage)
        }
    })

    it('serves the Content records on their own route through the resource renderer', async () => {
        const routeConfig = await import('../../../../cms/Content/admin/route-config.js')

        const route = findByName(routeConfig.contentRouteConfig.children, 'post-content')
        expect(route.path).toBe('content')
        const component = await route.load()
        expect(component.name).toBe('ResourcePageRoute')
        expect(component.render().props).toMatchObject({ resource: 'content/contents', title: 'nav.a.contents' })
    })

    it('lists the Content records in the Posts menu behind the Content permission', async () => {
        const nav = await import('../../../../cms/Content/admin/navigation-config.js')

        const entry = nav.contentNavigationConfig.children.find((child) => child.name === 'post-content')
        expect(entry.rule).toBe('content.viewAny')
    })
})
