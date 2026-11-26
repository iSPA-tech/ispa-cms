import { nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SettingItem from '@/pages/setting/setting-item.vue'
import ajax from '@/ajax'
import { message } from 'ant-design-vue'

const state = vi.hoisted(() => ({ canUpdate: true }))

vi.mock('@/hooks', () => ({
    useSettings: () => ({ data: { value: { data: [], template: ['default'] } } }),
}))

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({ fetchAndCacheGlobalSettings: vi.fn(() => Promise.resolve()) }),
}))

vi.mock('@/mixins', () => ({
    rule: () => state.canUpdate,
}))

vi.mock('@/ajax', () => ({
    default: { put: vi.fn(() => Promise.resolve({ data: {} })) },
}))

vi.mock('ant-design-vue', () => ({
    message: { success: vi.fn(), error: vi.fn() },
}))

const PLACEHOLDER_KEY = 'text.setting.secret_value_set_placeholder'
const CLEAR = '[data-testid="setting-secret-clear"]'
const CONFIRM = '[data-testid="popconfirm-ok"]'

const inputStub = {
    props: ['value', 'placeholder'],
    emits: ['update:value', 'change'],
    template:
        '<input :value="value" :placeholder="placeholder" @input="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event)" />',
}
// The confirmation is inline: the action itself never calls the API, only the popconfirm's OK does.
const popconfirmStub = {
    props: ['title', 'okText', 'disabled'],
    emits: ['confirm'],
    template:
        '<span><slot /><span data-testid="popconfirm-title">{{ title }}</span><button data-testid="popconfirm-ok" :disabled="disabled" @click="$emit(\'confirm\')">{{ okText }}</button></span>',
}
const buttonStub = {
    props: ['disabled'],
    template: '<button :disabled="disabled"><slot /></button>',
}

const settingFixture = (overrides: Record<string, unknown> = {}) => ({
    id: 7,
    group_name: 'email',
    name: 'MAIL_PASSWORD',
    setting_key: 'email.MAIL_PASSWORD',
    type: 'password',
    value: '********',
    has_value: true,
    language: null,
    input_setting: {
        title: 'text.s.mail_password',
        description: 'text.s.mail_password-desc',
        required: false,
        options: [],
    },
    ...overrides,
})

const mountItem = (item: Record<string, unknown>, props: Record<string, unknown> = {}) =>
    mount(SettingItem, {
        props: { item, ...props },
        global: {
            stubs: {
                AInputPassword: inputStub,
                APopconfirm: popconfirmStub,
                AButton: buttonStub,
                FieldInput: { template: '<input data-testid="field-input" />' },
                FieldSelect: { template: '<div data-testid="field-select" />' },
                FieldText: true,
                FieldBoolean: true,
                FieldImage: true,
                IIcon: true,
            },
        },
    })

describe('setting-item clear a stored secret', () => {
    beforeEach(() => {
        state.canUpdate = true
        vi.mocked(ajax.put).mockReset()
        vi.mocked(ajax.put).mockResolvedValue({ data: {} })
        vi.mocked(message.success).mockReset()
        vi.mocked(message.error).mockReset()
    })

    it('offers the clear action with a confirmation for a stored secret', async () => {
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        expect(wrapper.get(CLEAR).text()).toBe('text.setting.secret_clear')
        expect(wrapper.get('[data-testid="popconfirm-title"]').text()).toBe('text.setting.secret_clear_confirm')

        // Clicking the action alone only opens the confirmation.
        await wrapper.get(CLEAR).trigger('click')
        expect(ajax.put).not.toHaveBeenCalled()
    })

    it('calls the API with clear, empties the field and drops the "value is set" placeholder', async () => {
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        await wrapper.get(CONFIRM).trigger('click')
        await flushPromises()

        expect(ajax.put).toHaveBeenCalledTimes(1)
        expect(ajax.put).toHaveBeenCalledWith('setting/7/quick', { clear: true })

        const input = wrapper.get('[data-testid="setting-secret-input"]')
        expect((input.element as HTMLInputElement).value).toBe('')
        expect(input.attributes('placeholder')).toBeUndefined()
        expect(wrapper.html()).not.toContain(PLACEHOLDER_KEY)
        expect(wrapper.find(CLEAR).exists()).toBe(false)
        expect(message.success).toHaveBeenCalledWith('text.setting.secret_cleared')
    })

    it('sends one request when the confirmation is clicked twice', async () => {
        let release: (value: unknown) => void = () => {}
        vi.mocked(ajax.put).mockReturnValue(new Promise((resolve) => (release = resolve)) as never)
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        await wrapper.get(CONFIRM).trigger('click')
        expect((wrapper.get(CLEAR).element as HTMLButtonElement).disabled).toBe(true)
        wrapper.getComponent(popconfirmStub).vm.$emit('confirm')
        release({ data: {} })
        await flushPromises()

        expect(ajax.put).toHaveBeenCalledTimes(1)
    })

    it('keeps the stored-value state and the action usable when the request fails', async () => {
        vi.mocked(ajax.put).mockRejectedValue({ reported: true })
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        await wrapper.get(CONFIRM).trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-testid="setting-secret-input"]').attributes('placeholder')).toBe(PLACEHOLDER_KEY)
        expect((wrapper.get(CLEAR).element as HTMLButtonElement).disabled).toBe(false)
        expect(message.success).not.toHaveBeenCalled()
        // The ajax layer already reported it.
        expect(message.error).not.toHaveBeenCalled()
    })

    it('shows the action for a masked select too', async () => {
        const wrapper = mountItem(
            settingFixture({
                name: 'LOGGER_PROVIDER',
                setting_key: 'notify.LOGGER_PROVIDER',
                type: 'select',
                input_setting: { title: 't', description: '', required: false, options: [{ value: 'a', label: 'A' }] },
            }),
            { modelValue: '********' }
        )
        await nextTick()

        expect(wrapper.find(CLEAR).exists()).toBe(true)
        await wrapper.get(CONFIRM).trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="setting-secret-hint"]').exists()).toBe(false)
    })

    it('does not show the action for a plain field or a secret with no stored value', async () => {
        const plain = mountItem(
            settingFixture({ name: 'MAIL_HOST', setting_key: 'email.MAIL_HOST', type: 'text', value: 'smtp.example.test', has_value: undefined }),
            { modelValue: 'smtp.example.test' }
        )
        const emptySecret = mountItem(settingFixture({ value: '', has_value: false }), { modelValue: '' })
        await nextTick()

        expect(plain.find(CLEAR).exists()).toBe(false)
        expect(emptySecret.find(CLEAR).exists()).toBe(false)
    })

    it('does not show the action to someone who cannot update settings', async () => {
        state.canUpdate = false
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        expect(wrapper.find(CLEAR).exists()).toBe(false)
    })
})
