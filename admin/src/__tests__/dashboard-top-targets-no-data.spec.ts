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

const respondWithoutTopData = () =>
    mockedAjax.get.mockImplementation((url: string) =>
        Promise.resolve({
            data: url === 'track/top-targets' ? { status: 'success' } : { status: 'success', data: {} },
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
    const wrapper = mount(Dashboard, {
        global: {
            mocks: { $t: (key: string) => key },
            directives: { loading: () => undefined },
            stubs: { 'i-icon': true },
        },
    })
    await flushPromises()

    return wrapper
}

describe('dashboard top-targets without a data payload', () => {
    beforeEach(() => {
        window.localStorage.clear()
        mockedAjax.get.mockReset()
    })

    it('keeps the default shape and renders without throwing', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
        respondWithoutTopData()
        const wrapper = await mountDashboard()
        const vm = wrapper.vm as unknown as { topTargets: Record<string, unknown> }

        expect(mockedAjax.get).toHaveBeenCalledWith('track/top-targets', expect.anything())
        expect(vm.topTargets.range).toBe('7d')
        expect(Array.isArray(vm.topTargets.types)).toBe(true)
        expect(Array.isArray(vm.topTargets.items)).toBe(true)
        expect(wrapper.find('[data-testid="top-targets"]').exists()).toBe(true)
        expect(warn.mock.calls.some((c) => String(c[0]).includes('Unhandled error'))).toBe(false)
    })
})
