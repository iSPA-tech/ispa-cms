import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SubTitle from '@/components/sub-title.vue'

describe('components:SubTitle', () => {
    it('renders translated breadcrumb text', () => {
        const wrapper = mount(SubTitle, {
            props: {
                title: ['home', ['users', 'list']],
            },
        })

        const text = wrapper.text().replace(/\s+/g, ' ').trim()
        expect(text.startsWith('/')).toBe(true)
        expect(text).toContain('home')
        expect(text).toContain('users list')
    })

    it('renders nothing if title is empty', () => {
        const wrapper = mount(SubTitle, { props: { title: [] } })
        expect(wrapper.text().trim()).toBe('')
    })
})
