import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const pushSpy = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ push: pushSpy }) }))

import NoPermissionError from '@/components/no-permission-error.vue'

describe('components:NoPermissionError', () => {
    it('renders title and navigates on click', async () => {
        const wrapper = mount(NoPermissionError, {
            global: {
                stubs: {
                    'i-alert': { template: '<div><slot /></div>' },
                    'a-button': true,
                    'i-icon': true,
                },
                mocks: { $t: (k: string) => k },
            },
        })

        expect(wrapper.text()).toContain('mgs.no.permission')

        const btn = wrapper.find('a-button-stub')
        expect(btn.exists()).toBe(true)
        await btn.trigger('click')
        expect(pushSpy).toHaveBeenCalledWith('/')
    })
})
