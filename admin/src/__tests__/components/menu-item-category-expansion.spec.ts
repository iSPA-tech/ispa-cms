import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MenuItemV2 from '@/components/menu-item-v2.vue'

vi.mock('@/mixins', () => ({
    rule: () => true,
    ucfirst: (value: string) => value.charAt(0).toUpperCase() + value.slice(1),
}))

const SwitchStub = {
    props: ['checked'],
    emits: ['update:checked', 'change'],
    template: `
        <button
            type="button"
            @click="$emit('update:checked', !checked); $emit('change', !checked)"
        ><slot /></button>
    `,
}

const mountItem = (custom: Record<string, unknown>) =>
    shallowMount(MenuItemV2, {
        props: {
            element: {
                id: 1,
                name: 'Danh mục A',
                title: 'Danh mục A',
                type: 'category',
                url: 'danh-muc-a',
                custom,
                items: [],
                isShow: true,
            },
            index: 0,
            name: ['items', 0],
        },
        global: {
            mocks: {
                $t: (key: string) => key,
            },
            stubs: {
                'a-form-item': { template: '<div><slot /></div>' },
                'a-input': true,
                'a-input-number': true,
                'a-popconfirm': { template: '<div><slot /></div>' },
                'a-radio': { template: '<div><slot /></div>' },
                'a-radio-group': { template: '<div><slot /></div>' },
                'a-select': true,
                'a-switch': SwitchStub,
                'a-tooltip': { template: '<div><slot /></div>' },
                draggable: { template: '<div><slot name="item" /></div>' },
                'i-icon': true,
                'input-category': true,
                'menu-item-v2': true,
                'select-input-post': true,
                transition: false,
            },
        },
    })

describe('MenuItemV2 category expansion', () => {
    it('hides all descendant controls until child display is enabled', async () => {
        const wrapper = mountItem({ isExpandChild: false, expandChildLevel: 2 })

        expect(wrapper.find('[data-testid="menu-expand-all-child"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="menu-expand-child-level"]').exists()).toBe(false)

        await wrapper.get('[data-testid="menu-expand-child"]').trigger('click')

        expect(wrapper.get('[data-testid="menu-expand-all-child"]').exists()).toBe(true)
        expect(wrapper.get('[data-testid="menu-expand-child-level"]').exists()).toBe(true)
    })

    it('uses legacy level 2, toggles all descendants to level 0, then restores a positive level', async () => {
        const wrapper = mountItem({ isExpandChild: true })

        expect(wrapper.props('element').custom.expandChildLevel).toBe(2)
        expect(wrapper.get('[data-testid="menu-expand-child-level"]').exists()).toBe(true)

        await wrapper.get('[data-testid="menu-expand-all-child"]').trigger('click')

        expect(wrapper.props('element').custom.expandChildLevel).toBe(0)
        expect(wrapper.find('[data-testid="menu-expand-child-level"]').exists()).toBe(false)

        await wrapper.get('[data-testid="menu-expand-all-child"]').trigger('click')

        expect(wrapper.props('element').custom.expandChildLevel).toBe(2)
        expect(wrapper.get('[data-testid="menu-expand-child-level"]').exists()).toBe(true)
    })
})
