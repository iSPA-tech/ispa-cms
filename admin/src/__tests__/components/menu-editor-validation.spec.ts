import { flushPromises, mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import { reactive } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import MenuEditor from '@/components/menu-editor.vue'
import MenuItemDetails from '@/components/menu-item-details.vue'
import { prepareMenuItems } from '@/pages/setting/menu-editor-state'

vi.mock('@/mixins', () => ({ rule: () => true }))
vi.mock('@/libs', () => ({ searchFilterPosts: vi.fn().mockResolvedValue([]) }))
vi.mock('vue-router', () => ({ onBeforeRouteLeave: vi.fn() }))
vi.mock('vue-i18n', async (importOriginal) => {
    const actual = await importOriginal<any>()
    const { createMenuTestI18n } = await import('./menu-i18n-test-support')
    const instance = createMenuTestI18n(actual.createI18n)
    return { ...actual, useI18n: () => instance }
})

const node = (id: number, name: string, url: string, items: any[] = []) => ({
    id,
    name,
    title: name,
    url,
    type: 'url',
    custom: {},
    items,
})
const formFixture = () =>
    reactive({
        id: 7,
        name: 'Main menu',
        group_key: 'main_menu',
        language_id: 1,
        status: 'status_published',
        items: prepareMenuItems([
            node(1, 'Trang chủ', '/', [node(2, '', '', [node(3, '', '')])]),
            node(4, 'Liên hệ', '/contact'),
        ]),
    })
const mountEditor = (form: any) =>
    mount(MenuEditor, {
        props: { form },
        attachTo: document.body,
        global: {
            plugins: [Antd],
            mocks: { $t: (key: string) => key },
        },
    })

describe('menu validation error navigation', () => {
    it('shows unique item paths, selects a collapsed deep error, and clears corrected errors reactively', async () => {
        const form = formFixture()
        const wrapper = mountEditor(form)
        expect(wrapper.find('[data-menu-error-summary]').exists()).toBe(false)
        ;(wrapper.vm as any).expanded = []
        await (wrapper.vm as any).submit()
        await flushPromises()
        expect(wrapper.emitted('save')).toBeUndefined()
        const summary = wrapper.get('[data-menu-error-summary]')
        expect(summary.text()).toContain('1.1')
        expect(summary.text()).toContain('1.1.1')
        expect(summary.text()).toContain('Trang chủ')
        expect(summary.text()).toContain('Tên hiển thị')
        expect(summary.text()).toContain('Đường dẫn')
        expect(document.activeElement).toBe(summary.element)
        await summary.findAll('button')[1].trigger('click')
        await flushPromises()
        expect(wrapper.findComponent(MenuItemDetails).props('item').id).toBe(3)
        expect((wrapper.vm as any).expanded).toEqual(expect.arrayContaining(['item-1', 'item-2']))
        expect(wrapper.findComponent(MenuItemDetails).props('errors')).toEqual({
            name: 'Nhập Tên hiển thị.',
            link: 'Nhập Đường dẫn.',
        })
        const nameInput = wrapper.get('input[aria-label="Tên hiển thị"]')
        expect(document.activeElement).toBe(nameInput.element)
        expect(nameInput.attributes('aria-invalid')).toBe('true')
        expect(wrapper.get(`#${nameInput.attributes('aria-describedby')}`).text()).toBe(
            'Nhập Tên hiển thị.'
        )
        expect(wrapper.findAll('.menu-node-error')).toHaveLength(2)
        form.items[0].items[0].name = 'Services'
        form.items[0].items[0].url = '/services'
        form.items[0].items[0].items[0].name = 'Support'
        form.items[0].items[0].items[0].url = '/support'
        await flushPromises()
        expect(wrapper.find('[data-menu-error-summary]').exists()).toBe(false)
        expect(nameInput.attributes('aria-invalid')).toBe('false')
        expect(wrapper.findAll('.menu-node-error')).toHaveLength(0)
        await (wrapper.vm as any).submit()
        expect(wrapper.emitted('save')).toHaveLength(1)
        wrapper.unmount()
    })
})
