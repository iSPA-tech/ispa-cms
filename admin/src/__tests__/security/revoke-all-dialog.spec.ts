import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RevokeAllDialog from '@core-admin/components/security/revoke-all-dialog.vue'
import { dropGrant } from '@/libs/securityTokens'
import { stubs } from './_stubs'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn(), put: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', async () => (await import('./_stubs')).i18nMock)

const t = (w: ReturnType<typeof mount>, id: string) => w.find(`[data-testid="${id}"]`)
const preview = {
    audiences: { admin: { live: 3 }, account: { live: 2 }, client: { live: 0 }, service: { live: 0 } },
    without_audience: 4,
}

beforeEach(() => {
    dropGrant()
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    ajaxMock.get.mockResolvedValue({ data: { data: { possible: true, password_required: true, second_factor: [], locked: false } } })
    ajaxMock.post.mockImplementation((url: string) => {
        if (url === 'security/token-settings/preview') return Promise.resolve({ data: { data: preview } })
        if (url === 'security/step-up') return Promise.resolve({ data: { data: { status: 'confirmed', grant: 'g-9', expires_in: 300 } } })
        return Promise.resolve({ data: { data: { revoked: 9 } } })
    })
})

const openIt = async (props: Record<string, unknown> = { scope: 'all' }) => {
    const w = mount(RevokeAllDialog, { props, global: { stubs } })
    await t(w, 'revoke-all-open').trigger('click')
    await flushPromises()
    return w
}

describe('revoke-all dialog', () => {
    it('shows how many tokens and which APIs it ends, and says the session in use stays', async () => {
        const w = await openIt()
        expect(t(w, 'revoke-all-total').text()).toContain('9')
        const summary = t(w, 'revoke-all-summary').text()
        expect(summary).toContain('text.security_tokens.audience.admin: 3')
        expect(summary).toContain('text.security_tokens.audience.account: 2')
        expect(summary).toContain('text.security_tokens.audience.legacy: 4')
        expect(w.text()).toContain('text.security_tokens.revoke_all.spared')
    })

    it('keeps the confirm button disabled until the typed word is filled', async () => {
        const w = await openIt()
        expect(t(w, 'revoke-all-confirm').attributes('disabled')).toBeDefined()
        await t(w, 'revoke-all-word').setValue('revoke')
        expect(t(w, 'revoke-all-confirm').attributes('disabled')).toBeDefined()
        await t(w, 'revoke-all-word').setValue('REVOKE')
        expect(t(w, 'revoke-all-confirm').attributes('disabled')).toBeUndefined()
    })

    it('asks for the step-up confirmation, then sends once even on a double click', async () => {
        const w = await openIt()
        await t(w, 'revoke-all-word').setValue('REVOKE')
        const form = w.find('form')
        await form.trigger('submit')
        await form.trigger('submit')
        await flushPromises()
        // The confirmation form is open and nothing was revoked yet.
        expect(ajaxMock.post.mock.calls.some((c) => c[0] === 'security/tokens/revoke-all')).toBe(false)
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const sent = ajaxMock.post.mock.calls.filter((c) => c[0] === 'security/tokens/revoke-all')
        expect(sent).toHaveLength(1)
        expect(sent[0][1]).toEqual({ confirm: 'REVOKE' })
        expect(sent[0][2].headers['X-Step-Up-Grant']).toBe('g-9')
        expect(w.emitted('done')?.[0]).toEqual([{ revoked: 9 }])
    })

    it('sends no keep-current switch', async () => {
        const w = await openIt()
        await t(w, 'revoke-all-word').setValue('REVOKE')
        await w.find('form').trigger('submit')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const body = ajaxMock.post.mock.calls.find((c) => c[0] === 'security/tokens/revoke-all')?.[1]
        expect(Object.keys(body)).toEqual(['confirm'])
    })

    it('revokes one API with the audience in the body', async () => {
        const w = await openIt({ scope: 'audience', audience: 'client' })
        await t(w, 'revoke-all-word').setValue('REVOKE')
        await w.find('form').trigger('submit')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const call = ajaxMock.post.mock.calls.find((c) => c[0] === 'security/tokens/revoke-audience')
        expect(call?.[1]).toEqual({ audience: 'client', confirm: 'REVOKE' })
    })

    it('revokes one account with its uuid and the count it was given', async () => {
        const w = await openIt({ scope: 'user', userUuid: 'user-1', count: 5 })
        expect(t(w, 'revoke-all-total').text()).toContain('5')
        await t(w, 'revoke-all-word').setValue('REVOKE')
        await w.find('form').trigger('submit')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        const call = ajaxMock.post.mock.calls.find((c) => c[0] === 'security/tokens/revoke-user')
        expect(call?.[1]).toEqual({ user: 'user-1', confirm: 'REVOKE' })
    })

    it('shows an error and stays open when the revoke fails', async () => {
        ajaxMock.post.mockImplementation((url: string) =>
            url === 'security/step-up'
                ? Promise.resolve({ data: { data: { status: 'confirmed', grant: 'g', expires_in: 300 } } })
                : url === 'security/token-settings/preview'
                  ? Promise.resolve({ data: { data: preview } })
                  : Promise.reject({ response: { status: 500, data: {} } })
        )
        const w = await openIt()
        await t(w, 'revoke-all-word').setValue('REVOKE')
        await w.find('form').trigger('submit')
        await flushPromises()
        await t(w, 'stepup-password').setValue('pw')
        await t(w, 'stepup-password-form').trigger('submit')
        await flushPromises()
        expect(t(w, 'revoke-all-error').exists()).toBe(true)
        expect(w.emitted('done')).toBeUndefined()
    })
})
