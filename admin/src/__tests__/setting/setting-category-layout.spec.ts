import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SettingCategoryLayout from '@/pages/setting/setting-category-layout.vue'

const routeState = vi.hoisted(() => ({ value: {} as Record<string, unknown> }))
vi.mock('vue-router', () => ({ useRoute: () => routeState.value }))
const ruleState = vi.hoisted(() => ({ allowed: true as boolean | ((name: string) => boolean) }))
vi.mock('@/mixins', () => ({ rule: (name: string) => (typeof ruleState.allowed === 'function' ? ruleState.allowed(name) : ruleState.allowed) }))

const mountLayout = (meta: Record<string, unknown>, allowed: boolean | ((name: string) => boolean) = true) => {
    ruleState.allowed = allowed
    routeState.value = { name: 'setting-ai-runtime', path: '/setting/automation/ai-runtime', meta }
    return mount(SettingCategoryLayout, {
        global: {
            mocks: { $t: (key: string) => key },
            stubs: {
                'router-link': { template: '<a><slot /></a>' },
                'router-view': { template: '<div data-testid="content" />' },
                'i-icon': true,
            },
        },
    })
}

describe('SettingCategoryLayout', () => {
    it('keeps the left nav by default', () => {
        const wrapper = mountLayout({})
        expect(wrapper.find('[data-testid="setting-wide"]').exists()).toBe(false)
        expect(wrapper.find('nav.md\\:w-\\[210px\\]').exists()).toBe(true)
    })

    it('hides the left nav and renders a horizontal link row when settingWide is set', () => {
        const wrapper = mountLayout({ settingWide: true })
        expect(wrapper.find('[data-testid="setting-wide"]').exists()).toBe(true)
        expect(wrapper.find('nav.md\\:w-\\[210px\\]').exists()).toBe(false)
        expect(wrapper.text()).toContain('text.ai_runtime.title')
        expect(wrapper.text()).toContain('text.agentic.cms')
        expect(wrapper.find('[data-testid="content"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('|')
    })

    it('omits the separator when no category link is visible', () => {
        const wrapper = mountLayout({ settingWide: true }, false)
        expect(wrapper.find('[data-testid="setting-wide"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('|')
    })
})
