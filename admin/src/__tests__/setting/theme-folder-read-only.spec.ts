import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SettingItem from '@/pages/setting/setting-item.vue'

// The theme folder (VITE_TEMPLATE_FOLDER) is read when the site is built: the admin shows it read-only and the
// backend skips it on save; it changes with `php artisan cms:env-set` and a deploy.

vi.mock('@/hooks', () => ({
    useSettings: () => ({ data: { value: { data: [], template: ['Default', 'Arc'] } } }),
}))

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({ fetchAndCacheGlobalSettings: vi.fn(() => Promise.resolve()) }),
}))

vi.mock('@/mixins', () => ({
    rule: () => true,
}))

vi.mock('@/ajax', () => ({
    default: { put: vi.fn(() => Promise.resolve({ data: {} })) },
}))

vi.mock('ant-design-vue', () => ({
    message: { success: vi.fn(), error: vi.fn() },
}))

const HINT = '[data-testid="setting-theme-read-only"]'

const item = (name: string, options: unknown[] = []) => ({
    id: 3,
    group_name: 'advanced',
    name,
    setting_key: `advanced.${name}`,
    type: 'text',
    value: 'Default',
    language: null,
    input_setting: { title: 'text.s.template', description: '', required: false, options },
})

const mountItem = (setting: Record<string, unknown>) =>
    mount(SettingItem, {
        props: { item: setting, modelValue: 'Default' },
        global: {
            stubs: {
                FieldSelect: {
                    props: ['disabled'],
                    template: '<div data-testid="field-select" :data-disabled="String(disabled)" />',
                },
                FieldInput: true,
                FieldText: true,
                FieldBoolean: true,
                FieldImage: true,
                APopconfirm: true,
                AButton: true,
                AInputPassword: true,
                IIcon: true,
            },
        },
    })

describe('setting-item theme folder', () => {
    it('shows the theme folder read-only with the reason', async () => {
        const wrapper = mountItem(item('VITE_TEMPLATE_FOLDER'))
        await nextTick()

        expect(wrapper.get('[data-testid="field-select"]').attributes('data-disabled')).toBe('true')
        expect(wrapper.get(HINT).text()).toBe('text.setting.theme_read_only')
    })

    it('keeps other select settings editable without the hint', async () => {
        const wrapper = mountItem(item('LOGGER_PROVIDER', [{ value: 'telegram', label: 'Telegram' }]))
        await nextTick()

        expect(wrapper.get('[data-testid="field-select"]').attributes('data-disabled')).toBe('false')
        expect(wrapper.find(HINT).exists()).toBe(false)
    })
})
