/**
 * Users admin route rules (platform).
 *
 * The Users detail/create/update pages carry their own `meta.rule`, so the real router guard (`route.js`
 * `router.beforeEach`, backed by `rule()` from `@/mixins`) keeps an account without the matching permission out of
 * them even when it types the URL by hand.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

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

vi.mock('@/libs', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return { ...actual, getModules: getModulesMock }
})

import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { resetPermissionIndexCache } from '@/hooks/useCan'
import { router } from '../../config/route'

const USER_PAGES = [
    { name: 'user-manage', path: '/iPanel/user/manage', rule: 'user.viewAny' },
    { name: 'user-detail', path: '/iPanel/user/detail/abc', rule: 'user.view' },
    { name: 'user-create', path: '/iPanel/user/create', rule: 'user.create' },
    { name: 'user-update', path: '/iPanel/user/update/abc', rule: 'user.update' },
]

/**
 * Cache a non-Webmaster account that holds exactly the given `user` permission names.
 *
 * @param names - Permission names inside the `user` group (e.g. `viewAny`).
 */
const seedAccount = (names: string[]) => {
    resetPermissionIndexCache()
    storage.set(
        CACHE_KEY_ACCOUNT_DATA,
        ref({
            id: 'test-user',
            type: 1,
            roles: [
                {
                    id: 'role',
                    name: 'Role',
                    permissions: names.map((name) => ({ group: 'user', name })),
                },
            ],
        })
    )
}

/**
 * Navigate through the real guard and report whether the Users page was reached.
 *
 * @param page - Page definition from USER_PAGES.
 * @returns True when the router ended on the requested route.
 */
const canOpen = async (page: { name: string; path: string }): Promise<boolean> => {
    // Park on a neutral route so every push is a real navigation.
    await router.push({ name: 'dashboard' }).catch(() => undefined)
    await router.push(page.path).catch(() => undefined)
    return router.currentRoute.value.name === page.name
}

describe('Users route rules', () => {
    beforeEach(() => {
        getModulesMock.mockReset()
        seedAccount([])
    })

    it('gives every Users page its own meta.rule', () => {
        for (const page of USER_PAGES) {
            const record = router.getRoutes().find((r) => r.name === page.name)
            expect(record?.meta?.rule, page.name).toBe(page.rule)
        }
    })

    it('keeps an account without user permissions out of every Users page', async () => {
        for (const page of USER_PAGES) {
            expect(await canOpen(page), page.name).toBe(false)
        }
    })

    it('lets viewAny + view open manage and detail but not create or update', async () => {
        seedAccount(['viewAny', 'view'])

        expect(await canOpen(USER_PAGES[0])).toBe(true)
        expect(await canOpen(USER_PAGES[1])).toBe(true)
        expect(await canOpen(USER_PAGES[2])).toBe(false)
        expect(await canOpen(USER_PAGES[3])).toBe(false)
    })
})
