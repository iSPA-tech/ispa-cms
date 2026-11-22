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
})
