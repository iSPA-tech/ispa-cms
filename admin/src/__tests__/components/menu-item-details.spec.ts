import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MenuItemDetails from '@/components/menu-item-details.vue'
import IconPicker from '@/components/icon-picker.vue'
import {
    prepareMenuItems,
    serializeMenuItems,
    validateMenuItems,
} from '@/pages/setting/menu-editor-state'

vi.mock('@/mixins', () => ({ rule: () => true }))
vi.mock('@/libs', () => ({ searchFilterPosts: vi.fn().mockResolvedValue([]) }))
vi.mock('vue-i18n', async (importOriginal) => {
    const actual = await importOriginal<any>()
    const { createMenuTestI18n } = await import('./menu-i18n-test-support')
    const instance = createMenuTestI18n(actual.createI18n)
    return { ...actual, useI18n: () => instance }
})

const legacy = () => ({
    id: 12,
    name: 'Existing label',
    title: 'Independent title',
    type: 'category',
    url: 'saved-slug',
    target_id: 4,
    target_type: 'App\\Models\\Category',
    custom: {
        isExpandChild: true,
        prefix: { vi: 'chuyen-muc', en: 'category' },
        project_flag: true,
    },
    items: [],
})
const mountDetails = (item: any, categories: any[] = []) =>
    shallowMount(MenuItemDetails, {
        props: { item, categories },
        global: { mocks: { $t: (key: string) => key } },
    })

describe('actual menu item details compatibility', () => {
    it('writes picked icon and color into the existing custom fields without changing other settings', async () => {
        const item = prepareMenuItems([legacy()])[0]
        const wrapper = shallowMount(MenuItemDetails, {
            props: { item },
            global: {
                mocks: { $t: (key: string) => key },
                stubs: {
                    'a-select': true,
                    'a-cascader': true,
                    'a-spin': true,
                    'a-alert': true,
                    'a-switch': true,
                    'a-input-number': true,
                    'a-form-item': { template: '<div><slot /></div>' },
                    'a-input': { template: '<div><slot name="prefix" /></div>' },
                },
            },
        })
        wrapper.findComponent(IconPicker).vm.$emit('update:modelValue', 'home')
        await wrapper.get('input[type="color"]').setValue('#336699')
        const saved = serializeMenuItems([item])[0]
        expect(saved.custom).toEqual({ ...legacy().custom, icon: 'home', color: '#336699' })
        expect(saved.title).toBe('Independent title')
        wrapper.unmount()
    })

    it.each(['#abc', 'rgba(1, 2, 3, 0.5)', null])(
        'does not overwrite a legacy color when opening its picker (%s)',
        (color) => {
            const original = { ...legacy(), custom: { ...legacy().custom, color } }
            const item = prepareMenuItems([original])[0]
            const wrapper = mountDetails(item)
            expect((wrapper.vm as any).colorSwatch).toBe(color === '#abc' ? '#aabbcc' : '#000000')
            expect(serializeMenuItems([item])).toEqual([original])
            wrapper.unmount()
        }
    )
    it('shows legacy depth 2 without rewriting saved custom or localized prefix on mount', () => {
        const original = legacy()
        const item = prepareMenuItems([original])[0]
        const wrapper = mountDetails(item)
        expect((wrapper.vm as any).level).toBe(2)
        expect((wrapper.vm as any).prefix).toBe('chuyen-muc')
        expect(serializeMenuItems([item])).toEqual([original])
        wrapper.unmount()
    })

    it('derives show-all from zero and validates positive depth without a duplicate boolean', () => {
        const item = prepareMenuItems([legacy()])[0]
        const wrapper = mountDetails(item)
        ;(wrapper.vm as any).toggleAll(true)
        expect(item.custom.expandChildLevel).toBe(0)
        expect(validateMenuItems([item])).toBeNull()
        ;(wrapper.vm as any).toggleAll(false)
        expect(item.custom.expandChildLevel).toBe(2)
        item.custom.expandChildLevel = null
        expect(validateMenuItems([item])?.reason).toBe('level')
        item.custom.expandChildLevel = 1
        expect(validateMenuItems([item])).toBeNull()
        expect(Object.keys(item.custom)).not.toContain('expandAllChild')
        wrapper.unmount()
    })

    it('selects a category by label/path while preserving the independent title and project fields', () => {
        const item = prepareMenuItems([legacy()])[0]
        const categories = [
            {
                id: 1,
                name: 'A',
                children: [
                    {
                        id: 9,
                        name: 'B',
                        slug: 'current-slug',
                        route: '/category/current-slug',
                        children: [],
                    },
                ],
            },
        ]
        const wrapper = mountDetails(item, categories)
        ;(wrapper.vm as any).chooseCategory([1, 9])
        expect(item.target_id).toBe(9)
        expect(item.url).toBe('current-slug')
        expect(item.name).toBe('Existing label')
        expect(item.title).toBe('Independent title')
        expect(item.custom.project_flag).toBe(true)
        expect((wrapper.vm as any).resolvedUrl).toBe('/chuyen-muc/current-slug')
        expect(serializeMenuItems([item])[0]).not.toHaveProperty('target')
        wrapper.unmount()
    })
})
