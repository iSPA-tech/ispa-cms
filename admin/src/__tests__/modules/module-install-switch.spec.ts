import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import InstallSwitch from '@core-admin/pages/setting/modules/install-switch.vue'
import SettingModules from '@core-admin/pages/setting/modules.vue'
import { MODULE_ERROR_CODES, moduleErrorKey } from '@/libs/module'

const state = vi.hoisted(() => ({ webmaster: true }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))

vi.mock('@/pages/auth/site-gate', () => ({ loadSiteStatus: vi.fn() }))
vi.mock('@/pages/auth/connect-hub.vue', async () => {
    const { defineComponent: dc, h: hh } = await import('vue')
    return { default: dc({ setup: () => () => hh('div') }) }
})
vi.mock('@/config/navigation', () => ({ default: [] }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ rule: () => true, perm: (m: string) => m, iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => state.webmaster, hasAccountData: () => true }))
vi.mock('@/hooks', () => ({
    useModules: () => ({
        data: ref([
            { name: 'Core', alias: 'core', enabled: true, version: '1.0', requires: [], switchable: false, path: '/x/cms/Core', missing_dependencies: [], blocking_dependents: [], hub_configured: true },
            { name: 'Extra', alias: 'extra', enabled: true, version: '0.1.0', requires: [], switchable: true, path: '/x/runtime-modules/Extra', missing_dependencies: [], blocking_dependents: [], hub_configured: true },
        ]),
        isLoading: ref(false),
        isRefetching: ref(false),
        refetch: vi.fn(),
    }),
}))
const tr = (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k)
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: tr, locale: { value: 'vi' } }) }))

const setting = (over: Record<string, unknown> = {}) => ({
    enabled: false,
    state: 'disabled',
    setting: false,
    locked: false,
    server: 'unset',
    activator: 'file',
    activator_source: 'default',
    runtime_folder: false,
    ...over,
})
const allowed = () => setting({ enabled: true, state: 'allowed', setting: true })
const refuse = (status: number, errorCode: string) => Promise.reject({ response: { status, data: { errorCode } } })

const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const ASwitch = defineComponent({
    props: ['checked', 'disabled', 'loading'],
    emits: ['change'],
    setup: (p, { emit, attrs }) => () =>
        h('button', {
            type: 'button',
            role: 'switch',
            'data-testid': attrs['data-testid'],
            'aria-checked': String(Boolean(p.checked)),
            disabled: Boolean(p.disabled),
            // The real switch ignores clicks while disabled; `change` is still emitted here so the guard in the component is exercised.
            onClick: () => emit('change', !p.checked),
        }),
})
const IButton = defineComponent({
    props: ['disabled', 'loading', 'nativeType'],
    emits: ['click'],
    setup: (p, { slots, emit, attrs }) => () =>
        h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
})
const AAlert = defineComponent({ props: ['message', 'description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, [p.message, ' ', p.description]) })
const cardStubs = { 'a-skeleton': true, 'a-switch': ASwitch, 'i-button': IButton }

const mountCard = async () => {
    const wrapper = mount(InstallSwitch, { global: { mocks: { $t: tr }, stubs: cardStubs } })
    await flushPromises()
    return wrapper
}
const last = (wrapper: Awaited<ReturnType<typeof mountCard>>) => (wrapper.emitted('change') || []).at(-1)?.[0]

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    state.webmaster = true
})

describe('"Cho phép cài module" switch', () => {
    it('reads the setting and shows the switch off with the plain helper line', async () => {
        ajaxMock.get.mockResolvedValue({ data: { data: setting() } })
        const w = await mountCard()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/install-setting', expect.objectContaining({ silent: true }))
        const sw = w.get('[data-testid=install-switch]')
        expect(sw.attributes('aria-checked')).toBe('false')
        expect(sw.attributes('disabled')).toBeUndefined()
        expect(w.text()).toContain('text.modules.install.setting.label')
        expect(w.get('[data-testid=install-switch-help]').text()).toBe('text.modules.install.setting.help_off')
        // The state is written beside the switch, not left to its colour.
        expect(w.get('[data-testid=install-switch-state]').text()).toBe('text.modules.install.setting.state_off')
        expect(w.find('[data-testid=install-switch-locked]').exists()).toBe(false)
        expect(last(w)).toMatchObject({ state: 'disabled' })
    })

    it('switches on with one request, even on a double click, and reports the new state', async () => {
        ajaxMock.get.mockResolvedValue({ data: { data: setting() } })
        let finish: (v: unknown) => void = () => {}
        ajaxMock.put.mockReturnValue(new Promise((resolve) => (finish = resolve)))
        const w = await mountCard()
        const sw = w.get('[data-testid=install-switch]')
        await sw.trigger('click')
        await sw.trigger('click')
        expect(ajaxMock.put).toHaveBeenCalledTimes(1)
        expect(ajaxMock.put).toHaveBeenCalledWith('utilities/modules/install-setting', { enabled: true }, expect.objectContaining({ silent: true }))
        expect(w.get('[data-testid=install-switch]').attributes('disabled')).toBeDefined()
        finish({ data: { data: allowed() } })
        await flushPromises()
        expect(w.get('[data-testid=install-switch]').attributes('aria-checked')).toBe('true')
        expect(w.get('[data-testid=install-switch]').attributes('disabled')).toBeUndefined()
        expect(w.get('[data-testid=install-switch-help]').text()).toBe('text.modules.install.setting.help_on')
        expect(w.get('[data-testid=install-switch-state]').text()).toBe('text.modules.install.setting.state_on')
        expect(last(w)).toMatchObject({ state: 'allowed' })
    })

    it.each([
        ['the server forbids', setting({ state: 'forbidden_by_server', locked: true, server: 'off' }), 'false', 'text.modules.install.setting.locked_server_off'],
        ['the server switches it on', setting({ enabled: true, state: 'allowed', locked: true, server: 'on' }), 'true', 'text.modules.install.setting.locked_server_on'],
        ['iSPA Hub forbids', setting({ setting: true, state: 'forbidden_by_hub', locked: true }), 'false', 'text.modules.error.install_forbidden_by_hub'],
    ])('is read-only with the reason when %s', async (_name, body, checked, reason) => {
        ajaxMock.get.mockResolvedValue({ data: { data: body } })
        const w = await mountCard()
        const sw = w.get('[data-testid=install-switch]')
        expect(sw.attributes('disabled')).toBeDefined()
        expect(sw.attributes('aria-checked')).toBe(checked)
        expect(w.get('[data-testid=install-switch-locked]').text()).toBe(reason)
        expect(w.find('[data-testid=install-switch-help]').exists()).toBe(false)
        await sw.trigger('click')
        expect(ajaxMock.put).not.toHaveBeenCalled()
    })

    it('shows the translated refusal and reads the setting again when the server took over meanwhile', async () => {
        ajaxMock.get.mockResolvedValueOnce({ data: { data: setting() } })
        ajaxMock.get.mockResolvedValueOnce({ data: { data: setting({ state: 'forbidden_by_server', locked: true, server: 'off' }) } })
        ajaxMock.put.mockImplementation(() => refuse(409, 'MODULE_INSTALL_FORBIDDEN_BY_SERVER'))
        const w = await mountCard()
        await w.get('[data-testid=install-switch]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid=install-switch-error]').text()).toBe('text.modules.error.install_forbidden_by_server')
        expect(w.get('[data-testid=install-switch-locked]').text()).toBe('text.modules.install.setting.locked_server_off')
        expect(last(w)).toMatchObject({ state: 'forbidden_by_server' })
    })

    it('keeps the switch off and usable after a failed save', async () => {
        ajaxMock.get.mockResolvedValue({ data: { data: setting() } })
        ajaxMock.put.mockImplementation(() => refuse(500, 'MODULE_INSTALL_RUNTIME_FOLDER_FAILED'))
        const w = await mountCard()
        await w.get('[data-testid=install-switch]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid=install-switch-error]').text()).toBe('text.modules.error.install_runtime_folder_failed')
        const sw = w.get('[data-testid=install-switch]')
        expect(sw.attributes('aria-checked')).toBe('false')
        expect(sw.attributes('disabled')).toBeUndefined()
        expect(ajaxMock.get).toHaveBeenCalledTimes(1)
    })

    it('offers a retry when the setting cannot be read', async () => {
        ajaxMock.get.mockRejectedValueOnce(new Error('network'))
        ajaxMock.get.mockResolvedValueOnce({ data: { data: allowed() } })
        const w = await mountCard()
        expect(w.get('[data-testid=install-switch-load-error]').text()).toContain('text.modules.install.setting.load_failed')
        expect(w.find('[data-testid=install-switch]').exists()).toBe(false)
        expect(w.emitted('change')).toBeUndefined()
        await w.get('[data-testid=install-switch-retry]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid=install-switch]').attributes('aria-checked')).toBe('true')
    })

    it('knows the codes of the switch and of the install policy', () => {
        for (const code of [
            'MODULE_INSTALL_DISABLED',
            'MODULE_INSTALL_FORBIDDEN_BY_SERVER',
            'MODULE_INSTALL_FORBIDDEN_BY_HUB',
            'MODULE_INSTALL_MANAGED_BY_SERVER',
            'MODULE_INSTALL_NO_RUNNER',
            'MODULE_INSTALL_RUNTIME_FOLDER_FAILED',
            'MODULE_INSTALL_ACTIVATOR_MARKER_FAILED',
            'MODULE_INSTALL_CONFIG_CACHE_FAILED',
        ]) {
            expect(MODULE_ERROR_CODES).toContain(code)
        }
        expect(moduleErrorKey('MODULE_INSTALL_NO_RUNNER')).toBe('text.modules.error.install_no_runner')
    })
})

describe('Modules page while installs are blocked', () => {
    const catalogBody = [
        { name: 'Helpdesk', latest_version: '1.2.0', installed: 'none', enabled: false, installed_version: null, versions: [{ version: '1.2.0', revoked: false, requires: [], irreversible: false, custom_code: false }] },
        { name: 'Extra', latest_version: '0.2.0', installed: 'runtime', enabled: true, installed_version: '0.1.0', upgrade_available: true, upgrade_version: '0.2.0', versions: [{ version: '0.2.0', revoked: false, requires: [], irreversible: false, custom_code: false }] },
    ]
    let settingBody: Record<string, unknown>
    let preflightBody: Record<string, unknown>
    const pageStubs = {
        ...cardStubs,
        'a-button': passthrough('button'),
        'a-spin': passthrough(),
        'a-empty': true,
        'a-row': passthrough(),
        'a-col': passthrough(),
        'a-card': passthrough(),
        'a-tag': passthrough('span'),
        'a-tooltip': passthrough('span'),
        'a-input': true,
        'a-alert': AAlert,
        'a-tab-pane': true,
        'a-tabs': defineComponent({
            inheritAttrs: false,
            emits: ['update:activeKey', 'change'],
            setup: (_p, { emit }) => () => h('button', { 'data-testid': 'tab-available', onClick: () => { emit('update:activeKey', 'available'); emit('change', 'available') } }),
        }),
        'a-drawer': defineComponent({ props: ['open'], setup: (p, { slots }) => () => (p.open ? h('div', [slots.default?.(), slots.footer?.()]) : null) }),
        'i-icon': true,
        'core-update-card': true,
        'hub-connect-drawer': true,
        'support-requests': true,
        'support-dialog': true,
        'report-level': true,
        'integrity-note': true,
        'store-image': true,
        'store-community': true,
        'publisher-badge': true,
    }
    const mountPage = async () => {
        ajaxMock.get.mockImplementation((url: string) => {
            if (url === 'utilities/modules/install-setting') return settingBody ? Promise.resolve({ data: { data: settingBody } }) : Promise.reject(new Error('down'))
            if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: catalogBody } })
            if (url.includes('/preflight')) return Promise.resolve({ data: { data: preflightBody } })
            return Promise.resolve({ data: { data: [] } })
        })
        const wrapper = mount(SettingModules, { attachTo: document.body, global: { mocks: { $t: tr }, stubs: pageStubs } })
        await flushPromises()
        await wrapper.get('[data-testid=tab-available]').trigger('click')
        await flushPromises()
        return wrapper
    }
    const preflightCalls = () => ajaxMock.get.mock.calls.filter(([url]) => String(url).includes('/preflight')).length

    beforeEach(() => {
        settingBody = setting()
        preflightBody = { name: 'Helpdesk', version: '1.2.0', can_install: true, blocking: [], warnings: [], changes: [] }
    })

    it('hides the switch from everyone but the Webmaster', async () => {
        state.webmaster = false
        ajaxMock.get.mockResolvedValue({ data: { data: [] } })
        const w = mount(SettingModules, { global: { mocks: { $t: tr }, stubs: pageStubs } })
        await flushPromises()
        expect(w.find('[data-testid=install-switch-card]').exists()).toBe(false)
        expect(ajaxMock.get).not.toHaveBeenCalled()
    })

    it('switched off: one notice with a way to the switch, install and upgrade off, no drawer', async () => {
        const w = await mountPage()
        const notice = w.get('[data-testid=install-blocked-notice]')
        expect(notice.text()).toContain('text.modules.error.install_disabled')
        expect(w.findAll('[data-testid=install-blocked-notice]')).toHaveLength(1)
        expect(w.get('[data-testid=catalog-install]').attributes('disabled')).toBeDefined()
        expect(w.get('[data-testid=catalog-upgrade]').attributes('disabled')).toBeDefined()
        expect(w.get('[data-testid=module-upgrade]').attributes('disabled')).toBeDefined()
        expect(w.get('[data-testid=module-upload-open]').attributes('disabled')).toBeDefined()
        await w.get('[data-testid=catalog-install]').trigger('click')
        await flushPromises()
        expect(preflightCalls()).toBe(0)
        expect(w.find('[data-testid=install-form]').exists()).toBe(false)

        await w.get('[data-testid=install-blocked-go]').trigger('click')
        await flushPromises()
        expect(document.activeElement).toBe(w.get('[data-testid=install-switch]').element)
        w.unmount()
    })

    it.each([
        ['forbidden_by_server', 'text.modules.error.install_forbidden_by_server'],
        ['forbidden_by_hub', 'text.modules.error.install_forbidden_by_hub'],
    ])('%s: the notice says who locked it and offers no way to a switch that cannot be changed', async (blockedState, key) => {
        settingBody = setting({ state: blockedState, locked: true, server: blockedState === 'forbidden_by_server' ? 'off' : 'unset' })
        const w = await mountPage()
        expect(w.get('[data-testid=install-blocked-notice]').text()).toContain(key)
        expect(w.find('[data-testid=install-blocked-go]').exists()).toBe(false)
        expect(w.get('[data-testid=catalog-install]').attributes('disabled')).toBeDefined()
        w.unmount()
    })

    it('switching on from the page lifts the notice and lets the install drawer open', async () => {
        ajaxMock.put.mockResolvedValue({ data: { data: allowed() } })
        const w = await mountPage()
        await w.get('[data-testid=install-switch]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid=install-blocked-notice]').exists()).toBe(false)
        expect(w.get('[data-testid=catalog-install]').attributes('disabled')).toBeUndefined()
        await w.get('[data-testid=catalog-install]').trigger('click')
        await flushPromises()
        expect(preflightCalls()).toBe(1)
        expect(w.find('[data-testid=install-backup-notice]').exists()).toBe(true)
        expect(w.find('[data-testid=install-site-blocked]').exists()).toBe(false)
        w.unmount()
    })

    it('drawer: a preflight that still says "switched off" shows one plain blocked state and no backup notice', async () => {
        settingBody = allowed()
        preflightBody = {
            name: 'Helpdesk',
            version: '1.2.0',
            can_install: false,
            blocking: [
                { code: 'MODULE_INSTALL_NEEDS_DB_STATUS', message: 'x', details: {} },
                { code: 'MODULE_INSTALL_NO_RUNTIME_FOLDER', message: 'x', details: {} },
                { code: 'MODULE_INSTALL_DISABLED', message: 'x', details: {} },
            ],
            warnings: [],
            changes: [],
            backup: { recommended: true, reason: 'own_tables_only', last_backup: { at: null, source: null } },
        }
        const w = await mountPage()
        await w.get('[data-testid=catalog-install]').trigger('click')
        await flushPromises()
        const blocked = w.get('[data-testid=install-site-blocked]')
        expect(blocked.text()).toContain('text.modules.install.blocked.title')
        expect(blocked.text()).toContain('text.modules.error.install_disabled')
        expect(blocked.text()).not.toContain('install_needs_db_status')
        expect(blocked.text()).not.toContain('install_no_runtime_folder')
        expect(w.find('[data-testid=preflight-blocking-item]').exists()).toBe(false)
        expect(w.find('[data-testid=install-backup]').exists()).toBe(false)
        expect(w.find('[data-testid=install-backup-notice]').exists()).toBe(false)
        expect(w.find('[data-testid=install-steps]').exists()).toBe(false)
        expect(w.find('[data-testid=install-start]').exists()).toBe(false)
        await w.get('[data-testid=install-form]').trigger('submit')
        expect(ajaxMock.post).not.toHaveBeenCalled()

        await w.get('[data-testid=install-site-blocked-go]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid=install-form]').exists()).toBe(false)
        expect(document.activeElement).toBe(w.get('[data-testid=install-switch]').element)
        w.unmount()
    })

    it.each([
        ['MODULE_INSTALL_FORBIDDEN_BY_HUB', 'text.modules.error.install_forbidden_by_hub'],
        ['MODULE_INSTALL_NO_RUNNER', 'text.modules.error.install_no_runner'],
    ])('drawer: %s is one plain reason without a link to the switch', async (code, key) => {
        settingBody = allowed()
        preflightBody = { name: 'Helpdesk', version: '1.2.0', can_install: false, blocking: [{ code, message: 'x', details: {} }], warnings: [], changes: [] }
        const w = await mountPage()
        await w.get('[data-testid=catalog-install]').trigger('click')
        await flushPromises()
        expect(w.get('[data-testid=install-site-blocked-reason]').text()).toContain(key)
        expect(w.find('[data-testid=install-site-blocked-go]').exists()).toBe(false)
        expect(w.find('[data-testid=install-backup-notice]').exists()).toBe(false)
        w.unmount()
    })

    it('drawer: a reason about this one module keeps its own text but hides the backup notice', async () => {
        settingBody = allowed()
        preflightBody = { name: 'Helpdesk', version: '1.2.0', can_install: false, blocking: [{ code: 'MODULE_PHP_TOO_OLD', message: 'x', details: { required: '8.4', running: '8.3' } }], warnings: [], changes: [] }
        const w = await mountPage()
        await w.get('[data-testid=catalog-install]').trigger('click')
        await flushPromises()
        expect(w.find('[data-testid=install-site-blocked]').exists()).toBe(false)
        expect(w.get('[data-testid=preflight-blocking-item]').text()).toContain('text.modules.error.php_too_old')
        expect(w.find('[data-testid=install-backup-notice]').exists()).toBe(false)
        expect(w.get('[data-testid=install-start]').attributes('disabled')).toBeDefined()
        w.unmount()
    })

    it('history: a run that waits for the scheduler says so, any other waiting run does not', async () => {
        settingBody = allowed()
        const row = (uuid: string, over: Record<string, unknown>) => ({ uuid, name: 'Helpdesk', version: '1.2.0', source: 'hub', status: 'queued', started_at: null, warnings: [], created_at: '2026-11-22T10:00:00+07:00', ...over })
        const w = await mountPage()
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve({
                data: {
                    data: url.endsWith('/installs')
                        ? [
                              row('u1', { step_message: 'Waiting for the scheduled run (at most about a minute).' }),
                              row('u2', { step_message: 'Waiting for a queue worker.' }),
                              row('u3', { status: 'downloading', started_at: '2026-11-22T10:00:05+07:00', step_message: 'Waiting for the scheduled run (at most about a minute).' }),
                          ]
                        : [],
                },
            }),
        )
        await w.get('[data-testid=modules-reload]').trigger('click')
        await flushPromises()
        const rows = w.findAll('[data-testid=history-row]')
        expect(rows).toHaveLength(3)
        expect(rows[0].get('[data-testid=history-waiting-scheduler]').text()).toBe('text.modules.install.status.waiting_scheduler')
        expect(rows[1].find('[data-testid=history-waiting-scheduler]').exists()).toBe(false)
        expect(rows[2].find('[data-testid=history-waiting-scheduler]').exists()).toBe(false)
        w.unmount()
    })

    it('an unread setting blocks nothing by itself: the drawer preflight still decides', async () => {
        settingBody = undefined as unknown as Record<string, unknown>
        const w = await mountPage()
        expect(w.find('[data-testid=install-blocked-notice]').exists()).toBe(false)
        expect(w.get('[data-testid=catalog-install]').attributes('disabled')).toBeUndefined()
        w.unmount()
    })
})
