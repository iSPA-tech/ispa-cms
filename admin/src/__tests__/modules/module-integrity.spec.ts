import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import SettingModules from '@core-admin/pages/setting/modules.vue'
import IntegrityNote from '@core-admin/pages/setting/modules/integrity-note.vue'

/*
 * the installed modules list shows "files changed" per module with the list, "unknown" only for runtime
 * installs, a "check now" button that runs once at a time, and never blocks any other control.
 */

const state = vi.hoisted(() => ({ rows: [] as Record<string, unknown>[], stored: null as unknown }))
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

const $t = (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k)
const base = { enabled: true, version: '1.0', requires: [], switchable: true, missing_dependencies: [], blocking_dependents: [], hub_configured: true }
const module = (name: string, over: Record<string, unknown> = {}) => ({ name, alias: name.toLowerCase(), path: `/x/${name}`, ...base, ...over })

const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const alertStub = defineComponent({ props: ['message', 'description'], setup: (p, { attrs, slots }) => () => h('div', { 'data-testid': attrs['data-testid'] }, [p.message, ' ', p.description, slots.action?.()]) })
const iButton = defineComponent({
    props: ['loading'],
    emits: ['click'],
    setup: (p, { slots, attrs, emit }) => () => h('button', { 'data-testid': attrs['data-testid'], 'data-loading': String(!!p.loading), onClick: () => emit('click') }, slots.default?.()),
})
const stubs = {
    'a-button': passthrough('button'), 'a-spin': passthrough(), 'a-empty': true, 'a-row': passthrough(), 'a-col': passthrough(), 'a-card': passthrough(),
    'a-tag': passthrough('span'), 'a-tooltip': passthrough('span'), 'a-switch': true, 'a-input': true, 'a-skeleton': true, 'a-alert': alertStub,
    'a-tab-pane': true, 'a-tabs': true, 'a-drawer': true, 'i-icon': true, 'i-button': iButton,
}

const mountPage = async () => {
    const wrapper = mount(SettingModules, { global: { mocks: { $t }, stubs } })
    await flushPromises()
    return wrapper
}
const rowOf = (wrapper: Awaited<ReturnType<typeof mountPage>>, name: string) =>
    wrapper.findAll('[data-testid=module-row]').find((r) => r.find('h3').text().toLowerCase() === name.toLowerCase())!

const changed = { state: 'changed', version: '1.0', changed: 2, truncated: false, files: [{ path: 'app/Foo.php', status: 'modified' }, { path: 'app/Bar.php', status: 'missing' }] }

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    state.stored = null
    ajaxMock.get.mockImplementation((url: string) => {
        if (url === 'utilities/modules/integrity') return Promise.resolve({ data: { data: state.stored ?? { checked_at: null, modules: {} } } })
        if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: [] } })
        return Promise.reject(new Error('unexpected ' + url))
    })
})

describe('integrity note', () => {
    it('shows the count with the files on demand for a changed module', async () => {
        const wrapper = mount(IntegrityNote, { props: { entry: changed, source: 'runtime' }, global: { mocks: { $t }, stubs } })
        expect(wrapper.find('[data-testid=module-integrity-changed]').text()).toContain('text.modules.integrity.changed|{"count":2}')
        expect(wrapper.find('[data-testid=module-integrity-files]').exists()).toBe(false)

        await wrapper.find('[data-testid=module-integrity-toggle]').trigger('click')
        const list = wrapper.find('[data-testid=module-integrity-files]').text()
        expect(list).toContain('app/Foo.php')
        expect(list).toContain('text.modules.integrity.file.missing')
    })

    it('says unknown only for runtime installs and says ok for matching files', () => {
        const mountNote = (entry: object, source: string) => mount(IntegrityNote, { props: { entry, source }, global: { mocks: { $t }, stubs } })
        const unknown = { state: 'unknown', changed: 0, files: [], truncated: false }

        expect(mountNote(unknown, 'runtime').find('[data-testid=module-integrity-unknown]').exists()).toBe(true)
        expect(mountNote(unknown, 'release').find('[data-testid=module-integrity]').exists()).toBe(false)
        expect(mountNote({ state: 'ok', changed: 0, files: [], truncated: false }, 'runtime').find('[data-testid=module-integrity-ok]').exists()).toBe(true)
    })
})

describe('integrity on the installed list', () => {
    it('reads the stored result and shows a changed module while the other controls stay usable', async () => {
        state.rows = [module('Alpha', { source: 'runtime' }), module('Bravo', { source: 'release' })]
        state.stored = { checked_at: '2026-11-15T01:00:00+00:00', modules: { Alpha: changed } }
        const wrapper = await mountPage()

        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-integrity-changed]').exists()).toBe(true)
        expect(rowOf(wrapper, 'Bravo').find('[data-testid=module-integrity]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=module-integrity-status]').text()).toContain('text.modules.integrity.checked_at')
        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-switch]').exists()).toBe(true)
    })

    it('says nothing has been checked yet, and the check button runs once and shows the fresh result', async () => {
        state.rows = [module('Alpha', { source: 'runtime' })]
        let finish: (v: unknown) => void = () => {}
        ajaxMock.post.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid=module-integrity-status]').text()).toContain('text.modules.integrity.never')

        const button = wrapper.find('[data-testid=module-integrity-check]')
        await button.trigger('click')
        await button.trigger('click')
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post.mock.calls[0][0]).toBe('utilities/modules/integrity/check')
        expect(button.attributes('data-loading')).toBe('true')

        finish({ data: { data: { checked_at: '2026-11-15T02:00:00+00:00', modules: { Alpha: changed } } } })
        await flushPromises()
        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-integrity-changed]').exists()).toBe(true)
        expect(button.attributes('data-loading')).toBe('false')
    })

    it('shows a plain error and lets the check be retried when it fails', async () => {
        state.rows = [module('Alpha', { source: 'runtime' })]
        ajaxMock.post.mockRejectedValueOnce(new Error('boom'))
        const wrapper = await mountPage()

        await wrapper.find('[data-testid=module-integrity-check]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=module-integrity-error]').text()).toContain('text.modules.integrity.check_failed')

        ajaxMock.post.mockResolvedValueOnce({ data: { data: { checked_at: '2026-11-15T02:00:00+00:00', modules: { Alpha: { state: 'ok', changed: 0, files: [], truncated: false } } } } })
        await wrapper.find('[data-testid=module-integrity-check]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=module-integrity-error]').exists()).toBe(false)
        expect(rowOf(wrapper, 'Alpha').find('[data-testid=module-integrity-ok]').exists()).toBe(true)
    })
})
