import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import SettingModules from '../../../../cms/Core/admin/pages/setting/modules.vue'
import ThirdPartyConfirm from '../../../../cms/Core/admin/pages/setting/modules/third-party-confirm.vue'
import { MODULE_ERROR_CODES, moduleErrorKey } from '@/libs/module'

/*
 * the first install of a module that iSPA does not publish shows the publisher, what the module adds and a
 * confirmation box; the install can not start until it is ticked, and the request carries `confirm_third_party`.
 */

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))

vi.mock('@/config/navigation', () => ({ default: [] }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ rule: () => true, perm: (m: string) => m, iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => true, hasAccountData: () => true }))
vi.mock('@/hooks', () => ({
    useModules: () => ({
        data: ref([{ name: 'Core', alias: 'core', enabled: true, version: '1.0', requires: [], switchable: false, path: '/x/cms/Core', missing_dependencies: [], blocking_dependents: [] }]),
        isLoading: ref(false),
        isRefetching: ref(false),
        refetch: vi.fn(),
    }),
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, unknown>, plural?: number) => (p ? `${k}|${JSON.stringify(p)}${plural === undefined ? '' : `#${plural}`}` : k),
        locale: { value: 'vi' },
    }),
}))

const $t = (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k)
const ACME = { key: 'acme', type: 'partner', name: 'Acme Studio' }
const IMPACT = {
    permissions: ['gallery.view', 'gallery.edit'],
    menus: 1,
    routes: { public: 2, admin: 3, api: 0 },
    tables_created: ['gallery_items'],
    tables_altered: [],
    irreversible: false,
    migrations: 1,
}
const partnerWarning = { code: 'MODULE_WARN_THIRD_PARTY_FIRST_INSTALL', message: 'x', details: { publisher: ACME, impact: IMPACT } }
const catalogBody = [
    { name: 'Gallery', latest_version: '1.0.0', installed: 'none', enabled: false, installed_version: null, publisher: ACME, versions: [{ version: '1.0.0', revoked: false, requires: [], irreversible: false, custom_code: false }] },
]

let preflightBody: Record<string, unknown>
const preflight = (over: Record<string, unknown> = {}) => ({ name: 'Gallery', version: '1.0.0', kind: 'install', can_install: true, blocking: [], warnings: [], changes: [], ...over })

const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const stubs = {
    'a-button': passthrough('button'),
    'a-spin': passthrough(),
    'a-empty': passthrough(),
    'a-row': passthrough(),
    'a-col': passthrough(),
    'a-card': passthrough(),
    'a-tag': defineComponent({ setup: (_p, { slots, attrs }) => () => h('span', { 'data-testid': attrs['data-testid'], 'data-type': attrs['data-type'] }, slots.default?.()) }),
    'a-tooltip': passthrough('span'),
    'a-switch': true,
    'a-input': true,
    'a-skeleton': true,
    'a-alert': defineComponent({ props: ['message', 'description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, [p.message, ' ', p.description]) }),
    'a-tab-pane': true,
    'a-tabs': defineComponent({
        emits: ['update:activeKey', 'change'],
        setup: (_p, { emit }) => () =>
            h('div', ['installed', 'available', 'history'].map((k) => h('button', { 'data-testid': `tab-${k}`, onClick: () => { emit('update:activeKey', k); emit('change', k) } }))),
    }),
    'a-drawer': defineComponent({ props: ['open'], setup: (p, { slots }) => () => (p.open ? h('div', slots.default?.()) : null) }),
    'i-icon': true,
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}

const mountPage = async () => {
    const wrapper = mount(SettingModules, { global: { mocks: { $t }, stubs } })
    await flushPromises()
    return wrapper
}

const openInstall = async (wrapper: Awaited<ReturnType<typeof mountPage>>) => {
    await wrapper.find('[data-testid=tab-available]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid=catalog-install]').trigger('click')
    await flushPromises()
}

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    preflightBody = preflight()
    ajaxMock.get.mockImplementation((url: string) => {
        if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: catalogBody } })
        if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: [] } })
        if (url.includes('/preflight')) return Promise.resolve({ data: { data: preflightBody } })
        return Promise.resolve({ data: { data: {} } })
    })
})

describe('third-party-confirm', () => {
    it('names the publisher, lists what the module adds in plain words and never shows permission codes', () => {
        const wrapper = mount(ThirdPartyConfirm, { props: { details: { publisher: ACME, impact: IMPACT } }, global: { mocks: { $t }, stubs } })
        expect(wrapper.find('[data-testid=third-party-intro]').text()).toContain('text.modules.install.third_party.intro|{"name":"Acme Studio"}')
        expect(wrapper.find('[data-testid=publisher-badge]').attributes('data-type')).toBe('partner')
        const impact = wrapper.find('[data-testid=third-party-impact]').text()
        // The count is also the plural form, so one permission reads "1 new admin permission".
        expect(impact).toContain('impact.permissions|{"count":2}#2')
        expect(impact).toContain('impact.menus|{"count":1}#1')
        expect(impact).toContain('impact.routes|{"public":2,"admin":3,"api":0}')
        expect(impact).toContain('impact.tables_created|{"tables":"gallery_items"}')
        expect(impact).not.toContain('gallery.view')
        expect(wrapper.text()).toContain('text.modules.install.third_party.confirm_label')
    })

    it('says the details are unknown when the hub has no technical sheet, and the publisher when it is unknown', () => {
        const wrapper = mount(ThirdPartyConfirm, { props: { details: { publisher: null, impact: null } }, global: { mocks: { $t }, stubs } })
        expect(wrapper.find('[data-testid=third-party-impact-unknown]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=third-party-intro]').text()).toContain('text.modules.install.third_party.intro_unknown')
        expect(wrapper.find('[data-testid=publisher-badge]').exists()).toBe(false)
    })

    it('emits the ticked state', async () => {
        const wrapper = mount(ThirdPartyConfirm, { props: { details: { publisher: ACME, impact: null } }, global: { mocks: { $t }, stubs } })
        const box = wrapper.find('[data-testid=third-party-confirm-check]')
        ;(box.element as HTMLInputElement).checked = true
        await box.trigger('change')
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
    })
})

describe('install drawer', () => {
    it('keeps Install disabled until the box is ticked, then sends confirm_third_party', async () => {
        preflightBody = preflight({ warnings: [partnerWarning] })
        ajaxMock.post.mockResolvedValue({ data: { data: { uuid: 'u1', status: 'queued' } } })
        const wrapper = await mountPage()
        await openInstall(wrapper)

        expect(wrapper.find('[data-testid=third-party-confirm]').exists()).toBe(true)
        // The partner warning is the confirmation step, not one more info line.
        expect(wrapper.find('[data-testid=preflight-warnings]').exists()).toBe(false)
        const start = () => wrapper.find('[data-testid=install-start]')
        expect(start().attributes('disabled')).toBeDefined()

        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).not.toHaveBeenCalled()

        const box = wrapper.find('[data-testid=third-party-confirm-check]')
        ;(box.element as HTMLInputElement).checked = true
        await box.trigger('change')
        expect(start().attributes('disabled')).toBeUndefined()

        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post.mock.calls[0][1]).toMatchObject({ name: 'Gallery', version: '1.0.0', confirm_third_party: true })
    })

    it('shows the publisher badge in the install preview and asks nothing for a module confirmed before', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: { uuid: 'u1', status: 'queued' } } })
        const wrapper = await mountPage()
        await openInstall(wrapper)

        expect(wrapper.find('[data-testid=publisher-badge]').attributes('data-type')).toBe('partner')
        expect(wrapper.find('[data-testid=third-party-confirm]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=install-start]').attributes('disabled')).toBeUndefined()
        await wrapper.find('[data-testid=install-form]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post.mock.calls[0][1]).not.toHaveProperty('confirm_third_party')
    })
})

describe('upload drawer', () => {
    const pick = async (wrapper: Awaited<ReturnType<typeof mountPage>>, testid: string, name: string) => {
        const file = new File(['x'], name)
        const input = wrapper.find(`[data-testid=${testid}]`)
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
        await input.trigger('change')
    }

    it('asks for the confirmation when the server says the package is a partner package, then sends it', async () => {
        ajaxMock.post.mockImplementationOnce(() =>
            Promise.reject({ response: { status: 422, data: { errorCode: 'MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED', data: { publisher: null, impact: null } } } }),
        )
        const wrapper = await mountPage()
        await wrapper.find('[data-testid=module-upload-open]').trigger('click')
        await pick(wrapper, 'upload-zip', 'm.zip')
        await pick(wrapper, 'upload-signature', 'm.sig.json')
        const form = wrapper.find('[data-testid=upload-form]')
        await form.trigger('submit')
        await flushPromises()

        expect(wrapper.find('[data-testid=third-party-confirm]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=upload-error]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=upload-start]').attributes('disabled')).toBeDefined()

        const box = wrapper.find('[data-testid=third-party-confirm-check]')
        ;(box.element as HTMLInputElement).checked = true
        await box.trigger('change')
        ajaxMock.post.mockResolvedValueOnce({ data: { data: {} } })
        await form.trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(2)
        expect((ajaxMock.post.mock.calls[1][1] as FormData).get('confirm_third_party')).toBe('1')
    })
})

describe('error codes', () => {
    it('knows both partner codes', () => {
        expect(MODULE_ERROR_CODES).toContain('MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED')
        expect(MODULE_ERROR_CODES).toContain('MODULE_WARN_THIRD_PARTY_FIRST_INSTALL')
        expect(moduleErrorKey('MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED')).toBe('text.modules.error.third_party_confirmation_required')
    })
})
