import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h, ref } from 'vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string) => k, locale: { value: 'en' } }),
}))

const refetchHealth = vi.fn()
vi.mock('@/hooks', () => ({
    useAgenticHealth: () => ({ data: ref({ status: 'warn', provider: { ai_driver: 'openai' } }), isFetching: ref(false), refetch: refetchHealth }),
    useAgenticRuns: () => ({ data: ref({ data: [], meta: { total: 7 } }), isLoading: ref(false), isFetching: ref(false) }),
    useAgenticApprovals: () => ({ data: ref({ meta: { total: 3 } }) }),
    useAgenticRun: () => ({ data: ref(null), isFetching: ref(false) }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticPermissions', () => ({
    useAgenticPermissions: () => ({ canViewAgentic: computed(() => true), canProvisionAgentic: computed(() => true), canOperateAgentic: computed(() => true) }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMeta', () => ({
    useAgenticMeta: () => ({ runtimeStatusClass: () => '', formatMeta: () => '', runColumns: [], runStatusOptions: [] }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticFilters', () => ({
    useAgenticFilters: () => ({ runFilters: {} }),
}))
vi.mock('../../../../cms/Core/admin/pages/setting/agentic/composables/useAgenticMutations', () => ({
    useAgenticMutations: () => ({
        handleSyncTools: vi.fn(), handleSeedTemplates: vi.fn(), handleStopRun: vi.fn(), handleCancelRun: vi.fn(), handleRerunRun: vi.fn(),
        isSyncPending: ref(false), isSeedTemplatesPending: ref(false), activeRunId: ref(null), runActionMode: ref(''),
    }),
}))

const IStatTile = defineComponent({
    props: ['label', 'value', 'tone', 'to', 'description', 'loading'],
    setup: () => () => h('div', { 'data-tile': '1' }),
})
const IAttentionStrip = defineComponent({
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
const passthrough = (tag: string) => defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })
const stub = (name: string) => defineComponent({ name, setup: () => () => h('div', { 'data-stub': name }) })

const mountPage = async () => {
    const Page = (await import('../../../../cms/Core/admin/pages/setting/agentic/AgenticDashboard.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                breadcrumbs: Breadcrumbs,
                'i-button': IButton,
                'i-stat-tile': IStatTile,
                'i-attention-strip': IAttentionStrip,
                'i-box': passthrough('div'),
                'i-icon': true,
                'a-popconfirm': passthrough('div'),
                AgenticHealthBadge: stub('health'),
                AgenticSubNav: stub('subnav'),
                AgenticTaskLauncher: stub('launcher'),
                AgenticRunsSection: stub('runs'),
                AgenticRunDetailDrawer: stub('drawer'),
                AgenticFirstTimeWizard: stub('wizard'),
                'no-permission-error': true,
            },
        },
    })
    await flushPromises()
    return w
}

describe('agentic dashboard', () => {
    it('renders four KPI tiles with meaningful tones and the design-system grid', async () => {
        const w = await mountPage()
        expect(w.get('[data-testid="agentic-kpi-grid"]').classes()).toEqual(
            expect.arrayContaining(['grid-cols-2', 'gap-3', 'sm:grid-cols-3', 'xl:grid-cols-4'])
        )
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(4)
        const byLabel = Object.fromEntries(tiles.map((t) => [t.props('label'), t.props()]))
        expect(byLabel['text.agentic.stat.pending_approvals'].tone).toBe('warning')
        expect(byLabel['text.agentic.stat.pending_approvals'].to).toEqual({ name: 'setting-agentic-approvals' })
        expect(byLabel['text.agentic.stat.recent_runs'].tone).toBe('info')
        expect(byLabel['text.agentic.stat.recent_runs'].value).toBe(7)
        expect(byLabel['text.agentic.stat.health'].tone).toBe('warning')
        expect(byLabel['text.agentic.stat.provider'].value).toBe('OPENAI')
    })

    it('shows pending approvals in the attention strip', async () => {
        const w = await mountPage()
        const items = w.findComponent(IAttentionStrip).props('items')
        expect(items).toEqual([expect.objectContaining({ key: 'pending-approvals', route: 'setting-agentic-approvals', count: 3 })])
    })

    it('uses i-button controls in the header and operator block', async () => {
        const w = await mountPage()
        const buttons = w.findAllComponents(IButton)
        expect(buttons.length).toBeGreaterThanOrEqual(3)
        expect(buttons.every((b) => b.props('variant') === 'default' && b.props('size') === 'sm')).toBe(true)
        await w.get('[data-testid="agentic-refresh"]').trigger('click')
        expect(refetchHealth).toHaveBeenCalled()
    })
})
