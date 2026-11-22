import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SelectInputUserRole from '@/components/select-input-user-role.vue'

vi.mock('@/libs', () => ({
    searchFilterUserRoles: vi.fn().mockResolvedValue([{ id: 1, name: 'Admin' }]),
}))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
}))

const mountComponent = (props = {}) =>
    mount(SelectInputUserRole, {
        props,
        global: {
            mocks: {
                $t: (key) => key,
            },
            stubs: {
                'a-form-item': {
                    template: '<div><slot /></div>',
                    props: ['label', 'name', 'noStyle', 'rules'],
                },
                'a-select': {
                    name: 'ASelect',
                    template: '<select />',
                    props: [
                        'value',
                        'filterOption',
                        'mode',
                        'notFoundContent',
                        'options',
                        'placeholder',
                        'allowClear',
                        'showSearch',
                    ],
                    emits: ['update:value', 'search'],
                },
            },
        },
    })

describe('SelectInputUserRole', () => {
    it('uses multiple select mode instead of free-form tags for role ids', async () => {
        const wrapper = mountComponent({ modelValue: [], multiple: true })

        await flushPromises()

        expect(wrapper.findComponent({ name: 'ASelect' }).props('mode')).toBe('multiple')
    })

    it('does not enable free-form tags for single role selection', async () => {
        const wrapper = mountComponent({ modelValue: null })

        await flushPromises()

        expect(wrapper.findComponent({ name: 'ASelect' }).props('mode')).toBeUndefined()
    })
})
