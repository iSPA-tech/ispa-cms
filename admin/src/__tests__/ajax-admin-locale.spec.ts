// The shared API client tells the server which language the admin is read in (`X-Admin-Locale`), so widget and design
// names come back in that language. It never sends `X-Locale` by default: that header changes the API locale (content
// language, validation, mails) and stays for callers that ask for it, such as starter packs.

import { beforeEach, describe, expect, it, vi } from 'vitest'

const locale = vi.hoisted(() => ({ value: 'vi' as string | undefined }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))
vi.mock('@/config', () => ({ apiBase: 'https://api.example.test/', COOKIE_KEY_LOCALE: 'locale', COOKIE_KEY_TOKEN: 'token', defaultLocale: 'en' }))
vi.mock('@/config/cookies', () => ({
    cookie: {
        get: vi.fn((key: string, fallback?: string) => (key === 'locale' ? (locale.value ?? fallback) : undefined)),
        remove: vi.fn(),
    },
}))

// The test setup replaces these with stubs; this file is about the real clients.
const { default: ajax } = await vi.importActual<typeof import('@/ajax')>('@/ajax')
const { default: ajaxV4 } = await vi.importActual<typeof import('@/ajax-v4')>('@/ajax-v4')

/** Answers 200 and hands back the headers the request was sent with. */
const sentHeaders = async (client: typeof ajax, options: Record<string, unknown> = {}) => {
    let headers: Record<string, unknown> = {}
    const adapter = (config: any) => {
        headers = config.headers
        return Promise.resolve({ status: 200, statusText: 'OK', data: {}, headers: {}, config })
    }
    await client.get('/anything', { adapter, ...options } as any)
    return headers
}

describe('X-Admin-Locale on admin API requests', () => {
    beforeEach(() => {
        locale.value = 'vi'
    })

    it.each([
        ['ajax', ajax],
        ['ajax-v4', ajaxV4],
    ])('%s sends the admin language as X-Admin-Locale and no X-Locale', async (_name, client) => {
        const headers = await sentHeaders(client as typeof ajax)
        expect(headers['X-Admin-Locale']).toBe('vi')
        expect(headers['X-Locale']).toBeUndefined()
    })

    it('follows a language change without a reload', async () => {
        locale.value = 'en'

        expect((await sentHeaders(ajax))['X-Admin-Locale']).toBe('en')
    })

    it('falls back to the default admin language when none was chosen yet', async () => {
        locale.value = undefined

        expect((await sentHeaders(ajax))['X-Admin-Locale']).toBe('en')
    })

    it('keeps a language the caller asked for', async () => {
        expect((await sentHeaders(ajax, { headers: { 'X-Admin-Locale': 'en' } }))['X-Admin-Locale']).toBe('en')
    })

    it('leaves a caller X-Locale untouched (starter packs)', async () => {
        const headers = await sentHeaders(ajax, { headers: { 'X-Locale': 'en' } })
        expect(headers['X-Locale']).toBe('en')
        expect(headers['X-Admin-Locale']).toBe('vi')
    })
})
