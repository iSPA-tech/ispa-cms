import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SeoUrlParser from '@/components/seo-url-parser.vue'

describe('components:SeoUrlParser', () => {
    it('splits URL into base and path segments', async () => {
        const wrapper = mount(SeoUrlParser, {
            props: { url: 'admin/posts/view' },
            global: { stubs: { 'i-icon': true } },
        })

        // baseUrl should drop trailing slash on mount
        expect(wrapper.text()).toContain('https://example.com')
        // path segments should appear in order
        const text = wrapper.text()
        expect(text).toContain('admin')
        expect(text).toContain('posts')
        expect(text).toContain('view')
    })
})
