import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string) => k, locale: { value: 'en' } }),
}))

const payload = {
    stats: { total_users: 20, active_users: 15, inactive_users: 5, new_users: 3, students: 12, staffs: 8 },
    roles: Array.from({ length: 10 }, (_, i) => ({ id: i, name: `Role ${i}`, total: 10 - i })),
    latest_users: Array.from({ length: 8 }, (_, i) => ({ id: i + 1, name: `User ${i}` })),
}

vi.mock('@/libs', () => ({ getUserDashboard: vi.fn(async () => ({ status: 'success', data: payload })) }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))

const IStatTile = defineComponent({
    props: { label: String, value: Number, tone: String, to: Object, loading: Boolean },
    setup: (_p, { attrs }) => () => h('div', { 'data-tile': '1', ...attrs }),
})
const IChart = defineComponent({
    props: { type: String, horizontal: Boolean, series: Array, categories: Array },
    setup: () => () => h('div', { 'data-chart': '1' }),
})
const IStrip = defineComponent({
    props: ['items'],
    setup: () => () => h('div', { 'data-strip': '1' }),
})
const IButton = defineComponent({
    props: ['variant', 'size', 'loading'],
    setup: (_p, { slots, attrs }) => () => h('button', { ...attrs }, slots.default?.()),
})
const Breadcrumbs = defineComponent({
    setup: (_p, { slots }) => () => h('div', [slots.default?.(), slots.description?.()]),
})
const passthrough = (tag: string) =>
    defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })

const mountPage = async () => {
    const Page = (await import('../../../../cms/Core/admin/pages/user/dashboard.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                breadcrumbs: Breadcrumbs,
                'i-button': IButton,
                'i-stat-tile': IStatTile,
                'i-chart': IChart,
                'i-attention-strip': IStrip,
                'i-box': passthrough('div'),
                'i-icon': true,
                'a-empty': true,
                'a-alert': true,
                'router-link': passthrough('a'),
            },
        },
    })
    await flushPromises()
    return w
}

describe('user dashboard', () => {
    it('renders six tiles in the design-system grid with meaningful tones', async () => {
        const w = await mountPage()
        expect(w.get('[data-testid="user-kpi-grid"]').classes()).toEqual(
            expect.arrayContaining(['grid-cols-2', 'gap-3', 'sm:grid-cols-3', 'xl:grid-cols-6'])
        )
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(6)
        const tone = Object.fromEntries(tiles.map((t) => [t.props('label'), t.props('tone')]))
        expect(tone['text.user.metrics.active_users']).toBe('success')
        expect(tone['text.user.metrics.inactive_users']).toBe('warning')
        expect(tone['text.user.metrics.new_users']).toBe('info')
    })

    it('passes the inactive attention item to the strip', async () => {
        const w = await mountPage()
        const items = w.findComponent(IStrip).props('items')
        expect(items).toEqual([expect.objectContaining({ key: 'inactive_users', count: 5, route: 'user-manage' })])
    })

    it('uses a default sm reload i-button and a horizontal bars chart capped at 8 roles', async () => {
        const w = await mountPage()
        const reload = w.findAllComponents(IButton).find((b) => b.attributes('data-testid') === 'user-reload')!
        expect(reload.props('variant')).toBe('default')
        expect(reload.props('size')).toBe('sm')
        const chart = w.findComponent(IChart)
        expect(chart.props('type')).toBe('bars')
        expect(chart.props('horizontal')).toBe(true)
        expect(chart.props('series')[0].data).toHaveLength(8)
    })

    it('caps recent users at 5 rows', async () => {
        const w = await mountPage()
        expect(w.get('[data-testid="user-latest-list"]').element.children).toHaveLength(5)
    })
})
