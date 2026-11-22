/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// "Thư viện widget" (cms/Core/admin/pages/widget-library): the Core screen that lists every widget of the site with its
// origin, block state and usage, and hands off to the page editor, the shared-area editor and the design editor.
// Wording is read from the Core admin sidecar (read only), so the assertions check the Vietnamese copy operators see.

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
    onDesignPreviewUploaded: vi.fn(() => () => {}),
}))
vi.mock('../../../../cms/Core/admin/widget-library-api', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...apiMocks,
        // Pure helpers (no request): the real ones decide what a small picture and an empty preview are.
        thumbnailHtml: actual.thumbnailHtml,
        isBlankRender: actual.isBlankRender,
        usableDesigns: actual.usableDesigns,
        hubErrorCopy: actual.hubErrorCopy,
        isFinalHubRefusal: actual.isFinalHubRefusal,
        forgetWidgetThumbnails: vi.fn(),
        widgetErrorCode: (error: { response?: { data?: { error_code?: string } } }) => error?.response?.data?.error_code ?? '',
    }
})

const moduleState = vi.hoisted(() => ({ widgetOn: true }))
vi.mock('@/hooks/useActiveModules', () => ({
    isModuleActive: () => moduleState.widgetOn,
    isModuleExtensionAllowed: () => moduleState.widgetOn,
    // What the server said in this page load; the library asks the Widget module only on this answer.
    isModuleLiveEnabled: () => moduleState.widgetOn,
}))

const libsMocks = vi.hoisted(() => ({
    getWidget: vi.fn(),
    storeWidget: vi.fn(),
    updateWidget: vi.fn(),
    deleteWidget: vi.fn(),
    getWidgetPreview: vi.fn(),
}))
vi.mock('@/libs', () => ({
    ...libsMocks,
    buildWidgetSavePayload: (form: Record<string, unknown>) => ({ ...form }),
}))

const widgetsData = vi.hoisted(() => ({ value: { data: [] as unknown[] } }))
vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return {
        useWidgets: () => ({ data: ref(widgetsData.value), refetch: vi.fn() }),
        widgetInputDefault: () => ({ id: 0, name: '' }),
        widgetTemplateDefault: () => ({ id: 0, name: '' }),
    }
})

const permissionState = vi.hoisted(() => ({ denied: new Set<string>() }))
const iAlertMock = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', () => {
    const permitted = (a: unknown, b?: string) => !permissionState.denied.has(b !== undefined ? `${a}.${b}` : String(a))
    return {
        can: permitted,
        rule: permitted,
        iAlert: iAlertMock,
        finishFailed: vi.fn(),
        getInputVariableByTemplate: (inputs: unknown[]) => inputs,
    }
})

const messageMock = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() }))
vi.mock('ant-design-vue', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    message: messageMock,
}))

const pushMock = vi.hoisted(() => vi.fn())
const routeState = vi.hoisted(() => ({ query: {} as Record<string, unknown> }))
vi.mock('vue-router', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    useRouter: () => ({ push: pushMock, resolve: (to: { name: string }) => ({ href: `/iPanel/resolved/${to.name}` }) }),
    useRoute: () => routeState,
}))

import WidgetLibrary from '../../../../cms/Core/admin/pages/widget-library/index.vue'
import WidgetLibrarySources from '../../../../cms/Core/admin/pages/widget-library/sources.vue'
import { coreWidgetLibraryRouteConfig } from '../../../../cms/Core/admin/route-config'
import { coreWidgetLibraryNavigationConfig } from '../../../../cms/Core/admin/navigation-config'
import { siteLayoutRouteConfig } from '../../../../cms/Widget/admin/route-config'
import { siteLayoutNavigationConfig } from '../../../../cms/Widget/admin/navigation-config'
import * as widgetPageLoaders from '../../../../cms/Widget/admin/page-loaders'

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
const FormStub = defineComponent({
    emits: ['finish'],
    setup(_props, { slots, emit }) {
        return () =>
            h('form', { onSubmit: (event: Event) => (event.preventDefault(), emit('finish', {})) }, slots.default?.())
    },
})
const PassThrough = { template: '<div><slot /></div>' }
const stubs = {
    'a-modal': OverlayStub,
    'a-drawer': OverlayStub,
    'a-form': FormStub,
    'a-form-item': PassThrough,
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}</div>' },
    'a-select': true,
    'a-table': true,
    // The card menu renders in place: trigger, then its items, like the opened dropdown.
    'a-dropdown': { template: '<div><slot /><div data-menu><slot name="overlay" /></div></div>' },
    'a-menu': { template: '<ul role="menu"><slot /></ul>' },
    'a-menu-item': {
        props: ['danger'],
        template: '<li role="menuitem" :data-danger="danger ? \'yes\' : \'no\'"><slot name="icon" /><slot /></li>',
    },
    'a-menu-divider': { template: '<li role="separator" />' },
    'a-textarea': true,
    'a-input': true,
    'a-spin': PassThrough,
    'a-button': { template: '<button><slot /></button>' },
    'i-button': { template: '<button><slot name="icon" /><slot /></button>' },
    'i-icon': true,
    breadcrumbs: { template: '<div><slot /><slot name="description" /></div>' },
    'no-permission-error': true,
    'widget-template': true,
    'widget-input': true,
    'router-link': { template: '<a><slot /></a>' },
}
const mountPage = (component: unknown) =>
    mount(component as never, { global: { stubs, mocks: { $t: copy.t } } })

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
    usage: { pages: 3, areas: 1 },
    template: [{ id: 100, name: 'Hai cột' }],
    inputs: [{ id: 1, name: 'title' }],
    ...overrides,
})

beforeEach(() => {
    vi.clearAllMocks()
    permissionState.denied.clear()
    moduleState.widgetOn = true
    routeState.query = {}
    apiMocks.listLibraryWidgets.mockResolvedValue([
        widgetRow(),
        widgetRow({
            id: 11,
            name: 'Liên hệ',
            widget_key: 'contact-site',
            origin: 'site',
            cloned_from_widget_key: 'contact',
            is_blocked: true,
            blocked_reason: 'Lỗi hiển thị',
            usage: { pages: 0, areas: 0 },
            template: [{ id: 110, name: 'Mặc định' }],
        }),
    ])
    apiMocks.getCatalogStatus.mockResolvedValue({ authoring: false, unexported_widget_keys: [] })
    apiMocks.getSharedAreaCount.mockResolvedValue(2)
    apiMocks.loadWidgetThumbnail.mockResolvedValue('<p>preview</p>')
    apiMocks.searchPages.mockResolvedValue([])
    apiMocks.getHubCatalog.mockResolvedValue([])
    // Designs a shared area accepts (`placeable=1`): every design of both widgets unless a case says otherwise.
    apiMocks.listPlaceableDesigns.mockResolvedValue(new Map([[10, new Set([100])], [11, new Set([110])]]))
})

describe('Thư viện widget', () => {
    it('renders origin, blocked and usage', async () => {
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        // The library asks for usage in the list request (the endpoint omits it otherwise).
        expect(apiMocks.listLibraryWidgets).toHaveBeenCalledTimes(1)

        const hub = wrapper.get('[data-test="library-card-10"]')
        expect(hub.text()).toContain('Giới thiệu')
        // A hub widget names its installed version in the origin chip; a site copy shows none.
        expect(hub.get('[data-test="library-origin-10"]').text()).toBe('Từ kho gốc · bản 3')
        expect(hub.text()).toContain('Dùng ở 3 trang, 1 khu vực')
        expect(hub.find('[data-test="library-blocked-10"]').exists()).toBe(false)
        expect(hub.find('[data-test="library-unexported-10"]').exists()).toBe(false)

        const site = wrapper.get('[data-test="library-card-11"]')
        expect(site.get('[data-test="library-origin-11"]').text()).toBe('Bản riêng')
        expect(site.get('[data-test="library-blocked-11"]').text()).toContain('Đang tạm ẩn')
        expect(site.text()).toContain('Lỗi hiển thị')
        expect(site.get('[data-test="library-block-11"]').text()).toBe('Hiện lại trên site')
        expect(hub.get('[data-test="library-block-10"]').text()).toBe('Tạm ẩn trên site')

        // Nothing internal on screen: no ids or keys.
        expect(wrapper.text()).not.toMatch(/intro|contact-site|widget_id|theme_id/)
        // A blocked widget renders nothing, so its thumbnail is never requested.
        expect(apiMocks.loadWidgetThumbnail).not.toHaveBeenCalledWith(11, expect.anything(), expect.anything())
    })

    it('shows the local "not exported yet" chip only where hub widgets are authored', async () => {
        apiMocks.getCatalogStatus.mockResolvedValue({ authoring: true, unexported_widget_keys: ['intro'] })
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        expect(wrapper.get('[data-test="library-unexported-10"]').text()).toBe('Có thay đổi chưa export')
        expect(wrapper.find('[data-test="library-unexported-11"]').exists()).toBe(false)
    })

    it('hides the block action without widget.block', async () => {
        permissionState.denied.add('widget.block')
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        expect(wrapper.find('[data-test="library-block-10"]').exists()).toBe(false)
    })

    it('blocks with a reason and shows Vietnamese copy for a refused block', async () => {
        apiMocks.blockWidget.mockRejectedValueOnce({
            response: { status: 403, data: { error_code: 'WIDGET_BLOCK_FORBIDDEN', message: 'No permission (server text)' } },
        })
        apiMocks.blockWidget.mockResolvedValueOnce({ blocked: true, blocked_reason: 'Sai nội dung' })
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        await wrapper.get('[data-test="library-block-10"]').trigger('click')
        expect(wrapper.text()).toContain('Tạm ẩn widget này trên toàn site?')
        expect(wrapper.text()).toContain('Lý do')

        const dialog = wrapper.getComponent({ name: 'WidgetLibraryBlockDialog' })
        ;(dialog.vm as unknown as { reason: string }).reason = 'Sai nội dung'
        await wrapper.get('[data-test="block-confirm"]').trigger('click')
        await flushPromises()
        expect(messageMock.error).toHaveBeenCalledWith('Bạn không có quyền tạm ẩn widget trên site.')

        await wrapper.get('[data-test="block-confirm"]').trigger('click')
        await flushPromises()
        expect(apiMocks.blockWidget).toHaveBeenLastCalledWith(10, 'Sai nội dung')
        expect(wrapper.get('[data-test="library-blocked-10"]').text()).toContain('Đang tạm ẩn')
    })

    it('a card has one main action and a menu with the rest, the risky one last', async () => {
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        const card = wrapper.get('[data-test="library-card-10"]')
        // Two buttons in the action row: "Thêm vào…" and the menu, which says whose menu it is.
        expect(card.get('[data-test="library-add-10"]').text()).toBe('Thêm vào…')
        expect(card.get('[data-test="library-menu-10"]').attributes('aria-label')).toBe('Thao tác khác với “Giới thiệu”')

        const items = card.get('[data-test="library-menu-items-10"]').findAll('li')
        expect(items.map((item) => item.attributes('role') === 'separator' ? '—' : item.text())).toEqual([
            'Xem trước',
            'Sửa thiết kế',
            '—',
            'Tạm ẩn trên site',
        ])
        // Only hiding is marked as dangerous; showing a hidden widget again is not.
        expect(card.get('[data-test="library-block-10"]').attributes('data-danger')).toBe('yes')
        expect(card.get('[data-test="library-edit-10"]').attributes('data-danger')).toBe('no')
        expect(wrapper.get('[data-test="library-block-11"]').attributes('data-danger')).toBe('no')

        await card.get('[data-test="library-edit-10"]').trigger('click')
        expect(pushMock).toHaveBeenCalledWith({ name: 'widget-library-sources', query: { edit: 10 } })

        // Without the rights the menu keeps only the preview, with no stray divider.
        permissionState.denied.add('widget.block')
        permissionState.denied.add('widget.update')
        const viewer = mountPage(WidgetLibrary)
        await flushPromises()
        expect(viewer.get('[data-test="library-menu-items-10"]').findAll('li').map((item) => item.text())).toEqual(['Xem trước'])
    })

    it('the picture is a named button that opens the preview', async () => {
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        const picture = wrapper.get('[data-test="library-picture-10"]')
        expect(picture.element.tagName).toBe('BUTTON')
        expect(picture.attributes('type')).toBe('button')
        expect(picture.attributes('aria-label')).toBe('Xem trước “Giới thiệu”')

        await picture.trigger('click')
        await flushPromises()
        expect(apiMocks.loadWidgetThumbnail).toHaveBeenCalledWith(10, 100, 3)
        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(true)
    })

    it('showing a hidden widget again still asks first', async () => {
        apiMocks.unblockWidget.mockResolvedValue({ blocked: false })
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        await wrapper.get('[data-test="library-block-11"]').trigger('click')
        expect(wrapper.text()).toContain('Hiện lại widget này trên site?')
        expect(apiMocks.unblockWidget).not.toHaveBeenCalled()

        await wrapper.get('[data-test="block-confirm"]').trigger('click')
        await flushPromises()
        expect(apiMocks.unblockWidget).toHaveBeenCalledWith(11)
        expect(messageMock.success).toHaveBeenCalledWith('Widget đã hiện lại trên site.')
        expect(wrapper.find('[data-test="library-blocked-11"]').exists()).toBe(false)
    })

    it('a design that renders nothing says so in the preview instead of a blank frame', async () => {
        apiMocks.loadWidgetThumbnail.mockResolvedValue('<link rel="stylesheet" href="/a.css"><script src="/a.js"></script>\n')
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        await wrapper.get('[data-test="library-preview-10"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-test="preview-empty"]').text()).toBe('Kiểu hiển thị này chưa có nội dung mẫu để xem trước.')
        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(false)
    })

    it('the usage drawer links to the shared-area editor with a real address', async () => {
        apiMocks.getWidgetUsage.mockResolvedValue({ pages: [], areas: 1 })
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        await wrapper.get('[data-test="library-usage-10"]').trigger('click')
        await flushPromises()

        const link = wrapper.get('[data-test="usage-areas-link"]')
        expect(link.attributes('href')).toBe('/iPanel/resolved/site-layout-placements')
        await link.trigger('click')
        expect(pushMock).toHaveBeenCalledWith({ name: 'site-layout-placements' })
    })

    it('library page hides area actions when the module is off', async () => {
        moduleState.widgetOn = false
        const off = mountPage(WidgetLibrary)
        await flushPromises()
        // Core never asks the Widget module for anything while it is off.
        expect(apiMocks.getSharedAreaCount).not.toHaveBeenCalled()
        await off.get('[data-test="library-add-10"]').trigger('click')
        expect(off.find('[data-test="add-to-page"]').exists()).toBe(true)
        expect(off.find('[data-test="add-to-area"]').exists()).toBe(false)
        // Nor does the subtitle promise shared areas.
        expect(off.text()).toContain('Mọi widget dùng được trên site: xem trước, thêm vào trang, sửa thiết kế.')
        expect(off.text()).not.toContain('khu vực chung')
        expect(apiMocks.listPlaceableDesigns).not.toHaveBeenCalled()
        off.unmount()

        // Module on, but the active theme has no shared area: still hidden.
        moduleState.widgetOn = true
        apiMocks.getSharedAreaCount.mockResolvedValue(0)
        const empty = mountPage(WidgetLibrary)
        await flushPromises()
        await empty.get('[data-test="library-add-10"]').trigger('click')
        expect(empty.find('[data-test="add-to-area"]').exists()).toBe(false)
        empty.unmount()

        apiMocks.getSharedAreaCount.mockResolvedValue(2)
        const on = mountPage(WidgetLibrary)
        await flushPromises()
        expect(on.text()).toContain('thêm vào trang hoặc khu vực chung')
        await on.get('[data-test="library-add-10"]').trigger('click')
        await flushPromises()
        expect(on.get('[data-test="add-to-area"]').text()).toContain('Khu vực chung')
        await on.get('[data-test="add-to-area"]').trigger('click')
        expect(pushMock).toHaveBeenCalledWith({
            name: 'site-layout-placements',
            query: { add_widget: 10, theme: 100 },
        })
    })

    it('offers the shared area only for a widget with a design areas accept, and says so otherwise', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([
            widgetRow({ template: [{ id: 100, name: 'Hai cột' }, { id: 101, name: 'Của site' }] }),
            widgetRow({ id: 12, name: 'Mẫu site khác', widget_key: 'foreign-only', template: [{ id: 120, name: 'Site khác' }] }),
        ])
        // Shared areas accept design 101 of widget 10 and no design of widget 12. Since 2026-11-21 areas accept every
        // design pages accept (Default included), so the server leaves out only designs of another project.
        apiMocks.listPlaceableDesigns.mockResolvedValue(new Map([[10, new Set([101])]]))
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        // No usable design: the button is not offered, a plain sentence says why, pages still work.
        await wrapper.get('[data-test="library-add-12"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="add-to-area"]').exists()).toBe(false)
        expect(wrapper.get('[data-test="add-to-area-none"]').text()).toBe(
            'Widget này chưa có kiểu hiển thị nào dùng được cho khu vực chung trên site này. Bạn vẫn thêm được vào trang.'
        )
        expect(wrapper.find('[data-test="add-to-page"]').exists()).toBe(true)

        // A usable design exists, the chosen one (the first) is not it: offered, with the reason on the button.
        await wrapper.get('[data-test="library-add-10"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="add-to-area-none"]').exists()).toBe(false)
        expect(wrapper.get('[data-test="add-to-area-other-design"]').text()).toContain(
            'Kiểu hiển thị đang chọn chưa dùng được cho khu vực chung trên site này.'
        )
        await wrapper.get('[data-test="add-to-area"]').trigger('click')
        expect(pushMock).toHaveBeenCalledWith({ name: 'site-layout-placements', query: { add_widget: 10, theme: 100 } })
    })

    it('offers the shared area for a widget whose only design is Default, as pages do', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([
            widgetRow({ id: 12, name: 'Chỉ mẫu chung', widget_key: 'default-only', template: [{ id: 120, name: 'Mặc định' }] }),
        ])
        // The server's placeable list now holds the Default design.
        apiMocks.listPlaceableDesigns.mockResolvedValue(new Map([[12, new Set([120])]]))
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()

        await wrapper.get('[data-test="library-add-12"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="add-to-area-none"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="add-to-area-other-design"]').exists()).toBe(false)
        await wrapper.get('[data-test="add-to-area"]').trigger('click')
        expect(pushMock).toHaveBeenCalledWith({ name: 'site-layout-placements', query: { add_widget: 12, theme: 120 } })
    })

    it('add dialog offers only usable designs', async () => {
        apiMocks.listLibraryWidgets.mockResolvedValue([
            widgetRow({
                template: [
                    { id: 99, name: 'Site khác', usable_on_site: false },
                    { id: 100, name: 'Hai cột', usable_on_site: true },
                    { id: 101, name: 'Mặc định', usable_on_site: true },
                ],
            }),
            widgetRow({
                id: 12,
                name: 'Mẫu site khác',
                widget_key: 'foreign-only',
                template: [{ id: 120, name: 'Site khác', usable_on_site: false }],
            }),
        ])
        apiMocks.listPlaceableDesigns.mockResolvedValue(new Map([[10, new Set([100, 101])]]))
        const SelectStub = {
            props: ['options', 'value'],
            template: '<select><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
        }
        const wrapper = mount(WidgetLibrary as never, {
            global: { stubs: { ...stubs, 'a-select': SelectStub }, mocks: { $t: copy.t } },
        })
        await flushPromises()

        // The widget without a usable design still has its card, dimmed: nothing to add or preview, a sentence says
        // why, and the only action is the design editor.
        const unusable = wrapper.get('[data-test="library-card-12"]')
        expect(unusable.find('[data-test="library-add-12"]').exists()).toBe(false)
        expect(unusable.find('[data-test="library-preview-12"]').exists()).toBe(false)
        expect(unusable.find('[data-test="library-menu-12"]').exists()).toBe(false)
        expect(unusable.find('[data-test="library-block-12"]').exists()).toBe(false)
        expect(unusable.get('[data-test="library-picture-12"]').element.tagName).toBe('DIV')
        expect(unusable.get('[data-test="library-picture-12"]').classes()).toContain('opacity-60')
        // Besides the "where it is used" link, the only button left is the design editor.
        expect(unusable.findAll('button').map((button) => button.text())).toEqual(['Dùng ở 3 trang, 1 khu vực', 'Sửa thiết kế'])
        await unusable.get('[data-test="library-edit-12"]').trigger('click')
        expect(pushMock).toHaveBeenCalledWith({ name: 'widget-library-sources', query: { edit: 12 } })
        expect(wrapper.get('[data-test="library-no-design-12"]').text()).toBe(
            'Widget này chưa có kiểu hiển thị dùng được cho site này.'
        )
        expect(wrapper.find('[data-test="library-no-design-10"]').exists()).toBe(false)

        // The dialog offers only the designs this site can use, and hands off the first of them.
        await wrapper.get('[data-test="library-add-10"]').trigger('click')
        await flushPromises()
        const options = wrapper.get('[data-test="add-to-design"]').findAll('option')
        expect(options.map((option) => option.text())).toEqual(['Hai cột', 'Mặc định'])
        await wrapper.get('[data-test="add-to-area"]').trigger('click')
        expect(pushMock).toHaveBeenCalledWith({ name: 'site-layout-placements', query: { add_widget: 10, theme: 100 } })

        // The preview offers the same designs.
        await wrapper.get('[data-test="library-preview-10"]').trigger('click')
        await flushPromises()
        expect(wrapper.get('#widget-preview-design').findAll('option').map((option) => option.text())).toEqual([
            'Hai cột',
            'Mặc định',
        ])
        expect(apiMocks.loadWidgetThumbnail).toHaveBeenCalledWith(10, 100, 3)
        expect(apiMocks.loadWidgetThumbnail).not.toHaveBeenCalledWith(10, 99, expect.anything())
    })

    it('keeps the shared-area choice when the server cannot say which designs areas accept', async () => {
        apiMocks.listPlaceableDesigns.mockRejectedValue(new Error('refused'))
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()
        await wrapper.get('[data-test="library-add-10"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="add-to-area"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="add-to-area-none"]').exists()).toBe(false)
    })

    it('the copy toast mentions publishing shared areas only when the widget was in one', async () => {
        routeState.query = { edit: '10' }
        libsMocks.getWidget.mockResolvedValue({
            status: 'success',
            data: { ...widgetRow({ usage: { pages: 2, areas: 0 } }), description: 'Khối giới thiệu', js: '' },
        })
        libsMocks.updateWidget.mockResolvedValue({
            status: 'success',
            data: { id: 55, sync_status: 'ready', cloned: { widget_id: 55, widget_key: 'intro-site', pages_updated: 2 } },
        })
        const wrapper = mountPage(WidgetLibrarySources)
        await flushPromises()
        await wrapper.get('form').trigger('submit')
        await flushPromises()

        expect(messageMock.success).toHaveBeenCalledTimes(1)
        expect(messageMock.success).toHaveBeenCalledWith(
            'Đã tạo bản riêng. Các trang đang dùng widget này đã chuyển sang bản riêng.'
        )
        // A saved design can change which designs areas accept.
        expect(apiMocks.forgetPlaceableDesigns).toHaveBeenCalled()
    })

    it('hub edit shows notice and impact before save', async () => {
        routeState.query = { edit: '10' }
        libsMocks.getWidget.mockResolvedValue({
            status: 'success',
            data: {
                ...widgetRow({ usage: { pages: 2, areas: 1 } }),
                description: 'Khối giới thiệu',
                js: '',
            },
        })
        libsMocks.updateWidget.mockResolvedValue({
            status: 'success',
            data: { id: 55, sync_status: 'ready', cloned: { widget_id: 55, widget_key: 'intro-site', pages_updated: 2 } },
        })
        const wrapper = mountPage(WidgetLibrarySources)
        await flushPromises()

        const notice = wrapper.get('[data-test="hub-edit-notice"]').text()
        expect(notice).toContain('Mẫu từ kho gốc — khi lưu, hệ thống tạo bản riêng cho site này.')
        expect(notice).toContain('Lưu sẽ tạo bản riêng và đổi 2 trang, 1 khu vực sang bản này.')
        expect(libsMocks.updateWidget).not.toHaveBeenCalled()

        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(libsMocks.updateWidget).toHaveBeenCalledTimes(1)
        expect(messageMock.success).toHaveBeenCalledWith('Đã tạo bản riêng. Trang đã cập nhật; khu vực chung cần Xuất bản.')
    })

    it('does not flash the hub notice before it is known whether hub widgets are edited in place here', async () => {
        routeState.query = { edit: '10' }
        libsMocks.getWidget.mockResolvedValue({ status: 'success', data: widgetRow({ description: 'x', js: '' }) })
        let answer: (status: { authoring: boolean; unexported_widget_keys: string[] }) => void = () => {}
        apiMocks.getCatalogStatus.mockReturnValue(new Promise((resolve) => (answer = resolve)))
        const wrapper = mountPage(WidgetLibrarySources)
        await flushPromises()
        expect(wrapper.find('[data-test="hub-edit-notice"]').exists()).toBe(false)

        answer({ authoring: false, unexported_widget_keys: [] })
        await flushPromises()
        expect(wrapper.find('[data-test="hub-edit-notice"]').exists()).toBe(true)
    })

    it('passes the widget version to the live preview, so a changed design gets a new one', async () => {
        const wrapper = mountPage(WidgetLibrary)
        await flushPromises()
        // Cards show stored pictures; a render is asked for only from "Xem trước".
        expect(apiMocks.loadWidgetThumbnail).not.toHaveBeenCalled()

        await wrapper.get('[data-test="library-preview-10"]').trigger('click')
        await flushPromises()
        expect(apiMocks.loadWidgetThumbnail).toHaveBeenCalledWith(10, 100, 3)
    })

    it('shows no hub notice for a site copy, or where hub widgets are edited in place', async () => {
        routeState.query = { edit: '10' }
        libsMocks.getWidget.mockResolvedValue({ status: 'success', data: widgetRow({ description: 'x', js: '' }) })
        apiMocks.getCatalogStatus.mockResolvedValue({ authoring: true, unexported_widget_keys: [] })
        const authoring = mountPage(WidgetLibrarySources)
        await flushPromises()
        expect(authoring.find('[data-test="hub-edit-notice"]').exists()).toBe(false)
        authoring.unmount()

        apiMocks.getCatalogStatus.mockResolvedValue({ authoring: false, unexported_widget_keys: [] })
        libsMocks.getWidget.mockResolvedValue({
            status: 'success',
            data: widgetRow({ origin: 'site', description: 'x', js: '' }),
        })
        const siteCopy = mountPage(WidgetLibrarySources)
        await flushPromises()
        expect(siteCopy.find('[data-test="hub-edit-notice"]').exists()).toBe(false)
    })

    it('old site-layout routes redirect to the library', () => {
        const child = (name: string) => siteLayoutRouteConfig.children.find((route: { name?: string }) => route.name === name)
        const gallery = child('site-layout-widget-templates') as { redirect: unknown; load?: unknown }
        const sources = child('site-layout-widget-sources') as { redirect: (to: unknown) => unknown; load?: unknown }

        expect(gallery.redirect).toEqual({ name: 'widget-library' })
        expect(gallery.load).toBeUndefined()
        // The "edit this widget" deep link survives the redirect.
        expect(sources.redirect({ query: { edit: '7' } })).toEqual({ name: 'widget-library-sources', query: { edit: '7' } })
        expect(sources.load).toBeUndefined()
        expect(widgetPageLoaders).not.toHaveProperty('SiteLayoutWidgetTemplates')
        expect(widgetPageLoaders).not.toHaveProperty('SiteLayoutWidgetTemplateSources')
        // A redirect is not a menu entry: the Widget module's menu is the single "Khu vực chung" entry.
        expect(siteLayoutNavigationConfig.children).toBeUndefined()

        // The targets exist in Core, open to widget viewers and not tied to a module.
        const names = coreWidgetLibraryRouteConfig.children.map((route: { name: string }) => route.name)
        expect(names).toEqual(['widget-library', 'widget-library-sources'])
        expect(coreWidgetLibraryNavigationConfig).toMatchObject({
            name: 'widget-library',
            text: 'nav.a.widget-library',
            rule: 'widget.viewAny',
        })
        expect(coreWidgetLibraryNavigationConfig).not.toHaveProperty('module')
        expect(copy.keys['nav.a.widget-library']).toBe('Thư viện widget')
    })
})
