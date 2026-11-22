import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import SettingModules from '../../../../cms/Core/admin/pages/setting/modules.vue'
import { MODULE_ERROR_CODES, moduleErrorKey } from '@/libs/module'

const state = vi.hoisted(() => ({ webmaster: true, alerts: [] as unknown[], hub: undefined as boolean | undefined }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const refetchSpy = vi.hoisted(() => vi.fn())
const siteGateMock = vi.hoisted(() => ({ loadSiteStatus: vi.fn() }))

vi.mock('@/pages/auth/site-gate', () => siteGateMock)
// The real connect screen is covered by connect-hub.spec.ts; here a button stands in that reports `connected`.
vi.mock('@/pages/auth/connect-hub.vue', async () => {
    const { defineComponent: dc, h: hh } = await import('vue')
    return {
        default: dc({
            props: ['status', 'unavailable'],
            emits: ['connected', 'refresh'],
            setup: (p, { emit }) => () => hh('button', { 'data-testid': 'connect-hub-stub', 'data-status': p.status?.status ?? '', onClick: () => emit('connected') }),
        }),
    }
})

vi.mock('@/config/navigation', () => ({ default: [] }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ rule: () => true, perm: (m: string) => m, iAlert: (v: unknown) => state.alerts.push(v) }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => state.webmaster, hasAccountData: () => true }))
vi.mock('@/hooks', () => ({
    useModules: () => ({
        data: ref([
            { name: 'Core', alias: 'core', enabled: true, version: '1.0', requires: [], switchable: false, path: '/x/cms/Core', missing_dependencies: [], blocking_dependents: [] },
            { name: 'Content', alias: 'content', enabled: true, version: '1.0', requires: [], switchable: true, path: '/x/cms/Content', missing_dependencies: [], blocking_dependents: [], revoked: true, revoked_reason: 'Lỗi bảo mật' },
            { name: 'Extra', alias: 'extra', enabled: false, version: '0.1', requires: [], switchable: true, path: '/x/runtime-modules/Extra', missing_dependencies: [], blocking_dependents: [] },
        ].map((m) => (state.hub === undefined ? m : { ...m, hub_configured: state.hub }))),
        isLoading: ref(false),
        isRefetching: ref(false),
        refetch: refetchSpy,
    }),
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k),
        locale: { value: 'vi' },
    }),
}))

const catalogBody = [
    { name: 'Helpdesk', latest_version: '1.2.0', installed: 'none', enabled: false, installed_version: null, versions: [{ version: '1.2.0', revoked: false, requires: ['Content'], irreversible: false, custom_code: true }] },
    { name: 'Billing', latest_version: '2.0.0', installed: 'none', enabled: false, installed_version: null, versions: [{ version: '2.0.0', revoked: false, requires: { Ghost: '>=1.0' }, irreversible: ['2026_01_01_drop_x'], custom_code: false }] },
    { name: 'Content', latest_version: '3.0.0', installed: 'release', enabled: true, installed_version: '3.0.0', versions: [{ version: '3.0.0', revoked: false, requires: [], irreversible: false, custom_code: false }] },
]
const preflight = (over: Record<string, unknown> = {}) => ({ name: 'Helpdesk', version: '1.2.0', can_install: true, blocking: [], warnings: [], changes: [], ...over })
const irreversibleWarning = { code: 'MODULE_WARN_IRREVERSIBLE_MIGRATIONS', message: 'x', details: { migrations: ['2026_01_01_drop_x'] } }

let preflightBody: Record<string, unknown>
const answer = () => {
    ajaxMock.get.mockImplementation((url: string) => {
        if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: catalogBody } })
        if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: [] } })
        if (url.includes('/preflight')) return Promise.resolve({ data: { data: preflightBody } })
        return Promise.reject(new Error('unexpected ' + url))
    })
}
const failure = (status: number, errorCode: string) => Promise.reject({ response: { status, data: { errorCode } } })

const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const AInput = defineComponent({
    props: ['value'],
    emits: ['update:value'],
    setup: (p, { emit, attrs }) => () => h('input', { 'data-testid': attrs['data-testid'], value: p.value, onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value) }),
})
const stubs = {
    'a-button': passthrough('button'),
    'a-spin': passthrough(),
    'a-empty': defineComponent({ props: ['description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, p.description) }),
    'a-row': passthrough(),
    'a-col': passthrough(),
    'a-card': passthrough(),
    'a-tag': passthrough('span'),
    'a-tooltip': passthrough('span'),
    'a-switch': defineComponent({ setup: (_p, { attrs }) => () => h('span', { 'data-testid': attrs['data-testid'] }) }),
    'a-input': AInput,
    'a-skeleton': true,
    'a-alert': defineComponent({ props: ['message', 'description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, [p.message, ' ', p.description]) }),
    'a-tab-pane': true,
    'a-tabs': defineComponent({
        props: ['activeKey'],
        emits: ['update:activeKey', 'change'],
        setup: (_p, { emit }) => () =>
            h('div', ['installed', 'available', 'history'].map((k) => h('button', { 'data-testid': `tab-${k}`, onClick: () => { emit('update:activeKey', k); emit('change', k) } }))),
    }),
    'a-drawer': defineComponent({ props: ['open', 'title'], setup: (p, { slots }) => () => (p.open ? h('div', [h('div', { 'data-testid': 'drawer-title' }, p.title), slots.default?.()]) : null) }),
    'i-icon': true,
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}

const mountPage = async () => {
    const wrapper = mount(SettingModules, { global: { mocks: { $t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k) }, stubs } })
    await flushPromises()
    return wrapper
}
const openInstall = async (wrapper: Awaited<ReturnType<typeof mountPage>>, index: number) => {
    await wrapper.find('[data-testid=tab-available]').trigger('click')
    await flushPromises()
    await wrapper.findAll('[data-testid=catalog-install]')[index].trigger('click')
    await flushPromises()
}

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    state.webmaster = true
    state.alerts = []
    state.hub = undefined
    preflightBody = preflight()
    answer()
})

describe('non-Webmaster', () => {
    it('sees the list without switches, tabs, upload or install requests', async () => {
        state.webmaster = false
        const wrapper = await mountPage()
        expect(wrapper.findAll('[data-testid=module-row]')).toHaveLength(3)
        expect(wrapper.find('[data-testid=module-switch]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=modules-tabs]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=module-upload-open]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=catalog-install]').exists()).toBe(false)
        expect(ajaxMock.get).not.toHaveBeenCalled()
    })
})

/** A row whose source tag says runtime; the Core row has no source tag at all (it is part of iSPA CMS). */
const isRuntimeRow = (r: ReturnType<ReturnType<typeof mount>['find']>) => {
    const tag = r.find('[data-testid=module-source]')
    return tag.exists() && tag.text().includes('source.runtime')
}

describe('installed tab', () => {
    it('shows switches, the runtime source tag and the revoked warning for a Webmaster', async () => {
        const wrapper = await mountPage()
        expect(wrapper.findAll('[data-testid=module-switch]').length).toBeGreaterThan(0)
        const rows = wrapper.findAll('[data-testid=module-row]')
        expect(rows.filter((r) => isRuntimeRow(r))).toHaveLength(1)
        const core = rows.find((r) => r.text().includes('Core'))!
        expect(core.find('[data-testid=module-source]').exists()).toBe(false)
        expect(rows.find((r) => isRuntimeRow(r))!.get('[data-testid=module-source]').text()).toContain('source.runtime')
        expect(wrapper.find('[data-testid=module-revoked]').text()).toContain('Lỗi bảo mật')
    })
})

describe('available tab', () => {
    it('lists the catalog with requires state, irreversible badge and custom-code note', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()
        const rows = wrapper.findAll('[data-testid=catalog-row]')
        expect(rows).toHaveLength(3)
        expect(rows[0].find('[data-testid=catalog-custom-code]').exists()).toBe(true)
        expect(rows[0].find('[data-testid=catalog-requirement]').attributes('data-met')).toBe('true')
        expect(rows[1].find('[data-testid=catalog-irreversible]').exists()).toBe(true)
        expect(rows[1].find('[data-testid=catalog-requirement]').text()).toContain('requires_unmet')
        expect(rows[1].find('[data-testid=catalog-requirement]').attributes('data-met')).toBe('false')
        expect(rows[2].find('[data-testid=catalog-install]').exists()).toBe(false)
        expect(rows[2].find('[data-testid=catalog-installed]').exists()).toBe(true)
    })

    it('shows an error with retry, never an empty catalog, when the hub fails', async () => {
        let fail = true
        const normal = ajaxMock.get.getMockImplementation() as (u: string) => Promise<unknown>
        ajaxMock.get.mockImplementation((u: string) => (u.endsWith('/catalog') && fail ? failure(502, 'MODULE_CATALOG_UNAVAILABLE') : normal(u)))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=catalog-error]').text()).toContain('text.modules.error.catalog_unavailable')
        expect(wrapper.find('[data-testid=catalog-empty]').exists()).toBe(false)
        fail = false
        await wrapper.find('[data-testid=catalog-retry]').trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-testid=catalog-row]')).toHaveLength(3)
    })

    it('shows the empty state for an empty catalog', async () => {
        ajaxMock.get.mockImplementation((u: string) => Promise.resolve({ data: { data: u.endsWith('/catalog') ? [] : [] } }))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=catalog-empty]').exists()).toBe(true)
    })
})

describe('install drawer', () => {
    // A site-wide block (switch off, server, hub, no background work) is one plain state: module-install-switch.spec.ts.
    it('disables start and shows translated reasons when the preflight blocks', async () => {
        preflightBody = preflight({ can_install: false, blocking: [{ code: 'MODULE_PHP_TOO_OLD', message: 'x', details: {} }, { code: 'MODULE_REQUIRES_UNMET', message: 'x', details: { unmet: [{ module: 'Content' }] } }] })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        const items = wrapper.findAll('[data-testid=preflight-blocking-item]')
        expect(items.map((i) => i.text())).toEqual([
            expect.stringContaining('text.modules.error.php_too_old'),
            expect.stringContaining('text.modules.error.requires_unmet'),
        ])
        expect(wrapper.find('[data-testid=install-start]').attributes('disabled')).toBeDefined()
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        expect(ajaxMock.post).not.toHaveBeenCalled()
    })

    it('shows the backup notice and starts a plain install with name and version only', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: {} } })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        expect(wrapper.find('[data-testid=install-backup-notice]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=install-confirm-name]').exists()).toBe(false)
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith('utilities/modules/install', { name: 'Helpdesk', version: '1.2.0', backup: true }, expect.anything())
        expect(wrapper.find('[data-testid=install-form]').exists()).toBe(false)
    })

    it('needs the typed module name for an irreversible install', async () => {
        preflightBody = preflight({ name: 'Billing', warnings: [irreversibleWarning] })
        ajaxMock.post.mockResolvedValue({ data: { data: {} } })
        const wrapper = await mountPage()
        await openInstall(wrapper, 1)
        expect(wrapper.find('[data-testid=preflight-irreversible]').text()).toContain('2026_01_01_drop_x')
        const start = () => wrapper.find('[data-testid=install-start]')
        expect(start().attributes('disabled')).toBeDefined()
        await wrapper.find('[data-testid=install-confirm-name]').setValue('Bill')
        expect(start().attributes('disabled')).toBeDefined()
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        expect(ajaxMock.post).not.toHaveBeenCalled()
        await wrapper.find('[data-testid=install-confirm-name]').setValue('Billing')
        expect(start().attributes('disabled')).toBeUndefined()
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith(
            'utilities/modules/install',
            { name: 'Billing', version: '2.0.0', backup: true, confirm_name: 'Billing', confirm_irreversible: true },
            expect.anything()
        )
    })

    it('lists the migrations with the "will run" sentence when names are known, and still needs the typed name', async () => {
        preflightBody = preflight({ name: 'Billing', warnings: [irreversibleWarning] })
        const wrapper = await mountPage()
        await openInstall(wrapper, 1)
        const box = wrapper.find('[data-testid=preflight-irreversible]')
        expect(box.text()).toContain('text.modules.install.drawer.irreversible_text')
        expect(box.text()).not.toContain('irreversible_text_unlisted')
        expect(wrapper.findAll('[data-testid=preflight-irreversible-list] li').map((li) => li.text())).toEqual(['2026_01_01_drop_x'])
        expect(wrapper.find('[data-testid=install-start]').attributes('disabled')).toBeDefined()
    })

    it('shows the unlisted sentence and no list when only a boolean is known, and still needs the typed name', async () => {
        preflightBody = preflight({ name: 'Billing', warnings: [{ code: 'MODULE_WARN_IRREVERSIBLE_MIGRATIONS', message: 'x', details: { migrations: [] } }] })
        const wrapper = await mountPage()
        await openInstall(wrapper, 1)
        const box = wrapper.find('[data-testid=preflight-irreversible]')
        expect(box.text()).toContain('text.modules.install.drawer.irreversible_text_unlisted')
        expect(wrapper.find('[data-testid=preflight-irreversible-list]').exists()).toBe(false)
        expect(box.find('ul').exists()).toBe(false)
        const start = () => wrapper.find('[data-testid=install-start]')
        expect(start().attributes('disabled')).toBeDefined()
        await wrapper.find('[data-testid=install-confirm-name]').setValue('Billing')
        expect(start().attributes('disabled')).toBeUndefined()
    })

    it('sends one request for a double submit', async () => {
        ajaxMock.post.mockImplementation(() => new Promise(() => {}))
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        const form = wrapper.find('[data-testid=install-form]')
        await form.trigger('submit')
        await form.trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
    })

    it('maps a server error code to its key and releases the guard so the person can retry', async () => {
        ajaxMock.post.mockImplementationOnce(() => failure(409, 'MODULE_INSTALL_RUNNING'))
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(wrapper.find('[data-testid=install-submit-error]').text()).toContain('text.modules.error.install_running')
        expect(wrapper.find('[data-testid=install-start]').attributes('disabled')).toBeUndefined()
    })

    it('uses the generic message for an unknown code', async () => {
        ajaxMock.post.mockImplementationOnce(() => failure(500, 'SOMETHING_NEW'))
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(wrapper.find('[data-testid=install-submit-error]').text()).toContain('text.modules.error.unknown')
    })
})

describe('upload', () => {
    const pick = async (wrapper: Awaited<ReturnType<typeof mountPage>>, testid: string, size: number, name: string) => {
        const file = new File(['x'], name)
        Object.defineProperty(file, 'size', { value: size })
        const input = wrapper.find(`[data-testid=${testid}]`)
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
        await input.trigger('change')
    }

    it('shows a translated picker button, the empty text and the chosen file name', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        expect(wrapper.find('[data-testid=upload-zip-pick]').text()).toContain('text.modules.install.upload.pick_zip')
        expect(wrapper.find('[data-testid=upload-signature-pick]').text()).toContain('text.modules.install.upload.pick_signature')
        expect(wrapper.find('[data-testid=upload-zip-name]').text()).toContain('text.modules.install.upload.no_file')
        expect(wrapper.find('[data-testid=upload-zip]').classes()).toContain('hidden')
        await pick(wrapper, 'upload-zip', 1024, 'm.zip')
        expect(wrapper.find('[data-testid=upload-zip-name]').text()).toBe('m.zip')
    })

    it('refuses an oversized zip without any request', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await pick(wrapper, 'upload-zip', 60 * 1024 * 1024, 'm.zip')
        await pick(wrapper, 'upload-signature', 100, 'm.sig.json')
        expect(wrapper.find('[data-testid=upload-error]').text()).toContain('text.modules.install.upload.too_large')
        expect(wrapper.find('[data-testid=upload-start]').attributes('disabled')).toBeDefined()
        await wrapper.find('[data-testid=upload-form]').trigger('submit')
        expect(ajaxMock.post).not.toHaveBeenCalled()
    })

    it('uploads once for a double submit and asks for the typed name when the server requires it', async () => {
        ajaxMock.post.mockImplementationOnce(() => failure(422, 'MODULE_CONFIRMATION_REQUIRED'))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await pick(wrapper, 'upload-zip', 1024, 'm.zip')
        await pick(wrapper, 'upload-signature', 100, 'm.sig.json')
        const form = wrapper.find('[data-testid=upload-form]')
        await form.trigger('submit')
        await form.trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post.mock.calls[0][0]).toBe('utilities/modules/upload')
        expect(wrapper.find('[data-testid=upload-confirm]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=upload-start]').attributes('disabled')).toBeDefined()

        ajaxMock.post.mockResolvedValueOnce({ data: { data: {} } })
        await wrapper.find('[data-testid=upload-confirm-name]').setValue('Billing')
        await form.trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(2)
        const body = ajaxMock.post.mock.calls[1][1] as FormData
        expect(body.get('confirm_name')).toBe('Billing')
        expect(body.get('confirm_irreversible')).toBe('1')
    })
})

describe('error code mapping', () => {
    it('maps every known code to a lowercase key without the MODULE_ prefix', () => {
        for (const code of MODULE_ERROR_CODES) {
            expect(moduleErrorKey(code)).toBe('text.modules.error.' + code.replace(/^MODULE_/, '').toLowerCase())
        }
        expect(moduleErrorKey('MODULE_INSTALL_MIGRATE_FAILED')).toBe('text.modules.error.install_migrate_failed')
        expect(moduleErrorKey('WEBMASTER_ONLY')).toBe('text.modules.error.webmaster_only')
    })

    it('falls back to the generic key for unknown codes and null for none', () => {
        expect(moduleErrorKey('NOPE')).toBe('text.modules.error.unknown')
        expect(moduleErrorKey(null)).toBeNull()
    })
})

describe('install history', () => {
    it('shows translated status and error, and a kept-backup note for a failed install', async () => {
        ajaxMock.get.mockImplementation((u: string) =>
            Promise.resolve({
                data: {
                    data: u.endsWith('/installs')
                        ? [{ uuid: 'u1', name: 'Helpdesk', version: '1.2.0', source: 'hub', status: 'failed', error_code: 'MODULE_INSTALL_MIGRATE_FAILED', backup_uuid: 'BACKUPUUIDX', warnings: [], started_at: '2026-11-09T10:00:00+07:00' }]
                        : [],
                },
            })
        )
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-history]').trigger('click')
        await flushPromises()
        const row = wrapper.find('[data-testid=history-row]')
        expect(row.text()).toContain('text.modules.install.status.failed')
        expect(row.find('[data-testid=history-error-code]').text()).toContain('text.modules.error.install_migrate_failed')
        expect(row.text()).toContain('backup_kept')
        expect(row.text()).not.toContain('BACKUPUUIDX')
    })
})

describe('upgrade of an installed runtime module', () => {
    const extra = { name: 'Extra', latest_version: '0.2.0', installed: 'runtime', enabled: false, installed_version: '0.1', upgrade_available: true, upgrade_version: '0.2.0', versions: [{ version: '0.2.0', revoked: false, requires: [], irreversible: false, custom_code: false }] }
    const upgradePreflight = () => preflight({ name: 'Extra', version: '0.2.0', kind: 'upgrade', previous_version: '0.1' })
    const answerWithUpgrade = (installs: unknown[] = []) => {
        ajaxMock.get.mockImplementation((url: string) => {
            if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: [...catalogBody, extra] } })
            if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: installs } })
            if (url.includes('/preflight')) return Promise.resolve({ data: { data: preflightBody } })
            return Promise.reject(new Error('unexpected ' + url))
        })
    }

    it('shows "new version" and an upgrade action on the installed runtime row only', async () => {
        answerWithUpgrade()
        const wrapper = await mountPage()
        const rows = wrapper.findAll('[data-testid=module-row]')
        const withOffer = rows.filter((r) => r.find('[data-testid=module-upgrade-available]').exists())
        expect(withOffer).toHaveLength(1)
        expect(withOffer[0].find('[data-testid=module-upgrade-available]').text()).toContain('"version":"0.2.0"')
        expect(withOffer[0].find('[data-testid=module-upgrade]').exists()).toBe(true)
        expect(wrapper.findAll('[data-testid=module-upgrade]')).toHaveLength(1)
    })

    it('opens the drawer with the upgrade heading and starts it with name and the new version', async () => {
        answerWithUpgrade()
        preflightBody = upgradePreflight()
        ajaxMock.post.mockResolvedValue({ data: { data: {} } })
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upgrade]').trigger('click')
        await flushPromises()

        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Extra/0.2.0/preflight', expect.anything())
        expect(wrapper.find('[data-testid=upgrade-heading]').text()).toContain('{"from":"0.1","to":"0.2.0"}')
        expect(wrapper.find('[data-testid=install-steps]').text()).toContain('step.keep_previous')

        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith('utilities/modules/install', { name: 'Extra', version: '0.2.0', backup: true }, expect.anything())
    })

    it('maps downgrade and pinned-dependent codes to their own keys', () => {
        expect(moduleErrorKey('MODULE_DOWNGRADE')).toBe('text.modules.error.downgrade')
        expect(moduleErrorKey('MODULE_SAME_VERSION')).toBe('text.modules.error.same_version')
        expect(moduleErrorKey('MODULE_DEPENDENT_PINS_VERSION')).toBe('text.modules.error.dependent_pins_version')
    })

    it('shows an upgrade in the history with both versions, and that the old version came back when it failed', async () => {
        answerWithUpgrade([
            { uuid: 'u2', name: 'Extra', version: '0.2.0', kind: 'upgrade', previous_version: '0.1', source: 'upload', status: 'failed', error_code: 'MODULE_INSTALL_MIGRATE_FAILED', backup_uuid: null, warnings: [], started_at: '2026-11-10T10:00:00+07:00' },
        ])
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-history]').trigger('click')
        await flushPromises()
        const row = wrapper.find('[data-testid=history-row]')
        expect(row.find('[data-testid=history-kind]').exists()).toBe(true)
        expect(row.find('[data-testid=history-upgrade-versions]').text()).toContain('{"from":"0.1","to":"0.2.0"}')
        expect(row.find('[data-testid=history-upgrade-restored]').exists()).toBe(true)
    })

    it('tells the person in the upload drawer that a newer package upgrades an installed module', async () => {
        answerWithUpgrade()
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=upload-upgrade-hint]').exists()).toBe(true)
    })
})

describe('upgrade title from the preflight and hub not connected', () => {
    it('shows the upgrade title and heading when the preflight says upgrade, whatever the catalog says', async () => {
        // The catalog lists Helpdesk as not installed; only the preflight knows it replaces 1.0.0.
        preflightBody = preflight({ kind: 'upgrade', previous_version: '1.0.0' })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)

        expect(wrapper.find('[data-testid=drawer-title]').text()).toContain('text.modules.install.drawer.upgrade_title')
        expect(wrapper.find('[data-testid=upgrade-heading]').text()).toContain('{"from":"1.0.0","to":"1.2.0"}')
    })

    it('keeps the plain install title when the preflight says install', async () => {
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)

        expect(wrapper.find('[data-testid=drawer-title]').text()).toContain('text.modules.install.drawer.title')
        expect(wrapper.find('[data-testid=upgrade-heading]').exists()).toBe(false)
    })

    it('sends no catalog request and shows a quiet note when no hub is connected', async () => {
        state.hub = false
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()

        expect(ajaxMock.get).not.toHaveBeenCalledWith('utilities/modules/catalog', expect.anything())
        expect(ajaxMock.get).not.toHaveBeenCalledWith('utilities/modules/catalog')
        expect(wrapper.find('[data-testid=hub-not-connected]').text()).toBe('text.modules.install.hub_not_connected')
        expect(wrapper.find('[data-testid=catalog-error]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=catalog-row]').exists()).toBe(false)
    })

    it('offers the manual connect form from the Available tab and reads the modules again once connected', async () => {
        state.hub = false
        siteGateMock.loadSiteStatus.mockResolvedValue({ status: 'not_connected', message_key: 'text.site_status.not_configured', expires_at: null })
        refetchSpy.mockClear()
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid=connect-hub-stub]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=hub-connect-open]').text()).toBe('text.site_connect.title')

        // The site address + secret form sits behind the manual link, in the drawer.
        expect(wrapper.find('[data-testid=hub-connect-manual-open]').text()).toBe('text.hub_connect.manual_link')
        await wrapper.find('[data-testid=hub-connect-manual-open]').trigger('click')
        await flushPromises()
        const screen = wrapper.find('[data-testid=connect-hub-stub]')
        expect(screen.attributes('data-status')).toBe('not_connected')
        expect(ajaxMock.post).not.toHaveBeenCalled()

        await screen.trigger('click')
        await flushPromises()
        expect(refetchSpy).toHaveBeenCalled()
        // The drawer stays open on the screen's success message until the person closes it.
        expect(wrapper.find('[data-testid=connect-hub-stub]').exists()).toBe(true)
    })

    it('one tap: the Modules button starts the attempt and sends the browser to ispa.tech without opening the drawer', async () => {
        state.hub = false
        const oneTap = await import('../../../../cms/Core/admin/pages/setting/modules/hub-one-tap.js')
        const go = vi.spyOn(oneTap.browser, 'go').mockImplementation(() => {})
        ajaxMock.post.mockResolvedValue({ data: { data: { redirect_url: 'https://ispa.tech/portal/connect-site?state=s' } } })
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()

        await wrapper.find('[data-testid=hub-connect-open]').trigger('click')
        await flushPromises()

        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post.mock.calls[0][0]).toBe('hub-connect/start')
        expect(ajaxMock.post.mock.calls[0][1]).toEqual({})
        expect(go).toHaveBeenCalledWith('https://ispa.tech/portal/connect-site?state=s')
        expect(wrapper.find('[data-testid=hub-connect-waiting]').text()).toBe('text.hub_connect.waiting')
        expect(wrapper.find('[data-testid=hub-one-tap]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=connect-hub-stub]').exists()).toBe(false)
        go.mockRestore()
    })

    it('one tap: a site that already holds a connection is asked first, then starts again with replace', async () => {
        state.hub = false
        const oneTap = await import('../../../../cms/Core/admin/pages/setting/modules/hub-one-tap.js')
        const go = vi.spyOn(oneTap.browser, 'go').mockImplementation(() => {})
        ajaxMock.post.mockImplementationOnce(() => failure(409, 'HUB_ALREADY_CONNECTED'))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()

        await wrapper.find('[data-testid=hub-connect-open]').trigger('click')
        await flushPromises()

        expect(go).not.toHaveBeenCalled()
        expect(wrapper.find('[data-testid=hub-one-tap-replace]').text()).toContain('text.hub_connect.replace_text')

        ajaxMock.post.mockResolvedValueOnce({ data: { data: { redirect_url: 'https://ispa.tech/portal/connect-site?state=r' } } })
        await wrapper.find('[data-testid=hub-one-tap-replace-confirm]').trigger('click')
        await flushPromises()

        expect(ajaxMock.post.mock.calls[1][0]).toBe('hub-connect/start')
        expect(ajaxMock.post.mock.calls[1][1]).toEqual({ replace: true })
        expect(go).toHaveBeenCalledWith('https://ispa.tech/portal/connect-site?state=r')
        go.mockRestore()
    })

    it('one tap: a refused start opens the drawer on a plain message', async () => {
        state.hub = false
        ajaxMock.post.mockImplementationOnce(() => failure(409, 'HUB_CONNECT_MANAGED_BY_ENV'))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()

        await wrapper.find('[data-testid=hub-connect-open]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid=hub-one-tap-error]').text()).toBe('text.hub_connect.err_managed_by_env')
        // The server settings decide: nothing here can retry or replace them.
        expect(wrapper.find('[data-testid=hub-one-tap-start]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=hub-connect-manual]').exists()).toBe(false)
    })

    it('still loads the catalog when a hub is connected', async () => {
        state.hub = true
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()

        expect(ajaxMock.get.mock.calls.some((c) => c[0] === 'utilities/modules/catalog')).toBe(true)
        expect(wrapper.find('[data-testid=hub-not-connected]').exists()).toBe(false)
        expect(wrapper.findAll('[data-testid=catalog-row]').length).toBeGreaterThan(0)
    })
})

describe('upload drawer upgrade detection', () => {
    const signatureFor = (name: string, version: string) =>
        new File([JSON.stringify({ manifest: btoa(JSON.stringify({ name, version })) })], 'p.sig.json', { type: 'application/json' })
    const pick = async (wrapper: Awaited<ReturnType<typeof mountPage>>, testid: string, file: File) => {
        const input = wrapper.find(`[data-testid=${testid}]`)
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
        await input.trigger('change')
        await flushPromises()
    }
    const openUploadWith = async (name: string, version: string) => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await flushPromises()
        await pick(wrapper, 'upload-signature', signatureFor(name, version))
        return wrapper
    }

    it('shows the upgrade title and heading when the signature names a newer version of an installed module', async () => {
        const wrapper = await openUploadWith('Extra', '0.2.0')
        expect(wrapper.find('[data-testid=drawer-title]').text()).toContain('text.modules.install.drawer.upgrade_title|{"module":"Extra"}')
        expect(wrapper.find('[data-testid=upload-upgrade-heading]').text()).toContain('{"from":"0.1","to":"0.2.0"}')
        expect(wrapper.text()).toContain('text.modules.install.drawer.upgrade_backup_notice')
    })

    it.each([
        ['same version', 'Extra', '0.1'],
        ['older version', 'Extra', '0.0.9'],
        ['module not installed', 'Helpdesk', '9.9.9'],
    ])('keeps the plain title for %s', async (_label, name, version) => {
        const wrapper = await openUploadWith(name, version)
        expect(wrapper.find('[data-testid=drawer-title]').text()).toBe('text.modules.install.upload.title')
        expect(wrapper.find('[data-testid=upload-upgrade-heading]').exists()).toBe(false)
    })

    it('ignores an unreadable signature file silently', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await flushPromises()
        await pick(wrapper, 'upload-signature', new File(['not json'], 'x.json'))
        expect(wrapper.find('[data-testid=upload-upgrade-heading]').exists()).toBe(false)
    })

    it('tells in the toast that an upgrade started when the server answers kind upgrade', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: { kind: 'upgrade', previous_version: '0.1', version: '0.2.0' } } })
        const wrapper = await openUploadWith('Extra', '0.2.0')
        await pick(wrapper, 'upload-zip', new File(['zip'], 'p.zip'))
        await wrapper.find('[data-testid=upload-form]').trigger('submit')
        await flushPromises()

        expect(JSON.stringify(state.alerts)).toContain('text.modules.install.upload.upgrade_started|{\\"from\\":\\"0.1\\",\\"to\\":\\"0.2.0\\"}')
    })

    it('keeps the plain started toast for an install answer', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: { kind: 'install', version: '1.0.0' } } })
        const wrapper = await openUploadWith('Helpdesk', '1.0.0')
        await pick(wrapper, 'upload-zip', new File(['zip'], 'p.zip'))
        await wrapper.find('[data-testid=upload-form]').trigger('submit')
        await flushPromises()

        expect(JSON.stringify(state.alerts)).toContain('text.modules.install.upload.started')
        expect(JSON.stringify(state.alerts)).not.toContain('upgrade_started')
    })
})


describe('uninstall of an installed runtime module', () => {
    let info: Record<string, unknown>
    const answerWithInfo = (installs: unknown[] = []) => {
        ajaxMock.get.mockImplementation((url: string) => {
            if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: catalogBody } })
            if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: installs } })
            if (url === 'utilities/modules/runtime/Extra/uninstall') return Promise.resolve({ data: { data: info } })
            return Promise.reject(new Error('unexpected ' + url))
        })
    }
    const openUninstallDrawer = async () => {
        const wrapper = await mountPage()
        const row = wrapper.findAll('[data-testid=module-row]').find((r) => isRuntimeRow(r))!
        await row.find('[data-testid=module-uninstall]').trigger('click')
        await flushPromises()
        return wrapper
    }
    const type = async (wrapper: Awaited<ReturnType<typeof mountPage>>, value: string) => {
        const input = wrapper.find('[data-testid=uninstall-confirm-name]')
        ;(input.element as HTMLInputElement).value = value
        await input.trigger('input')
    }

    beforeEach(() => {
        info = { name: 'Extra', version: '0.1', enabled: false, uninstall_tables: [], dependents: [] }
        answerWithInfo()
    })

    it('shows the action only on runtime rows of a Webmaster', async () => {
        const wrapper = await mountPage()
        expect(wrapper.findAll('[data-testid=module-uninstall]')).toHaveLength(1)
        const rows = wrapper.findAll('[data-testid=module-row]')
        expect(rows.filter((r) => r.find('[data-testid=module-uninstall]').exists()).every((r) => isRuntimeRow(r))).toBe(true)

        state.webmaster = false
        const plain = await mountPage()
        expect(plain.find('[data-testid=module-uninstall]').exists()).toBe(false)
    })

    it('keeps the confirm button disabled until the typed name equals the module name', async () => {
        const wrapper = await openUninstallDrawer()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/runtime/Extra/uninstall', expect.anything())
        const start = () => wrapper.find('[data-testid=uninstall-start]').attributes('disabled')

        expect(start()).toBeDefined()
        await type(wrapper, 'extra')
        expect(start()).toBeDefined()
        await type(wrapper, 'Extra')
        expect(start()).toBeUndefined()
    })

    it('offers the drop-data choice only when the module declares tables', async () => {
        let wrapper = await openUninstallDrawer()
        expect(wrapper.find('[data-testid=uninstall-data-choice]').exists()).toBe(false)

        info = { ...info, uninstall_tables: ['extra_items', 'extra_logs'] }
        wrapper = await openUninstallDrawer()
        expect(wrapper.find('[data-testid=uninstall-data-choice]').exists()).toBe(true)
        expect((wrapper.find('[data-testid=uninstall-keep]').element as HTMLInputElement).checked).toBe(true)
        expect(wrapper.find('[data-testid=uninstall-data-choice]').text()).toContain('"count":2')
    })

    it('sends keep-data by default and the typed name, then moves to the history', async () => {
        info = { ...info, uninstall_tables: ['extra_items'] }
        ajaxMock.delete.mockResolvedValue({ data: { data: { kind: 'uninstall' } } })
        const wrapper = await openUninstallDrawer()
        await type(wrapper, 'Extra')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await flushPromises()

        expect(ajaxMock.delete).toHaveBeenCalledTimes(1)
        expect(ajaxMock.delete).toHaveBeenCalledWith('utilities/modules/runtime/Extra', expect.objectContaining({ data: { confirm_name: 'Extra', drop_data: false } }))
        expect(JSON.stringify(state.alerts)).toContain('text.modules.install.uninstall.started')
        expect(wrapper.find('[data-testid=uninstall-form]').exists()).toBe(false)
    })

    it('sends drop_data true after the person picks it, and sends one request for a double submit', async () => {
        info = { ...info, uninstall_tables: ['extra_items'] }
        ajaxMock.delete.mockImplementation(() => new Promise(() => {}))
        const wrapper = await openUninstallDrawer()
        await wrapper.find('[data-testid=uninstall-drop]').setValue(true)
        await type(wrapper, 'Extra')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await flushPromises()

        expect(ajaxMock.delete).toHaveBeenCalledTimes(1)
        expect(ajaxMock.delete.mock.calls[0][1].data).toEqual({ confirm_name: 'Extra', drop_data: true })
    })

    it('never sends drop_data true for a module without declared tables', async () => {
        ajaxMock.delete.mockResolvedValue({ data: {} })
        const wrapper = await openUninstallDrawer()
        await type(wrapper, 'Extra')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await flushPromises()

        expect(ajaxMock.delete.mock.calls[0][1].data.drop_data).toBe(false)
    })

    it('blocks the confirm button and names the dependents', async () => {
        info = { ...info, dependents: ['Content'] }
        const wrapper = await openUninstallDrawer()
        await type(wrapper, 'Extra')

        expect(wrapper.find('[data-testid=uninstall-dependents]').text()).toContain('text.modules.install.uninstall.dependents')
        expect(wrapper.find('[data-testid=uninstall-dependents]').text()).toContain('Content')
        expect(wrapper.find('[data-testid=uninstall-start]').attributes('disabled')).toBeDefined()
    })

    it('maps a server error code to its message with the dependents and releases the guard', async () => {
        ajaxMock.delete.mockImplementationOnce(() => Promise.reject({ response: { status: 409, data: { errorCode: 'MODULE_HAS_DEPENDENTS', data: { dependents: ['Content', 'Billing'] } } } }))
        const wrapper = await openUninstallDrawer()
        await type(wrapper, 'Extra')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await flushPromises()

        const message = wrapper.find('[data-testid=uninstall-submit-error]').text()
        expect(message).toContain('text.modules.error.has_dependents')
        expect(message).toContain('Content, Billing')
        expect(wrapper.find('[data-testid=uninstall-start]').attributes('disabled')).toBeUndefined()
    })

    it('names the tables a refused uninstall would have dropped, and no raw code is shown', async () => {
        ajaxMock.delete.mockImplementationOnce(() => Promise.reject({ response: { status: 409, data: { errorCode: 'MODULE_TABLE_NOT_OWNED', data: { tables: ['users', 'orders'] } } } }))
        const wrapper = await openUninstallDrawer()
        await type(wrapper, 'Extra')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await flushPromises()

        const message = wrapper.find('[data-testid=uninstall-submit-error]').text()
        expect(message).toContain('text.modules.error.table_not_owned')
        expect(message).toContain('users, orders')
        expect(message).not.toContain('MODULE_TABLE_NOT_OWNED')
        expect(wrapper.find('[data-testid=uninstall-start]').attributes('disabled')).toBeUndefined()
    })

    it('explains a refused uninstall while money records remain, naming the module', async () => {
        ajaxMock.delete.mockImplementationOnce(() => Promise.reject({ response: { status: 422, data: { errorCode: 'MODULE_HAS_DATA' } } }))
        const wrapper = await openUninstallDrawer()
        await type(wrapper, 'Extra')
        await wrapper.find('[data-testid=uninstall-form]').trigger('submit')
        await flushPromises()

        const message = wrapper.find('[data-testid=uninstall-submit-error]').text()
        expect(message).toContain('text.modules.error.has_data')
        expect(message).toContain('"module":"Extra"')
        expect(message).not.toContain('MODULE_HAS_DATA')
        expect(wrapper.find('[data-testid=uninstall-start]').attributes('disabled')).toBeUndefined()
    })

    it('shows a retry when the module details cannot be loaded', async () => {
        ajaxMock.get.mockImplementation((url: string) =>
            url.endsWith('/uninstall') ? failure(500, 'X') : Promise.resolve({ data: { data: url.endsWith('/installs') || url.endsWith('/catalog') ? [] : [] } }),
        )
        const wrapper = await openUninstallDrawer()
        expect(wrapper.find('[data-testid=uninstall-load-error]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=uninstall-form] [data-testid=uninstall-start]').exists()).toBe(false)
    })

    it('shows an uninstall row in the history with its own label and result', async () => {
        answerWithInfo([
            { uuid: 'u9', name: 'Extra', version: '0.1', source: 'local', kind: 'uninstall', status: 'succeeded', confirmed_irreversible: false, backup_uuid: 'B', warnings: [], started_at: '2026-11-10T10:00:00+07:00' },
        ])
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-history]').trigger('click')
        await flushPromises()
        const row = wrapper.find('[data-testid=history-row]')

        expect(row.find('[data-testid=history-kind]').text()).toContain('kind_uninstall')
        expect(row.find('[data-testid=history-uninstall-result]').text()).toContain('uninstall_kept')
        expect(row.text()).toContain('source_local')
    })
})

describe('backup choice and requirements', () => {
    const extra = { name: 'Extra', latest_version: '0.2.0', installed: 'runtime', enabled: true, installed_version: '0.1', upgrade_available: true, upgrade_version: '0.2.0', versions: [{ version: '0.2.0', revoked: false, requires: [], irreversible: false, custom_code: false }] }
    const ghost = { name: 'Ghost', latest_version: '1.0.0', installed: 'none', enabled: false, installed_version: null, versions: [{ version: '1.0.0', revoked: false, requires: [], irreversible: false, custom_code: false }] }
    const answerWith = (rows: unknown[]) => {
        ajaxMock.get.mockImplementation((url: string) => {
            if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: [...catalogBody, ...rows] } })
            if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: [] } })
            if (url.includes('/preflight')) return Promise.resolve({ data: { data: preflightBody } })
            return Promise.reject(new Error('unexpected ' + url))
        })
    }

    it('starts the switch off when the preflight advises it, shows why and the last own backup, and sends backup false', async () => {
        preflightBody = preflight({ backup: { recommended: false, reason: 'own_tables_only', last_backup: { at: '2026-11-13T01:05:00+07:00', source: 'scheduled' } } })
        ajaxMock.post.mockResolvedValue({ data: { data: {} } })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)

        const toggle = wrapper.find('[data-testid=install-backup-toggle]')
        expect((toggle.element as HTMLInputElement).checked).toBe(false)
        expect(wrapper.find('[data-testid=install-backup-reason]').text()).toContain('backup_reason.own_tables_only')
        expect(wrapper.find('[data-testid=install-backup-last]').text()).toContain('backup_last.scheduled')
        expect(wrapper.find('[data-testid=install-backup-notice]').text()).toContain('backup_off_notice')
        expect(wrapper.find('[data-testid=install-steps]').text()).not.toContain('step.backup')

        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledWith('utilities/modules/install', { name: 'Helpdesk', version: '1.2.0', backup: false }, expect.anything())
    })

    it('starts the switch on for table-altering changes and says when no own backup exists', async () => {
        preflightBody = preflight({ backup: { recommended: true, reason: 'alters_existing', last_backup: { at: null, source: null } } })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        expect((wrapper.find('[data-testid=install-backup-toggle]').element as HTMLInputElement).checked).toBe(true)
        expect(wrapper.find('[data-testid=install-backup-last]').text()).toContain('backup_last.none')
        expect(wrapper.find('[data-testid=install-steps]').text()).toContain('step.backup')
    })

    it('warns before the download when the version changes the database: count, tables, irreversible, last backup, advice', async () => {
        preflightBody = preflight({
            name: 'Billing',
            warnings: [irreversibleWarning],
            backup: { recommended: true, reason: 'irreversible', new_migrations: 3, changes_database: true, altered_tables: ['posts', 'users'], last_backup: { at: '2026-11-13T01:05:00+07:00', source: 'scheduled' } },
        })
        const wrapper = await mountPage()
        await openInstall(wrapper, 1)
        const warning = wrapper.find('[data-testid=preflight-db-warning]')
        expect(warning.find('[data-testid=preflight-db-warning-alert]').text()).toContain('db_warning.title')
        expect(warning.find('[data-testid=preflight-db-warning-alert]').text()).toContain('db_warning.advice')
        const facts = warning.find('[data-testid=preflight-db-warning-facts]').text()
        expect(facts).toContain('db_warning.count|{"count":3}')
        expect(facts).toContain('db_warning.tables|{"tables":"posts, users"}')
        expect(facts).toContain('db_warning.irreversible_listed')
        expect(facts).toContain('backup_last.scheduled')
        // The warning sits above the install button, before anything is downloaded.
        const html = wrapper.find('[data-testid=install-form]').html()
        expect(html.indexOf('preflight-db-warning')).toBeLessThan(html.indexOf('install-start'))
    })

    it('shows a general warning when the migration list is unknown and none when nothing new runs', async () => {
        preflightBody = preflight({ backup: { recommended: true, reason: 'unknown', new_migrations: 0, changes_database: true, altered_tables: [], last_backup: { at: null, source: null } } })
        let wrapper = await mountPage()
        await openInstall(wrapper, 0)
        expect(wrapper.find('[data-testid=preflight-db-warning-alert]').text()).toContain('db_warning.title_unknown')
        expect(wrapper.find('[data-testid=preflight-db-warning-facts]').text()).toContain('db_warning.unknown')

        preflightBody = preflight({ backup: { recommended: false, reason: 'no_new_migrations', new_migrations: 0, changes_database: false, altered_tables: [], last_backup: { at: null, source: null } } })
        wrapper = await mountPage()
        await openInstall(wrapper, 0)
        expect(wrapper.find('[data-testid=preflight-db-warning]').exists()).toBe(false)
    })

    it('a reinstall over tables kept from an earlier install starts the backup on and names the tables', async () => {
        preflightBody = preflight({
            backup: { recommended: true, reason: 'earlier_data', new_migrations: 0, changes_database: false, altered_tables: [], earlier_tables: ['probe_items', 'probe_tags'], conflicting_tables: [], last_backup: { at: null, source: null } },
        })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        expect((wrapper.find('[data-testid=install-backup-toggle]').element as HTMLInputElement).checked).toBe(true)
        expect(wrapper.find('[data-testid=install-backup-reason]').text()).toContain('backup_reason.earlier_data')
        expect(wrapper.find('[data-testid=preflight-db-warning-alert]').text()).toContain('db_warning.title_earlier')
        const facts = wrapper.find('[data-testid=preflight-db-warning-facts]').text()
        expect(facts).toContain('db_warning.earlier_tables|{"tables":"probe_items, probe_tags"}')
        expect(facts).not.toContain('db_warning.own_tables')
        expect(facts).not.toContain('db_warning.count')
    })

    it('a table that exists without its create record blocks the install and names the table', async () => {
        preflightBody = preflight({
            blocking: [{ code: 'MODULE_TABLE_EXISTS', message: 'x', details: { tables: ['probe_items'] } }],
            backup: { recommended: true, reason: 'alters_existing', new_migrations: 2, changes_database: true, altered_tables: ['probe_items'], earlier_tables: [], conflicting_tables: ['probe_items'], last_backup: { at: null, source: null } },
        })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        expect(moduleErrorKey('MODULE_TABLE_EXISTS')).toBe('text.modules.error.table_exists')
        expect(wrapper.text()).toContain('error.table_exists|{')
        expect(wrapper.text()).toContain('"tables":"probe_items"')
    })

    it('lists each unmet requirement with the needed and installed version and opens the upgrade or install of that module', async () => {
        answerWith([extra, ghost])
        preflightBody = preflight({
            name: 'Helpdesk',
            can_install: false,
            blocking: [{ code: 'MODULE_REQUIRES_UNMET', message: 'x', details: { unmet: [
                { module: 'Extra', constraint: '>=0.2.0,<1.0', reason: 'version', installed_version: '0.1' },
                { module: 'Ghost', constraint: '*', reason: 'missing', installed_version: null },
                { module: 'Content', constraint: '>=9.0', reason: 'version', installed_version: '3.0.0' },
            ] } }],
        })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)

        const rows = wrapper.findAll('[data-testid=preflight-requirement]')
        expect(rows).toHaveLength(3)
        expect(rows[0].text()).toContain('requirement.version')
        expect(rows[0].text()).toContain('"installed":"0.1"')
        expect(rows[0].text()).toContain('constraint.gte|{\\"version\\":\\"0.2.0\\"}, text.modules.install.constraint.lt')
        expect(rows[0].find('[data-testid=preflight-requirement-action]').text()).toContain('upgrade_action')
        expect(rows[1].text()).toContain('requirement.missing_any')
        expect(rows[1].find('[data-testid=preflight-requirement-action]').text()).toContain('install_action')
        // No newer Content on offer: no button, a plain hint instead.
        expect(rows[2].find('[data-testid=preflight-requirement-action]').exists()).toBe(false)
        expect(rows[2].find('[data-testid=preflight-requirement-hint]').text()).toContain('no_offer')

        preflightBody = preflight({ name: 'Extra', version: '0.2.0', kind: 'upgrade', previous_version: '0.1' })
        await rows[0].find('[data-testid=preflight-requirement-action]').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Extra/0.2.0/preflight', expect.anything())
        expect(wrapper.find('[data-testid=upgrade-heading]').text()).toContain('{"from":"0.1","to":"0.2.0"}')
    })

    it('names an installed module that pins an older range and offers its upgrade', async () => {
        answerWith([extra])
        preflightBody = preflight({ can_install: false, blocking: [{ code: 'MODULE_DEPENDENT_PINS_VERSION', message: 'x', details: { dependents: [{ module: 'Extra', constraint: '<2.0' }] } }] })
        const wrapper = await mountPage()
        await openInstall(wrapper, 0)
        const row = wrapper.find('[data-testid=preflight-requirement]')
        expect(row.text()).toContain('requirement.pinned')
        expect(row.find('[data-testid=preflight-requirement-action]').text()).toContain('upgrade_action')
    })

    it('shows in the history that the backup was skipped, with the restore advice when it failed', async () => {
        ajaxMock.get.mockImplementation((u: string) =>
            Promise.resolve({ data: { data: u.endsWith('/installs') ? [{ uuid: 'u1', name: 'Helpdesk', version: '1.2.0', source: 'hub', status: 'failed', error_code: 'MODULE_INSTALL_MIGRATE_FAILED', backup_uuid: null, backup_skipped: true, warnings: [], started_at: '2026-11-09T10:00:00+07:00' }] : [] } })
        )
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=tab-history]').trigger('click')
        await flushPromises()
        const row = wrapper.find('[data-testid=history-row]')
        expect(row.find('[data-testid=history-backup-skipped]').text()).toContain('history.backup_skipped_failed')
        expect(row.text()).not.toContain('backup_kept')
    })

    it('sends the upload backup choice', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: {} } })
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await flushPromises()
        expect((wrapper.find('[data-testid=upload-backup-toggle]').element as HTMLInputElement).checked).toBe(true)
        await wrapper.find('[data-testid=upload-backup-toggle]').setValue(false)
        expect(wrapper.find('[data-testid=upload-backup-off]').exists()).toBe(true)
    })
})
