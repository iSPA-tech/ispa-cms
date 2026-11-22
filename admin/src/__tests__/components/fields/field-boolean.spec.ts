import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FieldBoolean from '@/components/fields/field-boolean.vue'

const SwitchStub = {
    props: ['checked'],
    template: '<div data-testid="switch" :data-checked="String(checked)" />',
}

describe('FieldBoolean', () => {
    it('syncs local switch state when parent modelValue changes', async () => {
        const wrapper = mount(FieldBoolean, {
            props: {
                modelValue: false,
                name: 'order.enable',
                setting: {
                    input_setting: {
                        title: 'setting.order.enable',
                    },
                },
            },
            global: {
                mocks: {
                    $t: (key: string) => key,
                },
                stubs: {
                    'a-form-item': { template: '<div><slot /></div>' },
                    'a-switch': SwitchStub,
                },
            },
        })

        expect(wrapper.get('[data-testid="switch"]').attributes('data-checked')).toBe('false')

        await wrapper.setProps({ modelValue: true })
        await nextTick()

        expect(wrapper.get('[data-testid="switch"]').attributes('data-checked')).toBe('true')
    })
})
