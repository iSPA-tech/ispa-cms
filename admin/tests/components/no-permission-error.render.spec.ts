import { describe, it, expect } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { renderWithApp } from '@/test-utils/renderWithApp'
import NoPermissionError from '@/components/no-permission-error.vue'

describe('components:NoPermissionError (renderWithApp)', () => {
    it('navigates to dashboard when button is clicked', async () => {
        const { wrapper, router } = await renderWithApp(NoPermissionError, {
            route: { path: '/denied' },
            globalStubs: {
                'i-alert': { template: '<div><slot /></div>' },
                'i-icon': true,
            },
        })

        expect(router.currentRoute.value.path).toBe('/denied')
        expect(wrapper.text()).toContain('mgs.no.permission')

        await wrapper.get('button').trigger('click')
        await flushPromises()

        expect(router.currentRoute.value.path).toBe('/')
    })
})
