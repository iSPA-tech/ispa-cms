import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string) => k, locale: { value: 'en' } }),
}))
vi.mock('@tanstack/vue-query', () => ({ useQueryClient: () => ({ fetchQuery: vi.fn() }) }))
vi.mock('@/libs/upload', () => ({ getUploadDashboard: vi.fn() }))
vi.mock('@/mixins', () => ({ numberFormat: (v: number) => String(v) }))

const state = { data: {} as any, loading: false }
vi.mock('@/hooks', () => ({
    useUploadDashboard: () => ({
        data: ref({ data: state.data }),
        isLoading: ref(state.loading),
        isFetching: ref(false),
        error: ref(null),
    }),
}))

const rows = (n: number) =>
    Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `file-${i}`, file_size: 1024 }))

const buildPayload = (totalBytes: number, over: any = {}) => ({
    stats: {
        total_files: 20,
        total_bytes: totalBytes,
        capacity_bytes: 100,
        new_uploads_24h: 2,
        counts: { image: 10, video: 5, document: 5 },
    },
    unused_uploads: rows(8),
    recent_uploads: rows(8),
    largest_uploads: rows(8),
    ...over,
})

const IStatTile = defineComponent({
    props: {
        label: String,
        value: [Number, String],
        format: String,
        description: String,
        tone: String,
        loading: Boolean,
    },
    setup: () => () => h('div', { 'data-tile': '1' }),
})
const IChart = defineComponent({
    props: ['type', 'palette', 'series', 'categories', 'options', 'horizontal'],
    setup: () => () => h('div', { 'data-chart': '1' }),
})
const IButton = defineComponent({
    props: ['variant', 'size', 'loading', 'to'],
    setup: (_p, { slots, attrs }) => () => h('button', { 'data-ibutton': '1', ...attrs }, slots.default?.()),
})
const Breadcrumbs = defineComponent({
    setup: (_p, { slots }) => () => h('div', [slots.default?.(), slots.description?.()]),
})
const passthrough = (tag: string) =>
    defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })

const mountPage = async () => {
    const Page = (await import('../../../../cms/Content/admin/pages/media/dashboard.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                breadcrumbs: Breadcrumbs,
                'i-button': IButton,
                'i-stat-tile': IStatTile,
                'i-chart': IChart,
                'i-box': passthrough('div'),
                'i-icon': true,
                'i-attention-strip': true,
                'a-skeleton': true,
                'a-alert': true,
                'a-empty': true,
                'router-link': passthrough('a'),
            },
        },
    })
    await flushPromises()
    return w
}

const storageTone = async (bytes: number) => {
    state.data = buildPayload(bytes)
    const w = await mountPage()
    const tile = w.findAllComponents(IStatTile).find((t) => t.props('label') === 'text.media.storage-usage')!
    return tile.props()
}

describe('media dashboard', () => {
    beforeEach(() => {
        state.loading = false
        state.data = buildPayload(50)
    })

    it('renders six KPI tiles in the design-system grid', async () => {
        const w = await mountPage()
        expect(w.get('[data-testid="media-kpi-grid"]').classes()).toEqual(
            expect.arrayContaining(['grid-cols-2', 'gap-3', 'sm:grid-cols-3', 'xl:grid-cols-6'])
        )
        expect(w.findAllComponents(IStatTile)).toHaveLength(6)
    })

    it('shows six skeleton tiles while loading', async () => {
        state.loading = true
        state.data = {}
        const w = await mountPage()
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(6)
        expect(tiles.every((t) => t.props('loading'))).toBe(true)
    })

    it('maps storage usage to tone thresholds', async () => {
        const low = await storageTone(50)
        expect(low.format).toBe('percent')
        expect(low.value).toBe(50)
        expect(low.tone).toBe('default')
        expect((await storageTone(92)).tone).toBe('warning')
        expect((await storageTone(99)).tone).toBe('danger')
    })

    it('shows the used size as the main value when capacity is unknown', async () => {
        state.data = buildPayload(1024 * 1024, { stats: { total_files: 3, total_bytes: 1024 * 1024, capacity_bytes: 0 } })
        const w = await mountPage()
        const tile = w.findAllComponents(IStatTile).find((t) => t.props('label') === 'text.media.storage-usage')!
        expect(tile.props('value')).toMatch(/MB/)
        expect(tile.props('format')).not.toBe('percent')
        expect(tile.props('description')).toBeUndefined()
        expect(tile.props('tone')).toBe('default')
    })

    it('warns on unused media', async () => {
        const w = await mountPage()
        const unused = w.findAllComponents(IStatTile).find((t) => t.props('label') === 'text.media.unused')!
        expect(unused.props('tone')).toBe('warning')
    })

    it('draws the type share as a categorical donut, bars beyond five types', async () => {
        let w = await mountPage()
        let chart = w.findComponent(IChart)
        expect(chart.props('type')).toBe('donut')
        expect(chart.props('palette')).not.toBe('status')

        state.data = buildPayload(50, {
            type_distribution: ['a', 'b', 'c', 'd', 'e', 'f'].map((k) => ({ key: k, count: 3 })),
        })
        w = await mountPage()
        chart = w.findComponent(IChart)
        expect(chart.props('type')).toBe('bars')
        expect(chart.props('horizontal')).not.toBe(false)
    })

    it('uses an i-button for reload', async () => {
        const w = await mountPage()
        const reload = w.findAllComponents(IButton).find((b) => b.attributes('data-testid') === 'media-reload')!
        expect(reload.props('variant')).toBe('default')
        expect(reload.props('size')).toBe('sm')
    })

    it('caps lists at 5 rows and hides null blocks', async () => {
        let w = await mountPage()
        expect(w.findAll('[data-testid="media-unused-rows"] li')).toHaveLength(5)
        expect(w.findAll('[data-testid="media-recent-rows"] li')).toHaveLength(5)
        expect(w.findAll('[data-testid="media-largest-rows"] tbody tr')).toHaveLength(5)

        state.data = buildPayload(50, { recent_uploads: null })
        w = await mountPage()
        expect(w.find('[data-testid="media-recent-box"]').exists()).toBe(false)
    })

    it('has no English fallback strings', async () => {
        state.data = { stats: { total_files: 0, total_bytes: 0 }, unused_uploads: [], recent_uploads: [] }
        const w = await mountPage()
        const text = w.text()
        for (const phrase of [
            'No type data yet',
            'No storage data yet',
            'No unused media detected',
            'No uploads found yet',
        ]) {
            expect(text).not.toContain(phrase)
        }
    })
})
