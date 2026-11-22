import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

// Spec.
vi.mock('@/config/runtime', () => ({ runtimeConfig: () => ({ docsUrl: 'https://docs.example.org' }) }))

import DashboardHubBand from '@/components/dashboard/dashboard-hub-band.vue'

const RouterLinkStub = {
    props: ['to'],
    template: '<a data-test="rl" :data-to="JSON.stringify(to)"><slot /></a>',
}

const notConnected = { connected: false, development: false, portal_url: 'https://ispa.tech/portal' }

const mountBand = (props: Record<string, unknown>) =>
    mount(DashboardHubBand, {
        props: { hub: notConnected, name: 'Vương', version: '2026.12.0', webmaster: true, locale: 'vi', ...props },
        global: {
            mocks: { $t: (k: string, p?: Record<string, string>) => (p ? `${k}|${Object.values(p).join('|')}` : k) },
            stubs: { 'router-link': RouterLinkStub, 'i-icon': true },
        },
    })

describe('dashboard hub band', () => {
    it('shows the steps and the connect link to a webmaster when not connected', () => {
        const w = mountBand({})
        expect(w.text()).toContain('text.dashboard.band.not_connected')
        expect(w.text()).toContain('text.dashboard.band.title|Vương')
        expect(w.findAll('[data-testid=band-step]')).toHaveLength(3)
        const connect = w.find('[data-testid=band-connect]')
        expect(JSON.parse(connect.attributes('data-to') as string)).toEqual({ name: 'setting-modules', query: { connect: '1' } })
        expect(w.find('[data-testid=band-portal]').attributes('href')).toBe('https://ispa.tech/portal')
    })

    it('hides the steps from non-webmasters', () => {
        const w = mountBand({ webmaster: false })
        expect(w.findAll('[data-testid=band-step]')).toHaveLength(0)
        expect(w.find('[data-testid=band-connect]').exists()).toBe(false)
        expect(w.find('[data-testid=band-portal]').exists()).toBe(false)
        expect(w.find('[data-testid=band-docs]').exists()).toBe(true)
    })

    it('shows the development note on a development domain', () => {
        expect(mountBand({}).text()).not.toContain('text.dashboard.band.dev_note')
        expect(mountBand({ hub: { ...notConnected, development: true } }).text()).toContain('text.dashboard.band.dev_note')
    })

    it('shows one line when connected', () => {
        const w = mountBand({ hub: { ...notConnected, connected: true } })
        expect(w.findAll('[data-testid=band-step]')).toHaveLength(0)
        expect(w.find('[data-testid=band-connect]').exists()).toBe(false)
        expect(w.text()).toContain('text.dashboard.band.connected')
        expect(w.find('[data-testid=band-hello]').text()).toContain('text.dashboard.band.hello|Vương')
        expect(w.find('[data-testid=band-hello]').text()).toContain('iSPA CMS 2026.12.0')
        expect(JSON.parse(w.find('[data-testid=band-store]').attributes('data-to') as string)).toEqual({ name: 'setting-modules' })
        expect(mountBand({ hub: { ...notConnected, connected: true }, webmaster: false }).find('[data-testid=band-store]').exists()).toBe(false)
    })

    it('links the docs in the admin language', () => {
        const docs = (locale: string) => mountBand({ locale }).find('[data-testid=band-docs]')
        expect(docs('vi').attributes('href')).toBe('https://docs.example.org/vi/docs')
        expect(docs('en').attributes('href')).toBe('https://docs.example.org/en/docs')
        expect(docs('vi').attributes('target')).toBe('_blank')
        expect(docs('vi').attributes('rel')).toContain('noopener')
    })

    it('shows non-webmasters only the pill, the sentence and the docs link', () => {
        const w = mountBand({ webmaster: false, hub: { ...notConnected, development: true } })
        expect(w.text()).toContain('text.dashboard.band.not_connected')
        expect(w.text()).toContain('text.dashboard.band.intro')
        expect(w.text()).not.toContain('text.dashboard.band.title')
        expect(w.find('[data-testid=band-dev-note]').exists()).toBe(false)
        expect(w.find('[data-testid=band-docs]').exists()).toBe(true)
    })

    it('keeps the development note readable on the band', () => {
        const note = mountBand({ hub: { ...notConnected, development: true } }).find('[data-testid=band-dev-note]')
        expect(note.classes()).toContain('text-white/90')
    })

    it('takes its colours from the design tokens, never hard-coded values', () => {
        for (const hub of [notConnected, { ...notConnected, connected: true }]) {
            expect(mountBand({ hub }).html()).not.toMatch(/#[0-9a-f]{3,8}\b|var\(--/i)
        }
    })

    it('tells screen readers which links open a new tab', () => {
        for (const hub of [notConnected, { ...notConnected, connected: true }]) {
            const links = mountBand({ hub }).findAll('a[target=_blank]')
            expect(links.length).toBeGreaterThan(0)
            for (const link of links) {
                expect(link.find('.sr-only').text()).toBe('text.menu_editor.open-in-a-new-tab')
            }
        }
    })

    it('never greets an empty name', () => {
        const w = mountBand({ name: '' })
        expect(w.text()).toContain('text.dashboard.band.title_plain')
        expect(w.text()).not.toContain('text.dashboard.band.title|')
        const hello = mountBand({ name: '', hub: { ...notConnected, connected: true } }).find('[data-testid=band-hello]')
        expect(hello.text()).not.toContain('text.dashboard.band.hello')
        expect(hello.text().trim()).toBe('iSPA CMS 2026.12.0')
    })
})

