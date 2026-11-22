import { describe, expect, it, vi } from 'vitest'

vi.mock('@/mixins', () => ({
    perm: (moduleName: string) => moduleName,
}))

// A site build that ships none of the optional module bundles (no Content, Product, Real Estate, ...).
vi.mock('@/config/admin-modules', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/config/admin-modules')>()
    return {
        ...actual,
        adminModules: [],
        adminNavigationConfigsByKey: {},
        adminAdditionalNavigationConfigs: [],
        adminRouteContributions: [],
        adminModuleSettings: [],
    }
})

describe('navigation without optional module bundles', () => {
    it('builds the sidebar with no undefined entries', async () => {
        const { default: mainNav } = await import('../../config/navigation')

        expect(mainNav.length).toBeGreaterThan(0)
        expect(mainNav.every((item) => item && typeof item === 'object')).toBe(true)
    })
})
