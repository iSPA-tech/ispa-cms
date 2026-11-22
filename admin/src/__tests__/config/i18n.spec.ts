import { beforeEach, describe, expect, it, vi } from 'vitest'
import { I18N_MESSAGES_SCHEMA_VERSION } from '../../config/i18n-message-utils'

// Mutable so a spec can simulate a runtime admin rebuild (same appVersion, new build id).
const buildState = vi.hoisted(() => ({ buildVersion: 'testbuild' }))

// Bundled gate wording (built from the Core sidecar in the real app); a small fixed tree here so the other
// assertions in this file see only what the pack serves.
const fallbackState = vi.hoisted(() => ({
    messages: { en: {}, vi: {} } as Record<string, Record<string, unknown>>,
}))

vi.mock('virtual:admin-i18n-fallback', () => ({
    get default() {
        return fallbackState.messages
    },
}))

const ajaxGetMock = vi.fn()
const setLocaleMessageMock = vi.fn()
const storageState = { value: {} as Record<string, unknown> }
// What the browser already holds when the module loads (null: nothing cached yet).
const storagePreset = vi.hoisted(() => ({ value: null as null | Record<string, unknown> }))

vi.mock('../../ajax', () => ({
    default: {
        get: ajaxGetMock,
    },
}))

vi.mock('vue-i18n', () => ({
    createI18n: vi.fn((options: { locale: string }) => ({
        mode: 'composition',
        global: {
            setLocaleMessage: setLocaleMessageMock,
            locale: {
                value: options.locale,
            },
        },
    })),
}))

vi.mock('@vueuse/core', () => ({
    useStorage: vi.fn((_key: string, initialValue: Record<string, unknown>) => {
        storageState.value = storagePreset.value ?? initialValue
        return storageState
    }),
}))

vi.mock('@/config/cookies', () => ({
    cookie: {
        get: vi.fn(() => 'en'),
        set: vi.fn(),
    },
}))

vi.mock('@/config/constants', () => ({
    CACHE_KEY_MESSAGES_LOCALE: 'messages',
    COOKIE_KEY_LOCALE: 'locale',
}))

vi.mock('../../config/base', () => ({
    appVersion: 'testver',
    get buildVersion() {
        return buildState.buildVersion
    },
    apiUrl: 'https://admin.example/',
    defaultLocale: 'en',
    cookieDomain: 'localhost',
    domain: 'localhost',
    isLocal: true,
}))

/**
 * Locale files carry a `?r=<schema version>` cache buster, so assertions
 * compare the path and check the buster separately — mocks that matched on
 * endsWith('.json') silently fell through to the "unexpected URL" throw,
 * which the loader swallowed into its legacy fallback list.
 */
const stripCacheBuster = (url: string) => url.split('?')[0]

describe('i18n runtime', () => {
    beforeEach(() => {
        vi.resetModules()
        ajaxGetMock.mockReset()
        setLocaleMessageMock.mockReset()
        storageState.value = {}
        storagePreset.value = null
        buildState.buildVersion = 'testbuild'
        fallbackState.messages = { en: {}, vi: {} }
    })

    describe('bundled fallback messages', () => {
        const runtime = () => ({
            mode: 'composition',
            global: { setLocaleMessage: setLocaleMessageMock, locale: { value: 'vi' } },
        })

        it('still serves the bundled wording when every pack request fails', async () => {
            fallbackState.messages = {
                en: { text: { site_connect: { title: 'Connect to iSPA Hub' } } },
                vi: { text: { site_connect: { title: 'Kết nối iSPA Hub' } } },
            }
            ajaxGetMock.mockRejectedValue(new Error('Request failed with status code 404'))
            const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
            const { loadLocaleMessages } = await import('../../config/i18n')
            setLocaleMessageMock.mockClear()

            await loadLocaleMessages(runtime(), 'vi')

            expect(setLocaleMessageMock).toHaveBeenCalledWith('vi', {
                text: { site_connect: { title: 'Kết nối iSPA Hub' } },
            })
            errorSpy.mockRestore()
            warnSpy.mockRestore()
        })

        it('asks for the pack quietly, so a failing pack raises no error toast over the gate screen', async () => {
            ajaxGetMock.mockRejectedValue(new Error('Request failed with status code 404'))
            const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
            const { loadLocaleMessages } = await import('../../config/i18n')
            ajaxGetMock.mockClear()

            await loadLocaleMessages(runtime(), 'vi')

            expect(ajaxGetMock).toHaveBeenCalled()
            for (const call of ajaxGetMock.mock.calls) {
                expect(call[1]).toMatchObject({ silent: true })
            }
            errorSpy.mockRestore()
            warnSpy.mockRestore()
        })

        it('lets the loaded pack override the bundled wording', async () => {
            fallbackState.messages = {
                en: {},
                vi: { text: { site_connect: { title: 'Bản dự phòng', intro: 'Giới thiệu' } } },
            }
            ajaxGetMock.mockImplementation((url: string) =>
                Promise.resolve({
                    data: url.includes('/manifest.json') ? ['text'] : { text: { site_connect: { title: 'Bản từ gói' } } },
                })
            )
            const { loadLocaleMessages } = await import('../../config/i18n')
            setLocaleMessageMock.mockClear()

            await loadLocaleMessages(runtime(), 'vi')

            expect(setLocaleMessageMock).toHaveBeenCalledWith('vi', {
                text: { site_connect: { title: 'Bản từ gói', intro: 'Giới thiệu' } },
            })
            // The browser cache keeps only what the server sent, never the bundled wording.
            expect((storageState.value as any).vi).toEqual({ text: { site_connect: { title: 'Bản từ gói' } } })
        })
    })

    it('refreshes menu labels instead of reusing a pre-menu cached locale tree', async () => {
        ajaxGetMock.mockImplementation((url: string) =>
            Promise.resolve({
                data: url.includes('/manifest.json')
                    ? ['text']
                    : { 'menu-expand-all-child': 'Hiển thị tất cả danh mục con?' },
            })
        )
        const { loadLocaleMessages } = await import('../../config/i18n')
        storageState.value = {
            __schema: 'canonical-group-v27',
            vi: { text: { 'menu-expand-all-child': 'Menu Expand All Child' } },
        }
        const runtime = {
            mode: 'composition',
            global: { setLocaleMessage: setLocaleMessageMock, locale: { value: 'vi' } },
        }
        await loadLocaleMessages(runtime, 'vi')
        expect(setLocaleMessageMock).toHaveBeenCalledWith('vi', {
            text: { 'menu-expand-all-child': 'Hiển thị tất cả danh mục con?' },
        })
        expect(
            ajaxGetMock.mock.calls.some(([url]) =>
                url.includes('/vi/text.json?r=' + I18N_MESSAGES_SCHEMA_VERSION)
            )
        ).toBe(true)
        // The cache is bound to the admin build too, so a new deploy never serves a tree cached from the previous one.
        expect(storageState.value.__schema).toBe(I18N_MESSAGES_SCHEMA_VERSION + '@testver+testbuild')
    })

    it('loads canonical-only JSON without creating legacy aliases', async () => {
        ajaxGetMock.mockImplementation((url: string) => {
            if (url.includes('/manifest.json')) {
                return Promise.resolve({
                    data: ['nav', 'text'],
                })
            }

            if (url.includes('/nav.json')) {
                return Promise.resolve({
                    data: {
                        nav: {
                            dashboard: 'Dashboard',
                            b: { apply: 'Apply' },
                        },
                    },
                })
            }

            if (url.includes('/text.json')) {
                return Promise.resolve({
                    data: {
                        text: {
                            back: {
                                to: 'Back to',
                            },
                        },
                    },
                })
            }

            throw new Error(`Unexpected URL: ${url}`)
        })

        const { loadLocaleMessages } = await import('../../config/i18n')

        ajaxGetMock.mockClear()
        setLocaleMessageMock.mockClear()
        storageState.value = {}

        const runtime = {
            mode: 'composition',
            global: {
                setLocaleMessage: setLocaleMessageMock,
                locale: {
                    value: 'en',
                },
            },
        }

        await loadLocaleMessages(runtime, 'en')

        expect(ajaxGetMock.mock.calls.map(([url]) => stripCacheBuster(url))).toEqual([
            'https://admin.example/api/testver/i18n/adm/en/manifest.json',
            'https://admin.example/api/testver/i18n/adm/en/nav.json',
            'https://admin.example/api/testver/i18n/adm/en/text.json',
        ])

        const calledMessages = setLocaleMessageMock.mock.calls[0][1]

        // Canonical keys exist
        expect(calledMessages.nav.dashboard).toBe('Dashboard')
        expect(calledMessages.nav.b.apply).toBe('Apply')
        expect(calledMessages.text.back.to).toBe('Back to')

        expect(calledMessages.n).toBeUndefined()
        expect(calledMessages.t).toBeUndefined()

        expect(storageState.value).toMatchObject({
            __schema: I18N_MESSAGES_SCHEMA_VERSION + '@testver+testbuild',
            en: {
                nav: {
                    dashboard: 'Dashboard',
                },
                text: {
                    back: {
                        to: 'Back to',
                    },
                },
            },
        })
    })

    it('filters out non-admin groups from manifest', async () => {
        ajaxGetMock.mockImplementation((url: string) => {
            if (url.includes('/manifest.json')) {
                return Promise.resolve({
                    data: ['nav', 'alert', 'education', 'e', 'n', 'status'],
                })
            }

            if (url.includes('/nav.json')) {
                return Promise.resolve({
                    data: { nav: { dashboard: 'Dashboard' } },
                })
            }

            if (
                url.includes('/alert.json') ||
                url.includes('/education.json') ||
                url.includes('/status.json')
            ) {
                return Promise.resolve({ data: {} })
            }

            throw new Error(`Unexpected URL: ${url}`)
        })

        const { loadLocaleMessages } = await import('../../config/i18n')

        ajaxGetMock.mockClear()
        setLocaleMessageMock.mockClear()
        storageState.value = {}

        const runtime = {
            mode: 'composition',
            global: {
                setLocaleMessage: setLocaleMessageMock,
                locale: { value: 'en' },
            },
        }

        await loadLocaleMessages(runtime, 'en')

        // Should load manifest + canonical admin groups only.
        const loadedUrls = ajaxGetMock.mock.calls.map(([url]) => stripCacheBuster(url))
        expect(loadedUrls).toEqual([
            'https://admin.example/api/testver/i18n/adm/en/manifest.json',
            'https://admin.example/api/testver/i18n/adm/en/nav.json',
            'https://admin.example/api/testver/i18n/adm/en/alert.json',
            'https://admin.example/api/testver/i18n/adm/en/education.json',
            'https://admin.example/api/testver/i18n/adm/en/status.json',
        ])
    })

    describe('per-build cache invalidation', () => {
        const runtime = () => ({
            mode: 'composition',
            global: { setLocaleMessage: setLocaleMessageMock, locale: { value: 'en' } },
        })
        const answerShards = () =>
            ajaxGetMock.mockImplementation((url: string) =>
                Promise.resolve({
                    data: url.includes('/manifest.json') ? ['text'] : { text: { hello: 'Hello' } },
                })
            )

        it('adds the build id to every shard and manifest URL', async () => {
            buildState.buildVersion = 'rel-20261110010203'
            answerShards()
            const { loadLocaleMessages } = await import('../../config/i18n')
            await loadLocaleMessages(runtime(), 'en')

            const urls = ajaxGetMock.mock.calls.map(([url]) => url as string)
            expect(urls.length).toBeGreaterThanOrEqual(2)
            for (const url of urls) {
                expect(url).toContain('&b=rel-20261110010203')
                expect(url).toContain('?r=' + I18N_MESSAGES_SCHEMA_VERSION)
            }
        })

        it('stores a different cache schema for a different build id', async () => {
            answerShards()
            buildState.buildVersion = 'build-A'
            const first = await import('../../config/i18n')
            await first.loadLocaleMessages(runtime(), 'en')
            const schemaA = storageState.value.__schema

            vi.resetModules()
            storageState.value = {}
            buildState.buildVersion = 'build-B'
            const second = await import('../../config/i18n')
            await second.loadLocaleMessages(runtime(), 'en')
            const schemaB = storageState.value.__schema

            expect(schemaA).toBe(I18N_MESSAGES_SCHEMA_VERSION + '@testver+build-A')
            expect(schemaB).toBe(I18N_MESSAGES_SCHEMA_VERSION + '@testver+build-B')
        })

        it('drops messages cached by a previous build and refetches them', async () => {
            answerShards()
            buildState.buildVersion = 'build-B'
            const { loadLocaleMessages } = await import('../../config/i18n')
            storageState.value = {
                __schema: I18N_MESSAGES_SCHEMA_VERSION + '@testver+build-A',
                en: { text: { stale: 'Stale' } },
            }
            await loadLocaleMessages(runtime(), 'en')

            expect(ajaxGetMock).toHaveBeenCalled()
            expect(setLocaleMessageMock).toHaveBeenCalledWith('en', { text: { hello: 'Hello' } })
            expect(storageState.value.__schema).toBe(
                I18N_MESSAGES_SCHEMA_VERSION + '@testver+build-B'
            )
        })
    })
    describe('pack stamp revalidation (same build, new keys on the server)', () => {
        const runtime = () => ({
            mode: 'composition',
            global: { setLocaleMessage: setLocaleMessageMock, locale: { value: 'en' } },
        })
        const schema = () => I18N_MESSAGES_SCHEMA_VERSION + '@testver+testbuild'
        const answer = (manifest: unknown) =>
            ajaxGetMock.mockImplementation((url: string) =>
                Promise.resolve({ data: url.includes('/manifest.json') ? manifest : { text: { hello: 'Hello' } } })
            )
        // Importing the module loads the cookie locale once; let that settle so each case starts from its own cache.
        const importLoader = async () => {
            const mod = await import('../../config/i18n')
            await new Promise((resolve) => setTimeout(resolve, 0))
            ajaxGetMock.mockClear()
            setLocaleMessageMock.mockClear()
            return mod
        }
        const shardCalls = () => ajaxGetMock.mock.calls.filter(([url]) => !String(url).includes('/manifest.json'))

        it('shows the cached pack at once, then reloads it when the server pack changed', async () => {
            answer({ files: ['text'], stamp: 'new' })
            const { loadLocaleMessages } = await importLoader()
            storageState.value = { __schema: schema(), __stamps: { en: 'old' }, en: { text: { stale: 'Stale' } } }
            await loadLocaleMessages(runtime(), 'en')

            expect(setLocaleMessageMock.mock.calls[0]).toEqual(['en', { text: { stale: 'Stale' } }])
            await vi.waitFor(() => expect(setLocaleMessageMock).toHaveBeenLastCalledWith('en', { text: { hello: 'Hello' } }))
            expect((storageState.value as any).en).toEqual({ text: { hello: 'Hello' } })
            expect((storageState.value as any).__stamps.en).toBe('new')
        })

        it('reloads a pack cached before stamps existed', async () => {
            answer({ files: ['text'], stamp: 'first' })
            const { loadLocaleMessages } = await importLoader()
            storageState.value = { __schema: schema(), en: { text: { stale: 'Stale' } } }
            await loadLocaleMessages(runtime(), 'en')

            await vi.waitFor(() => expect(setLocaleMessageMock).toHaveBeenLastCalledWith('en', { text: { hello: 'Hello' } }))
        })

        it('asks only for the manifest when the stamp is unchanged', async () => {
            answer({ files: ['text'], stamp: 'same' })
            const { loadLocaleMessages } = await importLoader()
            storageState.value = { __schema: schema(), __stamps: { en: 'same' }, en: { text: { cached: 'Cached' } } }
            await loadLocaleMessages(runtime(), 'en')
            await vi.waitFor(() => expect(ajaxGetMock).toHaveBeenCalled())
            await new Promise((resolve) => setTimeout(resolve, 0))

            expect(shardCalls()).toHaveLength(0)
            expect(setLocaleMessageMock).toHaveBeenCalledTimes(1)
        })

        it('keeps the cached pack for an older server (plain manifest list) or a failing manifest', async () => {
            const { loadLocaleMessages } = await importLoader()
            for (const respond of [() => answer(['text']), () => ajaxGetMock.mockRejectedValue(new Error('offline'))]) {
                ajaxGetMock.mockReset()
                setLocaleMessageMock.mockClear()
                respond()
                storageState.value = { __schema: schema(), en: { text: { cached: 'Cached' } } }
                await loadLocaleMessages(runtime(), 'en')
                await new Promise((resolve) => setTimeout(resolve, 0))

                expect(shardCalls()).toHaveLength(0)
                expect(setLocaleMessageMock).toHaveBeenCalledTimes(1)
                expect((storageState.value as any).en).toEqual({ text: { cached: 'Cached' } })
            }
        })

        it('keeps the cached pack when a shard fails during the reload', async () => {
            ajaxGetMock.mockImplementation((url: string) =>
                url.includes('/manifest.json')
                    ? Promise.resolve({ data: { files: ['text'], stamp: 'new' } })
                    : Promise.reject(new Error('shard down'))
            )
            const { loadLocaleMessages } = await importLoader()
            storageState.value = { __schema: schema(), __stamps: { en: 'old' }, en: { text: { cached: 'Cached' } } }
            await loadLocaleMessages(runtime(), 'en')
            await vi.waitFor(() => expect(shardCalls().length).toBeGreaterThan(0))
            await new Promise((resolve) => setTimeout(resolve, 0))

            expect((storageState.value as any).en).toEqual({ text: { cached: 'Cached' } })
            expect((storageState.value as any).__stamps.en).toBe('old')
            expect(setLocaleMessageMock).toHaveBeenCalledTimes(1)
        })

        it('rechecks a cached pack when the admin starts (the module loads the cookie locale on import)', async () => {
            answer({ files: ['text'], stamp: 'new' })
            storagePreset.value = { __schema: schema(), __stamps: { en: 'old' }, en: { text: { stale: 'Stale' } } }
            const rejections: unknown[] = []
            const onRejection = (reason: unknown) => rejections.push(reason)
            process.on('unhandledRejection', onRejection)
            try {
                await import('../../config/i18n')
                await vi.waitFor(() => expect(setLocaleMessageMock).toHaveBeenLastCalledWith('en', { text: { hello: 'Hello' } }))
                await new Promise((resolve) => setTimeout(resolve, 0))
            } finally {
                process.off('unhandledRejection', onRejection)
            }
            expect(rejections).toEqual([])
        })

        it('stores the stamp with a freshly loaded pack', async () => {
            answer({ files: ['text'], stamp: 's1' })
            const { loadLocaleMessages } = await importLoader()
            await loadLocaleMessages(runtime(), 'en')

            expect((storageState.value as any).__stamps.en).toBe('s1')
            expect((storageState.value as any).en).toEqual({ text: { hello: 'Hello' } })
        })
    })

})
