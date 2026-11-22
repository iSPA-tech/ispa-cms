import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { resetPermissionIndexCache } from '@/hooks/useCan'
import { getDashboardSummary } from '@/libs/dashboard'
import { getMyTasks } from '@/libs/my-tasks'

vi.stubGlobal('IntersectionObserver', undefined)

const state = vi.hoisted(() => ({ summary: null as unknown }))

vi.mock('@/libs/dashboard', () => ({ getDashboardSummary: vi.fn(async () => state.summary) }))
vi.mock('@/libs/my-tasks', () => ({ getMyTasks: vi.fn() }))
vi.mock('@/ajax', () => ({ default: { get: vi.fn(async () => ({ data: { status: 'success', data: { range: '7d', ranges: ['7d'], type: '', types: [], items: [] } } })) } }))
vi.mock('@/ajax-v4', () => ({ default: { get: vi.fn() } }))
vi.mock('@/hooks', () => ({
    useActiveModules: () => ({ activeModuleCatalogLoaded: { value: true }, isModuleActive: () => false }),
    widgetInputDefault: vi.fn(),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (k: string) => k, te: () => false }) }))
vi.mock('@/components/common/i-attention-strip.vue', () => ({
    default: defineComponent({
        name: 'IAttentionStrip',
        props: ['items'],
        setup: (p) => () => h('div', { 'data-strip': p.items.map((i: { key: string }) => i.key).join(',') }),
    }),
}))

const passthrough = (tag: string) => defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })
const IButton = defineComponent({ setup: (_p, { slots, attrs }) => () => h('button', attrs, slots.default?.()) })

const mountPage = async (permissions: Array<[string, string]>) => {
    window.localStorage.setItem(
        CACHE_KEY_ACCOUNT_DATA,
        JSON.stringify({
            id: 'u',
            roles: [{ id: 'r', permissions: permissions.map(([group, name], i) => ({ id: i + 1, group, name })) }],
        })
    )
    resetPermissionIndexCache()
    const Page = (await import('@/pages/dashboard.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: (k: string) => k },
            directives: { loading: () => undefined },
            stubs: {
                breadcrumbs: passthrough('div'),
                'i-button': IButton,
                'i-box': passthrough('div'),
                'i-stat-strip': passthrough('div'),
                'i-stat-tile': true,
                'i-icon': true,
                'a-segmented': true,
                'a-popover': passthrough('div'),
                'a-alert': true,
                'student-report': true,
            },
        },
    })
    await flushPromises()
    return w
}

describe('dashboard for an agent-only account', () => {
    beforeEach(() => {
        window.localStorage.clear()
        vi.mocked(getDashboardSummary).mockClear()
        vi.mocked(getMyTasks).mockReset()
    })

    it('shows the Helpdesk attention row without requesting the summary', async () => {
        vi.mocked(getMyTasks).mockResolvedValue({
            items: [{ key: 'helpdesk', count: 2, route: 'helpdesk-tickets', query: {}, items: [{ id: 1 }] }],
            total: 2,
        })
        const w = await mountPage([['helpdesk', 'agent']])
        expect(w.get('[data-strip]').attributes('data-strip')).toBe('helpdesk')
        expect(getDashboardSummary).not.toHaveBeenCalled()
        expect(getMyTasks).toHaveBeenCalledTimes(1)
    })

    it('renders nothing and does not crash when there are no tasks', async () => {
        vi.mocked(getMyTasks).mockResolvedValue({ items: [], total: 0 })
        const w = await mountPage([['helpdesk', 'agent']])
        expect(w.find('[data-strip]').exists()).toBe(false)
        expect(getDashboardSummary).not.toHaveBeenCalled()
    })

    it('renders nothing when the task request fails', async () => {
        vi.mocked(getMyTasks).mockRejectedValue(new Error('boom'))
        const w = await mountPage([['helpdesk', 'agent']])
        expect(w.find('[data-strip]').exists()).toBe(false)
    })

    it('keeps the summary path for summary users and skips my-tasks', async () => {
        state.summary = { attention: [{ key: 'comments', route: 'post-comment', count: 3 }] }
        const w = await mountPage([['post', 'viewAny']])
        expect(getDashboardSummary).toHaveBeenCalled()
        expect(getMyTasks).not.toHaveBeenCalled()
        expect(w.get('[data-strip]').attributes('data-strip')).toBe('comments')
    })
})
