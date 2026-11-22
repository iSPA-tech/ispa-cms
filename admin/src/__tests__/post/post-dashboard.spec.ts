import { mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import PostDashboard from '../../../../cms/Content/admin/pages/dashboard.vue'

const mocks = vi.hoisted(() => ({ useEnumerates: vi.fn() }))

vi.mock('@/hooks', () => ({ useEnumerates: mocks.useEnumerates }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))
vi.mock('vue-i18n', () => {
    const messages: Record<string, string> = {
        'text.type.post': 'Bài viết',
        'text.type.page': 'Trang tĩnh',
    }

    return {
        useI18n: () => ({
            t: (key: string, fallback?: string) => messages[key] || (typeof fallback === 'string' ? fallback : key),
            te: (key: string) => key in messages,
            locale: ref('vi'),
        }),
    }
})

const IStatTile = defineComponent({
    props: ['label', 'value', 'tone', 'to', 'loading', 'description'],
    setup: () => () => h('div', { 'data-tile': '1' }),
})
const IChart = defineComponent({
    props: { type: String, series: Array, categories: Array, horizontal: Boolean, palette: String, height: Number },
    setup: () => () => h('div', { 'data-chart': '1' }),
})
const IAttentionStrip = defineComponent({
    props: ['items'],
    setup: () => () => h('div', { 'data-strip': '1' }),
})
const IButton = defineComponent({
    props: ['variant', 'size'],
    setup: (_p, { slots, attrs }) => () => h('button', { 'data-ibutton': '1', ...attrs }, slots.default?.()),
})

const mountDashboard = () => {
    mocks.useEnumerates.mockReturnValue({
        data: ref({
            data: [
                { type: 'status', enum_key: 'status_published', name: 'Công khai', name_alias: 'Published' },
                { type: 'status', enum_key: 'status_pending', name: 'Chờ duyệt', name_alias: 'Pending' },
            ],
        }),
    })

    return mount(PostDashboard, {
        global: {
            provide: {
                isLoading: ref(false),
                dashboardData: ref({
                    recents: [
                        { uuid: 'a', name: 'Published post', type: 'post', status: 'status_published', updated_at: '2026-10-30 10:00:00' },
                        { uuid: 'b', name: 'Pending page', type: 'page', status: 'status_pending', updated_at: '2026-10-30 10:00:00' },
                    ],
                    incoming: [],
                    postTypes: { post: 1, page: 1 },
                    commentCount: 0,
                }),
                dashboardError: ref(null),
                dashboardLastFetchedAt: ref(null),
                refreshPostDashboard: () => {},
            },
            config: { globalProperties: { $t: (key: string) => key } },
            stubs: {
                breadcrumbs: { template: '<nav><slot /></nav>' },
                'router-link': { template: '<a><slot /></a>' },
                'a-tag': { props: ['color'], template: '<span class="tag" :data-color="color"><slot /></span>' },
                'a-badge': { props: ['status', 'text'], template: '<span class="badge" :data-status="status">{{ text }}</span>' },
                'i-button': IButton,
                'i-stat-tile': IStatTile,
                'i-chart': IChart,
                'i-attention-strip': IAttentionStrip,
                'i-box': { template: '<section><slot /></section>' },
                'i-icon': true,
                'quick-report': true,
                'a-alert': true,
                'a-skeleton': true,
                'a-empty': true,
            },
        },
    })
}

describe('Post dashboard recent list', () => {
    it('labels status from the status enumerates and tags each row with its type colour', () => {
        const wrapper = mountDashboard()
        const text = wrapper.text()

        expect(text).not.toContain('status_published')
        expect(text).not.toContain('Status_published')

        const badges = wrapper.findAll('.badge').map((badge) => [badge.text(), badge.attributes('data-status')])
        expect(badges).toEqual([
            ['Công khai', 'success'],
            ['Chờ duyệt', 'warning'],
        ])

        const tags = wrapper.findAll('.tag').map((tag) => [tag.text(), tag.attributes('data-color')])
        expect(tags).toEqual([
            ['Bài viết', 'blue'],
            ['Trang tĩnh', 'purple'],
        ])
    })

    it('renders four KPI tiles on the design-system grid with meaningful tones', () => {
        const wrapper = mountDashboard()
        expect(wrapper.get('[data-testid="post-kpi-grid"]').classes()).toEqual(
            expect.arrayContaining(['grid-cols-2', 'gap-3', 'xl:grid-cols-4'])
        )
        const tiles = wrapper.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(4)
        expect(tiles.map((tile) => tile.props('tone'))).toEqual(['info', 'default', 'warning', 'success'])
    })

    it('passes scheduled posts to the attention strip and the type split to a horizontal bars chart', () => {
        const wrapper = mountDashboard()
        expect(wrapper.findComponent(IAttentionStrip).props('items')).toEqual([
            expect.objectContaining({ key: 'posts_scheduled', route: 'post-post', count: 0 }),
        ])
        const chart = wrapper.findComponent(IChart)
        expect(chart.props('type')).toBe('bars')
        expect(chart.props('horizontal')).toBe(true)
        expect(chart.props('categories')).toEqual(['Bài viết', 'Trang tĩnh'])
    })

    it('uses i-button for reload (default) and create (primary)', () => {
        const wrapper = mountDashboard()
        expect(wrapper.findAllComponents(IButton).map((b) => b.props('variant'))).toEqual(
            expect.arrayContaining(['default', 'primary'])
        )
    })
})
