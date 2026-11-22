import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ getMyTasks: vi.fn() }))
vi.mock('@/libs/my-tasks', () => api)
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
const setHidden = (hidden: boolean) =>
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden })

const mounted: Array<{ unmount: () => void }> = []
const mountBadge = async () => {
    const Badge = (await import('@/components/my-tasks-badge.vue')).default
    const w = mount(Badge, { global: { stubs: { 'router-link': RouterLinkStub } } })
    mounted.push(w)
    await flushPromises()
    return w
}

describe('my-tasks badge', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        api.getMyTasks.mockReset()
        api.getMyTasks.mockResolvedValue({ items: [], total: 4 })
        setHidden(false)
    })
    afterEach(() => {
        mounted.splice(0).forEach((w) => w.unmount())
        vi.useRealTimers()
        setHidden(false)
    })

    it('renders the total as a link to the inbox with an accessible label', async () => {
        const w = await mountBadge()
        const link = w.find('a')
        expect(JSON.parse(link.attributes('data-to') as string)).toEqual({ name: 'my-tasks' })
        expect(link.text()).toContain('4')
        expect(link.attributes('aria-label')).toBe('text.my_tasks.badge_label: 4')
        expect(link.attributes('title')).toBe('text.my_tasks.badge_label: 4')
    })

    it('renders nothing when the total is 0', async () => {
        api.getMyTasks.mockResolvedValue({ items: [], total: 0 })
        const w = await mountBadge()
        expect(w.find('a').exists()).toBe(false)
    })

    it('polls again after 60 seconds', async () => {
        await mountBadge()
        expect(api.getMyTasks).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(60_000)
        expect(api.getMyTasks).toHaveBeenCalledTimes(2)
    })

    it('does not call while the tab is hidden', async () => {
        await mountBadge()
        setHidden(true)
        await vi.advanceTimersByTimeAsync(120_000)
        expect(api.getMyTasks).toHaveBeenCalledTimes(1)
    })

    it('refreshes when the tab becomes visible again', async () => {
        await mountBadge()
        setHidden(true)
        setHidden(false)
        document.dispatchEvent(new Event('visibilitychange'))
        await flushPromises()
        expect(api.getMyTasks).toHaveBeenCalledTimes(2)
    })

    it('stops polling and removes the listener on unmount', async () => {
        const remove = vi.spyOn(document, 'removeEventListener')
        const w = await mountBadge()
        w.unmount()
        expect(remove).toHaveBeenCalledWith('visibilitychange', expect.any(Function))
        await vi.advanceTimersByTimeAsync(180_000)
        expect(api.getMyTasks).toHaveBeenCalledTimes(1)
        remove.mockRestore()
    })

    it('stays silent when the request fails', async () => {
        api.getMyTasks.mockRejectedValue(new Error('x'))
        const w = await mountBadge()
        expect(w.find('a').exists()).toBe(false)
    })
})
