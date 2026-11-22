import { describe, expect, it, vi } from 'vitest'

vi.mock('vue-router', () => ({
    createRouter: vi.fn((options) => ({ beforeEach: vi.fn(), options })),
    createWebHistory: vi.fn(() => ({})),
}))

vi.mock('@/config', () => ({
    basePath: '/iPanel/',
}))

/**
 * Collect sibling routes that share a path. Two siblings with the same path make the later one unreachable
 * (the contact list was hidden behind the contact dashboard this way until 2026-11-03).
 */
const duplicateSiblingPaths = (routes, parent = '') =>
    routes.flatMap((route) => {
        const siblings = Array.isArray(route?.children) ? route.children : []
        const seen = new Map()
        const dupes = []
        for (const child of siblings) {
            if (!child?.name || typeof child.path !== 'string' || child.redirect) continue
            if (seen.has(child.path)) dupes.push(`${parent}${route.path}/${child.path}: ${seen.get(child.path)} + ${child.name}`)
            else seen.set(child.path, child.name)
        }
        return [...dupes, ...duplicateSiblingPaths(siblings, `${parent}${route.path}/`)]
    })

describe('admin route paths', () => {
    it('gives the contact dashboard its own path so the contact list stays reachable', async () => {
        const { router } = await import('../../config/route')
        const contact = router.options.routes.find((route) => route.name === 'contact')
        const byName = Object.fromEntries((contact?.children || []).map((child) => [child.name, child.path]))

        expect(byName['contact-dashboard']).toBe('/iPanel/contact/dashboard')
        expect(byName['contact-manage']).toBe('/iPanel/contact/manage')
    })

    it('has no named sibling routes sharing a path', async () => {
        const { router } = await import('../../config/route')

        expect(duplicateSiblingPaths(router.options.routes)).toEqual([])
    })
})
