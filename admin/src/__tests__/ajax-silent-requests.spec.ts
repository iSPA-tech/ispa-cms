import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const alert = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', () => ({ iAlert: alert }))
vi.mock('@/config', () => ({ apiBase: 'https://api.example.test/', COOKIE_KEY_LOCALE: 'locale', COOKIE_KEY_TOKEN: 'token', defaultLocale: 'en' }))
vi.mock('@/config/cookies', () => ({ cookie: { get: vi.fn(), remove: vi.fn() } }))

// The test setup replaces '@/ajax' with a stub; this file is about the real client and its interceptor.
const { default: ajax } = await vi.importActual<typeof import('@/ajax')>('@/ajax')

// A request that fails the way the server answers a refused call: the adapter rejects with the response attached.
const failing = (status = 500, data: unknown = { message: 'Server error' }) => (config: any) =>
    Promise.reject(new AxiosError('failed', 'ERR_BAD_RESPONSE', config, null, { status, statusText: '', data, headers: {}, config }))

describe('ajax error reporting', () => {
    beforeEach(() => {
        alert.mockReset()
    })

    it('reports a failed request to the person by default', async () => {
        await expect(ajax.get('/anything', { adapter: failing() })).rejects.toBeInstanceOf(AxiosError)

        expect(alert).toHaveBeenCalledTimes(1)
        expect(alert).toHaveBeenCalledWith({ message: 'Server error' }, true)
    })

    it('stays quiet for a request marked silent, and still rejects so the caller can handle it', async () => {
        await expect(ajax.get('/anything', { adapter: failing(404, { message: 'Not found' }), silent: true } as any)).rejects.toBeInstanceOf(AxiosError)

        expect(alert).not.toHaveBeenCalled()
    })

    it('a silent request that succeeds is unaffected', async () => {
        const adapter = (config: any) => Promise.resolve({ status: 200, statusText: 'OK', data: { ok: true }, headers: {}, config })

        const response = await ajax.get('/anything', { adapter, silent: true } as any)

        expect(response.data).toEqual({ ok: true })
        expect(alert).not.toHaveBeenCalled()
    })
})
