import { afterEach, describe, expect, it, vi } from 'vitest'

vi.unmock('@/config/base')

/** A build without a usable VITE_DEFAULT_LOCALE must not produce a null locale (`i18n/adm/null/*.json` was a 404 wall). */
describe('defaultLocale', () => {
    afterEach(() => {
        vi.unstubAllEnvs()
        vi.resetModules()
    })

    it.each([
        ['', 'en'],
        ['de', 'en'],
        ['vi', 'vi'],
        ['en', 'en'],
    ])('VITE_DEFAULT_LOCALE=%j gives %s', async (value, expected) => {
        vi.stubEnv('VITE_DEFAULT_LOCALE', value)
        vi.resetModules()
        const base = await import('../../config/base')

        expect(base.defaultLocale).toBe(expected)
    })

    it('falls back to en when the variable is not set at all', async () => {
        vi.stubEnv('VITE_DEFAULT_LOCALE', undefined as unknown as string)
        vi.resetModules()
        const base = await import('../../config/base')

        expect(base.defaultLocale).toBe('en')
    })
})
