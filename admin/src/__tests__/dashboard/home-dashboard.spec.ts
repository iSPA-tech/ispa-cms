import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { resetPermissionIndexCache } from '@/hooks/useCan'
import IStatStrip from '@/components/common/i-stat-strip.vue'

vi.stubGlobal('IntersectionObserver', undefined)

const payload = {
    attention: [{ key: 'comments', route: 'post-comment', count: 3 }],
    modules: { ecommerce: true },
    traffic: {
        views: 100,
        visitors: 40,
        sessions: 50,
        previous: { views: 80, visitors: 40, sessions: 25 },
        lifetime: { views: 1, visitors: 1, sessions: 1, downloads: 1 },
    },
    content: { posts_total: 5, posts_published: 3, posts_pending: 1, posts_new: 1 },
    comments: { total: 1, new: 1, pending: 1, confirmed: 0, rejected: 0 },
    commerce: { orders_new: 2, orders_processing: 1, revenue_paid: 10, aov: 5, previous: { orders_new: 1, revenue_paid: 5 } },
    people: { users_total: 9, users_new: 1 },
    series: {
        views: [['2026-11-01', 5], ['2026-11-02', 7]],
        visitors: [['2026-11-01', 2], ['2026-11-02', 3]],
    },
}
const state = vi.hoisted(() => ({ summary: null as unknown, active: [] as string[] }))

vi.mock('@/libs/dashboard', () => ({ getDashboardSummary: vi.fn(async () => state.summary) }))
vi.mock('@/ajax', () => ({
    default: {
        get: vi.fn(async () => ({
            data: {
                status: 'success',
                data: {
                    range: '7d',
                    ranges: ['7d', '1m'],
                    type: '',
                    types: [{ type: 'post', views: 4 }],
                    items: [],
                },
            },
        })),
    },
}))
vi.mock('@/ajax-v4', () => ({ default: { get: vi.fn() } }))
vi.mock('@/hooks', () => ({
    useActiveModules: () => ({
        activeModuleCatalogLoaded: { value: true },
        isModuleActive: (name: string) => state.active.includes(name),
    }),
    widgetInputDefault: vi.fn(),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k, te: () => false }) }))

const IStatTile = defineComponent({
    name: 'IStatTile',
    props: ['label', 'value', 'previous', 'tone', 'to', 'format', 'loading', 'spark', 'sparkSlot', 'valueTone', 'fractionDigits'],
    setup: () => () => h('div', { 'data-tile': '1' }),
})
const IChart = defineComponent({
    name: 'IChart',
    props: ['type', 'series', 'categories'],
    setup: () => () => h('div', { 'data-chart': '1' }),
})
vi.mock('@/components/common/i-stat-tile.vue', () => ({ default: IStatTile }))
vi.mock('@/components/common/i-chart.vue', () => ({ default: IChart }))
vi.mock('@/components/common/i-attention-strip.vue', () => ({
    default: defineComponent({
        name: 'IAttentionStrip',
        props: ['items'],
        setup: (p) => () => h('div', { 'data-strip': p.items.length }),
    }),
}))

const IButton = defineComponent({
    props: ['variant', 'size', 'loading'],
    setup: (_p, { slots, attrs }) => () => h('button', attrs, slots.default?.()),
})
const Breadcrumbs = defineComponent({
    props: ['items'],
    setup: (_p, { slots }) => () => h('div', [slots.default?.()]),
})
const passthrough = (tag: string) => defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })

const mountPage = async (
    summary: unknown = payload,
    groups: string[] = ['post', 'comment', 'order', 'user', 'contact']
) => {
    state.summary = summary
    window.localStorage.setItem(
        CACHE_KEY_ACCOUNT_DATA,
        JSON.stringify({
            id: 'u',
            roles: [
                {
                    id: 'r',
                    permissions: groups.map((g, i) => ({
                        id: i + 1,
                        group: g,
                        name: 'viewAny',
                    })),
                },
            ],
        })
    )
    resetPermissionIndexCache()
    const Page = (await import('@/pages/dashboard.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: (k: string) => k },
            directives: { loading: () => undefined },
            stubs: {
                breadcrumbs: Breadcrumbs,
                'i-button': IButton,
                'i-box': passthrough('div'),
                'i-stat-strip': IStatStrip,
                'i-icon': true,
                'a-segmented': true,
                'a-select': true,
                'a-popover': passthrough('div'),
                'a-alert': true,
                'student-report': true,
            },
        },
    })
    await flushPromises()
    return w
}

describe('home dashboard', () => {
    beforeEach(() => {
        window.localStorage.clear()
        state.active = []
    })

    describe('hub band and colour (spec 2026-11-19-admin-dashboard-hub-band-design.md)', () => {
        it('shows the hub band from summary.hub', async () => {
            const hub = { connected: false, development: false, portal_url: 'https://ispa.tech/portal' }
            const band = (await mountPage({ ...payload, hub })).find('[data-testid=dashboard-hub-band]')
            expect(band.exists()).toBe(true)
            expect(band.attributes('data-state')).toBe('not-connected')
            expect((await mountPage(payload)).find('[data-testid=dashboard-hub-band]').exists()).toBe(false)
        })

        const tileByLabel = async (label: string) =>
            (await mountPage()).findAllComponents({ name: 'IStatTile' }).find((tile) => tile.props('label') === label)

        it('passes daily series to the period tiles', async () => {
            const views = await tileByLabel('text.rp.views')
            expect(views?.props('spark')).toEqual([5, 7])
            expect(views?.props('sparkSlot')).toBe(1)
            expect((await tileByLabel('text.dashboard.visitors'))?.props('spark')).toEqual([2, 3])
            expect((await tileByLabel('text.rp.sessions'))?.props('spark')).toEqual([])
        })

        it('colours pending posts', async () => {
            expect((await tileByLabel('text.dashboard.posts_pending'))?.props('valueTone')).toBe('warning')
            expect((await tileByLabel('text.dashboard.posts_published'))?.props('valueTone')).toBe('success')
            expect((await tileByLabel('text.dashboard.posts_total'))?.props('valueTone')).toBe('')
        })
    })

    it('renders section grids with the design-system classes and tones', async () => {
        const w = await mountPage()
        for (const id of ['traffic-grid', 'lifetime-row', 'content-grid', 'comments-grid', 'commerce-grid', 'people-grid']) {
            // Overview sections render as one i-stat-strip band (1c), not separate cards.
            expect(w.get(`[data-testid="${id}"]`).classes()).toEqual(expect.arrayContaining(['i-stat-strip', 'grid', 'overflow-hidden']))
        }
        const tiles = w.findAllComponents(IStatTile)
        const byLabel = Object.fromEntries([...tiles].reverse().map((t) => [t.props('label'), t.props()]))
        expect(byLabel['text.rp.views'].tone).toBe('info')
        expect(byLabel['text.rp.views'].previous).toBe(80)
        expect(byLabel['text.dashboard.posts_pending'].tone).toBe('warning')
        expect(byLabel['text.dashboard.revenue_paid'].tone).toBe('success')
        expect(byLabel['text.dashboard.revenue_paid'].format).toBe('currency')
        // Comment tiles filter the list by the statuses comments are saved with (public form, then approval).
        expect(byLabel['text.rp.pending'].to).toEqual({ name: 'post-comment', query: { status: 'status_pending' } })
        expect(byLabel['text.rp.confirmed'].to).toEqual({ name: 'post-comment', query: { status: 'status_published' } })
    })

    // the period row follows the 7d/30d/90d chip, so its labels drop "Tổng" (kept
    // for the all-time row); views per session replaces QR scans, which leave the dashboard.
    describe('period and all-time rows', () => {
        const labelsOf = (w: Awaited<ReturnType<typeof mountPage>>, id: string) =>
            w.get(`[data-testid="${id}"]`).findAllComponents(IStatTile).map((t) => t.props('label'))

        it('labels the period row without "total" and never shows scans', async () => {
            state.active = ['GeoPlaces']
            const w = await mountPage({ ...payload, traffic: { ...payload.traffic, scans: 9, lifetime: { ...payload.traffic.lifetime, scans: 9 } } })
            expect(labelsOf(w, 'traffic-grid')).toEqual([
                'text.rp.views',
                'text.dashboard.visitors',
                'text.rp.sessions',
                'text.dashboard.views_per_session',
            ])
            expect(labelsOf(w, 'lifetime-row')).toEqual([
                'text.rp.total-view',
                'text.dashboard.visitors',
                'text.rp.total-sessions',
                'text.rp.total-downloads',
            ])
            expect(w.text()).not.toContain('scan')
        })

        it('shows views per session with one decimal, its previous period and a daily line', async () => {
            const w = await mountPage({
                ...payload,
                series: { ...payload.series, sessions: [['2026-11-01', 2], ['2026-11-02', 0]] },
            })
            const tile = w
                .findAllComponents(IStatTile)
                .find((t) => t.props('label') === 'text.dashboard.views_per_session')
            expect(tile?.props('value')).toBe(2)
            expect(tile?.props('previous')).toBe(3.2)
            expect(tile?.props('fractionDigits')).toBe(1)
            expect(tile?.props('spark')).toEqual([2.5, 0])
        })

        it('leaves views per session at zero when there was no session', async () => {
            const w = await mountPage({
                ...payload,
                traffic: { ...payload.traffic, sessions: 0, previous: { views: 1, visitors: 1, sessions: 0 } },
            })
            const tile = w
                .findAllComponents(IStatTile)
                .find((t) => t.props('label') === 'text.dashboard.views_per_session')
            expect(tile?.props('value')).toBe(0)
            expect(tile?.props('previous')).toBe(null)
        })
    })

    it('passes attention items to the strip and draws one trend chart', async () => {
        const w = await mountPage()
        expect(w.get('[data-strip]').attributes('data-strip')).toBe('1')
        const charts = w.findAllComponents(IChart)
        expect(charts.map((c) => c.props('type'))).toEqual(['trend'])
        expect(charts[0].props('series')).toHaveLength(2)
        expect(charts[0].props('series')[1].data).toEqual([2, 3])
        expect(charts[0].props('categories')).toEqual(['2026-11-01', '2026-11-02'])
    })

    it('uses i-button for reload, never shows the domain token, and has no hand-rolled header', async () => {
        const w = await mountPage()
        expect(w.find('[data-testid="dashboard-reload"]').exists()).toBe(true)
        expect(w.find('[data-testid="domain-status"]').exists()).toBe(false)
        expect(w.text()).not.toContain('Token')
        expect(w.find('header').exists()).toBe(false)
    })

    it('hides sections whose block is null and skips an all-zero chart', async () => {
        const w = await mountPage({
            modules: {},
            traffic: { views: 0, visitors: 0, sessions: 0, lifetime: {} },
            series: { views: [['2026-11-01', 0]], visitors: [['2026-11-01', 0]] },
        })
        expect(w.find('[data-testid="content-grid"]').exists()).toBe(false)
        expect(w.find('[data-testid="comments-grid"]').exists()).toBe(false)
        expect(w.find('[data-testid="commerce-grid"]').exists()).toBe(false)
        expect(w.find('[data-testid="people-grid"]').exists()).toBe(false)
        expect(w.findAllComponents(IChart)).toHaveLength(0)
    })

    it('links every summary tile for an admin with all permissions', async () => {
        const w = await mountPage()
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles.length).toBeGreaterThan(10)
        expect(tiles.filter((t) => !t.props('to')).map((t) => t.props('label'))).toEqual([])
        const byLabel = Object.fromEntries([...tiles].reverse().map((t) => [t.props('label'), t.props('to')]))
        expect(byLabel['text.rp.total-view']).toEqual({ name: 'post-report' })
        expect(byLabel['text.dashboard.revenue_paid']).toEqual({ name: 'order-manage' })
        // the count and its list link use the same in-progress bucket
        expect(byLabel['text.dashboard.orders_processing']).toEqual({
            name: 'order-manage',
            query: { status_group: 'in_progress' },
        })
        expect(byLabel['text.dashboard.users_new']).toEqual({ name: 'user-manage' })
    })

    it('leaves traffic tiles unlinked when the viewer cannot open post lists', async () => {
        const w = await mountPage(payload, ['order'])
        const tiles = w.findAllComponents(IStatTile)
        const traffic = tiles.filter((t) => ['text.rp.total-view', 'text.dashboard.visitors'].includes(t.props('label')))
        expect(traffic.length).toBeGreaterThan(0)
        expect(traffic.every((t) => !t.props('to'))).toBe(true)
        const revenue = tiles.find((t) => t.props('label') === 'text.dashboard.revenue_paid')
        expect(revenue?.props('to')).toEqual({ name: 'order-manage' })
    })

    it('names each settings notice by its own key', async () => {
        const w = await mountPage({
            ...payload,
            notices: [
                { key: 'captcha_missing', route: 'setting-advanced', hash: '#x' },
                { key: 'other', route: 'setting-general', hash: '#y', message: 'Custom notice text' },
            ],
        } as unknown)
        const notices = w.findAll('[data-testid="dashboard-notice"]')
        expect(notices).toHaveLength(2)
        expect(notices[0].text()).toContain('text.s.gg-site-key')
        expect(notices[1].text()).toContain('Custom notice text')
        expect(notices[1].text()).not.toContain('text.s.gg-site-key')
    })
})
