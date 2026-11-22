import { beforeEach, describe, expect, it, vi } from 'vitest'
import { applyBackendRegistry, mergeBackendRegistry, registryNavigation } from '@/config/admin-modules'
import { isRouteModuleBlocked } from '@/utils/moduleRouteGate'

const getBackendRegistry = vi.hoisted(() => vi.fn())
vi.mock('@/libs/registry', () => ({ getBackendRegistry }))

const payload = {
    schema_version: 1,
    modules: [
        {
            name: 'Helpdesk',
            key: 'helpdesk',
            navigation: [{ name: 'helpdesk-organizations', text: 'nav.a.helpdesk-organizations', icon: 'domain', rule: 'helpdesk.agent', resource: 'helpdesk/organizations' }],
        },
        {
            name: 'Fresh',
            key: 'fresh',
            navigation: [
                { name: 'fresh-things', text: 'nav.a.fresh-things', icon: 'star', rule: 'fresh.view', resource: 'fresh/things' },
                { name: 'fresh-no-resource', text: 'x' },
            ],
        },
    ],
}

const fakeRouter = () => {
    const names = new Set<string>()
    return {
        names,
        added: [] as any[],
        hasRoute: (n: string) => names.has(n),
        addRoute(record: any) {
            names.add(record.name)
            this.added.push(record)
        },
    }
}

describe('backend registry merge', () => {
    beforeEach(() => {
        getBackendRegistry.mockReset()
        registryNavigation.value = []
    })

    it('ignores a registry module whose key a bundle owns and adds the others', () => {
        const merged = mergeBackendRegistry([{ key: 'helpdesk' }], payload)

        expect(merged.navigation.map((n) => n.name)).toEqual(['fresh'])
        expect(merged.routes.map((r) => r.name)).toEqual(['fresh'])
        expect(merged.navigation[0].children.map((c: any) => c.name)).toEqual(['fresh-things'])
        expect(merged.navigation[0].children[0]).toMatchObject({ rule: 'fresh.view', module: 'Fresh', icon: 'star' })
        expect(merged.routes[0].children[0].path.endsWith('/fresh/fresh-things')).toBe(true)
    })

    it('uses the registry label as the group text and falls back to the module name', () => {
        const nav = [{ name: 'a-x', text: 'x', resource: 'a/x' }]
        const merged = mergeBackendRegistry([], {
            modules: [
                { name: 'Labeled', key: 'labeled', label: 'nav.a.labeled', navigation: nav },
                { name: 'Plain', key: 'plain', navigation: nav },
            ],
        })

        expect(merged.navigation.map((n) => n.text)).toEqual(['nav.a.labeled', 'Plain'])
        expect(merged.routes.map((r) => r.meta.title)).toEqual(['nav.a.labeled', 'Plain'])
    })

    it('adds each route only once', async () => {
        getBackendRegistry.mockResolvedValue(payload)
        const router = fakeRouter()

        await applyBackendRegistry(router, { bundles: [{ key: 'helpdesk' }] as any })
        await applyBackendRegistry(router, { bundles: [{ key: 'helpdesk' }] as any })

        expect(router.added).toHaveLength(1)
        expect(registryNavigation.value).toHaveLength(1)
    })

    it('tags routes with the catalog module so the module gate applies', async () => {
        getBackendRegistry.mockResolvedValue(payload)
        const router = fakeRouter()
        await applyBackendRegistry(router, { bundles: [] })

        const group = router.added.find((r) => r.name === 'fresh')
        expect(group.meta.module).toBe('Fresh')
        expect(group.children[0].meta.module).toBe('Fresh')
        expect(isRouteModuleBlocked(group.children[0].meta.module, true, () => false)).toBe(true)
        expect(isRouteModuleBlocked(group.children[0].meta.module, true, () => true)).toBe(false)
    })

    it('keeps the bundle menu and throws nothing when the fetch fails', async () => {
        getBackendRegistry.mockRejectedValue(new Error('network'))
        const router = fakeRouter()

        await expect(applyBackendRegistry(router, { bundles: [] })).resolves.toEqual({ navigation: [], routes: [] })
        expect(router.added).toHaveLength(0)
        expect(registryNavigation.value).toEqual([])
    })
})
