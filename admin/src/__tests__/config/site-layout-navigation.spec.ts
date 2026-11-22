import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

// In-memory localStorage-shaped backing store shared by every useStorage()
// call this file exercises: the module-status cache read by
// @/hooks/useActiveModules.js and the account-data cache read by
// @/hooks/useCan.js's usePermissionIndex(). Mirrors the proven mocking
// approach in src/__tests__/hooks/active-modules.spec.ts. Declared via
// vi.hoisted() so these are safely initialized before the hoisted vi.mock
// factories below run (vi.mock is hoisted to the top of the file).
const { storage, getModulesMock } = vi.hoisted(() => ({
    storage: new Map<string, any>(),
    getModulesMock: vi.fn(),
}))

vi.mock('@vueuse/core', async () => {
    const vue = await import('vue')

    return {
        useStorage: (key: string, initialValue: any) => {
            if (!storage.has(key)) {
                storage.set(key, vue.ref(initialValue))
            }

            return storage.get(key)
        },
    }
})

// @/mixins transitively pulls in the full @/hooks barrel (rule() needs
// widgetInputDefault etc.), so this only overrides getModules and keeps
// every other real @/libs export intact for that unrelated import chain.
vi.mock('@/libs', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...actual,
        getModules: getModulesMock,
    }
})

import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { resetPermissionIndexCache } from '@/hooks/useCan'
import { rule } from '@/mixins'
import { siteLayoutAdminModule } from '../../../../cms/Widget/admin/module'

const nav = siteLayoutAdminModule.navigationConfig

const seedAccount = (
    permissions: Array<{ id?: number; group: string; name: string }>,
    overrides: Record<string, unknown> = {}
) => {
    storage.set(
        CACHE_KEY_ACCOUNT_DATA,
        ref({
            id: 'test-user',
            type: 1,
            roles: [{ id: 'role', name: 'Role', permissions }],
            ...overrides,
        })
    )
    resetPermissionIndexCache()
}

// Mirrors App.vue's filterNavByActiveModules(). Kept local to this test (App.vue does not export that helper) so the
// module-disabled behavior is proven against the REAL exported isModuleActive() primitive and the REAL
// navigationConfig, not a reimplementation of the gating rule itself.
const filterByActiveModule = (items: any[], isActive: (mod?: string) => boolean): any[] =>
    items.filter((item) => isActive(item.module))

describe('site layout navigation module gating', () => {
    beforeEach(() => {
        storage.clear()
        getModulesMock.mockReset()
    })

    it('is ONE entry ("Khu vực chung"), tagged module: Widget, without children', () => {
        expect(nav.module).toBe('Widget')
        expect(nav.name).toBe('site-layout')
        expect(nav.text).toBe('nav.a.site-layout')
        expect(nav.children).toBeUndefined()
        // Shown only when the active theme has a shared area (area-count.js; covered in widget/area-editor.spec.ts).
        expect(typeof nav.hidden).toBe('function')
    })

    it('hides the entry when the Widget module is disabled', async () => {
        getModulesMock.mockResolvedValue([{ name: 'Widget', alias: 'widget', enabled: false }])
        const { fetchAndCacheActiveModules, isModuleActive } = await import('@/hooks/useActiveModules')
        await fetchAndCacheActiveModules()

        expect(filterByActiveModule([nav], isModuleActive)).toHaveLength(0)
    })

    it('keeps the entry when the Widget module is enabled', async () => {
        getModulesMock.mockResolvedValue([{ name: 'Widget', alias: 'widget', enabled: true }])
        const { fetchAndCacheActiveModules, isModuleActive } = await import('@/hooks/useActiveModules')
        await fetchAndCacheActiveModules()

        expect(filterByActiveModule([nav], isModuleActive)).toHaveLength(1)
    })
})

describe('site layout navigation permission gating', () => {
    beforeEach(() => {
        storage.clear()
        resetPermissionIndexCache()
    })

    it('denies a user with no widget layout permission', () => {
        seedAccount([{ id: 1, group: 'post', name: 'viewAny' }])

        expect(rule(nav.rule)).toBe(false)
    })

    it('a Widget-instance-only role no longer sees the entry (the instance list is gone)', () => {
        seedAccount([{ id: 1, group: 'widget_instance', name: 'viewAny' }])

        expect(rule(nav.rule)).toBe(false)
    })

    it('lets an editor (update/preview) in without granting publish/rollback', () => {
        seedAccount([
            { id: 1, group: 'widget_layout', name: 'update' },
            { id: 2, group: 'widget_layout', name: 'preview' },
        ])

        expect(rule(nav.rule)).toBe(true)
        // The core "editor cannot inherit publish" regression: an editor's update/preview grant must never
        // evaluate true for the distinct publish/rollback actions.
        expect(rule('widget_layout.publish')).toBe(false)
        expect(rule('widget_layout.rollback')).toBe(false)
    })

    it('lets a publisher (publish/rollback) in without gaining editor access', () => {
        seedAccount([
            { id: 1, group: 'widget_layout', name: 'publish' },
            { id: 2, group: 'widget_layout', name: 'rollback' },
        ])

        expect(rule(nav.rule)).toBe(true)
        expect(rule('widget_layout.update')).toBe(false)
    })

    it('lets a viewer (viewAny) in: the editor opens read-only for them', () => {
        seedAccount([{ id: 1, group: 'widget_layout', name: 'viewAny' }])

        expect(rule(nav.rule)).toBe(true)
        expect(rule('widget_layout.update')).toBe(false)
    })
})
