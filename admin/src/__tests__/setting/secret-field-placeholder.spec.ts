import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SettingItem from '@/pages/setting/setting-item.vue'
import ajax from '@/ajax'

vi.mock('@/hooks', () => ({
    useSettings: () => ({ data: { value: { data: [], template: ['default'] } } }),
}))

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({ fetchAndCacheGlobalSettings: vi.fn(() => Promise.resolve()) }),
}))

vi.mock('@/mixins', () => ({
    rule: () => false,
}))

vi.mock('@/ajax', () => ({
    default: { put: vi.fn(() => Promise.resolve({ data: {} })) },
}))

const PLACEHOLDER_KEY = 'text.setting.secret_value_set_placeholder'

const inputStub = {
    props: ['value', 'placeholder', 'modelValue'],
    emits: ['update:value', 'change'],
    template:
        '<input :value="value" :placeholder="placeholder" @input="$emit(\'update:value\', $event.target.value); $emit(\'change\', $event)" />',
}
const fieldInputStub = {
    props: ['modelValue', 'setting'],
    template: '<input data-testid="field-input" :value="modelValue" />',
}
const fieldSelectStub = {
    props: ['modelValue', 'options'],
    template: '<div data-testid="field-select" :data-value="String(modelValue)" />',
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
                FieldInput: fieldInputStub,
                FieldSelect: fieldSelectStub,
                FieldText: true,
                FieldBoolean: true,
                FieldImage: true,
                IIcon: true,
            },
        },
    })

describe('setting-item secret fields', () => {
    it('shows the "value is set" placeholder and an empty input for a masked secret', async () => {
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        const input = wrapper.get('[data-testid="setting-secret-input"]')
        expect(input.attributes('placeholder')).toBe(PLACEHOLDER_KEY)
        expect((input.element as HTMLInputElement).value).toBe('')
        expect(wrapper.find('[data-testid="field-input"]').exists()).toBe(false)

        // The form never gets the mask back: an untouched field submits '' (kept by the backend).
        const emitted = wrapper.emitted('update:modelValue') ?? []
        expect(emitted.flat()).not.toContain('********')
    })

    it('emits the typed replacement value for a masked secret', async () => {
        const wrapper = mountItem(settingFixture(), { modelValue: '********' })
        await nextTick()

        await wrapper.get('[data-testid="setting-secret-input"]').setValue('new-password')

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['new-password'])
    })

    it('quick-syncs a masked secret only after a new value is typed', async () => {
        vi.useFakeTimers()
        try {
            const wrapper = mountItem(settingFixture(), { isImmediateSync: true })
            await nextTick()
            const input = wrapper.get('[data-testid="setting-secret-input"]')

            await input.setValue('')
            vi.advanceTimersByTime(400)
            expect(ajax.put).not.toHaveBeenCalled()

            await input.setValue('rotated-token')
            vi.advanceTimersByTime(400)
            expect(ajax.put).toHaveBeenCalledWith('setting/7/quick', {
                value: 'rotated-token',
                type: 'password',
            })
        } finally {
            vi.useRealTimers()
        }
    })

    it('keeps a masked select empty and explains that a value is saved', async () => {
        const wrapper = mountItem(
            settingFixture({
                name: 'LOGGER_PROVIDER',
                setting_key: 'notify.LOGGER_PROVIDER',
                type: 'select',
                input_setting: {
                    title: 'text.s.logger',
                    description: '',
                    required: false,
                    options: [{ value: 'telegram', label: 'Telegram' }],
                },
            }),
            { modelValue: '********' }
        )
        await nextTick()

        expect(wrapper.get('[data-testid="field-select"]').attributes('data-value')).toBe('')
        expect(wrapper.get('[data-testid="setting-secret-hint"]').text()).toBe(PLACEHOLDER_KEY)
        expect(wrapper.find('[data-testid="setting-secret-input"]').exists()).toBe(false)
    })

    it('shows a non-masked value as before', async () => {
        const wrapper = mountItem(
            settingFixture({
                name: 'MAIL_HOST',
                setting_key: 'email.MAIL_HOST',
                type: 'text',
                value: 'smtp.example.test',
                has_value: undefined,
            }),
            { modelValue: 'smtp.example.test' }
        )
        await nextTick()

        expect(wrapper.find('[data-testid="setting-secret-input"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="setting-secret-hint"]').exists()).toBe(false)
        expect(
            (wrapper.get('[data-testid="field-input"]').element as HTMLInputElement).value
        ).toBe('smtp.example.test')
    })
})
