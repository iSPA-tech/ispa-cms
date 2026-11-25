import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EmailTemplateForm from '@/components/email-template/EmailTemplateForm.vue'

describe('EmailTemplateForm', () => {
    it('shows required language selector for template creation', () => {
        const wrapper = mount(EmailTemplateForm, {
            props: {
                modelValue: {
                    name: '',
                    key_name: '',
                    content: '',
                    language_id: 2,
                    variables: [],
                    sample_data: {},
                },
                subjectLabel: 'Subject',
                keyNameLabel: 'Template key',
                bladeLayoutLabel: 'Blade layout',
                languageLabel: 'Language',
                variablesLabel: 'Variables',
                addVariableLabel: 'Add variable',
                sampleDataLabel: 'Sample data',
                valueLabel: 'Value',
                labelLabel: 'Label',
                contentLabel: 'Content',
                previewLabel: 'Preview',
                requiredMessage: vi.fn((label) => `${label} required`),
            },
            global: {
                stubs: {
                    'a-form': { template: '<form><slot /></form>' },
                    'a-form-item': { template: '<div><slot /></div>' },
                    'a-input': { template: '<input />' },
                    'a-textarea': { template: '<textarea />' },
                    'a-button': { template: '<button><slot /></button>' },
                    'a-tooltip': { template: '<span><slot /></span>' },
                    'select-input-language': {
                        props: ['modelValue', 'label', 'name', 'required'],
                        template: '<div data-testid="language" :data-model-value="modelValue" :data-label="label" :data-name="name" :data-required="required" />',
                    },
                    'i-icon': true,
                },
            },
        })

        const language = wrapper.get('[data-testid="language"]')

        expect(language.attributes('data-model-value')).toBe('2')
        expect(language.attributes('data-label')).toBe('Language')
        expect(language.attributes('data-name')).toBe('language_id')
        expect(language.attributes()).toHaveProperty('data-required')
    })

    const labels = {
        subjectLabel: 'Subject',
        keyNameLabel: 'Template key',
        bladeLayoutLabel: 'Mail frame',
        languageLabel: 'Language',
        variablesLabel: 'Blanks',
        addVariableLabel: 'Add variable',
        sampleDataLabel: 'Sample data',
        valueLabel: 'Value',
        labelLabel: 'Label',
        contentLabel: 'Content',
        previewLabel: 'Preview',
        requiredMessage: (label: string) => `${label} required`,
    }
    const stubs = {
        'a-form': { template: '<form><slot /></form>' },
        'a-form-item': { props: ['label'], template: '<div :data-item="label"><slot /><slot name="extra" /></div>' },
        'a-input': { props: ['value'], template: '<input :value="value" />' },
        'a-textarea': { props: ['value'], emits: ['update:value'], template: '<textarea :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
        'a-button': { template: '<button type="button"><slot /></button>' },
        'a-tooltip': { template: '<span><slot /></span>' },
        'a-select': {
            props: ['value', 'options'],
            emits: ['update:value'],
            template: '<select :value="value" @change="$emit(\'update:value\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
        },
        'a-radio-group': {
            props: ['value'],
            emits: ['update:value'],
            provide() {
                return { pick: (value: string) => (this as any).$emit('update:value', value) }
            },
            template: '<div role="radiogroup" :data-value="value"><slot /></div>',
        },
        'a-radio': { props: ['value'], inject: ['pick'], template: '<button type="button" :data-radio="value" @click="pick(value)"><slot /></button>' },
        'select-input-language': true,
        SafeHtml: { props: ['html'], template: '<div data-testid="quick-preview" />' },
        'i-icon': true,
    }
    const lastModel = (wrapper: ReturnType<typeof mount>) => {
        const events = wrapper.emitted('update:modelValue') || []
        return events[events.length - 1]?.[0] as Record<string, any>
    }

    it('keeps the technical form of the order and journal screens as it was', () => {
        const wrapper = mount(EmailTemplateForm, {
            props: { ...labels, modelValue: { name: 'A', key_name: 'order_created', content: 'Hello {name}', language_id: 1, blade_layout: 'mail.order.layout', variables: [{ value: 'name', label: 'Name' }], sample_data: { name: 'Lan' } } },
            global: { stubs },
        })

        // No class field and no described variable list unless a screen asks for them; the layout stays a typed field.
        expect(wrapper.find('[data-testid="email-template-mail-class"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="email-template-layout"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="email-template-variable-list"]').exists()).toBe(false)
        expect(wrapper.find('[data-item="Mail frame"] input').exists()).toBe(true)
        expect(wrapper.find('[data-testid="quick-preview"]').exists()).toBe(true)
        expect(lastModel(wrapper)?.mail_class).toBeUndefined()
    })

    it('edits the mail class and picks the frame from the allowed ones when a screen passes them', async () => {
        const wrapper = mount(EmailTemplateForm, {
            props: {
                ...labels,
                modelValue: { name: 'A', content: 'Hello', mail_class: 'system', blade_layout: '', language_id: 1, variables: [], sample_data: {} },
                showTechnicalFields: false,
                showPreview: false,
                mailClassLabel: 'Mail class',
                mailClassHelp: 'Applies at once',
                mailClassOptions: [
                    { value: 'system', label: 'System', hint: 'Automatic notices' },
                    { value: 'business', label: 'Business', hint: 'Needs the step switch' },
                ],
                layoutOptions: [
                    { value: '', label: 'No frame' },
                    { value: 'mail.branded', label: 'Website frame' },
                ],
            },
            global: { stubs },
        })

        const group = wrapper.get('[data-testid="email-template-mail-class"]')
        expect(group.attributes('data-value')).toBe('system')
        expect(group.text()).toContain('Needs the step switch')
        expect(wrapper.get('[data-item="Mail class"]').text()).toContain('Applies at once')
        await group.get('[data-radio="business"]').trigger('click')
        expect(lastModel(wrapper).mail_class).toBe('business')

        const layout = wrapper.get('[data-testid="email-template-layout"]')
        expect(layout.findAll('option').map((option) => option.text())).toEqual(['No frame', 'Website frame'])
        await layout.setValue('mail.branded')
        expect(lastModel(wrapper).blade_layout).toBe('mail.branded')
        // The screen brings its own preview.
        expect(wrapper.find('[data-testid="quick-preview"]').exists()).toBe(false)
    })

    it('lists the described variables and inserts one into the content on a click', async () => {
        const wrapper = mount(EmailTemplateForm, {
            attachTo: document.body,
            props: {
                ...labels,
                modelValue: { name: 'A', content: 'Chào bạn', mail_class: 'system', language_id: 1, variables: [], sample_data: {} },
                showTechnicalFields: false,
                showPreview: false,
                variableList: [
                    { key: 'display_name', description: 'Tên người gửi', sample: 'Lan Nguyễn' },
                    { key: 'site_name', description: null, sample: null },
                ],
            },
            global: { stubs },
        })

        const list = wrapper.get('[data-testid="email-template-variable-list"]')
        expect(list.text()).toContain('Tên người gửi')
        expect(list.text()).toContain('(Lan Nguyễn)')

        // At the caret: after "Chào " (5 characters).
        const field = wrapper.get('textarea').element as HTMLTextAreaElement
        field.focus()
        field.setSelectionRange(5, 5)
        await list.findAll('[data-testid="email-template-variable-insert"]')[0].trigger('click')
        expect(lastModel(wrapper).content).toBe('Chào {{ display_name }}bạn')
        wrapper.unmount()
    })
})
