import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import SettingModules from '@core-admin/pages/setting/modules.vue'

const state = vi.hoisted(() => ({ rows: [] as Record<string, unknown>[], catalog: [] as Record<string, unknown>[] }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))

vi.mock('@/config/navigation', () => ({ default: [] }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ rule: () => true, perm: (m: string) => m, iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => true, hasAccountData: () => true }))
vi.mock('@/hooks', () => ({
    useModules: () => ({ data: ref(state.rows), isLoading: ref(false), isRefetching: ref(false), refetch: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k),
        locale: { value: 'en' },
    }),
}))

const base = { enabled: true, version: '1.0', requires: [], switchable: true, missing_dependencies: [], blocking_dependents: [], hub_configured: true }
const module = (name: string, over: Record<string, unknown> = {}) => ({ name, alias: name.toLowerCase(), path: `/x/runtime-modules/${name}`, ...base, ...over })
const ent = (stateName: string, over: Record<string, unknown> = {}) => ({ state: stateName, expires_at: '2026-10-01T00:00:00+00:00', grace_until: '2026-10-15T00:00:00+00:00', ...over })

const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const stubs = {
    'a-button': passthrough('button'),
    'a-spin': passthrough(),
    'a-empty': true,
    'a-row': passthrough(),
    'a-col': passthrough(),
    'a-card': passthrough(),
    'a-tag': passthrough('span'),
    'a-tooltip': defineComponent({ props: ['title'], setup: (p, { slots }) => () => h('span', { 'data-title': p.title }, slots.default?.()) }),
    'a-switch': true,
    'a-input': true,
    'a-skeleton': true,
    'a-alert': defineComponent({ props: ['message', 'description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, [p.message, ' ', p.description]) }),
    'a-tab-pane': true,
    'a-tabs': true,
    'a-drawer': true,
    'i-icon': true,
    'i-button': defineComponent({ setup: (_p, { slots, attrs }) => () => h('button', { 'data-testid': attrs['data-testid'] }, slots.default?.()) }),
}

const mountPage = async () => {
    const wrapper = mount(SettingModules, { global: { mocks: { $t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k) }, stubs } })
    await flushPromises()
    return wrapper
}
const rowOf = (wrapper: Awaited<ReturnType<typeof mountPage>>, name: string) =>
    wrapper.findAll('[data-testid=module-row]').find((r) => r.find('h3').text().toLowerCase() === name.toLowerCase())!

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    state.catalog = []
    ajaxMock.get.mockImplementation((url: string) => {
        if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: state.catalog } })
        if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: [] } })
        return Promise.reject(new Error('unexpected ' + url))
    })
})

describe('entitlement badge on the installed list', () => {
    it('shows one badge per state with its dates in the tooltip', async () => {
        state.rows = [
            module('Alpha', { entitlement: ent('entitled', { expires_at: null, grace_until: null }) }),
            module('Bravo', { entitlement: ent('grace') }),
            module('Charlie', { entitlement: ent('expired'), updates_unavailable: true }),
            module('Delta', { entitlement: ent('revoked', { expires_at: null, grace_until: null }), updates_unavailable: true }),
            module('Echo', { entitlement: null, updates_unavailable: false }),
        ]
        const wrapper = await mountPage()

        const badge = (name: string) => rowOf(wrapper, name).find('[data-testid=module-entitlement]')
        expect(badge('Alpha').text()).toContain('state.entitled')
        expect(badge('Bravo').text()).toContain('state.grace')
        expect(badge('Charlie').text()).toContain('state.expired')
        expect(badge('Delta').text()).toContain('state.revoked')
        expect(rowOf(wrapper, 'Echo').find('[data-testid=module-entitlement]').exists()).toBe(false)

        const tip = rowOf(wrapper, 'Bravo').find('[data-title]').attributes('data-title') as string
        expect(tip).toContain('tooltip.expires')
        expect(tip).toContain('tooltip.grace_until')
        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-entitlement]').element.parentElement?.hasAttribute('data-title')).toBe(false)
    })

    it('shows the contact-iSPA note for grace, expired and revoked but not for active', async () => {
        state.rows = [
            module('Alpha', { entitlement: ent('entitled') }),
            module('Bravo', { entitlement: ent('grace') }),
            module('Charlie', { entitlement: ent('expired'), updates_unavailable: true }),
            module('Delta', { entitlement: ent('revoked'), updates_unavailable: true }),
        ]
        const wrapper = await mountPage()

        const note = (name: string) => rowOf(wrapper, name).find('[data-testid=module-entitlement-note]')
        expect(note('Alpha').exists()).toBe(false)
        expect(note('Bravo').text()).toContain('note.grace')
        expect(note('Charlie').text()).toContain('note.expired')
        expect(note('Charlie').text()).toContain('"name":"Charlie"')
        expect(note('Charlie').text()).toContain('keeps_running')
        expect(note('Delta').text()).toContain('note.revoked')
    })

    it('marks updates unavailable and hides the upgrade offer when access ended', async () => {
        state.rows = [module('Charlie', { entitlement: ent('expired'), updates_unavailable: true }), module('Alpha', { entitlement: ent('entitled') })]
        state.catalog = [
            { name: 'Charlie', latest_version: '2.0.0', installed: 'runtime', installed_version: '1.0', upgrade_available: true, upgrade_version: '2.0.0', versions: [{ version: '2.0.0', revoked: false }] },
            { name: 'Alpha', latest_version: '2.0.0', installed: 'runtime', installed_version: '1.0', upgrade_available: true, upgrade_version: '2.0.0', versions: [{ version: '2.0.0', revoked: false }] },
        ]
        const wrapper = await mountPage()

        expect(rowOf(wrapper, 'Charlie').find('[data-testid=module-updates-unavailable]').exists()).toBe(true)
        expect(rowOf(wrapper, 'Charlie').find('[data-testid=module-upgrade]').exists()).toBe(false)
        expect(rowOf(wrapper, 'Charlie').find('[data-testid=module-upgrade-available]').exists()).toBe(false)
        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-updates-unavailable]').exists()).toBe(false)
        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-upgrade]').exists()).toBe(true)
    })

    it('marks an installed runtime module the hub no longer lists as updates unavailable, and keeps it listed', async () => {
        state.rows = [module('Ghost')]
        state.catalog = [{ name: 'Ghost', latest_version: null, installed: 'runtime', installed_version: '1.0', upgrade_available: false, upgrade_version: null, updates_unavailable: true, versions: [] }]
        const wrapper = await mountPage()

        expect(wrapper.findAll('[data-testid=module-row]')).toHaveLength(1)
        expect(rowOf(wrapper, 'Ghost').find('[data-testid=module-updates-unavailable]').exists()).toBe(true)
    })

    it('leaves the page unchanged for an older hub answer (no entitlement fields)', async () => {
        state.rows = [module('Alpha'), module('Bravo')]
        const wrapper = await mountPage()

        expect(wrapper.find('[data-testid=module-entitlement]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=module-entitlement-note]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=module-updates-unavailable]').exists()).toBe(false)
    })
})
