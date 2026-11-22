import { describe, expect, it, vi } from 'vitest'

vi.unmock('@/ajax')

vi.doMock('@/config', () => ({
    apiBase: 'http://localhost/api/v2/admin/',
    defaultLocale: 'en',
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    COOKIE_KEY_TOKEN: 'token',
    basePath: '/',
    url: 'http://localhost/',
    cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
}))

vi.doMock('@/config/base', () => ({
    appVersion: 'test',
    buildVersion: 'test',
    domain: 'localhost',
    cookieDomain: 'localhost',
    isLocal: true,
    url: 'http://localhost/',
    basePath: '/',
    apiBase: 'http://localhost/api/v2/admin/',
    apiUrl: 'http://localhost/',
    cmsVersion: 'test',
    defaultLocale: 'en',
    supportLocales: ['en'],
    logoUrl: '',
    logoAuthUrl: '',
    logoDarkUrl: '',
    cmsTitle: 'CMS',
    projectTitle: 'CMS',
    cookieKey: 'cms-test',
    PusherId: '',
    PusherCluster: '',
    PusherDebug: false,
}))

vi.doMock('@/config/cookies', () => ({
    cookie: { get: () => null, set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
}))

describe('msw: ajax client integration', () => {
    it('returns mocked payload for ping endpoint', async () => {
        const { default: ajax } = await import('@/ajax')
        console.log('Test: Calling ajax.get(__msw__/ping)')
        const response = await ajax.get('__msw__/ping')
        console.log('Test: Received response status:', response.status)

        expect(response.status).toBe(200)
        expect(response.data).toEqual({ status: 'ok' })
    }, 30000)
})
