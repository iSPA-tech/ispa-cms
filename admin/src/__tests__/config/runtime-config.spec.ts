import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const VITE_KEYS = [
    'VITE_PROTOCOL',
    'VITE_BASE_URL',
    'VITE_DOMAIN',
    'VITE_SECURE_COOKIE',
    'VITE_APP_ADMIN_AUTH_KEY',
    'VITE_CMS_API_URL',
    'VITE_VERSION',
]

const clearVite = () => VITE_KEYS.forEach((key) => vi.stubEnv(key, ''))

const stubLocation = (href: string) => {
    const parsed = new URL(href)
    vi.stubGlobal('location', {
        origin: parsed.origin,
        hostname: parsed.hostname,
        protocol: parsed.protocol,
        href,
    })
}

describe('runtimeConfig', () => {
    beforeEach(() => {
        vi.resetModules()
        clearVite()
        vi.stubGlobal('__ISPA_ADMIN__', undefined)
    })

    afterEach(() => {
        vi.unstubAllEnvs()
        vi.unstubAllGlobals()
    })

    it('prefers window.__ISPA_ADMIN__ over VITE values', async () => {
        vi.stubEnv('VITE_PROTOCOL', 'http')
        vi.stubEnv('VITE_BASE_URL', 'vite.test')
        vi.stubEnv('VITE_DOMAIN', 'vite.test')
        vi.stubEnv('VITE_APP_ADMIN_AUTH_KEY', 'vite-key')
        vi.stubEnv('VITE_CMS_API_URL', 'https://vite-hub.test')
        vi.stubEnv('VITE_VERSION', 'vite-build')
        vi.stubGlobal('__ISPA_ADMIN__', {
            url: 'https://example.com/cms',
            cookieDomain: 'example.com',
            secureCookie: true,
            adminAuthKey: 'win-key',
            hubUrl: 'https://hub.example.com',
            version: '2026.12.0',
            docsUrl: 'https://docs.example.org/',
        })

        const { runtimeConfig } = await import('@/config/runtime')

        expect(runtimeConfig()).toEqual({
            url: 'https://example.com/cms/',
            cookieDomain: 'example.com',
            secureCookie: true,
            adminAuthKey: 'win-key',
            hubUrl: 'https://hub.example.com',
            version: '2026.12.0',
            docsUrl: 'https://docs.example.org',
        })
    })

    it('falls back to VITE values when no window config', async () => {
        vi.stubEnv('VITE_PROTOCOL', 'http')
        vi.stubEnv('VITE_BASE_URL', 'cms-dev.test:8000')
        vi.stubEnv('VITE_DOMAIN', 'cms-dev.test')
        vi.stubEnv('VITE_SECURE_COOKIE', 'true')
        vi.stubEnv('VITE_APP_ADMIN_AUTH_KEY', 'vite-key')
        vi.stubEnv('VITE_CMS_API_URL', 'https://vite-hub.test')
        vi.stubEnv('VITE_VERSION', '250430')

        const { runtimeConfig } = await import('@/config/runtime')

        expect(runtimeConfig()).toEqual({
            url: 'http://cms-dev.test:8000/',
            cookieDomain: 'cms-dev.test',
            secureCookie: true,
            adminAuthKey: 'vite-key',
            hubUrl: 'https://vite-hub.test',
            version: '250430',
            docsUrl: 'https://docs.ispa.tech',
        })
    })

    it('falls back to location when neither is set', async () => {
        stubLocation('http://localhost:8000/iPanel/')

        const { runtimeConfig } = await import('@/config/runtime')
        const config = runtimeConfig()

        expect(config.url).toBe('http://localhost:8000/')
        expect(config.cookieDomain).toBe('localhost')
        expect(config.secureCookie).toBe(false)
        expect(config.hubUrl).toBe('https://ispa.tech')
        expect(config.adminAuthKey).toBe('')
        expect(config.docsUrl).toBe('https://docs.ispa.tech')
    })

    it('location fallback derives secureCookie from the scheme', async () => {
        stubLocation('https://example.com/iPanel/')

        const { runtimeConfig } = await import('@/config/runtime')

        expect(runtimeConfig().secureCookie).toBe(true)
        expect(runtimeConfig().cookieDomain).toBe('example.com')
    })

    it('secureCookie is a real boolean', async () => {
        const { runtimeConfig } = await import('@/config/runtime')

        vi.stubEnv('VITE_SECURE_COOKIE', 'true')
        expect(runtimeConfig().secureCookie).toBe(true)

        vi.stubEnv('VITE_SECURE_COOKIE', 'false')
        expect(runtimeConfig().secureCookie).toBe(false)

        vi.stubEnv('VITE_SECURE_COOKIE', 'true')
        vi.stubGlobal('__ISPA_ADMIN__', { secureCookie: false })
        expect(runtimeConfig().secureCookie).toBe(false)
    })

    it('apiBase ends with api/v2/admin/', async () => {
        vi.stubGlobal('__ISPA_ADMIN__', { url: 'http://localhost:8000//' })

        const { apiBase, url } = await vi.importActual<typeof import('@/config/base')>('@/config/base')

        expect(url).toBe('http://localhost:8000/')
        expect(apiBase).toBe('http://localhost:8000/api/v2/admin/')
        expect(apiBase.endsWith('api/v2/admin/')).toBe(true)
    })
})
