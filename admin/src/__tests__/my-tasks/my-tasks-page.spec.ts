import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const api = vi.hoisted(() => ({ getMyTasks: vi.fn() }))
vi.mock('@/libs/my-tasks', () => api)

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
const T = (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k)
const AlertStub = defineComponent({
    props: ['message'],
    setup(props, { slots }) {
        return () => h('div', { 'data-testid': 'error' }, [props.message, slots.action?.()])
    },
})
const Passthrough = { template: '<div><slot /></div>' }
const IButton = defineComponent({
    props: ['loading'],
    emits: ['click'],
    setup(props, { emit, slots, attrs }) {
        return () => h('button', { 'data-testid': 'retry', ...attrs, disabled: !!props.loading, onClick: () => emit('click') }, slots.default?.())
    },
})

const mountPage = async () => {
    const Page = (await import('@/pages/my-tasks.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: T, $te: (k: string) => !k.endsWith('.no_hint') && !k.endsWith('.missing') },
            stubs: {
                'router-link': RouterLinkStub,
                breadcrumbs: Passthrough,
                'a-skeleton': { template: '<div data-testid="skeleton" />' },
                'a-alert': AlertStub,
                'i-button': IButton,
                'i-icon': { props: ['icon'], template: '<i :data-icon="icon" />' },
                'i-box': Passthrough,
            },
        },
    })
    await flushPromises()
    return w
}

const group = {
    key: 'helpdesk_tickets_waiting',
    count: 3,
    route: 'helpdesk-ticket',
    query: { status: 'waiting' },
    items: [
        { title: 'Ticket A', route: 'helpdesk-ticket-view', params: { uuid: 'u1' }, query: { tab: 'x' } },
        { title: 'Ticket B', route: 'helpdesk-ticket-view', params: { uuid: 'u2' } },
    ],
}

describe('my-tasks page', () => {
    beforeEach(() => {
        api.getMyTasks.mockReset()
    })

    it('shows a skeleton while loading', async () => {
        api.getMyTasks.mockReturnValue(new Promise(() => undefined))
        const w = await mountPage()
        expect(w.find('[data-testid="skeleton"]').exists()).toBe(true)
    })

    it('shows the empty state when nothing waits', async () => {
        api.getMyTasks.mockResolvedValue({ items: [], total: 0 })
        const w = await mountPage()
        expect(w.text()).toContain('text.my_tasks.empty')
        expect(w.find('[data-testid="skeleton"]').exists()).toBe(false)
    })

    it('shows an error with a retry that calls the API again', async () => {
        api.getMyTasks.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce({ items: [group], total: 3 })
        const w = await mountPage()
        expect(w.find('[data-testid="error"]').text()).toContain('text.my_tasks.load_failed')
        await w.find('[data-testid="retry"]').trigger('click')
        await flushPromises()
        expect(api.getMyTasks).toHaveBeenCalledTimes(2)
        expect(w.find('[data-testid="error"]').exists()).toBe(false)
        expect(w.text()).toContain('Ticket A')
    })

    it('renders a card per group: label, hint, toned count and one primary action to the list', async () => {
        api.getMyTasks.mockResolvedValue({ items: [{ ...group, tone: 'danger' }], total: 3 })
        const w = await mountPage()
        const card = w.find('[data-testid="my-tasks-group"]')
        expect(card.text()).toContain('text.dashboard.attention.helpdesk_tickets_waiting')
        expect(card.find('[data-testid="my-tasks-hint"]').text()).toBe('text.my_tasks.hint.helpdesk_tickets_waiting')
        expect(card.find('[data-testid="my-tasks-count"]').text()).toContain('3')
        expect(card.attributes('data-tone')).toBe('danger')
        const action = card.find('[data-testid="my-tasks-action"]')
        expect(action.text()).toBe('text.dashboard.attention.open')
        expect(JSON.parse(action.attributes('data-to') as string)).toEqual({ name: 'helpdesk-ticket', query: { status: 'waiting' } })
    })

    it('shows an intro line above the groups', async () => {
        api.getMyTasks.mockResolvedValue({ items: [group], total: 3 })
        const w = await mountPage()
        expect(w.find('[data-testid="my-tasks-intro"]').text()).toBe('text.my_tasks.intro')
    })

    it('makes the whole item row a link with subtitle, time and chevron', async () => {
        api.getMyTasks.mockResolvedValue({
            items: [{ ...group, items: [{ ...group.items[0], subtitle: 'sam@example.test', at: '2026-11-12T08:30:00+07:00' }] }],
            total: 3,
        })
        const w = await mountPage()
        const row = w.find('[data-testid="my-tasks-item"]')
        expect(row.element.tagName).toBe('A')
        expect(JSON.parse(row.attributes('data-to') as string)).toEqual({ name: 'helpdesk-ticket-view', params: { uuid: 'u1' }, query: { tab: 'x' } })
        expect(row.text()).toContain('Ticket A')
        expect(row.text()).toContain('sam@example.test')
        expect(row.find('time').attributes('datetime')).toBe('2026-11-12T08:30:00+07:00')
        expect(row.find('[data-icon="chevron-right"]').exists()).toBe(true)
        expect(row.classes().join(' ')).toContain('hover:')
        expect(row.classes().join(' ')).toContain('focus-visible:')
    })

    it('renders an item without a route as plain text, not a link', async () => {
        api.getMyTasks.mockResolvedValue({ items: [{ ...group, items: [{ title: 'No target' }] }], total: 3 })
        const w = await mountPage()
        const row = w.find('[data-testid="my-tasks-item"]')
        expect(row.element.tagName).not.toBe('A')
        expect(row.text()).toContain('No target')
        expect(row.find('[data-icon="chevron-right"]').exists()).toBe(false)
    })

    it('renders no link for a group without a route but keeps the card and its items', async () => {
        api.getMyTasks.mockResolvedValue({ items: [{ key: 'core_backup_problem', count: 1, items: [{ title: 'Disk', route: 'setting-backups' }] }], total: 1 })
        const w = await mountPage()
        expect(w.find('[data-testid="my-tasks-action"]').exists()).toBe(false)
        expect(w.find('[data-testid="my-tasks-group"]').exists()).toBe(true)
        expect(w.findAll('[data-testid="my-tasks-item"]')).toHaveLength(1)
    })

    it('still gives a card without items a hint and an action', async () => {
        api.getMyTasks.mockResolvedValue({ items: [{ key: 'tokens_legacy', count: 2, route: 'setting-security-tokens', query: { tab: 'all' }, tone: 'warning' }], total: 2 })
        const w = await mountPage()
        expect(w.find('[data-testid="my-tasks-hint"]').exists()).toBe(true)
        expect(w.find('[data-testid="my-tasks-action"]').exists()).toBe(true)
        expect(w.find('[data-testid="my-tasks-item"]').exists()).toBe(false)
    })

    it('omits the hint when the key has no translation', async () => {
        api.getMyTasks.mockResolvedValue({ items: [{ key: 'x.no_hint', count: 1, route: 'r' }], total: 1 })
        const w = await mountPage()
        expect(w.find('[data-testid="my-tasks-hint"]').exists()).toBe(false)
    })

    it('orders groups danger, then warning (default), then info, keeping the given order inside a tone', async () => {
        const g = (key: string, tone?: string) => ({ key, count: 1, route: 'r', ...(tone ? { tone } : {}) })
        api.getMyTasks.mockResolvedValue({ items: [g('a', 'info'), g('b'), g('c', 'danger'), g('d', 'warning'), g('e', 'danger')], total: 5 })
        const w = await mountPage()
        const labels = w.findAll('[data-testid="my-tasks-group"]').map((c) => c.attributes('data-key'))
        expect(labels).toEqual(['c', 'e', 'b', 'd', 'a'])
    })

    it('says how many more there are when the list is capped', async () => {
        api.getMyTasks.mockResolvedValue({ items: [{ ...group, count: 14 }], total: 14 })
        const w = await mountPage()
        const more = w.find('[data-testid="my-tasks-more"]')
        expect(more.text()).toBe('text.my_tasks.more|{"n":12}')
        expect(JSON.parse(more.attributes('data-to') as string)).toEqual({ name: 'helpdesk-ticket', query: { status: 'waiting' } })
    })

    it('refreshes in place without a reload and keeps the groups visible meanwhile', async () => {
        api.getMyTasks.mockResolvedValueOnce({ items: [group], total: 3 })
        const w = await mountPage()
        let resolve: (v: unknown) => void = () => undefined
        api.getMyTasks.mockReturnValueOnce(new Promise((r) => (resolve = r)))
        await w.find('[data-testid="my-tasks-refresh"]').trigger('click')
        expect(api.getMyTasks).toHaveBeenCalledTimes(2)
        expect(w.text()).toContain('Ticket A')
        expect(w.find('[data-testid="skeleton"]').exists()).toBe(false)
        expect(w.find('[data-testid="my-tasks-refresh"]').attributes('disabled')).toBeDefined()
        resolve({ items: [{ ...group, items: [{ title: 'Ticket C' }] }], total: 1 })
        await flushPromises()
        expect(w.text()).toContain('Ticket C')
        expect(w.text()).not.toContain('Ticket A')
    })

    it('titles an item by its summary, else its translated type, else the generic key, never a raw code', async () => {
        const mk = (item: object) => ({ key: 'k', count: 1, route: 'r', items: [item] })
        const titleOf = async (item: object) => {
            api.getMyTasks.mockResolvedValue({ items: [mk(item)], total: 1 })
            const w = await mountPage()
            return w.find('[data-testid="my-tasks-item"] .font-medium').text()
        }
        expect(await titleOf({ title: 'Real summary', route: 'x', title_key: 'text.agentic.artifact.a' })).toBe('Real summary')
        expect(await titleOf({ title: '', route: 'x', title_key: 'text.agentic.artifact.a', title_fallback_key: 'text.agentic.artifact.unknown' })).toBe('text.agentic.artifact.a')
        expect(await titleOf({ title: '', route: 'x', title_key: 'text.agentic.artifact.missing', title_fallback_key: 'text.agentic.artifact.unknown' })).toBe('text.agentic.artifact.unknown')
    })

    it('labels the time with what it means when the provider gives at_label', async () => {
        api.getMyTasks.mockResolvedValue({
            items: [{ ...group, items: [{ title: 'T', route: 'x', at: '2026-11-12T08:30:00+07:00', at_label: 'text.my_tasks.at.failed' }] }],
            total: 1,
        })
        const w = await mountPage()
        expect(w.find('[data-testid="my-tasks-item"] time').text()).toContain('text.my_tasks.at.failed')
    })

    it('ignores a second load while one is in flight', async () => {
        let resolve: (v: unknown) => void = () => undefined
        api.getMyTasks.mockReturnValue(new Promise((r) => (resolve = r)))
        const w = await mountPage()
        await (w.vm as any).load()
        expect(api.getMyTasks).toHaveBeenCalledTimes(1)
        resolve({ items: [], total: 0 })
    })
})
