import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = await vi.hoisted(async () => {
    // Reactive, like the real module catalog: the badge must notice a module turning on or off.
    const { ref } = await import('vue')

    return {
        allowed: true,
        inactiveModules: ref<string[]>([]),
        badges: [] as Array<{ name: string; module?: string; rule: string; label: string; intervalSeconds?: number; load: ReturnType<typeof vi.fn> }>,
    }
})

vi.mock('@/config/admin-modules', () => ({ adminNavBadges: state.badges }))
vi.mock('@/mixins', () => ({ rule: vi.fn(() => state.allowed) }))
// The module check the sidebar uses: a module that is not on this site is not active. No module named means always active.
vi.mock('@/hooks/useActiveModules', () => ({ isModuleActive: (name?: string) => !name || !state.inactiveModules.value.includes(name) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import NavBadge from '@/components/nav-badge.vue'

const load = vi.fn()
const mountBadge = async (name = 'helpdesk') => {
    const wrapper = mount(NavBadge, { props: { name } })
    await flushPromises()
    return wrapper
}
const setVisibility = (value: 'visible' | 'hidden') => Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => value })

describe('Sidebar badge', () => {
    beforeEach(() => {
        load.mockReset()
        state.allowed = true
        state.inactiveModules.value = []
        state.badges.splice(0, state.badges.length, { name: 'helpdesk', module: 'Helpdesk', rule: 'helpdesk.agent', label: 'text.helpdesk.tickets.badge_label', intervalSeconds: 60, load })
        setVisibility('visible')
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    })
    afterEach(() => {
        vi.useRealTimers()
    })

    it('shows the count with a text for screen readers', async () => {
        load.mockResolvedValue(7)

        const wrapper = await mountBadge()

        expect(wrapper.text()).toContain('7')
        expect(wrapper.text()).toContain('text.helpdesk.tickets.badge_label')
    })

    it('shows nothing for zero and caps large numbers', async () => {
        load.mockResolvedValue(0)
        expect((await mountBadge()).text()).toBe('')

        load.mockResolvedValue(250)
        expect((await mountBadge()).text()).toContain('99+')
    })

    it('does nothing for a person without the permission or for an item without a badge', async () => {
        state.allowed = false
        load.mockResolvedValue(5)
        expect((await mountBadge()).text()).toBe('')
        expect(load).not.toHaveBeenCalled()

        state.allowed = true
        expect((await mountBadge('dashboard')).text()).toBe('')
        expect(load).not.toHaveBeenCalled()
    })

    it('shows nothing and asks for nothing while its module is not active on this site, and starts once it is', async () => {
        state.inactiveModules.value = ['Helpdesk']
        load.mockResolvedValue(5)

        const wrapper = await mountBadge()
        await vi.advanceTimersByTimeAsync(120_000)
        expect(load).not.toHaveBeenCalled()
        expect(wrapper.text()).toBe('')

        state.inactiveModules.value = []
        await vi.advanceTimersByTimeAsync(60_000)
        await flushPromises()
        expect(load).toHaveBeenCalledTimes(1)
        expect(wrapper.text()).toContain('5')
    })

    it('hides a number it already has when its module turns off', async () => {
        load.mockResolvedValue(4)
        const wrapper = await mountBadge()
        expect(wrapper.text()).toContain('4')

        state.inactiveModules.value = ['Helpdesk']
        await vi.advanceTimersByTimeAsync(60_000)
        await flushPromises()

        expect(wrapper.text()).toBe('')
        expect(load).toHaveBeenCalledTimes(1)
    })

    it('follows the item it is given: a new name switches badge, restarts the timer and drops the old number', async () => {
        const loadOther = vi.fn().mockResolvedValue(8)
        state.badges.push({ name: 'other', module: 'Helpdesk', rule: 'helpdesk.agent', label: 'text.other.label', intervalSeconds: 30, load: loadOther })
        load.mockResolvedValue(3)

        const wrapper = await mountBadge('helpdesk')
        expect(wrapper.text()).toContain('3')

        await wrapper.setProps({ name: 'other' })
        await flushPromises()
        expect(wrapper.text()).toContain('8')
        expect(wrapper.text()).toContain('text.other.label')
        expect(wrapper.text()).not.toContain('text.helpdesk.tickets.badge_label')

        // The first badge's 60 second timer is gone; the new one ticks every 30 seconds.
        await vi.advanceTimersByTimeAsync(60_000)
        await flushPromises()
        expect(load).toHaveBeenCalledTimes(1)
        expect(loadOther).toHaveBeenCalledTimes(3)
    })

    it('ignores a late answer of the badge it no longer shows, and an item with no badge shows nothing', async () => {
        let answerLate: (value: number) => void = () => {}
        load.mockReturnValueOnce(new Promise<number>((resolve) => (answerLate = resolve)))
        const loadOther = vi.fn().mockResolvedValue(2)
        state.badges.push({ name: 'other', module: 'Helpdesk', rule: 'helpdesk.agent', label: 'text.other.label', load: loadOther })

        const wrapper = await mountBadge('helpdesk')
        await wrapper.setProps({ name: 'other' })
        await flushPromises()
        answerLate(40)
        await flushPromises()
        expect(wrapper.text()).toContain('2')
        expect(wrapper.text()).not.toContain('40')

        await wrapper.setProps({ name: 'dashboard' })
        await flushPromises()
        expect(wrapper.text()).toBe('')
        await vi.advanceTimersByTimeAsync(120_000)
        expect(loadOther).toHaveBeenCalledTimes(1)
    })

    it('refreshes every minute, keeps the last number when a refresh fails and stops when removed', async () => {
        load.mockResolvedValueOnce(3).mockRejectedValueOnce(new Error('down')).mockResolvedValue(4)

        const wrapper = await mountBadge()
        expect(wrapper.text()).toContain('3')

        await vi.advanceTimersByTimeAsync(60_000)
        await flushPromises()
        expect(load).toHaveBeenCalledTimes(2)
        expect(wrapper.text()).toContain('3')

        await vi.advanceTimersByTimeAsync(60_000)
        await flushPromises()
        expect(wrapper.text()).toContain('4')

        wrapper.unmount()
        await vi.advanceTimersByTimeAsync(180_000)
        expect(load).toHaveBeenCalledTimes(3)
    })

    it('does not ask the server while the browser tab is hidden', async () => {
        setVisibility('hidden')
        load.mockResolvedValue(9)

        const wrapper = await mountBadge()
        await vi.advanceTimersByTimeAsync(120_000)

        expect(load).not.toHaveBeenCalled()
        expect(wrapper.text()).toBe('')
    })
})
