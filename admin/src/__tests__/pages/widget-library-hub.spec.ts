/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// "Thư viện widget" with the hub (cms/Core/admin/pages/widget-library): "Trên site" / "Trên hub" tabs, still pictures on
// cards, "Tải về" and "Cập nhật". Wording is read from the Core admin sidecar (read only), so the assertions check the
// Vietnamese copy operators see.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

const copy = await vi.hoisted(async () => {
    const fs = await import('node:fs')
    const path = await import('node:path')
    const sidecar = path.resolve(
        process.cwd(),
        '../cms/Core/database/seeders/sql/translate-admin-authored.php'
    )
    const source = fs.readFileSync(sidecar, 'utf8')
    const vi_: Record<string, string> = {}
    const pattern =
        /\$entry\(null,\s*'(?:text|nav)',\s*'((?:text\.widget_library\.|nav\.a\.widget-library)[^']*)',\s*'((?:[^'\\]|\\.)*)',\s*'((?:[^'\\]|\\.)*)'/g
    for (const [, name, , text] of source.matchAll(pattern)) {
        vi_[name] = text.replace(/\\(['\\])/g, '$1')
    }
    /** Vietnamese sidecar text of a key with `{name}` placeholders filled; other keys come back as they are. */
    const t = (key: string, params: Record<string, unknown> = {}) =>
        (vi_[key] ?? key).replace(/\{(\w+)\}/g, (match, name) =>
            Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
        )
    return { t, keys: vi_ }
})

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: copy.t, te: () => true }),
    createI18n: () => ({
        global: { t: copy.t, setLocaleMessage: vi.fn(), locale: { value: 'vi' } },
        mode: 'composition',
        install: vi.fn(),
    }),
}))

const apiMocks = vi.hoisted(() => ({
    listLibraryWidgets: vi.fn(),
    getWidgetUsage: vi.fn(),
    getCatalogStatus: vi.fn(),
    getSharedAreaCount: vi.fn(),
    blockWidget: vi.fn(),
    unblockWidget: vi.fn(),
    searchPages: vi.fn(),
    loadWidgetThumbnail: vi.fn(),
    listPlaceableDesigns: vi.fn(),
    forgetPlaceableDesigns: vi.fn(),
    getHubCatalog: vi.fn(),
    downloadHubWidget: vi.fn(),
    getHubUpdateImpact: vi.fn(),
    updateHubWidget: vi.fn(),
    loadHubPreview: vi.fn(),
    onDesignPreviewUploaded: vi.fn(),
}))
vi.mock('../../../../cms/Core/admin/widget-library-api', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...apiMocks,
        // Pure helpers (no request).
        thumbnailHtml: actual.thumbnailHtml,
        isBlankRender: actual.isBlankRender,
        usableDesigns: actual.usableDesigns,
        hubErrorCopy: actual.hubErrorCopy,
        isFinalHubRefusal: actual.isFinalHubRefusal,
        forgetWidgetThumbnails: vi.fn(),
        widgetErrorCode: (error: { response?: { data?: { error_code?: string } } }) => error?.response?.data?.error_code ?? '',
    }
})

vi.mock('@/hooks/useActiveModules', () => ({
    isModuleActive: () => true,
    isModuleExtensionAllowed: () => true,
    isModuleLiveEnabled: () => true,
}))
vi.mock('@/libs', () => ({ getWidgetPreview: vi.fn() }))

const permissionState = vi.hoisted(() => ({ denied: new Set<string>() }))
const iAlertMock = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', () => {
    const permitted = (a: unknown, b?: string) => !permissionState.denied.has(b !== undefined ? `${a}.${b}` : String(a))
    return { can: permitted, rule: permitted, iAlert: iAlertMock, finishFailed: vi.fn() }
})

const messageMock = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() }))
vi.mock('ant-design-vue', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    message: messageMock,
}))

vi.mock('vue-router', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    useRouter: () => ({ push: vi.fn(), resolve: (to: { name: string }) => ({ href: `/iPanel/resolved/${to.name}` }) }),
    useRoute: () => ({ query: {} }),
}))

import WidgetLibrary from '../../../../cms/Core/admin/pages/widget-library/index.vue'
import { formatVersion } from '../../../../cms/Core/admin/pages/widget-library/format-version'

/** Renders the default and footer slots only while `open`, like the real modal and drawer. */
const OverlayStub = defineComponent({
    props: { open: { type: Boolean, default: false }, title: { type: String, default: '' } },
    setup(props, { slots }) {
        return () =>
            props.open
                ? h('section', {}, [h('h2', slots.title?.() ?? props.title), slots.default?.(), slots.footer?.()])
                : null
    },
})
/** The tab bar only: one button per `a-tab-pane`; the page renders the content of the active tab itself. */
const TabsStub = defineComponent({
    props: { activeKey: { type: String, default: '' } },
    emits: ['update:activeKey'],
    setup(_props, { emit, slots }) {
        return () =>
            h(
                'div',
                { role: 'tablist' },
                (slots.default?.() ?? []).map((pane) =>
                    h(
                        'button',
                        {
                            role: 'tab',
                            'data-test': `library-tab-${String(pane.key)}`,
                            onClick: () => emit('update:activeKey', String(pane.key)),
                        },
                        String((pane.props as { tab?: string } | null)?.tab ?? '')
                    )
                )
            )
    },
})
const stubs = {
    'a-modal': OverlayStub,
    'a-drawer': OverlayStub,
    'a-tabs': TabsStub,
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}</div>' },
    'a-select': true,
    'a-textarea': true,
    'a-input': true,
    // The card menu renders in place: trigger, then its items, like the opened dropdown.
    'a-dropdown': { template: '<div><slot /><div data-menu><slot name="overlay" /></div></div>' },
    'a-menu': { template: '<ul role="menu"><slot /></ul>' },
    'a-menu-item': {
        props: ['danger'],
        template: '<li role="menuitem" :data-danger="danger ? \'yes\' : \'no\'"><slot name="icon" /><slot /></li>',
    },
    'a-menu-divider': { template: '<li role="separator" />' },
    'i-button': {
        props: ['disabled', 'loading'],
        template: '<button :disabled="disabled || loading"><slot name="icon" /><slot /></button>',
    },
    'i-icon': true,
    breadcrumbs: { template: '<div><slot /><slot name="description" /></div>' },
    'no-permission-error': true,
    'router-link': { template: '<a><slot /></a>' },
}
const mountPage = () => mount(WidgetLibrary as never, { global: { stubs, mocks: { $t: copy.t } } })

const widgetRow = (overrides: Record<string, unknown> = {}) => ({
    id: 10,
    name: 'Giới thiệu',
    widget_key: 'intro',
    origin: 'hub',
    publisher: 'ispa',
    cloned_from_widget_key: null,
    is_blocked: false,
    blocked_reason: null,
    version: 3,
    hub_latest_version: null,
    usage: { pages: 3, areas: 1 },
    template: [
        {
            id: 100,
            name: 'Hai cột',
            usable_on_site: true,
            preview_image_url: '/storage/widget-previews/intro/two-columns-v3.png',
            preview_stale: false,
        },
    ],
    inputs: [{ id: 1, name: 'title' }],
    ...overrides,
})

const hubEntry = (overrides: Record<string, unknown> = {}) => ({
    widget_key: 'gallery',
    publisher: 'ispa',
    name: 'Thư viện ảnh',
    description: 'Lưới ảnh có chú thích.',
    latest_version: 2,
    content_sha256: 'a'.repeat(64),
    requires: { renderer: 1, resolvers: {} },
    preview_theme_keys: ['default'],
    preview_urls: {},
    installed_widget_id: null,
    installed_version: null,
    missing_resolvers: [],
    ...overrides,
})

const refused = (status: number, error_code: string) => ({
    response: { status, data: { status: 'error', error_code, message: 'server text' } },
})

const UNREACHABLE = 'Chưa kết nối được kho widget. Widget trên site vẫn dùng bình thường.'
const RESOLVER_MISSING = 'Widget này cần tính năng mà site chưa có. Hãy cập nhật hệ thống trước.'

beforeEach(() => {
    vi.clearAllMocks()
    permissionState.denied.clear()
    apiMocks.listLibraryWidgets.mockResolvedValue([
        widgetRow(),
        widgetRow({
            id: 11,
            name: 'Liên hệ',
            widget_key: 'contact-site',
            origin: 'site',
            cloned_from_widget_key: 'contact',
            usage: { pages: 0, areas: 0 },
            template: [
                {
                    id: 110,
                    name: 'Mặc định',
                    usable_on_site: true,
                    preview_image_url: '/storage/widget-previews/contact-site/default-abc.png',
                    preview_stale: true,
                },
            ],
        }),
    ])
    apiMocks.getCatalogStatus.mockResolvedValue({ authoring: false, unexported_widget_keys: [] })
    apiMocks.getSharedAreaCount.mockResolvedValue(2)
    apiMocks.loadWidgetThumbnail.mockResolvedValue('<p>preview</p>')
    apiMocks.listPlaceableDesigns.mockResolvedValue(new Map())
    apiMocks.getHubCatalog.mockResolvedValue([hubEntry()])
    apiMocks.loadHubPreview.mockResolvedValue(new Blob(['png'], { type: 'image/png' }))
    apiMocks.onDesignPreviewUploaded.mockReturnValue(() => {})
})

describe('Thư viện widget: Trên site / Trên hub', () => {
    it('cards render still images and no preview frames', async () => {
        const wrapper = mountPage()
        await flushPromises()

        expect(wrapper.get('[data-test="library-tab-site"]').text()).toBe('Trên site')
        expect(wrapper.get('[data-test="library-tab-hub"]').text()).toBe('Trên hub')

        const hub = wrapper.get('[data-test="library-card-10"]')
        expect(hub.get('img').attributes('src')).toBe('/storage/widget-previews/intro/two-columns-v3.png')
        expect(hub.get('img').attributes('loading')).toBe('lazy')
        expect(hub.text()).not.toContain('Chưa có ảnh mới')
        expect(wrapper.get('[data-test="library-card-11"]').text()).toContain('Chưa có ảnh mới')

        // No live frame on any card, and no render is requested until "Xem trước".
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(apiMocks.loadWidgetThumbnail).not.toHaveBeenCalled()

        // A design without a stored picture keeps the placeholder.
        apiMocks.listLibraryWidgets.mockResolvedValue([
            widgetRow({ template: [{ id: 100, name: 'Hai cột', usable_on_site: true, preview_image_url: null, preview_stale: false }] }),
        ])
        const plain = mountPage()
        await flushPromises()
        expect(plain.get('[data-test="library-card-10"]').find('img').exists()).toBe(false)
        expect(apiMocks.loadWidgetThumbnail).not.toHaveBeenCalled()

        // A first design without a picture does not hide the picture of a later one.
        apiMocks.listLibraryWidgets.mockResolvedValue([
            widgetRow({
                template: [
                    { id: 100, name: 'Cũ', usable_on_site: true, preview_image_url: null, preview_stale: false },
                    { id: 101, name: 'Mới', usable_on_site: true, preview_image_url: '/storage/widget-previews/intro/new.png', preview_stale: false },
                ],
            }),
        ])
        const second = mountPage()
        await flushPromises()
        expect(second.get('[data-test="library-card-10"]').get('img').attributes('src')).toBe('/storage/widget-previews/intro/new.png')

        await wrapper.get('[data-test="library-preview-10"]').trigger('click')
        await flushPromises()
        expect(apiMocks.loadWidgetThumbnail).toHaveBeenCalledWith(10, 100, 3)
        expect(wrapper.get('[data-test="preview-frame"]').attributes('srcdoc')).toBe('<p>preview</p>')
    })

    it('hub tab shows unreachable copy', async () => {
        apiMocks.getHubCatalog.mockRejectedValue(refused(503, 'WIDGET_HUB_UNREACHABLE'))
        const wrapper = mountPage()
        await flushPromises()

        // "Trên site" keeps working.
        expect(wrapper.get('[data-test="library-card-10"]').text()).toContain('Giới thiệu')

        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-test="hub-unreachable"]').text()).toContain(UNREACHABLE)
        expect(wrapper.find('[data-test="hub-loading"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="library-card-10"]').exists()).toBe(false)
        expect(messageMock.error).not.toHaveBeenCalled()
        expect(iAlertMock).not.toHaveBeenCalled()

        // "Tải mới" asks the hub again and the cards appear once it answers.
        apiMocks.getHubCatalog.mockResolvedValue([hubEntry()])
        await wrapper.get('[data-test="hub-refresh"]').trigger('click')
        await flushPromises()
        expect(apiMocks.getHubCatalog).toHaveBeenLastCalledWith({ refresh: true })
        expect(wrapper.get('[data-test="hub-card-gallery"]').text()).toContain('Thư viện ảnh')
        expect(wrapper.find('[data-test="hub-unreachable"]').exists()).toBe(false)

        await wrapper.get('[data-test="library-tab-site"]').trigger('click')
        expect(wrapper.find('[data-test="library-card-10"]').exists()).toBe(true)
    })

    it('download shows reason when resolver missing', async () => {
        apiMocks.getHubCatalog.mockResolvedValue([
            hubEntry(),
            hubEntry({ widget_key: 'product-latest', name: 'Sản phẩm mới', missing_resolvers: ['query:product.latest'] }),
            // Already on the site: not offered again.
            hubEntry({ widget_key: 'intro', name: 'Giới thiệu', installed_widget_id: 10, installed_version: 3, latest_version: 3 }),
        ])
        apiMocks.downloadHubWidget.mockRejectedValueOnce(refused(409, 'WIDGET_RESOLVER_MISSING'))
        const wrapper = mountPage()
        await flushPromises()
        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-test="hub-card-intro"]').exists()).toBe(false)

        // Known before pressing: the reason is there and the button cannot be pressed.
        const known = wrapper.get('[data-test="hub-card-product-latest"]')
        expect(known.text()).toContain(RESOLVER_MISSING)
        expect(known.get('[data-test="hub-download-product-latest"]').attributes('disabled')).toBeDefined()

        // Found out on pressing: the same reason on the card, no toast, nothing internal.
        const card = wrapper.get('[data-test="hub-card-gallery"]')
        expect(card.text()).not.toContain(RESOLVER_MISSING)
        expect(card.get('[data-test="hub-download-gallery"]').text()).toContain('Tải về')
        await card.get('[data-test="hub-download-gallery"]').trigger('click')
        await flushPromises()
        expect(apiMocks.downloadHubWidget).toHaveBeenCalledWith('gallery')
        expect(card.get('[data-test="hub-reason-gallery"]').text()).toBe(RESOLVER_MISSING)
        expect(messageMock.success).not.toHaveBeenCalled()
        expect(wrapper.text()).not.toMatch(/product\.latest|server text|query:|sha256|aaaa/)
        // No live frame on hub cards either.
        expect(wrapper.find('iframe').exists()).toBe(false)

    })

    it('a refused download stays explained and cannot be pressed again until "Tải mới"', async () => {
        apiMocks.getHubCatalog.mockResolvedValue([hubEntry(), hubEntry({ widget_key: 'pricing', name: 'Bảng giá' })])
        apiMocks.downloadHubWidget.mockImplementation((key: string) =>
            key === 'gallery' ? Promise.reject(refused(409, 'WIDGET_KEY_TAKEN')) : Promise.resolve({ id: 12, sync_status: 'synced' })
        )
        const wrapper = mountPage()
        await flushPromises()
        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await wrapper.get('[data-test="hub-download-gallery"]').trigger('click')
        await flushPromises()

        const button = () => wrapper.get('[data-test="hub-download-gallery"]')
        expect(wrapper.get('[data-test="hub-reason-gallery"]').text()).toBe('Site đã có một widget trùng mã này.')
        expect(button().attributes('disabled')).toBeDefined()
        await button().trigger('click')
        expect(apiMocks.downloadHubWidget).toHaveBeenCalledTimes(1)

        // Another card's download re-reads the lists; the refusal is still there.
        await wrapper.get('[data-test="hub-download-pricing"]').trigger('click')
        await flushPromises()
        expect(messageMock.success).toHaveBeenCalledWith('Đã tải widget về site.')
        expect(apiMocks.listLibraryWidgets).toHaveBeenCalledTimes(2)
        expect(apiMocks.getHubCatalog).toHaveBeenCalledTimes(2)
        expect(wrapper.get('[data-test="hub-reason-gallery"]').text()).toBe('Site đã có một widget trùng mã này.')
        expect(button().attributes('disabled')).toBeDefined()

        // "Tải mới" starts over.
        await wrapper.get('[data-test="hub-refresh"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="hub-reason-gallery"]').exists()).toBe(false)
        expect(button().attributes('disabled')).toBeUndefined()
    })

    it('a download the hub could not answer can be tried again', async () => {
        apiMocks.downloadHubWidget.mockRejectedValueOnce(refused(503, 'WIDGET_HUB_UNREACHABLE'))
        const wrapper = mountPage()
        await flushPromises()
        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await wrapper.get('[data-test="hub-download-gallery"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-test="hub-reason-gallery"]').text()).toBe(UNREACHABLE)
        expect(wrapper.get('[data-test="hub-download-gallery"]').attributes('disabled')).toBeUndefined()
    })

    it('hub cards show the published picture and fall back to the plain strip', async () => {
        // Every card counts as on screen.
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(private readonly seen: (entries: Array<{ isIntersecting: boolean }>) => void) {}
                observe() {
                    this.seen([{ isIntersecting: true }])
                }
                disconnect() {}
            }
        )
        const createObjectURL = vi.fn(() => 'blob:gallery')
        const revokeObjectURL = vi.fn()
        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
        apiMocks.getHubCatalog.mockResolvedValue([
            hubEntry({ preview_theme_keys: ['default', 'wide'], preview_urls: { default: 'http://site.test/p/gallery/2/default', wide: 'http://site.test/p/gallery/2/wide' } }),
            hubEntry({ widget_key: 'plain', name: 'Không ảnh' }),
            hubEntry({ widget_key: 'broken', name: 'Ảnh lỗi', preview_urls: { default: 'http://site.test/p/broken/2/default' } }),
        ])
        apiMocks.loadHubPreview.mockImplementation((url: string) =>
            url.includes('broken') ? Promise.reject(refused(404, 'WIDGET_HUB_NOT_FOUND')) : Promise.resolve(new Blob(['png'], { type: 'image/png' }))
        )
        const wrapper = mountPage()
        await flushPromises()
        // Nothing is fetched for a tab that is not open.
        expect(apiMocks.loadHubPreview).not.toHaveBeenCalled()

        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await flushPromises()

        // Only the first design's picture, through the API client (the admin signs requests with a header).
        expect(apiMocks.loadHubPreview).toHaveBeenCalledTimes(2)
        expect(apiMocks.loadHubPreview).toHaveBeenCalledWith('http://site.test/p/gallery/2/default')
        expect(wrapper.get('[data-test="hub-card-gallery"]').get('img').attributes('src')).toBe('blob:gallery')
        expect(wrapper.get('[data-test="hub-card-plain"]').find('img').exists()).toBe(false)
        expect(wrapper.get('[data-test="hub-card-broken"]').find('img').exists()).toBe(false)
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(messageMock.error).not.toHaveBeenCalled()

        wrapper.unmount()
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:gallery')
        vi.unstubAllGlobals()
    })

    it('a hub card off screen asks for no picture until it scrolls into view', async () => {
        const seen: Array<(entries: Array<{ isIntersecting: boolean }>) => void> = []
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(callback: (entries: Array<{ isIntersecting: boolean }>) => void) {
                    seen.push(callback)
                }
                observe() {}
                disconnect() {}
            }
        )
        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() }))
        apiMocks.getHubCatalog.mockResolvedValue([hubEntry({ preview_urls: { default: 'http://site.test/p/gallery/2/default' } })])
        const wrapper = mountPage()
        await flushPromises()
        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await flushPromises()
        expect(apiMocks.loadHubPreview).not.toHaveBeenCalled()

        seen.forEach((callback) => callback([{ isIntersecting: true }]))
        await flushPromises()
        expect(apiMocks.loadHubPreview).toHaveBeenCalledTimes(1)
        expect(wrapper.get('[data-test="hub-card-gallery"]').get('img').attributes('src')).toBe('blob:x')
        vi.unstubAllGlobals()
    })

    it('a picture captured after a design save replaces the one on the card', async () => {
        const stop = vi.fn()
        apiMocks.onDesignPreviewUploaded.mockReturnValue(stop)
        const wrapper = mountPage()
        await flushPromises()
        expect(wrapper.get('[data-test="library-card-11"]').text()).toContain('Chưa có ảnh mới')

        const listener = apiMocks.onDesignPreviewUploaded.mock.calls[0][0] as (saved: unknown) => void
        listener({ id: 110, preview_image_url: '/storage/widget-previews/contact-site/default-new.webp', preview_stale: false })
        await flushPromises()

        const card = wrapper.get('[data-test="library-card-11"]')
        expect(card.get('img').attributes('src')).toBe('/storage/widget-previews/contact-site/default-new.webp')
        expect(card.text()).not.toContain('Chưa có ảnh mới')
        // Other cards keep theirs.
        expect(wrapper.get('[data-test="library-card-10"]').get('img').attributes('src')).toBe(
            '/storage/widget-previews/intro/two-columns-v3.png'
        )

        wrapper.unmount()
        expect(stop).toHaveBeenCalledTimes(1)
    })

    it('download says so when the widget is saved but its files are still being prepared', async () => {
        apiMocks.downloadHubWidget.mockResolvedValue({ id: 12, sync_status: 'pending', sync_incident_id: 7 })
        const wrapper = mountPage()
        await flushPromises()
        await wrapper.get('[data-test="library-tab-hub"]').trigger('click')
        await wrapper.get('[data-test="hub-download-gallery"]').trigger('click')
        await flushPromises()

        expect(messageMock.success).not.toHaveBeenCalled()
        expect(messageMock.warning).toHaveBeenCalledWith(copy.keys['text.widget_library.hub_sync_pending'])
    })

    it('update shows impact before applying', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ hub_latest_version: 4 })])
        apiMocks.getHubUpdateImpact.mockResolvedValue({
            pages: 3,
            areas: 1,
            removed_designs_in_use: [],
            new_input_labels: ['Ảnh nền'],
        })
        apiMocks.updateHubWidget.mockResolvedValue({ id: 10, sync_status: 'synced' })
        const wrapper = mountPage()
        await flushPromises()

        const card = wrapper.get('[data-test="library-card-10"]')
        expect(card.get('[data-test="library-has-update-10"]').text()).toBe('Có bản mới')
        // The menu names the version the update leads to.
        expect(card.get('[data-test="library-update-10"]').text()).toBe('Cập nhật lên bản 4')

        await card.get('[data-test="library-update-10"]').trigger('click')
        await flushPromises()
        expect(apiMocks.getHubUpdateImpact).toHaveBeenCalledWith(10)
        // The dialog states which version is installed and which one arrives.
        expect(wrapper.get('[data-test="update-versions"]').text()).toBe('Bản 3 → bản 4')
        expect(wrapper.get('[data-test="update-impact"]').text()).toContain(
            'Cập nhật sẽ đổi giao diện ở 3 trang, 1 khu vực.'
        )
        expect(wrapper.text()).toContain('Ảnh nền')
        expect(apiMocks.updateHubWidget).not.toHaveBeenCalled()

        await wrapper.get('[data-test="update-confirm"]').trigger('click')
        await flushPromises()
        expect(apiMocks.updateHubWidget).toHaveBeenCalledWith(10)
        expect(messageMock.success).toHaveBeenCalledWith('Đã cập nhật widget.')
        expect(apiMocks.listLibraryWidgets).toHaveBeenCalledTimes(2)
        expect(wrapper.find('[data-test="update-impact"]').exists()).toBe(false)
    })

    describe('update dialog: pictures and what is new', () => {
        const NO_IMPACT = { pages: 0, areas: 0, removed_designs_in_use: [], new_input_labels: [] }
        const design = (overrides: Record<string, unknown> = {}) => ({
            id: 100,
            name: 'Hai cột',
            theme_key: 'two-columns',
            usable_on_site: true,
            preview_image_url: '/storage/widget-previews/intro/two-columns-v3.png',
            preview_stale: false,
            ...overrides,
        })
        const installedEntry = (overrides: Record<string, unknown> = {}) =>
            hubEntry({
                widget_key: 'intro',
                name: 'Giới thiệu',
                latest_version: 4,
                installed_widget_id: 10,
                installed_version: 3,
                preview_theme_keys: ['two-columns'],
                preview_urls: { 'two-columns': 'http://site.test/p/intro/4/two-columns' },
                ...overrides,
            })
        /** Open "Cập nhật" of widget 10 with the given site row and hub entry. */
        const openUpdate = async (row: Record<string, unknown>, entry: Record<string, unknown>) => {
            vi.stubGlobal(
                'URL',
                Object.assign(URL, {
                    createObjectURL: vi.fn((blob: Blob & { address?: string }) => `blob:${blob.address ?? 'picture'}`),
                    revokeObjectURL: vi.fn(),
                })
            )
            apiMocks.loadHubPreview.mockImplementation((url: string) =>
                Promise.resolve(Object.assign(new Blob(['png'], { type: 'image/png' }), { address: url }))
            )
            apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ hub_latest_version: 4, ...row })])
            apiMocks.getHubCatalog.mockResolvedValue([entry])
            apiMocks.getHubUpdateImpact.mockResolvedValue(NO_IMPACT)
            const wrapper = mountPage()
            await flushPromises()
            await wrapper.get('[data-test="library-update-10"]').trigger('click')
            await flushPromises()
            return wrapper
        }

        it('shows the picture in use next to the picture of the new version', async () => {
            const wrapper = await openUpdate({ template: [design()] }, installedEntry())

            const previews = wrapper.get('[data-test="update-previews"]')
            const current = previews.get('[data-test="update-picture-current"]')
            expect(current.attributes('src')).toBe('/storage/widget-previews/intro/two-columns-v3.png')
            expect(current.attributes('loading')).toBe('lazy')
            // The hub picture is read through the API client, never by address.
            expect(apiMocks.loadHubPreview).toHaveBeenCalledWith('http://site.test/p/intro/4/two-columns')
            expect(previews.get('[data-test="update-picture-new"]').attributes('src')).toBe(
                'blob:http://site.test/p/intro/4/two-columns'
            )
            expect(previews.text()).toContain('Đang dùng')
            expect(previews.text()).toContain('Bản mới')
            // One design: nothing to switch.
            expect(wrapper.find('[data-test="update-design-0"]').exists()).toBe(false)
            // Impact line and buttons stay.
            expect(wrapper.get('[data-test="update-impact"]').text()).toContain('0 trang')
            expect(wrapper.find('[data-test="update-confirm"]').exists()).toBe(true)
        })

        it('a missing picture is a plain tile on either side', async () => {
            const wrapper = await openUpdate(
                { template: [design({ preview_image_url: null })] },
                installedEntry({ preview_urls: {} })
            )

            expect(wrapper.find('[data-test="update-picture-current"]').exists()).toBe(false)
            expect(wrapper.find('[data-test="update-picture-new"]').exists()).toBe(false)
            expect(wrapper.get('[data-test="update-no-picture-current"]').text()).toBe('Chưa có ảnh')
            expect(wrapper.get('[data-test="update-no-picture-new"]').text()).toBe('Chưa có ảnh')
            expect(apiMocks.loadHubPreview).not.toHaveBeenCalled()
        })

        it('a hub picture that cannot be read is a plain tile', async () => {
            const wrapper = await openUpdate({ template: [design()] }, installedEntry())
            expect(wrapper.find('[data-test="update-picture-new"]').exists()).toBe(true)

            apiMocks.loadHubPreview.mockRejectedValue(new Error('gone'))
            await wrapper.get('[data-test="update-cancel"]').trigger('click')
            await wrapper.get('[data-test="library-update-10"]').trigger('click')
            await flushPromises()
            expect(wrapper.find('[data-test="update-picture-new"]').exists()).toBe(false)
            expect(wrapper.get('[data-test="update-no-picture-new"]').text()).toBe('Chưa có ảnh')
        })

        it('several designs: switches between the designs both versions have', async () => {
            const wrapper = await openUpdate(
                {
                    template: [
                        design(),
                        design({ id: 101, name: 'Một cột', theme_key: 'one-column', preview_image_url: '/storage/one-v3.png' }),
                        design({ id: 102, name: 'Bỏ rồi', theme_key: 'dropped', preview_image_url: '/storage/dropped.png' }),
                    ],
                },
                installedEntry({
                    preview_theme_keys: ['two-columns', 'one-column', 'brand-new'],
                    preview_urls: {
                        'two-columns': 'http://site.test/p/intro/4/two-columns',
                        'one-column': 'http://site.test/p/intro/4/one-column',
                        'brand-new': 'http://site.test/p/intro/4/brand-new',
                    },
                })
            )

            // Only designs present on the site and in the new version are offered, by name.
            expect(wrapper.get('[data-test="update-design-0"]').text()).toBe('Hai cột')
            expect(wrapper.get('[data-test="update-design-1"]').text()).toBe('Một cột')
            expect(wrapper.find('[data-test="update-design-2"]').exists()).toBe(false)
            expect(wrapper.get('[data-test="update-design-0"]').attributes('aria-pressed')).toBe('true')
            // Only the design shown is fetched.
            expect(apiMocks.loadHubPreview).toHaveBeenCalledTimes(1)

            await wrapper.get('[data-test="update-design-1"]').trigger('click')
            await flushPromises()
            expect(wrapper.get('[data-test="update-design-1"]').attributes('aria-pressed')).toBe('true')
            expect(wrapper.get('[data-test="update-picture-current"]').attributes('src')).toBe('/storage/one-v3.png')
            expect(wrapper.get('[data-test="update-picture-new"]').attributes('src')).toBe(
                'blob:http://site.test/p/intro/4/one-column'
            )
        })

        it('designs only the new version has are listed by number, next to the first design in use', async () => {
            const wrapper = await openUpdate(
                { template: [design({ theme_key: 'old' })] },
                installedEntry({
                    preview_theme_keys: ['fresh-a', 'fresh-b'],
                    preview_urls: { 'fresh-a': 'http://site.test/p/a', 'fresh-b': 'http://site.test/p/b' },
                })
            )

            expect(wrapper.get('[data-test="update-design-0"]').text()).toBe('Kiểu hiển thị 1')
            expect(wrapper.get('[data-test="update-design-1"]').text()).toBe('Kiểu hiển thị 2')
            // Design keys never reach the screen.
            expect(wrapper.get('[data-test="update-previews"]').text()).not.toContain('fresh')

            await wrapper.get('[data-test="update-design-1"]').trigger('click')
            await flushPromises()
            expect(wrapper.get('[data-test="update-picture-current"]').attributes('src')).toBe(
                '/storage/widget-previews/intro/two-columns-v3.png'
            )
            expect(wrapper.get('[data-test="update-picture-new"]').attributes('src')).toBe('blob:http://site.test/p/b')
        })

        it('"Có gì mới" groups the notes per version, newest first, and skips versions already installed', async () => {
            const wrapper = await openUpdate(
                { version: 20261031, hub_latest_version: 20261102, template: [design()] },
                installedEntry({
                    latest_version: 20261102,
                    installed_version: 20261031,
                    changes: [
                        { version: 20261101, items: [{ type: 'fixed', text: 'Sửa lệch ảnh trên điện thoại.' }] },
                        {
                            version: 20261102,
                            items: [
                                { type: 'added', text: 'Thêm kiểu hiển thị một cột.' },
                                { type: 'changed', text: 'Chữ tiêu đề lớn hơn.' },
                            ],
                        },
                        { version: 20261031, items: [{ type: 'added', text: 'Ghi chú của bản đang dùng.' }] },
                    ],
                })
            )

            const notes = wrapper.get('[data-test="update-notes"]')
            expect(notes.get('h3').text()).toBe('Có gì mới')
            expect(notes.findAll('h4').map((heading) => heading.text())).toEqual(['Bản 2026.11.02', 'Bản 2026.11.01'])
            expect(notes.find('[data-test="update-notes-20261031"]').exists()).toBe(false)
            expect(notes.text()).not.toContain('Ghi chú của bản đang dùng.')

            const newest = notes.get('[data-test="update-notes-20261102"]').findAll('li')
            expect(newest.map((item) => item.attributes('data-change'))).toEqual(['added', 'changed'])
            expect(newest[0].text()).toContain('Tính năng mới')
            expect(newest[0].text()).toContain('Thêm kiểu hiển thị một cột.')
            expect(newest[1].text()).toContain('Thay đổi')
            const older = notes.get('[data-test="update-notes-20261101"]').get('li')
            expect(older.text()).toContain('Sửa lỗi')
            expect(older.text()).toContain('Sửa lệch ảnh trên điện thoại.')
            expect(wrapper.find('[data-test="update-notes-empty"]').exists()).toBe(false)
        })

        it('notes of a single version carry no version heading', async () => {
            const wrapper = await openUpdate(
                { template: [design()] },
                installedEntry({ changes: [{ version: 4, items: [{ type: 'fixed', text: 'Sửa lỗi nút bấm.' }] }] })
            )

            const notes = wrapper.get('[data-test="update-notes"]')
            expect(notes.find('h4').exists()).toBe(false)
            expect(notes.text()).toContain('Sửa lỗi nút bấm.')
        })

        it('no notes for the new version says so', async () => {
            const wrapper = await openUpdate({ template: [design()] }, installedEntry({ changes: [] }))

            expect(wrapper.get('[data-test="update-notes-empty"]').text()).toBe('Chưa có ghi chú cho bản này.')
        })

        it('an older server without the notes field reads the same, and the update still works', async () => {
            apiMocks.updateHubWidget.mockResolvedValue({ id: 10, sync_status: 'synced' })
            const wrapper = await openUpdate({ template: [design()] }, installedEntry())

            expect(wrapper.get('[data-test="update-notes-empty"]').text()).toBe('Chưa có ghi chú cho bản này.')
            await wrapper.get('[data-test="update-confirm"]').trigger('click')
            await flushPromises()
            expect(apiMocks.updateHubWidget).toHaveBeenCalledWith(10)
        })

        it('the hub list not read: the picture in use, a plain tile for the new version, no notes', async () => {
            const wrapper = await openUpdate({ template: [design({ theme_key: undefined })] }, hubEntry())

            expect(wrapper.get('[data-test="update-picture-current"]').attributes('src')).toBe(
                '/storage/widget-previews/intro/two-columns-v3.png'
            )
            expect(wrapper.find('[data-test="update-no-picture-new"]').exists()).toBe(true)
            expect(wrapper.get('[data-test="update-notes-empty"]').text()).toBe('Chưa có ghi chú cho bản này.')
            expect(wrapper.find('[data-test="update-confirm"]').exists()).toBe(true)
        })
    })

    it('a version reads as a date once it is one, and as a plain number before', () => {
        expect(formatVersion(20261101)).toBe('2026.11.01')
        expect(formatVersion(20261102)).toBe('2026.11.02')
        expect(formatVersion(20261110)).toBe('2026.11.10')
        expect(formatVersion(3)).toBe('3')
        expect(formatVersion(null)).toBe('')
    })

    it('date versions read as dates on the card, in the menu and in the update dialog', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ version: 20261101, hub_latest_version: 20261102 })])
        apiMocks.getHubUpdateImpact.mockResolvedValue({ pages: 0, areas: 0, removed_designs_in_use: [], new_input_labels: [] })
        const wrapper = mountPage()
        await flushPromises()

        const card = wrapper.get('[data-test="library-card-10"]')
        expect(card.get('[data-test="library-origin-10"]').text()).toBe('Từ kho gốc · bản 2026.11.01')
        expect(card.get('[data-test="library-update-10"]').text()).toBe('Cập nhật lên bản 2026.11.02')

        await card.get('[data-test="library-update-10"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('[data-test="update-versions"]').text()).toBe('Bản 2026.11.01 → bản 2026.11.02')
    })

    it('the "Có bản mới" chip starts the same update, behind the same confirmation', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ hub_latest_version: 4 })])
        apiMocks.getHubUpdateImpact.mockResolvedValue({ pages: 3, areas: 1, removed_designs_in_use: [], new_input_labels: [] })
        const wrapper = mountPage()
        await flushPromises()

        const chip = wrapper.get('[data-test="library-has-update-10"]')
        expect(chip.element.tagName).toBe('BUTTON')
        await chip.trigger('click')
        await flushPromises()

        expect(apiMocks.getHubUpdateImpact).toHaveBeenCalledWith(10)
        expect(wrapper.find('[data-test="update-impact"]').exists()).toBe(true)
        // Nothing is applied until the operator confirms.
        expect(apiMocks.updateHubWidget).not.toHaveBeenCalled()
    })

    it('update is refused in the dialog when the new version drops a design in use', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ hub_latest_version: 4 })])
        apiMocks.getHubUpdateImpact.mockResolvedValue({
            pages: 3,
            areas: 1,
            removed_designs_in_use: ['Hai cột'],
            new_input_labels: [],
        })
        const wrapper = mountPage()
        await flushPromises()
        await wrapper.get('[data-test="library-update-10"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-test="update-blocked"]').text()).toContain(
            'Bản mới bỏ một thiết kế đang được dùng, nên chưa cập nhật được.'
        )
        expect(wrapper.get('[data-test="update-blocked"]').text()).toContain('Hai cột')
        expect(wrapper.find('[data-test="update-confirm"]').exists()).toBe(false)
    })

    it('a card learns about a newer version from the hub list when the site list does not carry it', async () => {
        apiMocks.getHubCatalog.mockResolvedValue([
            hubEntry({ widget_key: 'intro', name: 'Giới thiệu', installed_widget_id: 10, installed_version: 3, latest_version: 5 }),
            // Same key as a site copy: never an update for it.
            hubEntry({ widget_key: 'contact-site', installed_widget_id: 11, installed_version: 3, latest_version: 9 }),
        ])
        const wrapper = mountPage()
        await flushPromises()

        expect(wrapper.find('[data-test="library-has-update-10"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="library-has-update-11"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="library-update-11"]').exists()).toBe(false)
    })

    it('the authoring copy shows no "Có bản mới" and no "Cập nhật"', async () => {
        apiMocks.getCatalogStatus.mockResolvedValue({ authoring: true, unexported_widget_keys: [] })
        apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ hub_latest_version: 4 })])
        const wrapper = mountPage()
        await flushPromises()

        expect(wrapper.find('[data-test="library-has-update-10"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="library-update-10"]').exists()).toBe(false)
    })

    it('a refusal on the authoring copy reads in plain words', async () => {
        const { hubErrorCopy } = await vi.importActual<{ hubErrorCopy: (e: unknown) => string }>(
            '../../../../cms/Core/admin/widget-library-api'
        )
        expect(copy.t(hubErrorCopy(refused(409, 'WIDGET_AUTHORING_COPY')))).toBe(
            'Đây là bản soạn widget gốc, nên không tải về hay cập nhật từ kho.'
        )
    })

    it('actions hidden without widget.hub', async () => {
        permissionState.denied.add('widget.hub')
        apiMocks.listLibraryWidgets.mockResolvedValue([widgetRow({ hub_latest_version: 4 })])
        const wrapper = mountPage()
        await flushPromises()

        // The site list still says a newer version exists; acting on it needs the permission.
        // ...so the chip is plain text there, not a button.
        expect(wrapper.get('[data-test="library-has-update-10"]').element.tagName).toBe('SPAN')
        expect(wrapper.find('[data-test="library-update-10"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="library-tab-hub"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="hub-refresh"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Tải về')
        expect(apiMocks.getHubCatalog).not.toHaveBeenCalled()
    })
})
