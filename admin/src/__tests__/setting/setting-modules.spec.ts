import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

const labels: Record<string, string> = {
    'nav.a.posts': 'Bài viết',
    'text.modules.core_locked': 'Luôn bật',
    'text.modules.no_desc': 'Không có mô tả',
    'text.modules.priority': 'Độ ưu tiên',
    'text.not-found': 'Không tìm thấy',
    // What the global missing handler returns for a module that ships no translations (humanized last segment).
    'nav.module.broken-one.name': 'Name',
    'nav.module.broken-one.description': 'Description',
    'text.modules.quarantine.tag': 'Bị cách ly',
    'text.modules.quarantine.reason.load': 'Module không tải được nên đã bị tắt.',
    'text.modules.quarantine.reason.files': 'Thiếu tệp của module nên đã bị tắt.',
    'text.modules.quarantine.reason.invalid': 'Module không hợp lệ nên đã bị tắt.',
    'text.modules.quarantine.action': 'Nhờ đội kỹ thuật gỡ thư mục lỗi rồi cài lại. Hướng dẫn: tài liệu Cách ly module.',
    'text.modules.quarantine.shadow.tag': 'Đã bỏ qua một bản cài thêm trùng tên',
    'text.modules.quarantine.shadow.note': 'Module này vẫn chạy bình thường.',
    'text.modules.quarantine.shadow.action': 'Nhờ đội kỹ thuật xóa bản trùng.',
    'text.modules.quarantine.listed.tag': 'Bản cài trực tuyến chưa được dùng',
    'text.modules.quarantine.listed.note': 'Website đang dùng bản đi kèm.',
    'text.modules.quarantine.listed.action': 'Gỡ bản cài trực tuyến cũng không thay đổi gì.',
}

vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, string>) =>
            (labels[k] ?? k) + (p ? ` ${JSON.stringify(p)}` : ''),
        locale: { value: 'vi' },
    }),
}))

const quarantineBase = { requires: [], switchable: true, missing_dependencies: [], blocking_dependents: [], version: '1.0', priority: 0, description: '' }
const modules = [
    { ...quarantineBase, name: 'BrokenOne', alias: 'brokenone', enabled: false, quarantined: true, quarantineCode: 'PROVIDER_CLASS_MISSING', quarantineReason: 'The provider class X cannot be loaded.' },
    { ...quarantineBase, name: 'BrokenTwo', alias: 'brokentwo', enabled: false, quarantined: true, quarantineCode: 'SOMETHING_NEW', quarantineReason: 'Raw english detail' },
    { ...quarantineBase, name: 'ShadowedRel', alias: 'shadowedrel', enabled: true, quarantined: true, quarantineCode: 'MODULE_SHADOWS_RELEASE', quarantineReason: 'The runtime folder ShadowedRel has the same name' },
    { ...quarantineBase, name: 'ListedRel', alias: 'listedrel', enabled: true, handover: true, quarantined: true, quarantineCode: 'MODULE_SHADOWS_RELEASE', quarantineReason: 'The runtime folder ListedRel has the same name' },
    { name: 'Core', alias: 'core', description: '', priority: 0, enabled: true, version: '1.0', requires: [], switchable: false, missing_dependencies: [], blocking_dependents: [] },
    { name: 'Content', alias: 'content', description: '', priority: 0, enabled: true, version: '1.0', requires: ['core'], switchable: true, missing_dependencies: [], blocking_dependents: [] },
    { name: 'AcmeContentPipeline', alias: 'acmecontentpipeline', description: 'Crawl pipeline', priority: 5, enabled: false, version: '', requires: ['content'], switchable: true, missing_dependencies: [], blocking_dependents: [] },
]

// `afterRefetch` is what the list says once reloaded (a cached list can be older than the hub connection).
const hubState = vi.hoisted(() => ({
    value: undefined as boolean | undefined,
    afterRefetch: undefined as boolean | undefined,
    refetch: null as null | ReturnType<typeof vi.fn>,
}))
vi.mock('@/hooks', () => ({
    useModules: () => {
        const rows = (hub: boolean | undefined) => (hub === undefined ? modules : modules.map((m) => ({ ...m, hub_configured: hub })))
        const data = ref(rows(hubState.value))
        hubState.refetch = vi.fn(async () => {
            if (hubState.afterRefetch !== undefined) data.value = rows(hubState.afterRefetch)
        })
        return { data, isLoading: ref(false), isRefetching: ref(false), refetch: hubState.refetch }
    },
}))
// moved the module API calls to @/libs/module (which imports @/ajax) and the page now reads isWebmaster;
// both are mocked so this spec keeps testing labels, search and the Core lock only.
vi.mock('@/libs/module', () => ({
    MODULE_PACKAGE_MAX_BYTES: 50 * 1024 * 1024,
    MODULE_SIGNATURE_MAX_BYTES: 64 * 1024,
    MODULE_SITE_BLOCK_CODES: [],
    getModuleCatalog: vi.fn(() => Promise.resolve([])),
    getModuleInstalls: vi.fn(() => Promise.resolve([])),
    getModuleUninstallInfo: vi.fn(() => Promise.resolve({})),
    getModulePreflight: vi.fn(() => Promise.resolve({})),
    installModule: vi.fn(() => Promise.resolve({})),
    moduleErrorCodeOf: () => '',
    moduleErrorKey: (code: string) => code,
    uninstallModule: vi.fn(() => Promise.resolve({})),
    updateModuleStatus: vi.fn(),
    uploadModulePackage: vi.fn(() => Promise.resolve({})),
}))
const webmasterState = vi.hoisted(() => ({ value: true }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => webmasterState.value, hasAccountData: () => true }))
vi.mock('@/hooks/useActiveModules', () => ({ isModuleActive: () => true }))
// The dashboard band links here with ?connect=1.
const routeState = vi.hoisted(() => ({ query: {} as Record<string, string>, replace: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => routeState, useRouter: () => ({ replace: routeState.replace }) }))
// The hub connect drawer's screen is the shell page covered by connect-hub.spec.ts; it is not under test here.
vi.mock('@/pages/auth/connect-hub.vue', () => ({ default: { name: 'ConnectHubStub', render: () => null } }))
vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    perm: (moduleName: string) => moduleName,
    rule: () => true,
}))

import { getModuleCatalog } from '@/libs/module'
import SettingModules from '../../../../cms/Core/admin/pages/setting/modules.vue'
import HubConnectDrawer from '../../../../cms/Core/admin/pages/setting/modules/hub-connect-drawer.vue'

const passthrough = (tag: string) => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const AInput = defineComponent({
    props: ['value'],
    emits: ['update:value'],
    setup: (p, { emit, attrs }) => () =>
        h('input', { ...attrs, value: p.value, onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value) }),
})
const AAlert = defineComponent({
    props: ['message', 'description'],
    setup: (p, { attrs }) => () => h('div', attrs, [h('b', p.message), h('span', p.description)]),
})
const AEmpty = defineComponent({ props: ['description'], setup: (p) => () => h('div', { 'data-empty': '1' }, p.description) })

const mountPage = async () => {
    const wrapper = mount(SettingModules, {
        global: {
            mocks: { $t: (k: string) => labels[k] ?? k },
            stubs: {
                'a-button': passthrough('button'),
                'a-spin': passthrough('div'),
                'a-empty': AEmpty,
                'a-row': passthrough('div'),
                'a-col': passthrough('div'),
                'a-card': passthrough('div'),
                'a-tag': passthrough('span'),
                'a-alert': AAlert,
                'a-tooltip': passthrough('span'),
                'a-switch': passthrough('span'),
                'a-input': AInput,
                'a-input-search': AInput,
            },
        },
    })
    await flushPromises()
    return wrapper
}

describe('setting modules page', () => {
    it('shows the translated navigation label for a module', async () => {
        const w = await mountPage()
        expect(w.text()).toContain('Bài viết')
    })

    it('humanizes the class name when no navigation root exists', async () => {
        const w = await mountPage()
        const rows = w.findAll('[data-testid=module-row]')
        expect(rows.some((r) => r.text().includes('Acme Content Pipeline'))).toBe(true)
    })

    it('does not render priority or the empty-description text', async () => {
        const w = await mountPage()
        expect(w.text()).not.toContain('Độ ưu tiên')
        expect(w.text()).not.toContain('text.modules.priority')
        expect(w.text()).not.toContain('Không có mô tả')
        expect(w.text()).not.toContain('text.modules.no_desc')
    })

    it('filters by Vietnamese label without diacritics', async () => {
        const w = await mountPage()
        await w.find('[data-testid=modules-search]').setValue('bai viet')
        const rows = w.findAll('[data-testid=module-row]')
        expect(rows).toHaveLength(1)
        expect(rows[0].text()).toContain('Bài viết')
    })

    it('shows not-found when nothing matches', async () => {
        const w = await mountPage()
        await w.find('[data-testid=modules-search]').setValue('zzzz-nothing')
        expect(w.findAll('[data-testid=module-row]')).toHaveLength(0)
        expect(w.text()).toContain('Không tìm thấy')
    })

    const rowOf = (w: any, name: string) => w.findAll('[data-testid=module-row]').find((r: any) => r.text().includes(name))

    it('ignores the missing-key fallback and shows the humanized class name of a module without translations', async () => {
        const w = await mountPage()
        const row = rowOf(w, 'Broken One')
        expect(row.exists()).toBe(true)
        expect(row.text()).not.toContain('Description')
    })

    it('shows the Quarantined tag, a plain reason and the next step, never the raw code or English reason', async () => {
        const w = await mountPage()
        const row = rowOf(w, 'Broken One')
        expect(row.find('[data-testid=module-quarantine-tag]').text()).toBe('Bị cách ly')
        expect(row.text()).toContain('Module không tải được nên đã bị tắt.')
        expect(row.text()).toContain('Nhờ đội kỹ thuật')
        expect(row.text()).not.toContain('PROVIDER_CLASS_MISSING')
        expect(row.text()).not.toContain('provider class')
        expect(row.find('[data-testid=module-quarantine-shadow]').exists()).toBe(false)
    })

    it('falls back to the generic reason for an unknown code', async () => {
        const w = await mountPage()
        const row = rowOf(w, 'Broken Two')
        expect(row.text()).toContain('Module không tải được nên đã bị tắt.')
        expect(row.text()).not.toContain('SOMETHING_NEW')
        expect(row.text()).not.toContain('Raw english detail')
    })

    it('keeps the switch of a quarantined module for a Webmaster', async () => {
        const w = await mountPage()
        expect(rowOf(w, 'Broken One').find('[data-testid=module-switch]').exists()).toBe(true)
    })

    it('shows the tag and reason but no switch to a user who is not a Webmaster', async () => {
        webmasterState.value = false
        try {
            const w = await mountPage()
            const row = rowOf(w, 'Broken One')
            expect(row.find('[data-testid=module-quarantine-tag]').exists()).toBe(true)
            expect(row.text()).toContain('Module không tải được nên đã bị tắt.')
            expect(row.find('[data-testid=module-switch]').exists()).toBe(false)
        } finally {
            webmasterState.value = true
        }
    })

    it('a module listed in runtime_modules.json says the website keeps its own copy, never to delete the spare one', async () => {
        const w = await mountPage()
        const row = rowOf(w, 'Listed Rel')
        expect(row.find('[data-testid=module-quarantine-shadow]').text()).toContain('Bản cài trực tuyến chưa được dùng')
        expect(row.text()).toContain('Website đang dùng bản đi kèm.')
        expect(row.text()).toContain('Gỡ bản cài trực tuyến cũng không thay đổi gì.')
        expect(row.text()).not.toContain('Nhờ đội kỹ thuật xóa bản trùng.')
    })

    it('shows a soft note, not the Quarantined tag, on a release module whose runtime copy was ignored', async () => {
        const w = await mountPage()
        const row = rowOf(w, 'Shadowed Rel')
        expect(row.find('[data-testid=module-quarantine-tag]').exists()).toBe(false)
        expect(row.find('[data-testid=module-quarantine-shadow]').text()).toContain('Đã bỏ qua một bản cài thêm trùng tên')
        expect(row.text()).toContain('Module này vẫn chạy bình thường.')
        expect(row.text()).not.toContain('MODULE_SHADOWS_RELEASE')
        expect(row.text()).toContain('text.modules.active')
        expect(row.find('[data-testid=module-switch]').exists()).toBe(true)
    })

    it('marks Core as locked and lists it first', async () => {
        const w = await mountPage()
        const rows = w.findAll('[data-testid=module-row]')
        expect(rows[0].text()).toContain('Luôn bật')
        expect(rows[1].text()).not.toContain('Luôn bật')
    })

    it('the Core row has no version line or update and shows the iSPA CMS note; Content keeps its version and update', async () => {
        const offer = (name: string) => ({ name, installed: 'release', handover: true, upgrade_available: true, upgrade_version: '1.1', latest_version: '1.1', versions: [{ version: '1.1' }] })
        vi.mocked(getModuleCatalog).mockResolvedValueOnce([offer('Core'), offer('Content')] as never)
        hubState.value = true
        try {
            const w = await mountPage()
            const rows = w.findAll('[data-testid=module-row]')
            const core = rows.find((r) => r.text().includes('Luôn bật'))!
            const content = rows.find((r) => r.text().includes('Content') && !r.text().includes('Luôn bật') && !r.text().includes('Pipeline'))!
            expect(core.get('[data-testid=module-core-note]').text()).toBe('text.modules.core_row_note')
            expect(core.text()).not.toContain('text.modules.version')
            expect(core.find('[data-testid=module-upgrade]').exists()).toBe(false)
            expect(core.find('[data-testid=module-upgrade-available]').exists()).toBe(false)
            expect(core.find('[data-testid=module-uninstall]').exists()).toBe(false)
            expect(core.find('[data-testid=module-support]').exists()).toBe(true)
            // Core shows only its state and the locked tag, no source tag ("Có sẵn").
            expect(core.find('[data-testid=module-source]').exists()).toBe(false)
            expect(content.find('[data-testid=module-source]').exists()).toBe(true)
            expect(content.text()).toContain('text.modules.version: 1.0')
            expect(content.find('[data-testid=module-upgrade]').exists()).toBe(true)
            expect(content.find('[data-testid=module-core-note]').exists()).toBe(false)
        } finally {
            hubState.value = undefined
        }
    })

    it('loads the hub catalog when the list says a hub is connected', async () => {
        vi.mocked(getModuleCatalog).mockClear()
        hubState.value = true
        try {
            const w = await mountPage()
            expect(getModuleCatalog).toHaveBeenCalledTimes(1)
            expect(w.find('[data-testid=hub-not-connected]').exists()).toBe(false)
        } finally {
            hubState.value = undefined
        }
    })

    it('skips the hub catalog and shows a quiet note when no hub is connected', async () => {
        vi.mocked(getModuleCatalog).mockClear()
        hubState.value = false
        try {
            const w = await mountPage()
            expect(getModuleCatalog).not.toHaveBeenCalled()
            const note = w.find('[data-testid=hub-not-connected]')
            expect(note.text()).toBe('text.modules.install.hub_not_connected')
            expect(note.classes()).toContain('text-ink-muted')
            expect(w.find('[data-testid=catalog-error]').exists()).toBe(false)
        } finally {
            hubState.value = undefined
        }
    })

    describe('?connect=1 from the dashboard band', () => {
        const withConnect = async (hub: boolean, webmaster = true) => {
            hubState.value = hub
            webmasterState.value = webmaster
            routeState.query = { connect: '1' }
            routeState.replace.mockClear()
            try {
                return await mountPage()
            } finally {
                hubState.value = undefined
                webmasterState.value = true
                routeState.query = {}
            }
        }

        it('opens the connect drawer on the module store tab when not connected', async () => {
            const w = await withConnect(false)
            expect(w.findComponent(HubConnectDrawer).props('open')).toBe(true)
            expect((w.find('[data-testid=modules-available]').element as HTMLElement).style.display).not.toBe('none')
            expect(routeState.replace).toHaveBeenCalledWith({ query: {} })
        })

        it('ignores ?connect=1 when the hub is connected', async () => {
            const w = await withConnect(true)
            expect(w.findComponent(HubConnectDrawer).props('open')).toBe(false)
            expect(routeState.replace).toHaveBeenCalledWith({ query: {} })
        })

        it('reloads the list first, so a site connected a moment ago never gets the drawer', async () => {
            hubState.afterRefetch = true
            try {
                const w = await withConnect(false)
                expect(hubState.refetch).toHaveBeenCalled()
                expect(w.findComponent(HubConnectDrawer).props('open')).toBe(false)
                expect(routeState.replace).toHaveBeenCalledWith({ query: {} })
            } finally {
                hubState.afterRefetch = undefined
            }
        })

        it('ignores ?connect=1 for a user who is not a Webmaster', async () => {
            const w = await withConnect(false, false)
            expect(w.findComponent(HubConnectDrawer).exists()).toBe(false)
        })
    })
})
