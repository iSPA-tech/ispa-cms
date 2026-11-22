import { beforeEach, describe, expect, it, vi } from 'vitest'

const storage = new Map<string, any>()
const getModulesMock = vi.fn()

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

vi.mock('@/libs', () => ({
    getModules: getModulesMock,
}))

describe('active module gating', () => {
    beforeEach(() => {
        storage.clear()
        getModulesMock.mockReset()
    })

    it('fails open until the backend module catalog has loaded', async () => {
        const { isModuleActive } = await import('@/hooks/useActiveModules')

        expect(isModuleActive('AffiliateRecommendation')).toBe(true)
    })

    it('hides module names missing from the loaded backend catalog', async () => {
        getModulesMock.mockResolvedValue([
            { name: 'Content', alias: 'content', enabled: true },
            { name: 'GeoPlaces', alias: 'geoplaces', enabled: false },
        ])

        const { fetchAndCacheActiveModules, isModuleActive } = await import('@/hooks/useActiveModules')

        await fetchAndCacheActiveModules()

        expect(isModuleActive('Content')).toBe(true)
        expect(isModuleActive('GeoPlaces')).toBe(false)
        expect(isModuleActive('AffiliateRecommendation')).toBe(false)
    })
})
