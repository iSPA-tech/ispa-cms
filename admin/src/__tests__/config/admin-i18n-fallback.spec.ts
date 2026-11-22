import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import fallback from 'virtual:admin-i18n-fallback'
import { buildFallbackMessages } from '../../config/admin-i18n-fallback'
import { withFallbackMessages } from '../../config/i18n-message-utils'

/** Read a dotted key out of a nested message tree. */
const read = (tree: any, key: string) => key.split('.').reduce((node, part) => node?.[part], tree)

describe('buildFallbackMessages', () => {
    it('nests the gate keys per locale and unescapes PHP quotes', () => {
        const php = `
            $entry('text.site_connect.title', 'Connect', 'Kết nối'),
            $entry('text.site_connect.reason.wrong_answer', 'It\\'s wrong', 'Sai rồi'),
            $entry('text.other.skipped', 'x', 'y'),`
        const messages = buildFallbackMessages(php)

        expect(messages.en.text.site_connect.title).toBe('Connect')
        expect(messages.vi.text.site_connect.title).toBe('Kết nối')
        expect(messages.en.text.site_connect.reason.wrong_answer).toBe("It's wrong")
        expect(messages.en.text.other).toBeUndefined()
    })

    it('reads a row that carries its own updated_at as a fourth argument', () => {
        const php = `
            $entry('text.site_connect.intro', 'Connect once', 'Nối một lần', '2026-11-19 12:00:00'),
            $entry('text.site_connect.title', 'Connect', 'Kết nối'),`
        const messages = buildFallbackMessages(php)

        expect(messages.en.text.site_connect.intro).toBe('Connect once')
        expect(messages.vi.text.site_connect.intro).toBe('Nối một lần')
        expect(messages.vi.text.site_connect.title).toBe('Kết nối')
    })
})

describe('virtual:admin-i18n-fallback (built from the Core sidecar)', () => {
    it('carries a vi and an en text for every key the connect screen uses', () => {
        const component = readFileSync(resolve(__dirname, '../../pages/auth/connect-hub.vue'), 'utf8')
        const keys = [...new Set([...component.matchAll(/\bt\('(text\.site_(?:connect|status)\.[a-z_.]+)'/g)].map((m) => m[1]))]

        expect(keys.length).toBeGreaterThan(10)
        for (const key of keys) {
            for (const locale of ['vi', 'en']) {
                const text = read((fallback as any)[locale], key)
                expect(typeof text, `${locale} ${key}`).toBe('string')
                expect(String(text).trim(), `${locale} ${key}`).not.toBe('')
            }
        }
    })

    it('has the status messages the screen turns into notices', () => {
        for (const locale of ['vi', 'en']) {
            expect(read((fallback as any)[locale], 'text.site_status.not_connected')).toBeTruthy()
            expect(read((fallback as any)[locale], 'text.site_status.hub_unreachable')).toBeTruthy()
        }
    })
})

describe('withFallbackMessages', () => {
    it('lets the loaded pack override the fallback and keeps the rest', () => {
        const base = { text: { site_connect: { title: 'Fallback', intro: 'Intro' } } }
        const merged = withFallbackMessages(base, { text: { site_connect: { title: 'Pack' }, other: 'x' } })

        expect(merged).toEqual({ text: { site_connect: { title: 'Pack', intro: 'Intro' }, other: 'x' } })
        // The shared fallback object is never mutated.
        expect(base.text.site_connect.title).toBe('Fallback')
    })

    it('returns the fallback alone when the pack is empty', () => {
        expect(withFallbackMessages({ text: { a: 'b' } }, {})).toEqual({ text: { a: 'b' } })
        expect(withFallbackMessages(undefined, { text: { a: 'b' } })).toEqual({ text: { a: 'b' } })
    })
})
