import { flushPromises, mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useI18n } from 'vue-i18n'
import MenuItemDetails from '@/components/menu-item-details.vue'
import { prepareMenuItems } from '@/pages/setting/menu-editor-state'
import { menuMessages } from './menu-i18n-test-support'

vi.mock('@/mixins', () => ({ rule: () => true }))
vi.mock('@/libs', () => ({ searchFilterPosts: vi.fn().mockResolvedValue([]) }))
vi.mock('vue-i18n', async (importOriginal) => {
    const actual = await importOriginal<any>()
    const { createMenuTestI18n } = await import('./menu-i18n-test-support')
    const instance = createMenuTestI18n(actual.createI18n)
    return { ...actual, useI18n: () => instance }
})

afterEach(() => {
    useI18n().locale.value = 'vi'
})
describe('canonical menu admin i18n', () => {
    it('renders category expansion labels in Vietnamese and switches them to English', async () => {
        const item = prepareMenuItems([
            {
                id: 1,
                type: 'category',
                name: 'A',
                title: 'A',
                url: 'a',
                custom: { isExpandChild: true, expandChildLevel: 0 },
                items: [],
            },
        ])[0]
        const wrapper = mount(MenuItemDetails, {
            props: { item },
            global: {
                plugins: [Antd],
                mocks: { $t: (...args: any[]) => (useI18n().t as any)(...args) },
            },
        })
        expect(wrapper.text()).toContain('Hiển thị tất cả danh mục con?')
        expect(wrapper.text()).toContain('Mở trong tab mới')
        expect(wrapper.text()).not.toContain('Menu Expand All Child')
        useI18n().locale.value = 'en'
        await flushPromises()
        expect(wrapper.text()).toContain('Show all child category levels?')
        expect(wrapper.text()).toContain('Open in a new tab')
        wrapper.unmount()
    })

    it('resolves named validation parameters in both canonical locales without a fallback', () => {
        const key = 'text.menu_editor.validation-summary'
        expect(menuMessages.vi[key]).toBe('Có {count} mục cần sửa trước khi lưu.')
        expect(menuMessages.en[key]).toBe('{count} items need correction before saving.')
        expect(useI18n().t(key, { count: 3 })).toBe('Có 3 mục cần sửa trước khi lưu.')
        useI18n().locale.value = 'en'
        expect(useI18n().t(key, { count: 3 })).toBe('3 items need correction before saving.')
    })
})
