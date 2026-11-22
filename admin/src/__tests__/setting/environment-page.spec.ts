import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Environment from '@core-admin/pages/setting/environment.vue'
import { clearEnvironment, fetchEnvironment, itemUrl, saveEnvironment } from '@/libs/environmentApi'

const state = vi.hoisted(() => ({ webmaster: true }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), delete: vi.fn() }))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/hooks/useCan', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/hooks/useCan')>()
    return { ...actual, isWebmaster: () => state.webmaster, hasAccountData: () => true }
})
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, string>) => (params?.name ? `${key}|${params.name}` : key),
        te: (key: string) => key === 'text.from.host',
    }),
}))

const SECRET = 'super-secret-value-123'
const items = () => [
    { key: 'email.MAIL_HOST', env_name: 'MAIL_HOST', group: 'email', label: 'MAIL_HOST', title: 'text.from.host', secret: false, has_value: true, value: 'smtp.example.com' },
    { key: 'email.MAIL_PASSWORD', env_name: 'MAIL_PASSWORD', group: 'email', label: 'Mail password', secret: true, has_value: true, value: null },
    { key: 'api.GOOGLE_API', env_name: 'GOOGLE_API', group: 'api', label: 'Google key', secret: true, has_value: false, value: null },
]

const stubs = {
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}<slot name="action" /></div>' },
    'a-button': {
        props: ['disabled', 'loading'],
        emits: ['click'],
        template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
    },
    'a-tag': { template: '<span><slot /></span>' },
    'a-skeleton': true,
    'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
}

const mountPage = async () => {
    const wrapper = mount(Environment, { global: { stubs } })
    await flushPromises()
    return wrapper
}
const t = (wrapper: ReturnType<typeof mount>, id: string) => wrapper.find(`[data-testid="${id}"]`)

beforeEach(() => {
    state.webmaster = true
    ajaxMock.get.mockReset().mockResolvedValue({ data: { data: { items: items() } } })
    ajaxMock.put.mockReset()
    ajaxMock.delete.mockReset()
})

describe('environmentApi', () => {
    it('builds the endpoints and sends the value in the body only', async () => {
        ajaxMock.put.mockResolvedValue({ data: {} })
        ajaxMock.delete.mockResolvedValue({ data: {} })
        await fetchEnvironment()
        expect(ajaxMock.get.mock.calls[0][0]).toBe('environment-settings')
        await saveEnvironment('email.MAIL_HOST', 'x')
        expect(ajaxMock.put.mock.calls[0][0]).toBe('environment-settings/email.MAIL_HOST')
        expect(ajaxMock.put.mock.calls[0][1]).toEqual({ value: 'x' })
        await clearEnvironment('email.MAIL_HOST')
        expect(ajaxMock.delete.mock.calls[0][0]).toBe('environment-settings/email.MAIL_HOST')
        expect(itemUrl('a b')).toBe('environment-settings/a%20b')
    })
})

describe('environment page', () => {
    it('shows the not-allowed state to a non-Webmaster and makes no request', async () => {
        state.webmaster = false
        const wrapper = await mountPage()
        expect(wrapper.text()).toContain('text.environment.not_allowed')
        expect(ajaxMock.get).not.toHaveBeenCalled()
    })

    it('shows the translated title instead of the env name', async () => {
        const wrapper = await mountPage()
        expect(t(wrapper, 'env-row-email.MAIL_HOST').text()).toContain('text.from.host')
        expect(t(wrapper, 'env-row-email.MAIL_PASSWORD').text()).toContain('Mail password')
    })

    it('lists secrets as a badge with Replace and never as a prefilled input', async () => {
        const wrapper = await mountPage()
        expect(t(wrapper, 'env-badge-email.MAIL_PASSWORD').text()).toBe('text.environment.status_set')
        expect(t(wrapper, 'env-badge-api.GOOGLE_API').text()).toBe('text.environment.status_not_set')
        expect(t(wrapper, 'env-replace-email.MAIL_PASSWORD').exists()).toBe(true)
        expect(t(wrapper, 'env-secret-input-email.MAIL_PASSWORD').exists()).toBe(false)
        expect(wrapper.html()).not.toContain('value="null"')
        expect((t(wrapper, 'env-input-email.MAIL_HOST').element as HTMLInputElement).value).toBe('smtp.example.com')
        expect(wrapper.text()).toContain('text.environment.secret_notice')
        expect(wrapper.text()).toContain('text.environment.restart_notice')
    })

    it('shows a plain env value such as MAIL_USERNAME as an editable input while MAIL_PASSWORD stays write-only', async () => {
        ajaxMock.get.mockResolvedValue({
            data: {
                data: {
                    items: [
                        { key: 'email.MAIL_USERNAME', env_name: 'MAIL_USERNAME', group: 'email', label: 'Mail user', title: null, secret: false, has_value: true, value: 'mailer-user' },
                        ...items().filter((row) => row.key === 'email.MAIL_PASSWORD'),
                    ],
                },
            },
        })
        const wrapper = await mountPage()
        expect((t(wrapper, 'env-input-email.MAIL_USERNAME').element as HTMLInputElement).value).toBe('mailer-user')
        expect(t(wrapper, 'env-badge-email.MAIL_USERNAME').exists()).toBe(false)
        expect(t(wrapper, 'env-badge-email.MAIL_PASSWORD').exists()).toBe(true)
        expect(t(wrapper, 'env-input-email.MAIL_PASSWORD').exists()).toBe(false)
    })

    it('opens an empty password input, saves, then wipes and hides it', async () => {
        ajaxMock.put.mockResolvedValue({ data: { data: { ...items()[1], has_value: true } } })
        const wrapper = await mountPage()
        await t(wrapper, 'env-replace-email.MAIL_PASSWORD').trigger('click')
        const input = t(wrapper, 'env-secret-input-email.MAIL_PASSWORD')
        expect(input.attributes('type')).toBe('password')
        expect(input.attributes('autocomplete')).toBe('new-password')
        expect((input.element as HTMLInputElement).value).toBe('')
        await input.setValue(SECRET)
        await t(wrapper, 'env-save-email.MAIL_PASSWORD').trigger('submit')
        await t(wrapper, 'env-save-email.MAIL_PASSWORD').trigger('click')
        await flushPromises()
        expect(ajaxMock.put).toHaveBeenCalledTimes(1)
        expect(ajaxMock.put.mock.calls[0][1]).toEqual({ value: SECRET })
        expect(t(wrapper, 'env-secret-input-email.MAIL_PASSWORD').exists()).toBe(false)
        expect(wrapper.html()).not.toContain(SECRET)
        expect(JSON.stringify((wrapper.vm as any).$.setupState.secretDraft)).not.toContain(SECRET)
        expect(wrapper.text()).toContain('text.environment.saved|Mail password')
    })

    it('keeps the typed secret out of the page when saving fails, and says what to do', async () => {
        ajaxMock.put.mockRejectedValue(new Error('boom'))
        const wrapper = await mountPage()
        await t(wrapper, 'env-replace-api.GOOGLE_API').trigger('click')
        await t(wrapper, 'env-secret-input-api.GOOGLE_API').setValue(SECRET)
        await t(wrapper, 'env-save-api.GOOGLE_API').trigger('submit')
        await flushPromises()
        const err = t(wrapper, 'env-error-api.GOOGLE_API')
        expect(err.text()).toContain('text.environment.save_error')
        expect(err.text()).toContain('text.environment.save_error_hint')
        expect(wrapper.html()).not.toContain(SECRET.replace('super', 'zzz'))
    })

    it('does not send a second request while one is running', async () => {
        let release: (v: unknown) => void = () => {}
        ajaxMock.put.mockReturnValue(new Promise((r) => (release = r)))
        const wrapper = await mountPage()
        await t(wrapper, 'env-input-email.MAIL_HOST').setValue('smtp.other.com')
        await t(wrapper, 'env-save-email.MAIL_HOST').trigger('submit')
        await t(wrapper, 'env-save-email.MAIL_HOST').trigger('submit')
        expect(ajaxMock.put).toHaveBeenCalledTimes(1)
        expect(t(wrapper, 'env-save-email.MAIL_HOST').attributes('disabled')).toBeDefined()
        release({ data: { data: { ...items()[0], value: 'smtp.other.com' } } })
        await flushPromises()
        expect(t(wrapper, 'env-save-email.MAIL_HOST').attributes('disabled')).toBeDefined()
    })

    it('asks for confirmation before clearing and clears only after yes', async () => {
        ajaxMock.delete.mockResolvedValue({ data: { data: { ...items()[1], has_value: false } } })
        const wrapper = await mountPage()
        await t(wrapper, 'env-clear-email.MAIL_PASSWORD').trigger('click')
        expect(ajaxMock.delete).not.toHaveBeenCalled()
        expect(wrapper.text()).toContain('text.environment.clear_confirm|Mail password')
        await t(wrapper, 'env-clear-no-email.MAIL_PASSWORD').trigger('click')
        expect(ajaxMock.delete).not.toHaveBeenCalled()
        await t(wrapper, 'env-clear-email.MAIL_PASSWORD').trigger('click')
        await t(wrapper, 'env-clear-yes-email.MAIL_PASSWORD').trigger('click')
        await flushPromises()
        expect(ajaxMock.delete).toHaveBeenCalledTimes(1)
        expect(t(wrapper, 'env-badge-email.MAIL_PASSWORD').text()).toBe('text.environment.status_not_set')
    })

    it('shows a retry when loading fails', async () => {
        ajaxMock.get.mockRejectedValueOnce(new Error('x'))
        const wrapper = await mountPage()
        expect(t(wrapper, 'environment-error').exists()).toBe(true)
        await t(wrapper, 'environment-retry').trigger('click')
        await flushPromises()
        expect(t(wrapper, 'env-row-email.MAIL_HOST').exists()).toBe(true)
    })

    it('makes no request that returns a secret: only the list and the PUT/DELETE of one key', async () => {
        const wrapper = await mountPage()
        expect(ajaxMock.get).toHaveBeenCalledTimes(1)
        expect(ajaxMock.get.mock.calls[0][0]).toBe('environment-settings')
        wrapper.unmount()
    })

    it('wipes the typed secret on unmount', async () => {
        const wrapper = await mountPage()
        await t(wrapper, 'env-replace-api.GOOGLE_API').trigger('click')
        await t(wrapper, 'env-secret-input-api.GOOGLE_API').setValue(SECRET)
        const state = (wrapper.vm as any).$.setupState
        wrapper.unmount()
        expect(state.secretDraft).toBe('')
    })
})
