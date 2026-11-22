import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import IFormConfig from '@/components/i-form-config.vue'

const mountConfig = (type: string) =>
    mount(IFormConfig, {
        props: { type, modelValue: {} },
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                'a-form-item': {
                    props: ['label'],
                    template: '<label>{{ label }}<slot /></label>',
                },
                'a-switch': {
                    props: ['checked'],
                    emits: ['update:checked'],
                    template: '<button type="button" role="switch" :aria-checked="String(checked)" @click="$emit(\'update:checked\', !checked)" />',
                },
                'a-checkbox-group': { template: '<div />' },
                'select-input-user-role': { template: '<div />' },
            },
        },
    })

describe('post full-page config', () => {
    it('defaults to the normal article and updates the saved config when selected', async () => {
        const wrapper = mountConfig('post')
        await nextTick()
        const field = wrapper.findAll('label')
            .find((label) => label.text().includes('text.post.full_page_article'))

        expect(field).toBeDefined()
        expect(field!.get('[role="switch"]').attributes('aria-checked')).toBe('false')

        await field!.get('[role="switch"]').trigger('click')

        expect(field!.get('[role="switch"]').attributes('aria-checked')).toBe('true')
        expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toMatchObject({ is_full_page: true })
    })

    it('does not expose the article layout option to product or gallery config', () => {
        expect(mountConfig('product').text()).not.toContain('text.post.full_page_article')
        expect(mountConfig('gallery').text()).not.toContain('text.post.full_page_article')
    })
})
