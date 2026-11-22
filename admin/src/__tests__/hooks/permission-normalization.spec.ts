import { beforeEach, describe, expect, it } from 'vitest'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { can, resetPermissionIndexCache } from '@/hooks/useCan'
import { rule } from '@/mixins'

const ensureLocalStorage = () => {
    if (window.localStorage) return

    const store = new Map<string, string>()
    const storage = {
        get length() {
            return store.size
        },
        clear: () => store.clear(),
        getItem: (key: string) => store.get(key) ?? null,
        key: (index: number) => Array.from(store.keys())[index] ?? null,
        removeItem: (key: string) => store.delete(key),
        setItem: (key: string, value: string) => store.set(key, String(value)),
    }

    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        value: storage,
    })
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: storage,
    })
}

const seedAccount = (
    permissions: Array<{ id?: number; group: string; name: string }>,
    overrides: Record<string, unknown> = {},
) => {
    ensureLocalStorage()
    window.localStorage.setItem(
        CACHE_KEY_ACCOUNT_DATA,
        JSON.stringify({
            id: 'test-user',
            type: 1,
            roles: [
                {
                    id: 'webmaster',
                    name: 'Webmaster',
                    permissions,
                },
            ],
            ...overrides,
        }),
    )
    resetPermissionIndexCache()
}

describe('permission normalization', () => {
    beforeEach(() => {
        ensureLocalStorage()
        window.localStorage.clear()
        resetPermissionIndexCache()
    })

    it('allows legacy rule checks against padded permission payloads', () => {
        seedAccount([
            { id: 1, group: 'post                                              ', name: 'viewAny                                           ' },
            { id: 2, group: 'menu                                              ', name: 'viewAny                                           ' },
        ])

        expect(rule('post.viewAny')).toBe(true)
        expect(rule(['menu.viewAny', 'user.viewAny'])).toBe(true)
        expect(rule('post.create')).toBe(false)
    })

    it('allows useCan checks against padded legacy and module-prefixed permissions', () => {
        seedAccount([
            { id: 1, group: 'post                                              ', name: 'viewAny                                           ' },
            {
                id: 2,
                group: 'education.edu_subject                                  ',
                name: 'viewAny                                           ',
            },
        ])

        expect(can('post.viewAny')).toBe(true)
        expect(can('education', 'edu_subject', 'viewAny')).toBe(true)
        expect(can('education', 'edu_subject')).toBe(true)
        expect(can('education', 'edu_subject', 'delete')).toBe(false)
    })

    it('allows legacy custom post action keys used by navigation', () => {
        seedAccount([
            { id: 1, group: 'post                                              ', name: 'page                                              ' },
            { id: 2, group: 'post                                              ', name: 'crawled                                           ' },
            { id: 3, group: 'post                                              ', name: 'legacyKey                                         ' },
        ])

        expect(can('post.page')).toBe(true)
        expect(can('post.crawled')).toBe(true)
        expect(can('post.legacyKey')).toBe(true)
        expect(rule('post.page')).toBe(true)
        expect(rule('post.crawled')).toBe(true)
        expect(rule('post.legacyKey')).toBe(true)
    })

    it('allows string-based new-format permission checks used by module navigation', () => {
        seedAccount([
            {
                id: 1,
                group: 'education.edu_semester                                 ',
                name: 'viewAny                                           ',
            },
        ])

        expect(can('education.edu_semester.viewAny')).toBe(true)
        expect(can('education.edu_semester')).toBe(true)
        expect(rule('education.edu_semester.viewAny')).toBe(true)
        expect(rule('education.edu_subject.viewAny')).toBe(false)
    })

    it('allows SampleModule content type manage permissions for post type options', () => {
        seedAccount([
            {
                id: 1,
                group: 'sample-module.qa',
                name: 'manage',
            },
            {
                id: 2,
                group: 'sample-module.prayers',
                name: 'manage',
            },
            {
                id: 3,
                group: 'sample-module.liturgy',
                name: 'manage',
            },
        ])

        expect(rule('sample-module.qa.manage')).toBe(true)
        expect(rule('sample-module.prayers.manage')).toBe(true)
        expect(rule('sample-module.liturgy.manage')).toBe(true)
        expect(rule('sample-module.sources.manage')).toBe(false)
    })

    it('only lets webmaster bypass role and permission management permissions', () => {
        seedAccount([], {
            id: 1,
            roles: [
                {
                    id: 'webmaster',
                    name: 'Webmaster',
                    custom: { level: 0 },
                    permissions: [],
                },
            ],
        })

        expect(can('role.viewAny')).toBe(true)
        expect(can('permission.viewAny')).toBe(true)
        expect(can('gameadmin.authority.view')).toBe(false)
        expect(rule('place.viewAny')).toBe(false)
    })

    it('does not treat admin account type as webmaster bypass', () => {
        seedAccount([], {
            type: 0,
            roles: [],
        })

        expect(can('permission.viewAny')).toBe(false)
        expect(can('place.viewAny')).toBe(false)
    })
})
