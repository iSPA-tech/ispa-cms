import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import Breadcrumbs from '@/components/breadcrumbs.vue'

describe('components:Breadcrumbs', () => {
    it('renders items and badge slot', () => {
        const wrapper = mount(Breadcrumbs, {
            props: {
                items: [
                    { name: 'dashboard', text: 'Dashboard' },
                    { text: 'Users' },
                ],
            },
            global: {
                stubs: {
                    'router-link': { template: '<a><slot /></a>' },
                    'i-icon': true,
                },
                mocks: { $t: (k) => k },
            },
            slots: { badge: '<span class="badge">Badge</span>' },
        })

        const text = wrapper.text()
        expect(text).toContain('Dashboard')
        expect(text).toContain('Users')
        expect(text).toContain('Badge')
    })
})
