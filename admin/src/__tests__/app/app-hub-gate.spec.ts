import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

import {
    GATE_LOADING,
    GATE_READY,
    GATE_UNAVAILABLE,
    loadSiteStatus,
    siteGateView,
    submitSiteConnect,
} from '@/pages/auth/site-gate'

const read = (relative: string) => readFileSync(resolve(__dirname, '../..', relative), 'utf8')

describe('siteGateView', () => {
    it.each([
        // signing in never waits for the hub; only a silent site backend blocks it.
        [{ status: 'connected' }, false, GATE_READY],
        [{ status: 'not_connected' }, false, GATE_READY],
        [{ status: 'pending' }, false, GATE_READY],
        [{ status: 'something-new' }, false, GATE_READY],
        [null, false, GATE_LOADING],
        [{}, false, GATE_LOADING],
        [{ status: 'connected' }, true, GATE_UNAVAILABLE],
        [null, true, GATE_UNAVAILABLE],
    ])('answer %j (failed %s) shows %s', (answer, failed, expected) => {
        expect(siteGateView(answer, failed)).toBe(expected)
    })
})

describe('loadSiteStatus', () => {
    it('asks the site backend, never the hub, and returns the answer', async () => {
        const http = { get: vi.fn(async () => ({ data: { data: { status: 'connected', message_key: 'text.site_status.connected', expires_at: null } } })) }

        const answer = await loadSiteStatus(http, 'https://site.example/')

        expect(http.get).toHaveBeenCalledWith('https://site.example/api/v2/public/site-status')
        expect(answer).toEqual({ status: 'connected', message_key: 'text.site_status.connected', expires_at: null })
    })

    it('returns null when the request fails or the shape is wrong', async () => {
        expect(await loadSiteStatus({ get: vi.fn(async () => Promise.reject(new Error('down'))) }, 'https://s/')).toBeNull()
        expect(await loadSiteStatus({ get: vi.fn(async () => ({ data: { data: {} } })) }, 'https://s/')).toBeNull()
        expect(await loadSiteStatus({ get: vi.fn(async () => ({ data: 'oops' })) }, 'https://s/')).toBeNull()
    })
})

describe('submitSiteConnect', () => {
    const form = { siteId: 'a.example', siteSecret: 'sec', setupCode: 'CODE' }

    it('posts the three fields to the site backend and reports the status', async () => {
        const http = { post: vi.fn(async () => ({ data: { data: { status: 'connected', message_key: 'text.site_connect.connected' } } })) }

        const answer = await submitSiteConnect(http, 'https://site.example/', form)

        expect(http.post).toHaveBeenCalledWith('https://site.example/api/v2/public/site-connect', {
            site_id: 'a.example',
            site_secret: 'sec',
            setup_code: 'CODE',
        })
        expect(answer).toEqual({ ok: true, status: 'connected', messageKey: 'text.site_connect.connected' })
    })

    it('keeps the backend message key of a refusal', async () => {
        const http = {
            post: vi.fn(async () => Promise.reject({ response: { status: 422, data: { data: { status: 'not_connected', message_key: 'text.site_connect.setup_code_invalid' } } } })),
        }

        expect(await submitSiteConnect(http, 'https://s/', form)).toEqual({
            ok: false,
            status: 'not_connected',
            messageKey: 'text.site_connect.setup_code_invalid',
        })
    })

    it('has plain keys for a throttle hit and for a silent site', async () => {
        const throttled = { post: vi.fn(async () => Promise.reject({ response: { status: 429, data: {} } })) }
        const silent = { post: vi.fn(async () => Promise.reject(new Error('network'))) }

        expect((await submitSiteConnect(throttled, 'https://s/', form)).messageKey).toBe('text.site_connect.too_many_attempts_here')
        expect((await submitSiteConnect(silent, 'https://s/', form)).messageKey).toBe('text.site_connect.unavailable')
    })
})

describe('admin shell gate wiring', () => {
    const app = read('App.vue')

    it('asks the site status through the gate helper and never calls the hub verify endpoint', () => {
        expect(app).toContain('loadSiteStatus(')
        expect(app).not.toContain('/api/v2/public/verify')
        expect(app).not.toContain('VITE_CMS_API_URL')
        expect(app).not.toContain("'x-domain'")
    })

    it('never puts the connect screen in front of sign-in; it only offers a retry when the site backend is silent', () => {
        expect(app).toMatch(/siteGate === 'ready'/)
        expect(app).not.toMatch(/siteGate === 'connect'/)
        expect(app).toMatch(/<ConnectHub\s+v-else-if="siteGate === 'unavailable'"/)
    })

    it('no longer provides the legacy domainToken injection: the browser holds no domain token at all', () => {
        expect(app).not.toContain('domainToken')
        expect(app).not.toContain('domainTokenRef')
        expect(app).not.toContain('res.data.data.token')
    })
})

describe('components no longer hold or send the domain token', () => {
    it.each(['components/modal-edit-content.vue', 'components/view-detail-content.vue', 'pages/dashboard.vue'])('%s', (file) => {
        const source = read(file)

        expect(source).not.toContain('domainToken')
        expect(source).not.toMatch(/\.data\?\.token/)
    })
})
