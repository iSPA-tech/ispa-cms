import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { resetPermissionIndexCache } from '@/hooks/useCan'
import Dashboard from '@/pages/dashboard.vue'

vi.stubGlobal('IntersectionObserver', undefined)

const mockedAjax = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/ajax', () => ({ default: { get: mockedAjax.get, post: mockedAjax.post } }))
vi.mock('@/ajax-v4', () => ({ default: { get: vi.fn() } }))
vi.mock('@/hooks', () => ({
    useActiveModules: () => ({
        activeModuleCatalogLoaded: { value: true },
        isModuleActive: () => false,
    }),
    widgetInputDefault: vi.fn(),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => false }) }))

const respond = (topData: Record<string, unknown>) =>
    mockedAjax.get.mockImplementation((url: string) =>
        Promise.resolve({
            data: {
                status: 'success',
                data: url === 'track/top-targets' ? topData : {},
            },
        }),
    )

const mountDashboard = async () => {
    window.localStorage.setItem(
        CACHE_KEY_ACCOUNT_DATA,
        JSON.stringify({
            id: 'u',
            roles: [{ id: 'r', permissions: [{ id: 1, group: 'post', name: 'viewAny' }] }],
        }),
    )
    resetPermissionIndexCache()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const wrapper = mount(Dashboard, {
        global: {
            mocks: {
                $t: (key: string, params?: Record<string, unknown>) =>
                    params ? `${key}|${JSON.stringify(params)}` : key,
            },
            directives: { loading: () => undefined },
            stubs: { 'i-icon': true },
        },
    })
    await flushPromises()

    return { wrapper, warn }
}

const base = {
    range: '7d',
    ranges: ['7d'],
    type: '',
    types: [],
    rangeStart: '2026-10-21',
    rangeEnd: '2026-10-27',
}

describe('dashboard top-targets coverage line', () => {
    beforeEach(() => {
        window.localStorage.clear()
        mockedAjax.get.mockReset()
    })

    it('renders sum, percent and window', async () => {
        respond({
            ...base,
            rangeTotalViews: 1000,
            items: [
                { type: 'route', target_id: 0, rank: 1, name: 'a', url: '/a', views: 100 },
                { type: 'route', target_id: 0, rank: 2, name: 'b', url: '/b', views: 50 },
            ],
        })
        const { wrapper, warn } = await mountDashboard()
        const line = wrapper.find('[data-testid="top-targets-coverage"]')
        expect(line.exists()).toBe(true)
        expect(line.text()).toContain('text.rp.top-coverage|')
        expect(line.text()).toContain('"views":"150"')
        expect(line.text()).toContain('"percent":"15.0"')
        expect(line.text()).toContain('"from":"21/10"')
        expect(line.text()).toContain('"to":"27/10"')
        const dupKey = warn.mock.calls.some((c) => String(c[0]).includes('Duplicate keys'))
        expect(dupKey).toBe(false)
    })

    it('hides percent when rangeTotalViews is 0', async () => {
        respond({
            ...base,
            rangeTotalViews: 0,
            items: [{ type: 'route', target_id: 0, rank: 1, name: 'a', url: '/a', views: 5 }],
        })
        const { wrapper } = await mountDashboard()
        const text = wrapper.find('[data-testid="top-targets-coverage"]').text()
        expect(text).toContain('text.rp.top-coverage-window|')
        expect(text).not.toContain('percent')
    })
})
