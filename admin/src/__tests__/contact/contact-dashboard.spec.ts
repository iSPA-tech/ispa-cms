import { mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { registerUiExtension, resetUiExtensions } from '@/runtime/uiExtensions'
import ContactDashboard from '../../../../cms/Content/admin/pages/contact/dashboard.vue'

const mockedHooks = vi.hoisted(() => ({
    useActiveModules: vi.fn(),
    useContactDashboard: vi.fn(),
}))
const dashboardData = ref<Record<string, unknown>>({})
const isLoading = ref(false)

vi.mock('@/hooks', () => ({
    useActiveModules: mockedHooks.useActiveModules,
    useContactDashboard: mockedHooks.useContactDashboard,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
    useRoute: () => ({ query: {} }),
    useRouter: () => ({ push: vi.fn() }),
}))

const IStatTile = defineComponent({
    props: { label: String, value: Number, tone: String, to: Object, loading: Boolean },
    setup: () => () => h('div', { 'data-tile': '1' }),
})
const IChart = defineComponent({
    props: { type: String, palette: String, series: Array, categories: Array, horizontal: Boolean },
    setup: () => () => h('div', { 'data-chart': '1' }),
})
const IButton = defineComponent({
    props: ['variant', 'size', 'loading', 'to'],
    setup: (_p, { slots }) => () => h('button', { 'data-ibutton': '1' }, slots.default?.()),
})
const IAttentionStrip = defineComponent({
    props: ['items'],
    setup: () => () => h('div', { 'data-strip': '1' }),
})
const passthrough = (tag: string) => defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })

const mountDashboard = () =>
    mount(ContactDashboard, {
        global: {
            config: { globalProperties: { $t: (key: string) => key } },
            stubs: {
                breadcrumbs: defineComponent({
                    setup: (_p, { slots }) => () => h('nav', [slots.default?.(), slots.description?.()]),
                }),
                'i-button': IButton,
                'i-stat-tile': IStatTile,
                'i-chart': IChart,
                'i-attention-strip': IAttentionStrip,
                'i-box': passthrough('div'),
                'i-icon': true,
                'a-alert': true,
                'a-segmented': true,
                'a-empty': { template: '<div data-test="empty" />' },
                'a-tag': passthrough('span'),
                'router-link': passthrough('a'),
            },
        },
    })

describe('Contact dashboard', () => {
    beforeEach(() => {
        isLoading.value = false
        dashboardData.value = {
            activity: [{ date: '2026-11-01', total: 3 }],
            recentContacts: Array.from({ length: 8 }, (_, i) => ({
                id: i + 1,
                name: `Sender ${i}`,
                email: `s${i}@example.test`,
                status: 'new',
            })),
            refreshedAt: '2026-11-03T08:00:00+07:00',
            sources: [{ label: 'website', total: 4 }],
            status: { blocked: 1, pending: 5, replied: 2, unreadEmails: 3 },
            totals: { assigned: 0, month: 9, subscribed: 0, today: 2, total: 10, week: 4 },
            types: [{ label: 'visitor', total: 4 }],
        }
        mockedHooks.useActiveModules.mockReturnValue({ isModuleActive: () => false })
        mockedHooks.useContactDashboard.mockReturnValue({
            data: dashboardData,
            error: ref(null),
            isError: ref(false),
            isFetching: ref(false),
            isLoading,
            refetch: vi.fn(),
        })
    })

    it('renders six KPI tiles on the design-system grid with meaningful tones', () => {
        const w = mountDashboard()
        expect(w.get('[data-testid="contact-kpi-grid"]').classes()).toEqual(
            expect.arrayContaining(['grid-cols-2', 'gap-3', 'sm:grid-cols-3', 'xl:grid-cols-6'])
        )
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(6)
        expect(tiles.map((t) => t.props('tone'))).toEqual([
            'warning', 'warning', 'info', 'success', 'default', 'danger',
        ])
        expect(tiles[0].props('value')).toBe(5)
        expect(tiles[0].props('to')).toBeTruthy()
    })

    it('shows skeleton tiles while loading', () => {
        isLoading.value = true
        const w = mountDashboard()
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(6)
        expect(tiles.every((t) => t.props('loading') === true)).toBe(true)
    })

    it('feeds the attention strip with pending replies linking to the contact list', () => {
        const items = mountDashboard().findComponent(IAttentionStrip).props('items')
        expect(items).toHaveLength(1)
        expect(items[0]).toMatchObject({ route: 'contact-manage', count: 5 })
    })

    it('renders i-chart trend plus horizontal bars and capped recent rows', () => {
        const w = mountDashboard()
        const charts = w.findAllComponents(IChart)
        expect(charts.map((c) => c.props('type'))).toEqual(['trend', 'bars', 'bars'])
        expect(charts[1].props('horizontal')).toBe(true)
        expect(w.findAll('[data-testid="contact-recent-rows"] tbody tr')).toHaveLength(5)
    })

    it('uses i-button for reload (default) and the manage action (primary)', () => {
        const buttons = mountDashboard().findAllComponents(IButton)
        expect(buttons.map((b) => b.props('variant'))).toEqual(['default', 'primary'])
    })

    it('keeps the dashboard visible and shows a blocked tile when no active contacts remain', () => {
        dashboardData.value = {
            ...dashboardData.value,
            recentContacts: [],
            sources: [],
            types: [],
            activity: [],
            status: { blocked: 2, pending: 0, replied: 0, unreadEmails: 0 },
            totals: { total: 0, today: 0 },
        }
        const w = mountDashboard()
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(6)
        expect(tiles[5].props('value')).toBe(2)
        expect(w.findAll('[data-chart]')).toHaveLength(0)
    })

    it('renders the blocked status badge for blocked recent contacts', () => {
        dashboardData.value = {
            ...dashboardData.value,
            recentContacts: [{ id: 1, name: 'Blocked sender', status: 'blocked', source_label: 'website' }],
        }
        expect(mountDashboard().text()).toContain('text.status-off')
    })

    // the inquiry scope switch exists only when a module registered the `contact.inquiry-scope` extension and
    // that module is active; the screen names no module itself.
    describe('inquiry scope extension', () => {
        beforeEach(() => resetUiExtensions())

        it('shows no scope switch without a registered inquiry scope, even when modules are active', () => {
            mockedHooks.useActiveModules.mockReturnValue({ isModuleActive: () => true })
            const w = mountDashboard()
            expect(w.find('[data-testid="contact-scope"]').exists()).toBe(false)
        })

        it('shows the scope switch when the registered module is active, and hides it when it is not', () => {
            registerUiExtension('contact.inquiry-scope', { sourcePrefix: 'example-inquiry:', moduleName: 'ExampleInquiries' })
            const asked: string[] = []
            mockedHooks.useActiveModules.mockReturnValue({ isModuleActive: (name: string) => (asked.push(name), true) })
            expect(mountDashboard().find('[data-testid="contact-scope"]').exists()).toBe(true)
            expect(asked).toContain('ExampleInquiries')

            mockedHooks.useActiveModules.mockReturnValue({ isModuleActive: () => false })
            expect(mountDashboard().find('[data-testid="contact-scope"]').exists()).toBe(false)
        })
    })
})
