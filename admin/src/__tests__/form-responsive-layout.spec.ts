import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormResponsiveLayout from '@/components/form-responsive-layout.vue'

const FormItemStub = defineComponent({
    name: 'AFormItem',
    props: ['label', 'htmlFor'],
    template: '<div class="form-item"><slot /></div>',
})

const SelectStub = defineComponent({
    name: 'ASelect',
    props: ['value'],
    emits: ['change'],
    template: '<button type="button" @click="$emit(\'change\', 2)"><slot />{{ value }}</button>',
})

const SelectOptionStub = defineComponent({
    name: 'ASelectOption',
    props: ['value'],
    template: '<span><slot /></span>',
})

const mountLayout = (form: Record<string, any>, item?: Record<string, any>) =>
    mount(FormResponsiveLayout, {
        props: { form, item },
        global: {
            mocks: { $t: (key: string, values?: Record<string, number>) => values?.count ? `${values.count} cột` : key },
            stubs: {
                'a-form-item': FormItemStub,
                'a-select': SelectStub,
                'a-select-option': SelectOptionStub,
            },
        },
    })

describe('form responsive layout', () => {
    it('shows one-column defaults without materializing form settings on inspection', () => {
        const form = { custom: { retained: 'yes' } }
        const wrapper = mountLayout(form)

        expect(wrapper.classes()).toEqual(expect.arrayContaining(['grid', 'lg:grid-cols-2']))
        expect(wrapper.find('[data-responsive-setting="columns.mobile"]').findComponent(SelectStub).props('value')).toBe(1)
        expect(wrapper.find('[data-responsive-setting="columns.tablet"]').findComponent(SelectStub).props('value')).toBe(1)
        expect(wrapper.find('[data-responsive-setting="columns.desktop"]').findComponent(SelectStub).props('value')).toBe(1)
        expect(wrapper.text()).toContain('1 cột')
        expect(wrapper.text()).toContain('2 cột')
        expect(wrapper.text()).toContain('3 cột')
        expect(wrapper.find('#responsive-layout-form-mobile').exists()).toBe(true)
        expect(wrapper.findComponent(FormItemStub).props('htmlFor')).toBe('responsive-layout-form-mobile')
        expect(form).toEqual({ custom: { retained: 'yes' } })
    })

    it('materializes only the changed form profile and preserves unknown metadata', async () => {
        const form = {
            custom: {
                retained: 'yes',
                layout: { retainedLayout: 'yes', columns: { desktop: 3, retainedColumn: 9 } },
            },
        }
        const wrapper = mountLayout(form)

        await wrapper.find('[data-responsive-setting="columns.mobile"] button').trigger('click')

        expect(form.custom).toMatchObject({
            retained: 'yes',
            layout: {
                retainedLayout: 'yes',
                columns: { mobile: 2, desktop: 3, retainedColumn: 9 },
            },
        })
    })

    it('shows auto item spans and preserves unknown item metadata when changed', async () => {
        const form = { custom: {} }
        const item = { custom: { retained: 'yes', layout: { retainedLayout: 'yes' } } }
        const wrapper = mountLayout(form, item)

        expect(wrapper.find('[data-responsive-setting="span.mobile"]').findComponent(SelectStub).props('value')).toBe('auto')
        expect(wrapper.find('[data-responsive-setting="span.tablet"]').findComponent(SelectStub).props('value')).toBe('auto')
        expect(wrapper.find('[data-responsive-setting="span.desktop"]').findComponent(SelectStub).props('value')).toBe('auto')
        expect(wrapper.find('#responsive-layout-item-mobile').exists()).toBe(true)

        await wrapper.find('[data-responsive-setting="span.tablet"] button').trigger('click')

        expect(item.custom).toMatchObject({
            retained: 'yes',
            layout: { retainedLayout: 'yes', span: { tablet: 2 } },
        })
        expect(form).toEqual({ custom: {} })
    })
})
