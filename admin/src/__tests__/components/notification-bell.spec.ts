import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
    getNotifications: vi.fn(),
    getUnreadCount: vi.fn(),
    markNotificationRead: vi.fn(),
    markAllNotificationsRead: vi.fn(),
}))
vi.mock('@/libs/notifications', () => api)
vi.mock('@/config/admin-modules', () => ({
    adminNotificationTypes: [
        { type: 'helpdesk.ticket.assigned', text: 'text.helpdesk.assigned', moduleKey: 'helpdesk', open: (p: { uuid: string }) => ({ name: 'helpdesk-ticket-detail', params: { uuid: p.uuid } }) },
        { type: 'broken', text: 'text.broken', moduleKey: 'x', open: () => { throw new Error('boom') } },
    ],
}))
const router = vi.hoisted(() => ({ push: vi.fn(), hasRoute: vi.fn(() => true) }))
vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k, te: (k: string) => k !== 'text.broken' }) }))

const setHidden = (hidden: boolean) => Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden })
const row = (id: string, type: string, over: Record<string, unknown> = {}) => ({ id, type, payload: { uuid: `u-${id}` }, read_at: null, created_at: '2026-11-09T10:00:00+07:00', ...over })

const mounted: Array<{ unmount: () => void }> = []
const mountBell = async () => {
    const Bell = (await import('@/components/notification-bell.vue')).default
    const w = mount(Bell, {
        global: {
            stubs: {
                'i-icon': true,
                'router-link': { template: '<a><slot /></a>' },
                'a-dropdown': { name: 'a-dropdown', props: ['open'], emits: ['update:open'], template: '<div><slot /><div v-if="open"><slot name="overlay" /></div></div>' },
            },
        },
    })
    mounted.push(w)
    await flushPromises()
    return w
}

describe('notification bell', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        Object.values(api).forEach((f) => f.mockReset())
        api.getUnreadCount.mockResolvedValue(3)
        api.getNotifications.mockResolvedValue({ items: [row('a', 'helpdesk.ticket.assigned'), row('b', 'unknown.type')], unreadCount: 2 })
        api.markNotificationRead.mockResolvedValue(undefined)
        api.markAllNotificationsRead.mockResolvedValue(undefined)
        router.push.mockReset()
        setHidden(false)
    })
    afterEach(() => {
        mounted.splice(0).forEach((w) => w.unmount())
        vi.useRealTimers()
        setHidden(false)
    })

    it('shows the count and hides the badge at 0', async () => {
        const w = await mountBell()
        expect(w.find('[data-testid="notification-count"]').text()).toBe('3')
        api.getUnreadCount.mockResolvedValue(0)
        await vi.advanceTimersByTimeAsync(60_000)
        expect(w.find('[data-testid="notification-count"]').exists()).toBe(false)
    })

    it('pauses polling while hidden and refreshes at once when visible again', async () => {
        await mountBell()
        expect(api.getUnreadCount).toHaveBeenCalledTimes(1)
        setHidden(true)
        await vi.advanceTimersByTimeAsync(180_000)
        expect(api.getUnreadCount).toHaveBeenCalledTimes(1)
        setHidden(false)
        document.dispatchEvent(new Event('visibilitychange'))
        await flushPromises()
        expect(api.getUnreadCount).toHaveBeenCalledTimes(2)
    })

    it('refreshes the count at once when another screen changed read marks, and not after unmount', async () => {
        const w = await mountBell()
        expect(api.getUnreadCount).toHaveBeenCalledTimes(1)
        api.getUnreadCount.mockResolvedValue(0)
        window.dispatchEvent(new Event('ispa:notifications-changed'))
        await flushPromises()
        expect(api.getUnreadCount).toHaveBeenCalledTimes(2)
        expect(w.find('[data-testid="notification-count"]').exists()).toBe(false)

        w.unmount()
        window.dispatchEvent(new Event('ispa:notifications-changed'))
        await flushPromises()
        expect(api.getUnreadCount).toHaveBeenCalledTimes(2)
    })

    it('makes no call after unmount and aborts in-flight requests', async () => {
        let signal: AbortSignal | undefined
        api.getUnreadCount.mockImplementation(({ signal: s }: { signal: AbortSignal }) => {
            signal = s
            return new Promise(() => {})
        })
        const w = await mountBell()
        w.unmount()
        mounted.splice(0)
        expect(signal?.aborted).toBe(true)
        const calls = api.getUnreadCount.mock.calls.length
        await vi.advanceTimersByTimeAsync(300_000)
        document.dispatchEvent(new Event('visibilitychange'))
        await flushPromises()
        expect(api.getUnreadCount).toHaveBeenCalledTimes(calls)
        expect(vi.getTimerCount()).toBe(0)
    })

    it('loads the list only when opened, with empty and error states', async () => {
        const w = await mountBell()
        expect(api.getNotifications).not.toHaveBeenCalled()
        await w.findComponent({ name: 'a-dropdown' }).vm.$emit('update:open', true)
        await flushPromises()
        expect(api.getNotifications).toHaveBeenCalledTimes(1)
        expect(w.findAll('[data-testid="notification-row"]')).toHaveLength(2)

    })

    it('shows an error with retry and an empty state', async () => {
        api.getNotifications.mockRejectedValueOnce(new Error('x'))
        const w = await mountBell()
        await w.findComponent({ name: 'a-dropdown' }).vm.$emit('update:open', true)
        await flushPromises()
        expect(w.find('[data-testid="notification-error"]').exists()).toBe(true)
        api.getNotifications.mockResolvedValue({ items: [], unreadCount: 0 })
        await w.find('[data-testid="notification-retry"]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid="notification-empty"]').exists()).toBe(true)
    })

    it('marks a row read, then pushes the resolved location', async () => {
        const w = await mountBell()
        await w.findComponent({ name: 'a-dropdown' }).vm.$emit('update:open', true)
        await flushPromises()
        const order: string[] = []
        api.markNotificationRead.mockImplementation(async () => { order.push('read') })
        router.push.mockImplementation(() => { order.push('push') })
        await w.findAll('button[data-testid="notification-row"]')[0].trigger('click')
        await flushPromises()
        expect(api.markNotificationRead).toHaveBeenCalledWith('a')
        expect(router.push).toHaveBeenCalledWith({ name: 'helpdesk-ticket-detail', params: { uuid: 'u-a' } })
        expect(order).toEqual(['read', 'push'])
    })

    it('does not navigate for an unknown type or a throwing open; those rows are plain text', async () => {
        api.getNotifications.mockResolvedValue({ items: [row('b', 'unknown.type'), row('c', 'broken')], unreadCount: 2 })
        const w = await mountBell()
        await w.findComponent({ name: 'a-dropdown' }).vm.$emit('update:open', true)
        await flushPromises()
        expect(w.findAll('button[data-testid="notification-row"]')).toHaveLength(0)
        const plain = w.findAll('div[data-testid="notification-row"]')
        expect(plain).toHaveLength(2)
        await plain[0].trigger('click')
        await plain[1].trigger('click')
        await flushPromises()
        expect(router.push).not.toHaveBeenCalled()
        expect(api.markNotificationRead).not.toHaveBeenCalled()
    })

    it('marks all read', async () => {
        const w = await mountBell()
        await w.findComponent({ name: 'a-dropdown' }).vm.$emit('update:open', true)
        await flushPromises()
        await w.find('[data-testid="notification-mark-all"]').trigger('click')
        await flushPromises()
        expect(api.markAllNotificationsRead).toHaveBeenCalledTimes(1)
        expect(w.find('[data-testid="notification-count"]').exists()).toBe(false)
    })

    it('never keeps more than 20 rows', async () => {
        api.getNotifications.mockResolvedValue({ items: Array.from({ length: 35 }, (_, i) => row(`r${i}`, 'helpdesk.ticket.assigned')), unreadCount: 35 })
        const w = await mountBell()
        await w.findComponent({ name: 'a-dropdown' }).vm.$emit('update:open', true)
        await flushPromises()
        expect(w.findAll('[data-testid="notification-row"]')).toHaveLength(20)
    })
})
