import { mount } from '@vue/test-utils'
import dayjs from 'dayjs'
import { describe, expect, it } from 'vitest'
import InputPublishedDate from '@/components/input-published-date.vue'

const mountInputPublishedDate = (props = {}) =>
    mount(InputPublishedDate, {
        props: {
            type: 'posts',
            ...props,
        },
        global: {
            mocks: {
                $t: (key: string) => key,
            },
            stubs: {
                'a-tooltip': { template: '<span><slot /></span>' },
                'a-modal': { template: '<div><slot /><slot name="footer" /></div>' },
                'a-date-picker': { template: '<input />' },
                'a-button': { template: '<button type="button"><slot /></button>' },
                'i-icon': { template: '<span />' },
            },
        },
    })

describe('InputPublishedDate', () => {
    it('emits a local datetime string instead of a raw dayjs object', () => {
        const wrapper = mountInputPublishedDate()

        ;(wrapper.vm as any).innerValue = dayjs('2026-06-26 23:23:25')
        ;(wrapper.vm as any).updateValue()

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['2026-06-26 23:23:25'])
    })

    it('reopens with the current model value after cancel', async () => {
        const wrapper = mountInputPublishedDate({
            modelValue: '2026-06-26 23:23:25',
        })

        ;(wrapper.vm as any).openModal()
        ;(wrapper.vm as any).innerValue = dayjs('2026-06-27 12:23:25')
        ;(wrapper.vm as any).closeModal()

        expect((wrapper.vm as any).innerValue.format('YYYY-MM-DD HH:mm:ss')).toBe(
            '2026-06-26 23:23:25'
        )
    })
})
