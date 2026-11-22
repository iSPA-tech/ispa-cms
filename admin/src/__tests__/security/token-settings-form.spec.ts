import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TokenSettingsForm from '@core-admin/components/security/token-settings-form.vue'
import { dropGrant } from '@/libs/securityTokens'
import { stubs } from './_stubs'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn(), put: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', async () => (await import('./_stubs')).i18nMock)

const settings = (over: Record<string, unknown> = {}) => ({
    lifetime: { admin: '7d', account: 'never', client: 'never', service: 'never' },
    idle_days: { admin: 0, account: 0, client: 0, service: 0 },
    max_live_tokens: 50,
    mode: 'log',
    unused_days: 0,
    ...over,
})
const body = (s = settings()) => ({
    settings: s,
    audiences: ['admin', 'account', 'client', 'service'],
    options: { idle_days: [0, 1, 7, 30], unused_days: [0, 30, 90, 180], modes: ['off', 'log', 'enforce'], max_live_tokens: { min: 1, max: 100 } },
    recommended: { admin: '7d' },
    warnings: [],
})
const t = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)

beforeEach(() => {
    dropGrant()
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    ajaxMock.get.mockImplementation((url: string) =>
        url === 'security/step-up/status'
            ? Promise.resolve({ data: { data: { possible: true, password_required: true, second_factor: [] } } })
            : Promise.resolve({ data: { data: body() } })
    )
    ajaxMock.put.mockImplementation((_u: string, sent: Record<string, any>) =>
        Promise.resolve({ data: { data: body(settings({ lifetime: { admin: '7d', account: 'never', client: 'never', service: 'never', ...(sent.lifetime || {}) } })) } })
    )
})

const mountForm = async (section = 'lifetime') => {
    const w = mount(TokenSettingsForm, { props: { section }, global: { stubs } })
    await flushPromises()
    return w
}

describe('token settings form', () => {
    it('shows the standing warning wherever "never" is selected and a recommended hint elsewhere', async () => {
        const w = await mountForm()
        expect(t(w, 'never-warning-account').exists()).toBe(true)
        expect(t(w, 'never-warning-admin').exists()).toBe(false)
        expect(t(w, 'recommended-admin').exists()).toBe(true)
        await t(w, 'lifetime-admin').setValue('1d')
        await t(w, 'lifetime-account').setValue('7d')
        expect(t(w, 'never-warning-account').exists()).toBe(false)
    })

    it('cannot save "never" without the confirmation, and keeps the warning after it', async () => {
        const w = await mountForm()
        await t(w, 'lifetime-admin').setValue('never')
        expect(t(w, 'never-dialog').exists()).toBe(true)
        await t(w, 'never-cancel').trigger('click')
        expect((t(w, 'lifetime-admin').element as HTMLSelectElement).value).toBe('7d')
        expect(t(w, 'never-warning-admin').exists()).toBe(false)

        await t(w, 'lifetime-admin').setValue('never')
        await t(w, 'never-confirm').trigger('click')
        expect(t(w, 'never-warning-admin').exists()).toBe(true)
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajaxMock.put).toHaveBeenCalledTimes(1)
        expect(ajaxMock.put.mock.calls[0][1]).toEqual({ lifetime: { admin: 'never' }, confirm_never_expire: true })
        expect(t(w, 'never-warning-admin').exists()).toBe(true)
    })

    it('sends only what changed, so saved "never" values need no new confirmation', async () => {
        const w = await mountForm()
        expect(t(w, 'settings-save').attributes('disabled')).toBeDefined()
        await t(w, 'mode-enforce').setValue(true)
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajaxMock.put.mock.calls[0][1]).toEqual({ mode: 'enforce' })
    })

    it('explains the three enforcement modes in plain words', async () => {
        const w = await mountForm()
        for (const m of ['off', 'log', 'enforce']) expect(w.text()).toContain(`text.security_tokens.mode.${m}_hint`)
    })

    it('disables "apply to existing tokens" for "never" and while there are unsaved changes', async () => {
        const w = await mountForm()
        expect(t(w, 'apply-account').attributes('disabled')).toBeDefined()
        expect(t(w, 'apply-never-hint-account').exists()).toBe(true)
        expect(t(w, 'apply-admin').attributes('disabled')).toBeUndefined()
        await t(w, 'mode-off').setValue(true)
        expect(t(w, 'apply-admin').attributes('disabled')).toBeDefined()
    })

    it('shows the counts, says older tokens stay alive, and reports already_past after applying', async () => {
        ajaxMock.post.mockImplementation((url: string) => {
            if (url === 'security/token-settings/preview')
                return Promise.resolve({ data: { data: { audiences: { admin: { live: 10, never_expiring: 4, would_expire_now: 3 } }, without_audience: 0, warnings: [] } } })
            if (url === 'security/step-up') return Promise.resolve({ data: { data: { status: 'confirmed', grant: 'g', expires_in: 300 } } })
            return Promise.resolve({ data: { data: { audience: 'admin', set: 7, already_past: 3, revoked: 0 } } })
        })
        const w = await mountForm()
        await t(w, 'apply-admin').trigger('click')
        await flushPromises()
        expect(t(w, 'apply-counts').text()).toContain('10')
        expect(t(w, 'apply-stay-alive').text()).toBe('text.security_tokens.apply.stay_alive')
        await t(w, 'apply-confirm').trigger('click')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const sent = ajaxMock.post.mock.calls.find((c) => c[0] === 'security/token-settings/apply-to-existing')
        expect(sent?.[1]).toEqual({ audience: 'admin', revoke_past: false })
        expect(sent?.[2].headers['X-Step-Up-Grant']).toBe('g')
        expect(t(w, 'apply-result-admin').text()).toContain('text.security_tokens.apply.result_past_alive')
    })

    it('sends revoke_past only when ticked', async () => {
        ajaxMock.post.mockImplementation((url: string) =>
            url === 'security/token-settings/preview'
                ? Promise.resolve({ data: { data: { audiences: { admin: { live: 1, never_expiring: 0, would_expire_now: 1 } } } } })
                : url === 'security/step-up'
                  ? Promise.resolve({ data: { data: { status: 'confirmed', grant: 'g', expires_in: 300 } } })
                  : Promise.resolve({ data: { data: { set: 0, already_past: 1, revoked: 1 } } })
        )
        const w = await mountForm()
        await t(w, 'apply-admin').trigger('click')
        await flushPromises()
        await t(w, 'apply-revoke-past').setValue(true)
        await t(w, 'apply-confirm').trigger('click')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const sent = ajaxMock.post.mock.calls.find((c) => c[0] === 'security/token-settings/apply-to-existing')
        expect(sent?.[1].revoke_past).toBe(true)
    })

    it('shows the new-device notice switch off, and saves only that field when it is ticked', async () => {
        const w = await mountForm()
        expect((t(w, 'notify-new-device').element as HTMLInputElement).checked).toBe(false)
        await t(w, 'notify-new-device').setValue(true)
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajaxMock.put.mock.calls[0][1]).toEqual({ notify_new_device: true })
    })

    it('shows the switch on when the site has it on, and keeps it out of the cleanup section', async () => {
        ajaxMock.get.mockImplementation((url: string) =>
            url === 'security/step-up/status'
                ? Promise.resolve({ data: { data: { possible: true, password_required: true, second_factor: [] } } })
                : Promise.resolve({ data: { data: body(settings({ notify_new_device: true })) } })
        )
        const w = await mountForm()
        expect((t(w, 'notify-new-device').element as HTMLInputElement).checked).toBe(true)
        expect(t(w, 'settings-save').attributes('disabled')).toBeDefined()
        const c = await mountForm('cleanup')
        expect(t(c, 'notify-new-device').exists()).toBe(false)
    })

    it('shows error with retry when the settings cannot be loaded', async () => {
        ajaxMock.get.mockRejectedValueOnce(new Error('x'))
        const w = await mountForm()
        expect(t(w, 'settings-error').exists()).toBe(true)
        await t(w, 'settings-retry').trigger('click')
        await flushPromises()
        expect(t(w, 'settings-error').exists()).toBe(false)
    })

    it('edits the cleanup days in its own section and sends only that field', async () => {
        const w = await mountForm('cleanup')
        expect(t(w, 'lifetime-admin').exists()).toBe(false)
        await t(w, 'unused-days').setValue('90')
        await w.find('form').trigger('submit')
        await flushPromises()
        expect(ajaxMock.put.mock.calls[0][1]).toEqual({ unused_days: 90 })
    })
})
