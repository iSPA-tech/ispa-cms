import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, reactive } from 'vue'

const api = vi.hoisted(() => ({ getNotifications: vi.fn(), markNotificationRead: vi.fn(), markAllNotificationsRead: vi.fn() }))
vi.mock('@/libs/notifications', () => api)
vi.mock('@/config/admin-modules', () => ({
    adminNotificationTypes: [
        { type: 'helpdesk.ticket.assigned', text: 'text.helpdesk.assigned', open: (p: { uuid: string }) => ({ name: 'helpdesk-ticket-detail', params: { uuid: p.uuid } }) },
    ],
}))
const route = vi.hoisted(() => ({ query: {} as Record<string, unknown> }))
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => router }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k, te: () => true }) }))

const Passthrough = { template: '<div><slot /></div>' }
const AlertStub = defineComponent({
    props: ['message'],
    setup(props, { slots }) {
        return () => h('div', { 'data-testid': 'error' }, [props.message, slots.action?.()])
    },
})
const IButton = defineComponent({
    props: ['loading', 'disabled'],
    emits: ['click'],
    setup(_, { emit, slots, attrs }) {
        return () => h('button', { ...attrs, onClick: () => emit('click') }, slots.default?.())
    },
})
const ATable = defineComponent({
    props: ['dataSource', 'columns', 'pagination', 'customRow', 'loading'],
    emits: ['change'],
    setup(props, { emit, slots }) {
        return () =>
            h('div', { 'data-testid': 'table', 'data-total': props.pagination?.total, 'data-current': props.pagination?.current }, [
                ...props.dataSource.map((record: any) =>
                    h('div', { 'data-testid': 'row', ...(props.customRow?.(record) ? { onClick: props.customRow(record).onClick } : {}) }, [
                        slots.bodyCell?.({ column: { key: 'type' }, record }),
                        slots.bodyCell?.({ column: { key: 'time' }, record }),
                    ])
                ),
                h('button', { 'data-testid': 'page2', onClick: () => emit('change', { current: 2, pageSize: 20 }) }),
            ])
    },
})

const row = (id: string, type: string, over: Record<string, unknown> = {}) => ({ id, type, payload: { uuid: `u-${id}` }, read_at: null, created_at: '2026-11-09T10:00:00+07:00', ...over })

const mountPage = async () => {
    const Page = (await import('@/pages/notifications.vue')).default
    const w = mount(Page, {
        global: {
            stubs: {
                breadcrumbs: Passthrough,
                'a-skeleton': { template: '<div data-testid="skeleton" />' },
                'a-alert': AlertStub,
                'a-table': ATable,
                'a-radio-group': { props: ['value'], emits: ['change', 'update:value'], template: '<div><slot /></div>' },
                'a-radio-button': { props: ['value'], template: '<button :data-testid="`filter-${value}`" @click="$parent.$emit(\'update:value\', value); $parent.$emit(\'change\', { target: { value } })"><slot /></button>' },
                'a-tag': Passthrough,
                'i-button': IButton,
                'i-icon': true,
            },
        },
    })
    await flushPromises()
    return w
}

describe('notifications page', () => {
    beforeEach(() => {
        Object.values(api).forEach((f) => f.mockReset())
        router.push.mockReset()
        router.replace.mockReset()
        route.query = {}
        api.getNotifications.mockResolvedValue({ items: [row('a', 'helpdesk.ticket.assigned'), row('b', 'unknown.type')], unreadCount: 2, total: 41 })
        api.markNotificationRead.mockResolvedValue(undefined)
        api.markAllNotificationsRead.mockResolvedValue(undefined)
    })

    it('shows a skeleton while loading', async () => {
        api.getNotifications.mockReturnValue(new Promise(() => undefined))
        const w = await mountPage()
        expect(w.find('[data-testid="skeleton"]').exists()).toBe(true)
    })

    it('lists rows with the server total and page 1 by default', async () => {
        const w = await mountPage()
        expect(api.getNotifications).toHaveBeenCalledWith(expect.objectContaining({ unread: false, page: 1, perPage: 20 }))
        expect(w.findAll('[data-testid="row"]')).toHaveLength(2)
        expect(w.get('[data-testid="table"]').attributes('data-total')).toBe('41')
    })

    it('reads filter and page from the query', async () => {
        route.query = { filter: 'unread', page: '3' }
        await mountPage()
        expect(api.getNotifications).toHaveBeenCalledWith(expect.objectContaining({ unread: true, page: 3 }))
    })

    it('keeps the filter in the query and resets the page when it changes', async () => {
        route.query = { page: '3' }
        const w = await mountPage()
        await w.get('[data-testid="filter-unread"]').trigger('click')
        await flushPromises()
        expect(router.replace).toHaveBeenCalledWith({ query: { filter: 'unread' } })
    })

    it('keeps the page in the query and loads it', async () => {
        const w = await mountPage()
        await w.get('[data-testid="page2"]').trigger('click')
        await flushPromises()
        expect(router.replace).toHaveBeenCalledWith({ query: { page: 2 } })
    })

    it('falls back to page 1 when the requested page is empty', async () => {
        route.query = { page: '9' }
        api.getNotifications.mockResolvedValueOnce({ items: [], unreadCount: 0, total: 3 })
        await mountPage()
        expect(router.replace).toHaveBeenCalledWith({ query: {} })
        expect(api.getNotifications).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1 }))
    })

    it('shows an empty state', async () => {
        api.getNotifications.mockResolvedValue({ items: [], unreadCount: 0, total: 0 })
        const w = await mountPage()
        expect(w.find('[data-testid="notifications-empty"]').exists()).toBe(true)
    })

    it('shows an error with retry', async () => {
        api.getNotifications.mockRejectedValueOnce(new Error('x'))
        const w = await mountPage()
        expect(w.find('[data-testid="error"]').exists()).toBe(true)
        await w.get('[data-testid="retry"]').trigger('click')
        await flushPromises()
        expect(api.getNotifications).toHaveBeenCalledTimes(2)
        expect(w.find('[data-testid="error"]').exists()).toBe(false)
    })

    it('opens a row target after marking it read; a row with no target does nothing', async () => {
        const w = await mountPage()
        const rows = w.findAll('[data-testid="row"]')
        await rows[1].trigger('click')
        await flushPromises()
        expect(router.push).not.toHaveBeenCalled()
        expect(api.markNotificationRead).not.toHaveBeenCalled()
        await rows[0].trigger('click')
        await flushPromises()
        expect(api.markNotificationRead).toHaveBeenCalledWith('a')
        expect(router.push).toHaveBeenCalledWith({ name: 'helpdesk-ticket-detail', params: { uuid: 'u-a' } })
    })

    it('opens the target even when the read mark fails', async () => {
        api.markNotificationRead.mockRejectedValue(new Error('x'))
        const w = await mountPage()
        await w.findAll('[data-testid="row"]')[0].trigger('click')
        await flushPromises()
        expect(router.push).toHaveBeenCalled()
    })

    it('marks all read and reloads', async () => {
        const w = await mountPage()
        await w.get('[data-testid="mark-all"]').trigger('click')
        await flushPromises()
        expect(api.markAllNotificationsRead).toHaveBeenCalledTimes(1)
        expect(api.getNotifications).toHaveBeenCalledTimes(2)
    })

    it('tells the header bell when read marks change, and stays quiet when marking fails', async () => {
        const heard = vi.fn()
        window.addEventListener('ispa:notifications-changed', heard)
        try {
            const w = await mountPage()
            await w.get('[data-testid="mark-all"]').trigger('click')
            await flushPromises()
            expect(heard).toHaveBeenCalledTimes(1)

            api.markAllNotificationsRead.mockRejectedValueOnce(new Error('offline'))
            await w.get('[data-testid="mark-all"]').trigger('click')
            await flushPromises()
            expect(heard).toHaveBeenCalledTimes(1)
        } finally {
            window.removeEventListener('ispa:notifications-changed', heard)
        }
    })

    it('hides mark all when nothing is unread', async () => {
        api.getNotifications.mockResolvedValue({ items: [row('a', 'x', { read_at: 'z' })], unreadCount: 0, total: 1 })
        const w = await mountPage()
        expect(w.find('[data-testid="mark-all"]').exists()).toBe(false)
    })
})
