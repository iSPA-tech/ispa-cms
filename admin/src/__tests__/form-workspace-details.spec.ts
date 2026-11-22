import { defineComponent, ref } from 'vue'
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FieldLogic from '@/components/field-logic.vue'

vi.mock('@/hooks', () => ({
    useTranslates: () => ({ data: ref([]) }),
}))

const FormItemStub = defineComponent({
    name: 'AFormItem',
    props: ['label', 'name'],
    template: '<div class="form-item"><slot /></div>',
})

const makeItem = (type = 'text', overrides = {}) => ({
    field_name: `field_${type}`,
    name: `Field ${type}`,
    type,
    status: true,
    isShow: false,
    description: null,
    name_trans_key: null,
    phd_trans_key: null,
    options: [
        { label: 'One', value: 'one' },
        { label: 'Two', value: 'two' },
    ],
    custom: {
        required: false,
        placeholder: null,
        section: null,
        visibleWhen: { field: null, value: null },
        optionsSource: null,
        choiceLayout: 'auto',
        multiple: false,
        icon: null,
        color: null,
        iconAppend: false,
        defaultValue: null,
        minValue: null,
        maxValue: null,
        stepValue: null,
        rows: 3,
        toolbar: true,
        aspectRatio: null,
        allowFileType: null,
        maxFileSizeMb: 10,
    },
    ...overrides,
})

const mountField = (items: any[], index: number, workspace = false) =>
    shallowMount(FieldLogic, {
        props: {
            workspace,
            form: { items },
            index,
            name: 'items',
            type: items[index].type,
            value: items[index],
        },
        global: {
            mocks: { $t: (key: string) => key },
            provide: {
                typeOption: [
                    {
                        text: 'Information',
                        options: [{ value: 'text' }, { value: 'select' }, { value: 'file' }],
                    },
                ],
                nameHandle: vi.fn(),
                newFormKey: (prefix: string) => `${prefix}_new`,
                optionSources: ref([]),
                optionSourcesLoading: ref(false),
                optionSourcesError: ref(false),
                reloadOptionSources: vi.fn(),
            },
            stubs: {
                'a-form-item': FormItemStub,
                'a-select': true,
                'a-select-opt-group': true,
                'a-select-option': true,
                'a-input': true,
                'a-input-number': true,
                'a-textarea': true,
                'a-switch': true,
                'a-radio-group': true,
                'a-radio': true,
                'a-button': true,
                'a-tooltip': true,
                'i-icon': true,
                'select-input-translate': true,
                'form-option-source': true,
            },
        },
    })

describe('form workspace field details', () => {
    it('groups additional settings clearly without nested accordion for image fields', () => {
        const wrapper = mountField([makeItem('image')], 0, true)
        const advanced = wrapper.find('[data-field-section="advanced"]')
        expect(advanced.find('details').exists()).toBe(false)
        expect(advanced.find('[data-additional-group="language"]').exists()).toBe(true)
        expect(advanced.find('.fw-settings-grid').exists()).toBe(true)
        expect(advanced.text()).toContain('text.form_builder.additional_language_help')
    })

    it('separates number limits from decoration and shows only relevant additional groups', () => {
        const number = mountField([makeItem('number')], 0, true).find('[data-field-section="advanced"]')
        expect(number.findAll('[data-additional-group]').map(group => group.attributes('data-additional-group'))).toEqual(['language', 'appearance', 'number'])
        const textarea = mountField([makeItem('textarea')], 0, true).find('[data-field-section="advanced"]')
        expect(textarea.findAll('[data-additional-group]').map(group => group.attributes('data-additional-group'))).toEqual(['language', 'input'])
    })

    it('keeps the legacy accordion collapsed by default', () => {
        const wrapper = mountField([makeItem('text')], 0)

        expect(wrapper.find('[data-field-summary]').exists()).toBe(true)
        expect(wrapper.find('.items-form').exists()).toBe(false)
        expect(wrapper.classes()).toContain('max-w-lg')
    })

    it('keeps legacy expanded controls and footer when workspace mode is off', () => {
        const wrapper = mountField([makeItem('text', { isShow: true })], 0)

        expect(wrapper.find('[data-field-summary]').exists()).toBe(true)
        expect(wrapper.find('.items-form').exists()).toBe(true)
        expect(wrapper.find('[data-field-footer]').exists()).toBe(true)
        expect(wrapper.find('[data-field-section]').exists()).toBe(false)
        expect(wrapper.findComponent({ name: 'FormResponsiveLayout' }).exists()).toBe(false)
    })

    it('shows the selected field controls without accordion chrome in workspace mode', () => {
        const items = [makeItem('text'), makeItem('select')]
        const wrapper = mountField(items, 1, true)

        expect(wrapper.find('[data-field-summary]').exists()).toBe(false)
        expect(wrapper.find('.items-form').exists()).toBe(true)
        expect(wrapper.classes()).not.toContain('max-w-lg')
        expect(wrapper.find('[data-field-footer]').exists()).toBe(false)

        const sectionNames = wrapper
            .findAll('[data-field-section]')
            .map((section) => section.attributes('data-field-section'))
        expect(sectionNames).toHaveLength(4)
        expect(sectionNames).toEqual(
            expect.arrayContaining(['content', 'choices', 'visibility', 'advanced'])
        )
        expect(wrapper.find('[data-field-section="content"]').classes()).toContain('order-1')
        expect(wrapper.find('[data-field-section="choices"]').classes()).toContain('order-2')
        expect(wrapper.find('[data-field-section="visibility"]').classes()).toContain('order-3')
        expect(wrapper.find('[data-field-section="advanced"]').classes()).toContain('order-5')
        expect(wrapper.text()).toContain('text.form_builder.workspace_content')
        expect(wrapper.text()).toContain('text.form_builder.workspace_choices')
        expect(wrapper.text()).toContain('text.form_builder.workspace_visibility')
        expect(wrapper.text()).toContain('text.form_builder.workspace_advanced')
        expect(wrapper.findAll('.fw-settings-grid')).toHaveLength(4)
        expect(wrapper.find('[data-field-section="content"] > .fw-settings-grid').exists()).toBe(true)
        expect(wrapper.find('[data-field-section="content"] > summary + .fw-settings-grid').exists()).toBe(true)
        expect(wrapper.find('details[data-field-section="content"]').attributes()).toHaveProperty(
            'open'
        )
        expect(
            wrapper.find('details[data-field-section="visibility"]').attributes()
        ).not.toHaveProperty('open')

        const names = wrapper.findAllComponents(FormItemStub).map((item) => item.props('name'))
        expect(names).toContainEqual(['items', 1, 'type'])
        expect(names).toContainEqual(['items', 1, 'custom', 'visibleWhen', 'field'])
        expect(names).toContainEqual(['items', 1, 'options', 0, 'label'])
        expect(wrapper.find('[data-field-setting="custom.optionsSource"]').exists()).toBe(true)
        const responsiveLayout = wrapper.findComponent({ name: 'FormResponsiveLayout' })
        expect(responsiveLayout.exists()).toBe(true)
        expect(responsiveLayout.props('form')).toBe(wrapper.props('form'))
        expect(responsiveLayout.props('item')).toEqual(items[1])
    })

    it('shows upload settings only for upload fields', () => {
        const fileWrapper = mountField([makeItem('file')], 0, true)
        const textWrapper = mountField([makeItem('text')], 0, true)

        expect(fileWrapper.find('[data-field-section="upload"]').exists()).toBe(true)
        expect(fileWrapper.find('[data-field-section="choices"]').exists()).toBe(false)
        expect(fileWrapper.text()).toContain('text.form_builder.workspace_upload')
        expect(textWrapper.find('[data-field-section="upload"]').exists()).toBe(false)
        expect(textWrapper.find('[data-field-section="choices"]').exists()).toBe(false)
    })
})
