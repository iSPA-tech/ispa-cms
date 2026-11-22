import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import TokenList from '@core-admin/components/security/token-list.vue'
import SecurityTokensPage from '@core-admin/pages/setting/security-tokens.vue'
import { dropGrant } from '@/libs/securityTokens'

const state = vi.hoisted(() => ({ webmaster: true, locale: 'vi' }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn(), put: vi.fn() }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/hooks/useCan', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/hooks/useCan')>()
    return { ...actual, isWebmaster: () => state.webmaster, hasAccountData: () => true }
})
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}|${Object.values(params).join(',')}` : key),
        d: (v: unknown) => String(v),
        locale: { get value() { return state.locale } },
    }),
}))

const stubs = {
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}<slot name="action" /></div>' },
    'a-skeleton': true,
    'a-tag': { template: '<span><slot /></span>' },
    'a-tabs': { template: '<div><slot /></div>' },
    'a-tab-pane': { props: ['tab'], template: '<section><h4>{{ tab }}</h4><slot /></section>' },
    'a-modal': { props: ['open'], template: '<div v-if="open"><slot /></div>' },
    'a-table': {
        props: ['dataSource', 'columns', 'locale'],
        template:
            '<div><div v-if="!dataSource.length" data-testid="empty">{{ locale.emptyText }}</div><table><tbody><tr v-for="row in dataSource" :key="row.uuid"><td v-for="c in columns" :key="c.key"><slot name="bodyCell" :column="c" :record="row" /></td></tr></tbody></table></div>',
    },
    'i-button': {
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        template: '<button :type="nativeType || \'button\'" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
    },
    TokenSettingsForm: true,
    RevokeAllDialog: true,
}

const row = (over: Record<string, unknown> = {}) => ({
    uuid: 'u-1',
    label: 'Chrome on Mac',
    audience: 'admin',
    network: '10.0.0.0/24',
    created_at: '2026-11-01T10:00:00+00:00',
    last_used_at: null,
    expires_at: null,
    never_expires: true,
    current: false,
    ...over,
})
const rows = () => [row({ uuid: 'u-cur', label: 'This browser', current: true }), row({ uuid: 'u-2', label: 'Phone' })]

const t = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)
const mountList = async (props: Record<string, unknown> = { mode: 'mine' }) => {
    const w = mount(TokenList, { props, global: { stubs } })
    await flushPromises()
    return w
}
const status = (over: Record<string, unknown> = {}) => ({
    data: { data: { possible: true, password_required: true, second_factor: [], locked: false, retry_after: null, ...over } },
})

beforeEach(() => {
    dropGrant()
    state.webmaster = true
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    ajaxMock.get.mockImplementation((url: string) =>
        url === 'security/step-up/status' ? Promise.resolve(status()) : Promise.resolve({ data: { data: rows() } })
    )
    ajaxMock.delete.mockResolvedValue({ data: { data: { revoked: 1 } } })
    ajaxMock.post.mockResolvedValue({ data: { data: { status: 'confirmed', grant: 'g-1', expires_in: 300 } } })
})
afterEach(() => vi.useRealTimers())

describe('token list', () => {
    it('lists the tokens, marks the current one and never shows a token value', async () => {
        ajaxMock.get.mockResolvedValue({ data: { data: [row({ uuid: 'u-cur', current: true, token: 'PLAIN-SECRET', token_hash: 'HASH' })] } })
        const w = await mountList()
        expect(t(w, 'token-current-badge').exists()).toBe(true)
        expect(w.html()).not.toContain('PLAIN-SECRET')
        expect(w.html()).not.toContain('HASH')
    })

    it('shows the device label, never the generic token name', async () => {
        ajaxMock.get.mockResolvedValue({
            data: { data: [row({ uuid: 'u-d', label: 'authToken', device: 'Chrome on Mac' }), row({ uuid: 'u-g', label: 'authToken', device: null })] },
        })
        const w = await mountList()
        const html = w.html()
        expect(html).toContain('Chrome on Mac')
        expect(html).not.toContain('authToken')
        expect(html).toContain('text.security_tokens.list.unknown_device')
    })

    it('writes Vietnamese times in 24 hours', async () => {
        state.locale = 'vi'
        ajaxMock.get.mockResolvedValue({
            data: { data: [row({ uuid: 'u-t', label: 'Phone', created_at: new Date(2026, 10, 1, 15, 5).toISOString(), never_expires: false, expires_at: new Date(2026, 11, 1, 3, 9).toISOString() })] },
        })
        const w = await mountList()
        const text = w.text()
        expect(text).not.toMatch(/\b(AM|PM|SA|CH)\b/)
        expect(text).toContain('15:05')
        expect(text).toContain('03:09')
    })

    it('disables the revoke button of the current row and says why', async () => {
        const w = await mountList()
        expect(t(w, 'token-revoke-u-cur').attributes('disabled')).toBeDefined()
        expect(t(w, 'token-current-hint-u-cur').text()).toBe('text.security_tokens.list.current_hint')
        expect(t(w, 'token-revoke-u-2').attributes('disabled')).toBeUndefined()
    })

    it('shows loading, empty and error with a retry', async () => {
        ajaxMock.get.mockResolvedValue({ data: { data: [] } })
        const empty = await mountList()
        expect(t(empty, 'empty').text()).toBe('text.security_tokens.list.empty')
        ajaxMock.get.mockRejectedValueOnce(new Error('x'))
        const failed = await mountList()
        expect(t(failed, 'token-error').exists()).toBe(true)
        ajaxMock.get.mockResolvedValue({ data: { data: rows() } })
        await t(failed, 'token-retry').trigger('click')
        await flushPromises()
        expect(t(failed, 'token-error').exists()).toBe(false)
        expect(t(failed, 'token-revoke-u-2').exists()).toBe(true)
    })

    it('opens the step-up form when no grant is held, then repeats the revoke with the grant', async () => {
        const w = await mountList()
        await t(w, 'token-revoke-u-2').trigger('click')
        await flushPromises()
        expect(ajaxMock.delete).not.toHaveBeenCalled()
        expect(t(w, 'stepup-dialog').exists() || t(w, 'stepup-password-form').exists()).toBe(true)
        await t(w, 'stepup-password').setValue('correct horse')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post.mock.calls[0][0]).toBe('security/step-up')
        expect(ajaxMock.delete).toHaveBeenCalledTimes(1)
        expect(ajaxMock.delete.mock.calls[0][0]).toBe('security/tokens/mine/u-2')
        expect(ajaxMock.delete.mock.calls[0][1].headers['X-Step-Up-Grant']).toBe('g-1')
        expect(t(w, 'stepup-password-form').exists()).toBe(false)
        expect(t(w, 'token-notice').exists()).toBe(true)
    })

    it('reuses a held grant for the next single revoke without asking again', async () => {
        const w = await mountList()
        await t(w, 'token-revoke-u-2').trigger('click')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        await t(w, 'token-revoke-u-2').trigger('click')
        await flushPromises()
        expect(ajaxMock.delete).toHaveBeenCalledTimes(2)
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
    })

    it('uses the grant up on "end all other sessions"', async () => {
        ajaxMock.post.mockImplementation((url: string) =>
            Promise.resolve({ data: { data: url === 'security/step-up' ? { status: 'confirmed', grant: 'g-2', expires_in: 300 } : { revoked: 1 } } })
        )
        const w = await mountList()
        await t(w, 'token-revoke-others').trigger('click')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const call = ajaxMock.post.mock.calls.find((c) => c[0] === 'security/tokens/mine/revoke-others')
        expect(call?.[2].headers['X-Step-Up-Grant']).toBe('g-2')
        // The grant is gone: a second bulk call asks again.
        await t(w, 'token-revoke-others').trigger('click')
        await flushPromises()
        expect(t(w, 'stepup-password-form').exists()).toBe(true)
    })
})

describe('narrow screens', () => {
    const narrow = (matches: boolean) =>
        vi.stubGlobal('matchMedia', () => ({ matches, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
    afterEach(() => vi.unstubAllGlobals())

    it('shows one stacked card per token with expiry and the revoke action, no table', async () => {
        narrow(true)
        const w = await mountList()
        expect(t(w, 'token-table').exists()).toBe(false)
        expect(t(w, 'token-cards').exists()).toBe(true)
        const card = t(w, 'token-card-u-2')
        expect(card.text()).toContain('text.security_tokens.list.expires')
        expect(card.text()).toContain('text.security_tokens.list.never_expires')
        expect(t(w, 'token-revoke-u-cur').attributes('disabled')).toBeDefined()
        expect(t(w, 'token-current-hint-u-cur').exists()).toBe(true)
    })

    it('revokes from a card through the same confirmation', async () => {
        narrow(true)
        const w = await mountList()
        await t(w, 'token-revoke-u-2').trigger('click')
        await flushPromises()
        expect(t(w, 'stepup-password-form').exists()).toBe(true)
    })

    it('keeps the table on wide screens', async () => {
        narrow(false)
        const w = await mountList()
        expect(t(w, 'token-table').exists()).toBe(true)
        expect(t(w, 'token-cards').exists()).toBe(false)
    })
})

describe('step-up form', () => {
    const open = async (statusOver: Record<string, unknown> = {}) => {
        ajaxMock.get.mockImplementation((url: string) =>
            url === 'security/step-up/status' ? Promise.resolve(status(statusOver)) : Promise.resolve({ data: { data: rows() } })
        )
        const w = await mountList()
        await t(w, 'token-revoke-u-2').trigger('click')
        await flushPromises()
        return w
    }

    it('shows no second-factor field for a password-only account', async () => {
        const w = await open()
        expect(t(w, 'stepup-password').exists()).toBe(true)
        expect(t(w, 'stepup-code').exists()).toBe(false)
    })

    it('asks for the authenticator code after the password when the account has one', async () => {
        ajaxMock.post.mockResolvedValueOnce({
            data: { data: { status: 'second_factor_required', challenge_id: 'c-1', method: 'totp', can_use_recovery_code: true } },
        })
        const w = await open({ second_factor: ['totp'] })
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        expect(t(w, 'stepup-code').exists()).toBe(true)
        expect(t(w, 'stepup-toggle-recovery').exists()).toBe(true)
        expect(t(w, 'stepup-resend').exists()).toBe(false)
    })

    it('offers only the e-mail code to an account without a password', async () => {
        const w = await open({ password_required: false })
        expect(t(w, 'stepup-password').exists()).toBe(false)
        expect(t(w, 'stepup-send-first').exists()).toBe(true)
    })

    it('shows the lock countdown on a 429 and never echoes what was typed', async () => {
        vi.useFakeTimers()
        ajaxMock.post.mockRejectedValueOnce({
            response: { status: 429, headers: { 'retry-after': '3' }, data: { error_code: 'step_up_locked', retry_after: 3 } },
        })
        const w = await open()
        await t(w, 'stepup-password').setValue('secret-pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        expect(t(w, 'stepup-locked').text()).toContain('3')
        expect((t(w, 'stepup-password').element as HTMLInputElement).value).toBe('')
        expect(w.html()).not.toContain('secret-pw')
        vi.advanceTimersByTime(3000)
        await flushPromises()
        expect(t(w, 'stepup-locked').exists()).toBe(false)
    })

    it('uses one generic text for a wrong password and clears the field', async () => {
        ajaxMock.post.mockRejectedValueOnce({ response: { status: 422, headers: {}, data: { error_code: 'step_up_failed' } } })
        const w = await open()
        await t(w, 'stepup-password').setValue('wrong')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        expect(t(w, 'stepup-error').text()).toContain('text.security_tokens.stepup.failed')
        expect((t(w, 'stepup-password').element as HTMLInputElement).value).toBe('')
    })
})

describe('lifecycle and page', () => {
    it('releases the lock timer on unmount', async () => {
        vi.useFakeTimers()
        ajaxMock.post.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '30' }, data: { error_code: 'step_up_locked' } } })
        const w = await mountList()
        await t(w, 'token-revoke-u-2').trigger('click')
        await flushPromises()
        await t(w, 'stepup-password').setValue('x')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        expect(vi.getTimerCount()).toBeGreaterThan(0)
        w.unmount()
        expect(vi.getTimerCount()).toBe(0)
    })

    it('shows a non-Webmaster only "My devices"', async () => {
        state.webmaster = false
        const w = mount(SecurityTokensPage, { global: { stubs } })
        await flushPromises()
        expect(w.html()).toContain('text.security_tokens.tab.mine')
        expect(w.html()).not.toContain('text.security_tokens.tab.all')
        expect(w.html()).not.toContain('text.security_tokens.tab.lifetime')
        expect(t(w, 'security-tokens-browser-note').text()).toBe('text.security_tokens.page.browser_note')
    })

    it('shows the Webmaster the four tabs', async () => {
        const w = mount(SecurityTokensPage, { global: { stubs } })
        await flushPromises()
        for (const k of ['mine', 'all', 'lifetime', 'cleanup']) expect(w.html()).toContain(`text.security_tokens.tab.${k}`)
    })
})
