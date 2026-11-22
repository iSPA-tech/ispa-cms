import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import StoreTab from '../../../../cms/Core/admin/pages/setting/modules/store-tab.vue'
import StoreDetailDrawer from '../../../../cms/Core/admin/pages/setting/modules/store-detail-drawer.vue'
import PublisherBadge from '../../../../cms/Core/admin/pages/setting/modules/publisher-badge.vue'
import SettingModules from '../../../../cms/Core/admin/pages/setting/modules.vue'

const state = vi.hoisted(() => ({ listing: null as unknown }))
const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))

vi.mock('@/config/navigation', () => ({ default: [] }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ rule: () => true, perm: (m: string) => m, iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => true, hasAccountData: () => true }))
vi.mock('@/hooks', () => ({
    useModules: () => ({
        data: ref([{ name: 'Core', alias: 'core', enabled: true, version: '1.0', requires: [], switchable: false, path: '/x/cms/Core', missing_dependencies: [], blocking_dependents: [], description: 'Core module' }]),
        isLoading: ref(false),
        isRefetching: ref(false),
        refetch: vi.fn(),
    }),
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (k: string, p?: Record<string, unknown>, n?: number) => (p ? `${k}|${JSON.stringify(p)}${n === undefined ? '' : '#' + n}` : k),
        locale: { value: 'vi' },
    }),
}))

const $t = (k: string, p?: Record<string, unknown>, n?: number) => (p ? `${k}|${JSON.stringify(p)}${n === undefined ? '' : '#' + n}` : k)
const passthrough = (tag = 'div') => defineComponent({ setup: (_p, { slots, attrs }) => () => h(tag, attrs, slots.default?.()) })
const stubs = {
    'a-button': passthrough('button'),
    'a-spin': passthrough(),
    'a-empty': defineComponent({ props: ['description'], setup: (p, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'] }, p.description) }),
    'a-row': passthrough(),
    'a-col': passthrough(),
    'a-card': passthrough(),
    'a-tag': passthrough('span'),
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
    'a-drawer': defineComponent({ props: ['open', 'title'], setup: (p, { slots }) => () => (p.open ? h('div', { 'data-testid': 'drawer' }, [h('div', { 'data-testid': 'drawer-title' }, p.title), slots.default?.(), slots.footer?.()]) : null) }),
    'i-icon': true,
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (p, { slots, emit, attrs }) => () =>
            h('button', { type: p.nativeType || 'button', disabled: p.disabled || p.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.()),
    }),
}
const mountOpts = { global: { mocks: { $t }, stubs } }

const HASH = 'a'.repeat(64)
const row = (over: Record<string, unknown> = {}) => ({
    name: 'Helpdesk',
    label: 'Helpdesk',
    latest_version: '1.2.0',
    latest: { version: '1.2.0', revoked: false, requires: [], irreversible: false, custom_code: false },
    installed: 'none',
    installed_version: null,
    canInstall: true,
    canUpgrade: false,
    revoked: false,
    irreversible: false,
    requirements: [],
    listing: null,
    ...over,
})
const listingRow = row({
    listing: {
        category: 'support',
        publisher: { key: 'ispa', type: 'ispa', name: 'iSPA' },
        icon_hash: HASH,
        content: { vi: { display_name: 'Trung tâm trợ giúp', summary: 'Nhận và trả lời yêu cầu hỗ trợ' }, en: { display_name: 'Help center', summary: '' } },
    },
})

const sheet = (database: Record<string, unknown> = {}) => ({
    core_range: '>=2026.11',
    requires_modules: { Content: '>=1.0' },
    php_min: '8.3',
    database: { migrations: 0, tables_created: [], tables_altered: [], irreversible: false, ...database },
    admin: { permissions: ['a.view', 'a.edit'], menus: 2 },
    routes: { public: 1, admin: 2, api: 3 },
    size: { zip_bytes: 2048, unpacked_bytes: 8192, files: 40 },
})
const listingAnswer = (over: Record<string, unknown> = {}) => ({
    name: 'Helpdesk',
    available: true,
    installs: 12,
    last_updated_at: '2026-11-10T00:00:00Z',
    installed_version: '1.0.0',
    listing: {
        category: 'support',
        publisher: { key: 'ispa', type: 'ispa', name: 'iSPA' },
        icon_hash: HASH,
        revision: 1,
        published_at: null,
        screenshots: [{ hash: 'b'.repeat(64), kind: 'desktop', caption_vi: 'Danh sách phiếu', caption_en: 'Ticket list' }],
        video_url: null,
        demo_url: 'https://demo.example.com/helpdesk',
        support_contact: 'help@example.com',
        content: {
            vi: { display_name: 'Trung tâm trợ giúp', summary: 'Tóm tắt', price_note: 'Miễn phí cho website iSPA', description: 'Dòng một\n\nDòng hai', features: ['Phiếu hỗ trợ', 'Trả lời nhanh'], faq: [{ q: 'Có tốn phí không?', a: 'Không.' }] },
            en: { display_name: 'Help center', summary: 'Summary', price_note: 'Free for iSPA sites', description: 'Line one', features: ['Tickets'], faq: [] },
        },
    },
    versions: [
        { version: '1.0.0', revoked: false, published_at: null, sheet: sheet(), release_notes: 'Old notes 1.0.0' },
        { version: '1.2.0', revoked: false, published_at: null, sheet: sheet({ migrations: 3, tables_altered: ['users'] }), release_notes: 'New notes 1.2.0' },
    ],
    release_notes: [{ version: '1.2.0', published_at: null, notes: 'New notes 1.2.0' }],
    ...over,
})

beforeEach(() => {
    for (const fn of Object.values(ajaxMock)) fn.mockReset()
    state.listing = listingAnswer()
    ajaxMock.get.mockImplementation((url: string) => {
        if (url.endsWith('/listing')) return Promise.resolve({ data: { data: state.listing } })
        if (url.includes('/media/')) return Promise.reject(new Error('no image in tests'))
        return Promise.reject(new Error('unexpected ' + url))
    })
})

describe('PublisherBadge', () => {
    it('names the publisher by type and renders nothing without one', () => {
        const ispa = mount(PublisherBadge, { ...mountOpts, props: { publisher: { key: 'ispa', type: 'ispa', name: 'iSPA' } } })
        expect(ispa.text()).toContain('text.modules.store.publisher.ispa')
        const partner = mount(PublisherBadge, { ...mountOpts, props: { publisher: { key: 'acme', type: 'partner', name: 'Acme' } } })
        expect(partner.text()).toContain('text.modules.store.publisher.partner')
        expect(partner.text()).toContain('Acme')
        const none = mount(PublisherBadge, { ...mountOpts, props: { publisher: null } })
        expect(none.find('[data-testid=publisher-badge]').exists()).toBe(false)
    })
})

describe('StoreTab cards', () => {
    it('renders a card without a listing from the name and the module description', () => {
        const wrapper = mount(StoreTab, { ...mountOpts, props: { rows: [row()], locale: 'vi', describe: (n: string) => (n === 'Helpdesk' ? 'Module description text' : '') } })
        const card = wrapper.find('[data-testid=catalog-row]')
        expect(card.text()).toContain('Helpdesk')
        expect(card.text()).toContain('Module description text')
        expect(card.find('[data-testid=publisher-badge]').exists()).toBe(false)
        expect(card.find('[data-testid=store-card-icon]').exists()).toBe(true)
    })

    it('uses the listing name and summary of the admin language and falls back to the other language', () => {
        const vi = mount(StoreTab, { ...mountOpts, props: { rows: [listingRow], locale: 'vi' } })
        expect(vi.find('[data-testid=catalog-row]').text()).toContain('Trung tâm trợ giúp')
        expect(vi.find('[data-testid=catalog-row]').text()).toContain('Nhận và trả lời yêu cầu hỗ trợ')
        expect(vi.find('[data-testid=publisher-badge]').exists()).toBe(true)
        const en = mount(StoreTab, { ...mountOpts, props: { rows: [listingRow], locale: 'en' } })
        expect(en.find('[data-testid=catalog-row]').text()).toContain('Help center')
        // English summary is empty, so the Vietnamese one is shown instead of a blank line.
        expect(en.find('[data-testid=catalog-row]').text()).toContain('Nhận và trả lời yêu cầu hỗ trợ')
    })

    it('shows the install count and the price note from the catalog summary, and nothing when the hub sends none', () => {
        const withStats = row({ listing: { ...(listingRow.listing as Record<string, unknown>), installs: 12, content: { vi: { display_name: 'Trung tâm trợ giúp', summary: '', price_note: 'Miễn phí cho website iSPA' }, en: { display_name: 'Help center', summary: '', price_note: 'Free for iSPA sites' } } } })
        const card = mount(StoreTab, { ...mountOpts, props: { rows: [withStats], locale: 'vi' } }).find('[data-testid=catalog-row]')
        expect(card.find('[data-testid=store-card-installs]').text()).toBe('text.modules.store.installs|{"count":12}#12')
        expect(card.find('[data-testid=store-card-price-note]').text()).toBe('Miễn phí cho website iSPA')
        // the price note follows the admin language.
        const enCard = mount(StoreTab, { ...mountOpts, props: { rows: [withStats], locale: 'en' } })
        expect(enCard.find('[data-testid=store-card-price-note]').text()).toBe('Free for iSPA sites')

        // Zero installs is still a number worth showing.
        const zero = row({ listing: { ...(listingRow.listing as Record<string, unknown>), installs: 0, content: { vi: { display_name: 'x', summary: '', price_note: '' }, en: { display_name: 'x', summary: '', price_note: '' } } } })
        const zeroCard = mount(StoreTab, { ...mountOpts, props: { rows: [zero], locale: 'vi' } })
        expect(zeroCard.find('[data-testid=store-card-installs]').text()).toBe('text.modules.store.installs|{"count":0}#0')
        expect(zeroCard.find('[data-testid=store-card-price-note]').exists()).toBe(false)

        // Private/paid modules (installs null), summaries from an older hub and cards without a listing show neither.
        for (const entry of [row({ listing: { ...(listingRow.listing as Record<string, unknown>), installs: null } }), listingRow, row()]) {
            const plain = mount(StoreTab, { ...mountOpts, props: { rows: [entry], locale: 'vi' } })
            expect(plain.find('[data-testid=store-card-installs]').exists()).toBe(false)
            expect(plain.find('[data-testid=store-card-price-note]').exists()).toBe(false)
        }
    })

    it('shows "Gia hạn" with the date and a plain note when the grant ended, the update window ended or grace runs', () => {
        const day = (iso: string, locale: string) => new Date(iso).toLocaleDateString(locale, { dateStyle: 'short' })
        const expired = row({ latest_version: null, latest: null, canInstall: false, renewal: { reason: 'expired', date: '2026-11-27T03:00:00+00:00', version: null } })
        const card = mount(StoreTab, { ...mountOpts, props: { rows: [expired], locale: 'vi' } }).find('[data-testid=catalog-row]')
        expect(card.find('[data-testid=catalog-renew]').text()).toBe('text.modules.store.renew.tag')
        // Not installed: the "_new" wording (nothing on the site keeps running).
        expect(card.find('[data-testid=catalog-renew-note]').text()).toBe(
            `text.modules.store.renew.expired_new|${JSON.stringify({ date: day('2026-11-27T03:00:00+00:00', 'vi-VN'), version: '' })}`,
        )
        // Nothing to install after the end: no dead Install button, Details stays.
        expect(card.find('[data-testid=catalog-install]').exists()).toBe(false)
        expect(card.find('[data-testid=catalog-details]').exists()).toBe(true)

        const installedEnded = row({ installed: 'release', installed_version: '2026.1.0', canInstall: false, updates_unavailable: true, renewal: { reason: 'expired', date: '2026-11-27T03:00:00+00:00', version: null } })
        const installedCard = mount(StoreTab, { ...mountOpts, props: { rows: [installedEnded], locale: 'vi' } })
        expect(installedCard.find('[data-testid=catalog-renew-note]').text()).toContain('text.modules.store.renew.expired|')
        // "Gia hạn" replaces the "no updates" tag on the same card.
        expect(installedCard.find('[data-testid=catalog-updates-unavailable]').exists()).toBe(false)

        const window = row({ installed: 'runtime', installed_version: '2026.1.0', canInstall: false, renewal: { reason: 'updates_ended', date: '2027-01-31T16:59:59+00:00', version: '2027.1.0' } })
        const en = mount(StoreTab, { ...mountOpts, props: { rows: [window], locale: 'en' } })
        expect(en.find('[data-testid=catalog-renew-note]').text()).toBe(
            `text.modules.store.renew.updates_ended|${JSON.stringify({ date: day('2027-01-31T16:59:59+00:00', 'en-GB'), version: '2027.1.0' })}`,
        )

        const grace = row({ renewal: { reason: 'grace', date: '2026-12-11T03:00:00+00:00', version: null } })
        const graceCard = mount(StoreTab, { ...mountOpts, props: { rows: [grace], locale: 'vi' } })
        expect(graceCard.find('[data-testid=catalog-renew-note]').text()).toContain('text.modules.store.renew.grace|')
        // Grace still downloads, so Install stays.
        expect(graceCard.find('[data-testid=catalog-install]').exists()).toBe(true)

        // No note from an older hub or for a live grant.
        for (const entry of [row(), row({ renewal: null })]) {
            const plain = mount(StoreTab, { ...mountOpts, props: { rows: [entry], locale: 'vi' } })
            expect(plain.find('[data-testid=catalog-renew]').exists()).toBe(false)
            expect(plain.find('[data-testid=catalog-renew-note]').exists()).toBe(false)
        }
    })

    it('emits details, install and upgrade for the entry', async () => {
        const entry = row()
        const up = row({ name: 'Billing', label: 'Billing', installed: 'runtime', canInstall: false, canUpgrade: true, upgrade_version: '2.0.0' })
        const wrapper = mount(StoreTab, { ...mountOpts, props: { rows: [entry, up], locale: 'vi' } })
        await wrapper.findAll('[data-testid=catalog-details]')[0].trigger('click')
        await wrapper.find('[data-testid=catalog-install]').trigger('click')
        await wrapper.find('[data-testid=catalog-upgrade]').trigger('click')
        expect(wrapper.emitted('details')![0][0]).toMatchObject({ name: 'Helpdesk' })
        expect(wrapper.emitted('install')![0][0]).toMatchObject({ name: 'Helpdesk' })
        expect(wrapper.emitted('upgrade')![0][0]).toMatchObject({ name: 'Billing' })
    })
})

describe('StoreDetailDrawer', () => {
    const open = async (entry = row({ installed: 'runtime', installed_version: '1.0.0' }), locale = 'vi') => {
        const wrapper = mount(StoreDetailDrawer, { ...mountOpts, props: { open: true, entry, locale } })
        await flushPromises()
        return wrapper
    }

    it('loads the listing of the entry and shows features, FAQ, links, price note and the technical sheet', async () => {
        const wrapper = await open()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/listing', expect.anything())
        expect(wrapper.find('[data-testid=store-features]').text()).toContain('Phiếu hỗ trợ')
        expect(wrapper.find('[data-testid=store-faq]').text()).toContain('Có tốn phí không?')
        expect(wrapper.find('[data-testid=store-demo-link]').attributes('href')).toBe('https://demo.example.com/helpdesk')
        expect(wrapper.find('[data-testid=store-demo-link]').attributes('rel')).toContain('noopener')
        expect(wrapper.find('[data-testid=store-support]').text()).toContain('help@example.com')
        expect(wrapper.find('[data-testid=store-price-note]').text()).toContain('Miễn phí cho website iSPA')
        // the count goes in as the plural choice too, so English reads "1 website" and "2 websites".
        expect(wrapper.find('[data-testid=store-installs]').text()).toBe('text.modules.store.installs|{"count":12}#12')
        expect(wrapper.find('[data-testid=store-sheet]').text()).toContain('>=2026.11')
        expect(wrapper.findAll('[data-testid=store-screenshot]')).toHaveLength(1)
    })

    it('gives screenshots a fixed height and phone shots a narrow frame, so a tall phone image never stretches the row', async () => {
        const base = listingAnswer()
        const listing = { ...(base.listing as Record<string, unknown>), screenshots: [
            { hash: 'b'.repeat(64), kind: 'desktop', caption_vi: 'Máy tính', caption_en: 'Desktop' },
            { hash: 'c'.repeat(64), kind: 'phone', caption_vi: 'Điện thoại', caption_en: 'Phone' },
        ] }
        state.listing = { ...base, listing }
        const wrapper = await open()
        const shots = wrapper.findAll('[data-testid=store-screenshot]')
        expect(shots).toHaveLength(2)
        for (const shot of shots) expect(shot.classes()).toContain('h-40')
        const [desktop, phone] = wrapper.findAll('[data-testid=store-screenshots] figure')
        expect(desktop.classes()).toContain('w-64')
        expect(phone.classes()).toContain('w-20')
    })

    it('offers "Báo lỗi / Cần hỗ trợ" only for an installed module and hands the entry to the page', async () => {
        const wrapper = await open()
        await wrapper.find('[data-testid=store-report]').trigger('click')
        expect(wrapper.emitted('support')![0][0]).toMatchObject({ name: 'Helpdesk' })
        const notInstalled = await open(row({ installed: 'none', installed_version: null }))
        expect(notInstalled.find('[data-testid=store-report]').exists()).toBe(false)
    })

    it('shows release notes only for versions above the installed one', async () => {
        const wrapper = await open()
        const notes = wrapper.find('[data-testid=store-release-notes]')
        expect(notes.text()).toContain('New notes 1.2.0')
        expect(notes.text()).not.toContain('Old notes 1.0.0')
        expect(wrapper.findAll('[data-testid=store-release-note]')).toHaveLength(1)
    })

    it('hides the release notes section when nothing is newer than the installed version', async () => {
        state.listing = listingAnswer({ release_notes: [] })
        const wrapper = await open(row({ installed: 'runtime', installed_version: '1.2.0' }))
        expect(wrapper.find('[data-testid=store-release-notes]').exists()).toBe(false)
    })

    it('shows the database impact of the latest version', async () => {
        const wrapper = await open()
        const db = wrapper.find('[data-testid=store-database]')
        expect(db.text()).toContain('text.modules.install.drawer.db_warning.title')
        expect(db.text()).toContain('"count":3')
        expect(db.text()).toContain('users')
    })

    it('says a version without new migrations does not change the database', async () => {
        const wrapper = await open(row({ latest: { version: '1.0.0' }, latest_version: '1.0.0' }))
        expect(wrapper.find('[data-testid=store-database]').text()).toContain('text.modules.store.database.none')
    })

    it('shows release notes as plain text, never as markup', async () => {
        const evil = '# Title <img src=x onerror=alert(1)> <script>alert(2)</script> **bold**'
        state.listing = listingAnswer({ release_notes: [{ version: '1.2.0', published_at: null, notes: evil }] })
        const wrapper = await open()
        const notes = wrapper.find('[data-testid=store-release-notes]')
        expect(notes.find('img').exists()).toBe(false)
        expect(notes.find('script').exists()).toBe(false)
        expect(notes.text()).toContain('<img src=x onerror=alert(1)>')
        expect(notes.text()).toContain('**bold**')
    })

    it('falls back to the module name and a note when the hub has no store page', async () => {
        state.listing = { name: 'Helpdesk', available: false, listing: null, installs: null, last_updated_at: null, versions: [], release_notes: [], installed_version: null }
        const wrapper = await open(row())
        expect(wrapper.find('[data-testid=store-no-listing]').exists()).toBe(true)
        expect(wrapper.find('[data-testid=drawer-title]').text()).toContain('Helpdesk')
        expect(wrapper.find('[data-testid=store-install]').exists()).toBe(true)
    })

    it('shows an error with a retry when the listing cannot be loaded', async () => {
        ajaxMock.get.mockImplementationOnce(() => Promise.reject({ response: { status: 502, data: { errorCode: 'MODULE_CATALOG_UNAVAILABLE' } } }))
        const wrapper = await open(row())
        expect(wrapper.find('[data-testid=store-error]').exists()).toBe(true)
        await wrapper.find('[data-testid=store-retry]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid=store-error]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=store-features]').exists()).toBe(true)
    })

    it('offers Install for a new module and Upgrade for an upgradable one, and emits them', async () => {
        const install = await open(row())
        await install.find('[data-testid=store-install]').trigger('click')
        expect(install.emitted('install')![0][0]).toMatchObject({ name: 'Helpdesk' })
        const upgrade = await open(row({ installed: 'runtime', installed_version: '1.0.0', canInstall: false, canUpgrade: true, upgrade_version: '1.2.0' }))
        expect(upgrade.find('[data-testid=store-install]').exists()).toBe(false)
        await upgrade.find('[data-testid=store-upgrade]').trigger('click')
        expect(upgrade.emitted('upgrade')![0][0]).toMatchObject({ name: 'Helpdesk' })
    })

    it('disables Install when the module cannot be installed', async () => {
        const wrapper = await open(row({ canInstall: false, revoked: true }))
        expect(wrapper.find('[data-testid=store-install]').attributes('disabled')).toBeDefined()
    })

    it('does nothing while closed', async () => {
        mount(StoreDetailDrawer, { ...mountOpts, props: { open: false, entry: row(), locale: 'vi' } })
        await flushPromises()
        expect(ajaxMock.get).not.toHaveBeenCalled()
    })
})

describe('modules page store flow', () => {
    const catalogBody = [
        { name: 'Helpdesk', latest_version: '1.2.0', installed: 'none', enabled: false, installed_version: null, versions: [{ version: '1.2.0', revoked: false, requires: [], irreversible: false, custom_code: false }], listing: null },
    ]
    const flow = async () => {
        ajaxMock.get.mockImplementation((url: string) => {
            if (url === 'utilities/modules/catalog') return Promise.resolve({ data: { data: catalogBody } })
            if (url === 'utilities/modules/installs') return Promise.resolve({ data: { data: [] } })
            if (url.endsWith('/listing')) return Promise.resolve({ data: { data: state.listing } })
            if (url.includes('/preflight')) return Promise.resolve({ data: { data: { name: 'Helpdesk', version: '1.2.0', can_install: true, blocking: [], warnings: [], changes: [] } } })
            return Promise.reject(new Error('unexpected ' + url))
        })
        const wrapper = mount(SettingModules, mountOpts)
        await flushPromises()
        await wrapper.find('[data-testid=tab-available]').trigger('click')
        await flushPromises()
        return wrapper
    }

    it('opens the detail drawer from a card and starts the same preflight when Install is pressed there', async () => {
        const wrapper = await flow()
        await wrapper.find('[data-testid=catalog-details]').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/listing', expect.anything())
        await wrapper.find('[data-testid=store-install]').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/1.2.0/preflight', expect.anything())
        // The store page closed and the install drawer opened.
        expect(wrapper.find('[data-testid=store-features]').exists()).toBe(false)
        expect(wrapper.find('[data-testid=install-form]').exists()).toBe(true)
    })

    it('keeps the card Install button on the same preflight', async () => {
        const wrapper = await flow()
        await wrapper.find('[data-testid=catalog-install]').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('utilities/modules/catalog/Helpdesk/1.2.0/preflight', expect.anything())
    })
})
