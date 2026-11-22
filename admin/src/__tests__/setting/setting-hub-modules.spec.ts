import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SettingHub from '@/pages/setting/setting-hub.vue'

const state = vi.hoisted(() => ({ allowed: true, inactive: [] as string[] }))

vi.mock('@/mixins', () => ({ rule: vi.fn(() => state.allowed) }))
vi.mock('@/hooks/useActiveModules', () => ({
    isModuleActive: (name?: string) => !name || !state.inactive.includes(name),
}))
vi.mock('@/config/admin-modules', () => ({
    adminModuleSettings: [
        {
            name: 'helpdesk-settings',
            category: 'modules',
            text: 'nav.a.helpdesk-settings',
            rule: 'helpdesk.admin',
            module: 'Helpdesk',
            moduleKey: 'helpdesk',
        },
    ],
}))

const mountHub = () =>
    mount(SettingHub, {
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                'router-link': {
                    props: ['to'],
                    template: '<a :data-to="to && to.name"><slot /></a>',
                },
                'i-icon': true,
            },
        },
    })

describe('SettingHub modules category', () => {
    beforeEach(() => {
        state.allowed = true
        state.inactive = []
    })

    it('lists module settings under a Modules category', () => {
        const wrapper = mountHub()
        expect(wrapper.text()).toContain('setting.hub.modules')
        const link = wrapper.find('a[data-to="helpdesk-settings"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('nav.a.helpdesk-settings')
    })

    it('hides the category when the permission rule fails', () => {
        state.allowed = false
        const wrapper = mountHub()
        expect(wrapper.text()).not.toContain('setting.hub.modules')
        expect(wrapper.find('a[data-to="helpdesk-settings"]').exists()).toBe(false)
    })

    it('hides the category when the module is inactive', () => {
        state.inactive = ['Helpdesk']
        const wrapper = mountHub()
        expect(wrapper.text()).not.toContain('setting.hub.modules')
        expect(wrapper.find('a[data-to="helpdesk-settings"]').exists()).toBe(false)
    })
})
