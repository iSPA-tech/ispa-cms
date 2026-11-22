import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import IButton from '@/components/i-button.vue'

// Regression (2026-10-27): pages pass `:to` without `as="router-link"`; i-button rendered a plain <button> that ignored
// `to`, so header actions such as Webmail "Gói dịch vụ" did nothing when clicked.
const makeRouter = () =>
    createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/billing', name: 'billing', component: { template: '<div />' } },
            { path: '/subscriptions', name: 'subscriptions', component: { template: '<div />' } },
        ],
    })

describe('i-button', () => {
    it('renders a router link for `to` even when `as` is left at its default, and navigates on click', async () => {
        const router = makeRouter()
        await router.push('/billing')
        const wrapper = mount(IButton, { props: { to: { name: 'subscriptions' } }, slots: { default: 'Gói dịch vụ' }, global: { plugins: [router] } })
        const link = wrapper.get('a')
        expect(link.attributes('href')).toBe('/subscriptions')
        expect(wrapper.find('button').exists()).toBe(false)
        await link.trigger('click')
        await flushPromises()
        expect(router.currentRoute.value.name).toBe('subscriptions')
    })

    it('stays a native button without `to`, keeping its submit type', () => {
        const wrapper = mount(IButton, { props: { nativeType: 'submit' }, slots: { default: 'Lưu' } })
        expect(wrapper.get('button').attributes('type')).toBe('submit')
    })

    it('keeps an explicit `as="a"` with href', () => {
        const wrapper = mount(IButton, { props: { as: 'a', href: 'https://example.test' }, slots: { default: 'Mở' } })
        expect(wrapper.get('a').attributes('href')).toBe('https://example.test')
    })

    it('maps primary to brand tokens and ghost/muted/secondary to the ghost classes', () => {
        const classesOf = (variant: string) => mount(IButton, { props: { variant } }).get('button').classes()
        expect(classesOf('primary')).toEqual(expect.arrayContaining(['bg-primary', 'text-on-primary']))
        const ghost = classesOf('ghost')
        expect(ghost).toEqual(expect.arrayContaining(['bg-transparent', 'text-ink-muted']))
        expect(classesOf('muted')).toEqual(expect.arrayContaining(['bg-transparent', 'text-ink-muted']))
        expect(classesOf('secondary')).toContain('bg-transparent')
        expect(classesOf('muted')).not.toContain('bg-gray-50')
    })
})
