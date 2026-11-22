/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// "Khu vực chung" (cms/Widget/admin/pages/placements.vue): the shared-area editor for a site owner. Wording is read
// from the Widget admin sidecar (read only), so the assertions check the Vietnamese copy the operator sees.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

const copy = await vi.hoisted(async () => {
    const fs = await import('node:fs')
    const path = await import('node:path')
    const sidecar = path.resolve(process.cwd(), '../cms/Widget/database/seeders/sql/translate-admin-authored.php')
    const source = fs.readFileSync(sidecar, 'utf8')
    const vi_: Record<string, string> = {}
    // Rows are `['group', 'name', 'en', 'vi'],`.
    const pattern = /\[\s*'[^']*',\s*'((?:[^'\\]|\\.)*)',\s*'((?:[^'\\]|\\.)*)',\s*'((?:[^'\\]|\\.)*)'\s*\]/g
    for (const [, name, , text] of source.matchAll(pattern)) {
        vi_[name] = text.replace(/\\(['\\])/g, '$1')
    }
    // Keys that live in the module's main translation file, with their current Vietnamese text.
    const extra: Record<string, string> = {
        'widget_site_layout.slot.site_header': 'Đầu trang',
        'widget_site_layout.slot.site_footer': 'Chân trang',
        'widget_site_layout.scope.global': 'Toàn website',
        'widget_site_layout.scope.template': 'Theo loại trang',
    }
    /** Vietnamese text of a key with `{name}` placeholders filled; unknown keys come back as they are. */
    const t = (key: string, params: Record<string, unknown> = {}) =>
        (vi_[key] ?? extra[key] ?? key).replace(/\{(\w+)\}/g, (match, name) =>
            Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
        )
    return { t, keys: vi_ }
})

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: copy.t, te: () => false }),
    createI18n: () => ({ global: { t: copy.t, setLocaleMessage: vi.fn(), locale: { value: 'vi' } }, mode: 'composition', install: vi.fn() }),
}))
vi.mock('@/config/i18n', () => ({ i18n: { global: { t: copy.t } } }))
vi.mock('@/config', () => ({ defaultLocale: 'vi' }))
vi.mock('@/config/base', () => ({ supportLocales: ['en', 'vi'] }))

const permissions = vi.hoisted(() => ({ denied: new Set<string>() }))
const iAlertMock = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', () => ({ can: (rule: string) => !permissions.denied.has(rule), iAlert: iAlertMock }))
vi.mock('@/libs', () => ({ getInputByWidget: vi.fn().mockResolvedValue({ status: 'success', data: [] }) }))

const routerState = vi.hoisted(() => ({ query: {} as Record<string, string>, replace: vi.fn() }))
vi.mock('vue-router', () => ({
    useRoute: () => ({ query: routerState.query }),
    useRouter: () => ({ replace: routerState.replace, push: vi.fn() }),
}))

const api = vi.hoisted(() => ({
    listSlotsWithAreaCount: vi.fn(),
    getAreaCount: vi.fn(),
    listTargetContexts: vi.fn(),
    bootstrapWidgetLayoutByKey: vi.fn(),
    listWidgetLayouts: vi.fn(),
    getWidgetLayout: vi.fn(),
    getWidgetLayoutDraft: vi.fn(),
    saveWidgetLayoutDraft: vi.fn(),
    getWidgetLayoutDraftChanges: vi.fn(),
    createWidgetLayoutDraftPlacement: vi.fn(),
    updateWidgetLayoutDraftPlacementContent: vi.fn(),
    duplicateWidgetLayoutDraftPlacement: vi.fn(),
    createWidgetLayoutPreviewSession: vi.fn(),
    publishWidgetLayout: vi.fn(),
    rollbackWidgetLayout: vi.fn(),
    listWidgetLayoutReleases: vi.fn(),
    listPlaceableWidgets: vi.fn(),
    listWidgetDefinitionCatalog: vi.fn(),
    getWidgetThumbnail: vi.fn(),
    searchWidgetTargets: vi.fn(),
    getWidgetTargetDetail: vi.fn(),
    // The area editor must never reach these.
    listWidgetInstances: vi.fn(),
    createWidgetInstance: vi.fn(),
    updateWidgetInstance: vi.fn(),
    cloneWidgetInstance: vi.fn(),
    archiveWidgetInstance: vi.fn(),
    deleteWidgetInstance: vi.fn(),
    getWidgetInstanceUsage: vi.fn(),
    getWidgetInstance: vi.fn(),
}))
vi.mock('../../../../cms/Widget/admin/api', () => api)
vi.mock('../../../../cms/Widget/admin/widget-error-toast', () => ({ iWidgetError: vi.fn() }))

import Placements from '../../../../cms/Widget/admin/pages/placements.vue'
import AreaAddDrawer from '../../../../cms/Widget/admin/components/area-add-drawer.vue'
import AreaPublishDialog from '../../../../cms/Widget/admin/components/area-publish-dialog.vue'
import WidgetLayoutReleaseDrawer from '../../../../cms/Widget/admin/components/widget-layout-release-drawer.vue'
import { useWidgetSiteLayout } from '../../../../cms/Widget/admin/use-widget-site-layout'
import { siteLayoutNavigationConfig } from '../../../../cms/Widget/admin/navigation-config'
import { siteLayoutRouteConfig } from '../../../../cms/Widget/admin/route-config'
import { resetAreaCount } from '../../../../cms/Widget/admin/area-count'
import { formatLocalDateTime } from '../../../../cms/Widget/admin/area-format'

const SLOTS = [
    { key: 'site.header', label_key: 'widget_site_layout.slot.site_header', supported_scope_types: ['global', 'template', 'target'] },
    { key: 'site.footer', label_key: 'widget_site_layout.slot.site_footer', supported_scope_types: ['global'] },
]

const placement = (overrides: Record<string, unknown> = {}) => ({
    uuid: 'pl-1',
    slot_key: 'site.header',
    scope_type: 'global',
    scope_key: null,
    target_type: null,
    target_id: null,
    widget_instance_uuid: 'inst-1',
    widget_instance_version: 1,
    position: 0,
    enabled: true,
    rules: [],
    ...overrides,
})

const content = (overrides: Record<string, unknown> = {}) => ({
    widget_id: 5,
    widget_theme_id: 9,
    widget_name: 'Banner',
    schema: { title: { type: 'text' } },
    values: { title: 'Xin chào' },
    is_blocked: false,
    ...overrides,
})

const draft = (overrides: Record<string, unknown> = {}) => ({
    version: 4,
    lock_version: 3,
    placements: [placement()],
    instance_fingerprints: {},
    contents: { 'inst-1:1': content() },
    ...overrides,
})

const LIBRARY = [
    { id: 5, name: 'Banner', is_blocked: false, version: 2, template: [{ id: 9, name: 'Mặc định' }], inputs: [{ name: 'title', type: 'text' }] },
    { id: 6, name: 'Bộ đếm cũ', is_blocked: true, version: 1, template: [{ id: 11, name: 'Mặc định' }], inputs: [] },
]

// The shared widget form, reduced to a button that types one value.
const FieldsStub = defineComponent({
    name: 'WidgetInputFields',
    props: ['inputs', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () =>
            h(
                'button',
                { 'data-test': 'fields-stub', type: 'button', onClick: () => emit('update:modelValue', { ...props.modelValue, title: 'Chào mừng' }) },
                props.inputs.map((input: { name: string }) => input.name).join(',')
            )
    },
})

const globalOptions = {
    mocks: { $t: copy.t },
    stubs: {
        WidgetInputFields: FieldsStub,
        AreaWidgetThumbnail: true,
        breadcrumbs: { template: '<div><slot name="description" /></div>' },
        'i-icon': true,
        'widget-target-picker': true,
    },
}

const mountPage = async () => {
    const wrapper = mount(Placements, { global: globalOptions, attachTo: document.body })
    await flushPromises()
    await flushPromises()
    return wrapper
}

const readyLayout = async () => {
    const layout = useWidgetSiteLayout()
    await layout.bootstrap('site')
    await flushPromises()
    return layout
}

beforeEach(() => {
    Object.values(api).forEach((mock) => mock.mockReset())
    iAlertMock.mockReset()
    routerState.replace.mockReset()
    routerState.query = {}
    permissions.denied = new Set()
    resetAreaCount()
    window.localStorage.clear()

    api.listSlotsWithAreaCount.mockResolvedValue({ slots: SLOTS, areaCount: 2 })
    api.getAreaCount.mockResolvedValue(2)
    api.listTargetContexts.mockResolvedValue([{ target_type: 'page', label_key: 'Trang', template_context_key: 'page.static' }])
    api.bootstrapWidgetLayoutByKey.mockResolvedValue({
        uuid: 'layout-1',
        key: 'site',
        current_draft: { version: 4, lock_version: 3 },
        current_published: { version: 3 },
    })
    api.getWidgetLayoutDraft.mockResolvedValue(draft())
    api.getWidgetLayoutDraftChanges.mockResolvedValue([])
    api.createWidgetLayoutPreviewSession.mockResolvedValue({ preview_url: 'https://site.test/?preview=1', expires_at: '2999-01-01T00:00:00Z' })
    api.listPlaceableWidgets.mockResolvedValue(LIBRARY)
    api.listWidgetLayoutReleases.mockResolvedValue({ data: [] })
})

describe('Khu vực chung editor', () => {
    it('opens on untargeted preview', async () => {
        // A page remembered by an older version of the editor must not decide what opens.
        window.localStorage.setItem(
            'wpe-site-layout-editor-context.v1',
            JSON.stringify({ targetType: 'page', targetId: '77', templateContext: 'page.static', locale: 'vi' })
        )

        const wrapper = await mountPage()

        expect(api.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(1)
        const [layoutUuid, body] = api.createWidgetLayoutPreviewSession.mock.calls[0]
        expect(layoutUuid).toBe('layout-1')
        expect(body).not.toHaveProperty('target_id')
        expect(body).not.toHaveProperty('target_type')
        expect(body).not.toHaveProperty('template_context')
        expect(body.locale).toBe('vi')
        expect(typeof body.editor_nonce).toBe('string')
        expect(body.overlay).toHaveLength(1)

        // The page selector stays, named after what the preview shows; areas are listed with their counts.
        expect(wrapper.find('[data-test="context-page-chip"]').text()).toContain('Trang chủ')
        expect(wrapper.find('[data-test="structure-count-site.header"]').text()).toBe('1')
        expect(wrapper.find('[data-test="structure-count-site.footer"]').text()).toBe('0')
        expect(wrapper.find('[data-test="structure-add-site.footer"]').text()).toBe('+ Thêm')
        expect(wrapper.text()).toContain('Banner')
        expect(api.listWidgetInstances).not.toHaveBeenCalled()
        wrapper.unmount()
    })

    it('says plainly when an area is not on the page being viewed', async () => {
        const wrapper = await mountPage()
        const preview = wrapper.findComponent({ name: 'WidgetLayoutPreview' })

        preview.vm.$emit('areas', ['site.header'])
        await flushPromises()

        expect(wrapper.find('[data-test="structure-not-on-preview-site.header"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="structure-not-on-preview-site.footer"]').text()).toContain(
            'Khu vực này không có trên trang đang xem'
        )
        wrapper.unmount()
    })

    it('add drawer creates placement with content and default scope', async () => {
        const layout = await readyLayout()
        api.createWidgetLayoutDraftPlacement.mockResolvedValue({
            ...draft({ lock_version: 4, placements: [placement(), placement({ uuid: 'pl-2', widget_instance_uuid: 'inst-2', position: 1 })] }),
            placementUuid: 'pl-2',
        })

        const wrapper = mount(AreaAddDrawer, {
            props: { open: true, slot: SLOTS[0], layout, targetContexts: [] },
            global: globalOptions,
        })
        await flushPromises()

        expect(wrapper.find('[data-test="drawer-panel"]').attributes('aria-label')).toBe('Thêm widget vào “Đầu trang”')
        expect(wrapper.find('[data-test="area-add-steps"]').text()).toMatch(/Chọn mẫu.*Nội dung.*Hiển thị ở đâu/s)

        // Step 1: a blocked widget is shown, disabled, with its chip.
        const blocked = wrapper.find('[data-test="area-add-widget-6"]')
        expect(blocked.attributes('disabled')).toBeDefined()
        expect(wrapper.find('[data-test="area-add-blocked-6"]').text()).toBe('Đang bị chặn')

        await wrapper.find('[data-test="area-add-widget-5"]').trigger('click')
        await flushPromises()

        // Step 2: the shared widget form.
        expect(wrapper.find('[data-test="fields-stub"]').text()).toBe('title')
        await wrapper.find('[data-test="fields-stub"]').trigger('click')
        await wrapper.find('[data-test="area-add-next"]').trigger('click')

        // Step 3: "Toàn website" is chosen already.
        expect((wrapper.find('[data-test="placement-scope-global"]').element as HTMLInputElement).checked).toBe(true)
        expect(wrapper.find('[data-test="area-add-step-scope"]').text()).toMatch(/Toàn website.*Theo loại trang.*Riêng trang này/s)
        const submit = wrapper.find('[data-test="area-add-submit"]')
        expect(submit.text()).toBe('Đặt vào khu vực')
        await submit.trigger('click')
        await flushPromises()

        expect(api.createWidgetLayoutDraftPlacement).toHaveBeenCalledTimes(1)
        const [uuid, body, options] = api.createWidgetLayoutDraftPlacement.mock.calls[0]
        expect(uuid).toBe('layout-1')
        expect(body).toEqual({
            slot_key: 'site.header',
            scope_type: 'global',
            scope_key: null,
            target_type: null,
            target_id: null,
            widget_id: 5,
            widget_theme_id: 9,
            values: { title: 'Chào mừng' },
            expected_draft_revision: 3,
        })
        expect(options.idempotencyKey).toEqual(expect.any(String))
        expect(wrapper.emitted('added')?.[0]?.[0]).toMatchObject({ placementUuid: 'pl-2', areaLabel: 'Đầu trang' })
        expect(layout.state.baselineDraft.lockVersion).toBe(4)
        expect(layout.state.localDraft.placements).toHaveLength(2)
        expect(api.createWidgetInstance).not.toHaveBeenCalled()
    })

    it('a blocked widget and a draft changed elsewhere get plain Vietnamese copy, never the server message', async () => {
        const layout = await readyLayout()
        const wrapper = mount(AreaAddDrawer, {
            props: { open: true, slot: SLOTS[0], layout, targetContexts: [], preset: { widgetId: 5, themeId: 9 } },
            global: globalOptions,
        })
        await flushPromises()
        // The library hand-off lands on the content step.
        expect(wrapper.find('[data-test="fields-stub"]').exists()).toBe(true)
        await wrapper.find('[data-test="area-add-next"]').trigger('click')

        api.createWidgetLayoutDraftPlacement.mockRejectedValueOnce({ status: 409, code: 'WIDGET_BLOCKED', message: 'Widget is blocked by Core.' })
        await wrapper.find('[data-test="area-add-submit"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="area-add-error"]').text()).toContain('Widget này đang bị chặn')
        expect(wrapper.text()).not.toContain('blocked by Core')

        api.createWidgetLayoutDraftPlacement.mockRejectedValueOnce({ status: 409, code: 'WIDGET_DRAFT_STALE', message: 'The draft changed.' })
        api.getWidgetLayoutDraft.mockResolvedValueOnce(draft({ lock_version: 9 }))
        await wrapper.find('[data-test="area-add-submit"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="area-add-error"]').text()).toContain('vừa được người khác thay đổi')
        expect(layout.state.baselineDraft.lockVersion).toBe(9)
        expect(wrapper.emitted('added')).toBeUndefined()
    })

    it('an editor who may not read the widget library still gets the placeable widgets from the module catalog', async () => {
        const layout = await readyLayout()
        api.listPlaceableWidgets.mockRejectedValue({ status: 403, code: null, message: 'No permission.' })
        api.listWidgetDefinitionCatalog.mockResolvedValue({
            data: [
                { widget_id: 5, widget_theme_id: 9, name: 'Banner', theme_name: 'Mặc định', label: 'Banner', canonical_schema: { title: { type: 'text' } } },
                { widget_id: 5, widget_theme_id: 10, name: 'Banner', theme_name: 'Rộng', label: 'Banner', canonical_schema: { title: { type: 'text' }, note: { type: 'text' } } },
            ],
        })

        const wrapper = mount(AreaAddDrawer, { props: { open: true, slot: SLOTS[0], layout, targetContexts: [] }, global: globalOptions })
        await flushPromises()

        expect(wrapper.find('[data-test="area-add-failed"]').exists()).toBe(false)
        await wrapper.find('[data-test="area-add-widget-5"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="fields-stub"]').text()).toBe('title')
        // Two designs: the user chooses by name.
        const designs = wrapper.find('[data-test="placement-design-select"]')
        expect(designs.text()).toMatch(/Mặc định.*Rộng/s)
        await designs.setValue('10')
        await flushPromises()
        expect(wrapper.find('[data-test="fields-stub"]').text().split(',').sort()).toEqual(['note', 'title'])
    })

    it('publish dialog lists changes', async () => {
        const layout = await readyLayout()
        api.getWidgetLayoutDraftChanges.mockResolvedValue([
            { kind: 'added', slot_key: 'site.header', slot_label: 'Đầu trang', slot_label_key: null, widget_name: 'Banner', scope_type: 'global', scope_label: 'Toàn website' },
            // Labels the server could not translate: the client translates the keys.
            { kind: 'removed', slot_key: 'site.footer', slot_label: null, slot_label_key: 'widget_site_layout.slot.site_footer', widget_name: 'Liên hệ', scope_type: 'target', scope_label: null },
            { kind: 'changed', slot_key: 'site.header', slot_label: 'Đầu trang', slot_label_key: null, widget_name: 'Menu', scope_type: 'template', scope_label: 'Theo loại trang · Trang' },
        ])
        api.publishWidgetLayout.mockResolvedValue({ release: { version: 4 }, draft: { version: 5, lock_version: 0 } })

        const wrapper = mount(AreaPublishDialog, { props: { open: true, layout }, global: globalOptions })
        await flushPromises()

        expect(wrapper.find('#area-publish-title').text()).toBe('Xuất bản thay đổi lên site?')
        const rows = wrapper.findAll('[data-test="publish-change"]').map((row) => row.text())
        expect(rows[0]).toContain('Thêm “Banner” vào Đầu trang')
        expect(rows[0]).toContain('Toàn website')
        expect(rows[1]).toContain('Gỡ “Liên hệ” khỏi Chân trang')
        expect(rows[1]).toContain('Riêng trang này')
        expect(rows[2]).toContain('Sửa “Menu” ở Đầu trang')
        expect(wrapper.text()).not.toMatch(/site\.header|widget_site_layout\.|lock|v\d/)

        await wrapper.find('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(api.publishWidgetLayout).toHaveBeenCalledWith(
            'layout-1',
            { expected_draft_id: 4, expected_lock_version: 3 },
            expect.objectContaining({ idempotencyKey: expect.any(String) })
        )
        expect(wrapper.emitted('published')).toHaveLength(1)
    })

    it('blocked chip on placement', async () => {
        api.getWidgetLayoutDraft.mockResolvedValue(
            draft({
                placements: [placement(), placement({ uuid: 'pl-2', widget_instance_uuid: 'inst-2', position: 1 })],
                contents: { 'inst-1:1': content(), 'inst-2:1': content({ widget_id: 6, widget_name: 'Bộ đếm cũ', is_blocked: true }) },
            })
        )

        const wrapper = await mountPage()

        const chip = wrapper.find('[data-test="structure-blocked-site.header::global::inst-2"]')
        expect(chip.text()).toBe('Đang bị chặn')
        expect(wrapper.find('[data-test="structure-blocked-site.header::global::inst-1"]').exists()).toBe(false)
        // The name keeps a minimum width, so the chips wrap to the next line before the name is cut inside a word.
        const name = wrapper.find('[data-test="structure-placement-site.header::global::inst-2"] > span')
        expect(name.text()).toBe('Bộ đếm cũ')
        expect(name.classes()).toContain('min-w-32')
        expect(name.classes()).not.toContain('min-w-0')

        // Opening it says why it is not on the site.
        await wrapper.find('[data-test="structure-placement-site.header::global::inst-2"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="inspector-blocked-note"]').text()).toContain('không hiện trên website')
        expect(wrapper.find('[data-test="area-edit-drawer"]').exists()).toBe(true)
        const footer = wrapper.find('[data-test="drawer-footer"]').text()
        expect(footer).toMatch(/Lưu.*Nhân bản.*Gỡ khỏi khu vực/s)
        wrapper.unmount()
    })

    it('nav hidden when no areas', async () => {
        expect(siteLayoutNavigationConfig.children).toBeUndefined()
        expect(siteLayoutNavigationConfig.text).toBe('nav.a.site-layout')
        expect(copy.t('nav.a.site-layout')).toBe('Khu vực chung')

        api.getAreaCount.mockResolvedValue(0)
        expect(siteLayoutNavigationConfig.hidden()).toBe(true) // unknown yet: hidden, and asked once
        await flushPromises()
        expect(siteLayoutNavigationConfig.hidden()).toBe(true)
        expect(api.getAreaCount).toHaveBeenCalledTimes(1)

        resetAreaCount()
        api.getAreaCount.mockResolvedValue(3)
        expect(siteLayoutNavigationConfig.hidden()).toBe(true)
        await flushPromises()
        expect(siteLayoutNavigationConfig.hidden()).toBe(false)
    })

    it('a view-only role is told why nothing can be changed, and why there is no preview', async () => {
        const editor = await mountPage()
        expect(editor.find('[data-test="read-only-notice"]').exists()).toBe(false)
        expect(editor.find('[data-test="no-preview-notice"]').exists()).toBe(false)
        editor.unmount()

        permissions.denied = new Set(['widget_layout.update', 'widget_layout.preview'])
        const viewer = await mountPage()
        expect(viewer.get('[data-test="read-only-notice"]').text()).toBe(copy.t('widget_site_layout.area.read_only'))
        expect(copy.t('widget_site_layout.area.read_only')).toContain('chỉ xem được')
        expect(viewer.get('[data-test="no-preview-notice"]').text()).toBe(copy.t('widget_site_layout.area.no_preview'))
        expect(viewer.find('[data-test^="structure-add-"]').exists()).toBe(false)
    })

    it('shows the no-area sentence instead of the editor when the theme has no shared area', async () => {
        api.listSlotsWithAreaCount.mockResolvedValue({ slots: [], areaCount: 0 })

        const wrapper = await mountPage()

        expect(wrapper.find('[data-test="no-areas-state"]').text()).toBe('Giao diện hiện tại chưa có khu vực chung.')
        expect(wrapper.find('[data-test="save-bar"]').exists()).toBe(false)
        expect(api.bootstrapWidgetLayoutByKey).not.toHaveBeenCalled()
        wrapper.unmount()
    })

    it('saves an edit through the placement content route and reloads the draft when the answer says it moved', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-test="structure-placement-site.header::global::inst-1"]').trigger('click')
        await flushPromises()
        await wrapper.find('[data-test="area-edit-drawer"] [data-test="fields-stub"]').trigger('click')

        // A retry after a success answers 409/404: the draft is read again instead of reporting a blind failure.
        api.updateWidgetLayoutDraftPlacementContent.mockRejectedValueOnce({ status: 404, code: null, message: 'Not found.' })
        api.getWidgetLayoutDraft.mockResolvedValueOnce(draft({ lock_version: 5, contents: { 'inst-1:1': content({ values: { title: 'Chào mừng' } }) } }))
        await wrapper.find('[data-test="area-edit-save"]').trigger('click')
        await flushPromises()

        expect(api.updateWidgetLayoutDraftPlacementContent).toHaveBeenCalledWith(
            'layout-1',
            'pl-1',
            { values: { title: 'Chào mừng' }, expected_draft_revision: 3 },
            expect.objectContaining({ idempotencyKey: expect.any(String) })
        )
        expect(api.getWidgetLayoutDraft).toHaveBeenCalledTimes(2)
        expect(iAlertMock).toHaveBeenCalledWith(expect.stringContaining('Đã tải lại bản mới nhất'), true)
        expect(iAlertMock).not.toHaveBeenCalledWith(expect.stringContaining('Not found'), expect.anything())
        expect(api.updateWidgetInstance).not.toHaveBeenCalled()
        wrapper.unmount()
    })

    it('duplicates and removes without the instance routes; removing autosaves the draft', async () => {
        vi.useFakeTimers()
        try {
            const wrapper = await mountPage()
            await wrapper.find('[data-test="structure-placement-site.header::global::inst-1"]').trigger('click')
            await flushPromises()

            api.duplicateWidgetLayoutDraftPlacement.mockResolvedValue({
                ...draft({
                    lock_version: 4,
                    placements: [placement(), placement({ uuid: 'pl-2', widget_instance_uuid: 'inst-2', position: 1 })],
                    contents: { 'inst-1:1': content(), 'inst-2:1': content() },
                }),
                placementUuid: 'pl-2',
            })
            await wrapper.find('[data-test="area-edit-duplicate"]').trigger('click')
            await flushPromises()
            expect(api.duplicateWidgetLayoutDraftPlacement).toHaveBeenCalledWith(
                'layout-1',
                'pl-1',
                { expected_draft_revision: 3 },
                expect.objectContaining({ idempotencyKey: expect.any(String) })
            )
            expect(wrapper.find('[data-test="structure-count-site.header"]').text()).toBe('2')

            await wrapper.find('[data-test="structure-placement-site.header::global::inst-2"]').trigger('click')
            await flushPromises()
            api.saveWidgetLayoutDraft.mockResolvedValue([placement()])
            await wrapper.find('[data-test="area-edit-remove"]').trigger('click')
            await flushPromises()
            expect(wrapper.find('[data-test="structure-count-site.header"]').text()).toBe('1')
            expect(api.saveWidgetLayoutDraft).not.toHaveBeenCalled()

            await vi.advanceTimersByTimeAsync(1000)
            await flushPromises()
            expect(api.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)
            expect(api.saveWidgetLayoutDraft.mock.calls[0][1].placements).toHaveLength(1)
            expect(api.cloneWidgetInstance).not.toHaveBeenCalled()
            expect(api.archiveWidgetInstance).not.toHaveBeenCalled()
            expect(api.deleteWidgetInstance).not.toHaveBeenCalled()
            wrapper.unmount()
        } finally {
            vi.useRealTimers()
        }
    })

    it('a widget removed while a save is in flight stays removed and is sent by a follow-up save', async () => {
        const layout = await readyLayout()
        const first = placement()
        const second = placement({ uuid: 'pl-2', widget_instance_uuid: 'inst-2', position: 1 })
        const third = placement({ uuid: 'pl-3', widget_instance_uuid: 'inst-3', position: 2 })
        layout.updateLocalDraft([second, first, third].map((item, position) => ({ ...item, position })))

        let release: (value: unknown) => void = () => {}
        api.saveWidgetLayoutDraft.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)))
        const slowSave = layout.saveDraft()
        await flushPromises()

        // The user removes a widget while the first save is still on its way.
        layout.updateLocalDraft([second, first].map((item, position) => ({ ...item, position })))
        release([second, first, third].map((item, position) => ({ ...item, uuid: `saved-${position}`, position })))
        await slowSave

        // Not "saved" yet: the removal is still pending, on the new revision.
        expect(layout.hasUnsavedChanges()).toBe(true)
        expect(layout.state.localDraft.placements).toHaveLength(2)
        expect(layout.state.localDraft.lockVersion).toBe(4)

        api.saveWidgetLayoutDraft.mockResolvedValueOnce([second, first].map((item, position) => ({ ...item, position })))
        await layout.saveDraft()

        expect(api.saveWidgetLayoutDraft).toHaveBeenCalledTimes(2)
        const followUp = api.saveWidgetLayoutDraft.mock.calls[1][1]
        expect(followUp.expected_lock_version).toBe(4)
        expect(followUp.placements.map((item: { widget_instance_uuid: string }) => item.widget_instance_uuid)).toEqual(['inst-2', 'inst-1'])
        expect(layout.hasUnsavedChanges()).toBe(false)
    })

    it('a change of where a widget shows made during a slow save is carried by the follow-up save; the chip never says saved meanwhile', async () => {
        vi.useFakeTimers()
        try {
            api.getWidgetLayoutDraft.mockResolvedValue(
                draft({
                    placements: [placement(), placement({ uuid: 'pl-2', widget_instance_uuid: 'inst-2', position: 1 })],
                    contents: { 'inst-1:1': content(), 'inst-2:1': content({ widget_name: 'Menu' }) },
                })
            )
            const wrapper = await mountPage()

            // Reorder, and let the autosave start a save that stays in flight.
            let release: (value: unknown) => void = () => {}
            api.saveWidgetLayoutDraft.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)))
            await wrapper.find('[data-test="structure-move-down-site.header::global::inst-1"]').trigger('click')
            await vi.advanceTimersByTimeAsync(1000)
            expect(api.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)

            // Meanwhile: "Hiển thị ở đâu" of the first widget becomes a page type, then "Lưu".
            await wrapper.find('[data-test="structure-placement-site.header::global::inst-1"]').trigger('click')
            await flushPromises()
            await wrapper.find('[data-test="area-edit-drawer"] [data-test="placement-scope-template"]').setValue(true)
            await wrapper.find('[data-test="placement-page-type-select"]').setValue('page.static')
            api.saveWidgetLayoutDraft.mockImplementationOnce((_uuid: string, body: { placements: unknown[] }) => Promise.resolve(body.placements))
            await wrapper.find('[data-test="area-edit-save"]').trigger('click')
            await flushPromises()

            release([
                placement({ uuid: 'pl-2b', widget_instance_uuid: 'inst-2', position: 0 }),
                placement({ uuid: 'pl-1b', position: 1 }),
            ])
            await flushPromises()
            await flushPromises()

            expect(api.saveWidgetLayoutDraft).toHaveBeenCalledTimes(2)
            const followUp = api.saveWidgetLayoutDraft.mock.calls[1][1]
            expect(followUp.expected_lock_version).toBe(4)
            const moved = followUp.placements.find((item: { widget_instance_uuid: string }) => item.widget_instance_uuid === 'inst-1')
            expect(moved).toMatchObject({ scope_type: 'template', scope_key: 'page.static' })
            expect(iAlertMock).toHaveBeenCalledWith('Đã lưu. Bấm Xuất bản để đưa lên website.')
            wrapper.unmount()
        } finally {
            vi.useRealTimers()
        }
    })

    it('the status chip shows a pending change, never "saved", while a change made during a save is waiting', async () => {
        const layout = await readyLayout()
        const SaveBar = (await import('../../../../cms/Widget/admin/components/widget-layout-save-bar.vue')).default
        const wrapper = mount(SaveBar, { props: { layout }, global: globalOptions })
        const second = placement({ uuid: 'pl-2', widget_instance_uuid: 'inst-2', position: 1 })

        layout.updateLocalDraft([placement(), second])
        let release: (value: unknown) => void = () => {}
        api.saveWidgetLayoutDraft.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)))
        const slowSave = layout.saveDraft()
        layout.updateLocalDraft([placement()])
        release([placement(), second])
        await slowSave
        await flushPromises()

        expect(wrapper.find('[data-test="save-bar-state-dirty"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="save-bar-state-clean"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="unpublished-chip"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="command-publish"]').attributes('disabled')).toBeDefined()
    })

    it('a failed save before a placement request is reported as a failed save, not as someone else\'s change', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([])
        api.saveWidgetLayoutDraft.mockRejectedValueOnce({ status: null, code: null, message: 'Network Error' })

        const result = await layout.duplicatePlacement('pl-1')

        expect(result).toMatchObject({ ok: false, reason: 'error' })
        expect(api.duplicateWidgetLayoutDraftPlacement).not.toHaveBeenCalled()
        expect(api.getWidgetLayoutDraft).toHaveBeenCalledTimes(1) // nothing was reloaded
    })

    it('shows the unpublished-changes chip and opens the publish dialog from the command bar', async () => {
        api.getWidgetLayoutDraftChanges.mockResolvedValue([
            { kind: 'added', slot_key: 'site.header', slot_label: 'Đầu trang', widget_name: 'Banner', scope_type: 'global', scope_label: 'Toàn website' },
            { kind: 'changed', slot_key: 'site.header', slot_label: 'Đầu trang', widget_name: 'Menu', scope_type: 'global', scope_label: 'Toàn website' },
        ])

        const wrapper = await mountPage()

        expect(wrapper.find('[data-test="unpublished-chip"]').text()).toBe('● 2 thay đổi chưa xuất bản')
        const publish = wrapper.find('[data-test="command-publish"]')
        expect(publish.text()).toBe('Xuất bản…')
        await publish.trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-test="publish-change"]')).toHaveLength(2)
        wrapper.unmount()
    })

    it('opens the add drawer on the content step for a widget handed over by the library', async () => {
        routerState.query = { add_widget: '5', theme: '9', keep: '1' }
        api.listSlotsWithAreaCount.mockResolvedValue({ slots: [SLOTS[0]], areaCount: 1 })

        const wrapper = await mountPage()
        await flushPromises()

        expect(routerState.replace).toHaveBeenCalledWith({ query: { keep: '1' } })
        expect(wrapper.find('[data-test="area-add-step-content"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="area-add-step-content"]').text()).toContain('Banner')
        wrapper.unmount()
    })

    it('a handed-over widget with no design areas accept stays on step 1 with the reason', async () => {
        const layout = await readyLayout()
        // Widget 77 is not in the placeable list: all its designs belong to another project (a Default design would be
        // listed, as areas accept what pages accept).
        const wrapper = mount(AreaAddDrawer, {
            props: { open: true, slot: SLOTS[0], layout, targetContexts: [], preset: { widgetId: 77, themeId: 700 } },
            global: globalOptions,
        })
        await flushPromises()

        expect(wrapper.get('[data-test="area-add-notice"]').text()).toBe(
            'Widget này chưa có kiểu hiển thị nào dùng được cho khu vực chung trên site này. Bạn hãy chọn widget khác bên dưới.'
        )
        expect(wrapper.find('[data-test="area-add-step-content"]').exists()).toBe(false)
        // The other widgets are still on offer; choosing one clears the notice.
        await wrapper.find('[data-test="area-add-widget-5"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="area-add-notice"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="area-add-step-content"]').exists()).toBe(true)
    })

    it('a handed-over design areas do not accept is named as replaced, and another design can be picked', async () => {
        const layout = await readyLayout()
        api.listPlaceableWidgets.mockResolvedValue([
            {
                id: 5,
                name: 'Banner',
                is_blocked: false,
                version: 2,
                template: [{ id: 9, name: 'Mặc định' }, { id: 10, name: 'Rộng' }],
                inputs: [{ name: 'title', type: 'text' }],
            },
        ])
        api.createWidgetLayoutDraftPlacement.mockResolvedValue({ ...draft({ lock_version: 4 }), placementUuid: 'pl-2' })
        // Design 300 belongs to the widget but to another project, so a shared area here does not accept it.
        const wrapper = mount(AreaAddDrawer, {
            props: { open: true, slot: SLOTS[0], layout, targetContexts: [], preset: { widgetId: 5, themeId: 300 } },
            global: globalOptions,
        })
        await flushPromises()

        expect(wrapper.get('[data-test="area-add-notice"]').text()).toBe(
            'Kiểu hiển thị bạn chọn chưa dùng được cho khu vực chung trên site này. Đang dùng kiểu “Mặc định”; bạn có thể đổi kiểu khác ở bên dưới.'
        )
        const designs = wrapper.get('[data-test="placement-design-select"]')
        expect(designs.text()).toMatch(/Mặc định.*Rộng/s)

        // Picking a design is the user's own choice: the notice goes, and that design is what gets placed.
        await designs.setValue('10')
        await flushPromises()
        expect(wrapper.find('[data-test="area-add-notice"]').exists()).toBe(false)
        await wrapper.find('[data-test="area-add-next"]').trigger('click')
        await wrapper.find('[data-test="area-add-submit"]').trigger('click')
        await flushPromises()
        expect(api.createWidgetLayoutDraftPlacement.mock.calls[0][1]).toMatchObject({ widget_id: 5, widget_theme_id: 10 })
    })

    it('a handed-over design areas accept opens without any notice', async () => {
        const layout = await readyLayout()
        const wrapper = mount(AreaAddDrawer, {
            props: { open: true, slot: SLOTS[0], layout, targetContexts: [], preset: { widgetId: 5, themeId: 9 } },
            global: globalOptions,
        })
        await flushPromises()
        expect(wrapper.find('[data-test="area-add-step-content"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="area-add-notice"]').exists()).toBe(false)
    })

    it('a change made by someone else leaves one way out: load the latest version', async () => {
        vi.useFakeTimers()
        try {
            const wrapper = await mountPage()
            api.saveWidgetLayoutDraft.mockRejectedValue({ status: 409, code: 'WIDGET_DRAFT_STALE', message: 'The draft changed.' })
            api.getWidgetLayout.mockResolvedValue({ uuid: 'layout-1', current_draft: { version: 4, lock_version: 8 } })

            // A site copy moved the area to another widget meanwhile; this user removes the widget.
            await wrapper.find('[data-test="structure-placement-site.header::global::inst-1"]').trigger('click')
            await flushPromises()
            await wrapper.find('[data-test="area-edit-remove"]').trigger('click')
            await vi.advanceTimersByTimeAsync(1000)
            await flushPromises()

            const panel = wrapper.get('[data-test="save-conflict-panel"]')
            expect(panel.text()).toContain('Khu vực chung vừa được người khác thay đổi. Hãy tải lại bản mới nhất')
            expect(panel.text()).not.toContain('giữ thay đổi')
            expect(wrapper.find('[data-test="conflict-keep-local"]').exists()).toBe(false)
            expect(panel.findAll('button')).toHaveLength(1)

            // Nothing is sent again on its own while the panel waits.
            await vi.advanceTimersByTimeAsync(3000)
            await flushPromises()
            expect(api.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)

            api.getWidgetLayoutDraft.mockResolvedValue(
                draft({ lock_version: 8, placements: [placement({ widget_instance_uuid: 'inst-9' })], contents: { 'inst-9:1': content() } })
            )
            api.bootstrapWidgetLayoutByKey.mockResolvedValue({
                uuid: 'layout-1',
                key: 'site',
                current_draft: { version: 4, lock_version: 8 },
                current_published: { version: 3 },
            })
            await wrapper.get('[data-test="conflict-reload"]').trigger('click')
            await flushPromises()
            expect(wrapper.find('[data-test="save-conflict-panel"]').exists()).toBe(false)
            // The other change is what is on screen now, untouched.
            expect(wrapper.find('[data-test="structure-placement-site.header::global::inst-9"]').exists()).toBe(true)
            wrapper.unmount()
        } finally {
            vi.useRealTimers()
        }
    })

    it('history shows local time, publisher, the live chip and restore', async () => {
        const layout = await readyLayout()
        api.listWidgetLayoutReleases.mockResolvedValue({
            data: [
                { version: 3, published_at: '2026-11-21T05:30:00Z', published_by: 7, published_by_name: 'Chị Lan', is_live: true },
                { version: 2, published_at: '2026-11-20T02:00:00Z', published_by: 7, published_by_name: 'Anh Minh', is_live: false },
            ],
        })

        const wrapper = mount(WidgetLayoutReleaseDrawer, { props: { layout, open: true }, global: globalOptions })
        await flushPromises()

        expect(wrapper.find('[data-test="release-time-3"]').text()).toBe(formatLocalDateTime('2026-11-21T05:30:00Z'))
        expect(wrapper.text()).not.toContain('2026-11-21T05:30:00Z')
        expect(wrapper.find('[data-test="release-live-3"]').text()).toBe('Đang chạy')
        expect(wrapper.find('[data-test="release-by-2"]').text()).toBe('bởi Anh Minh')
        expect(wrapper.find('[data-test="rollback-to-version-3"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="rollback-to-version-2"]').text()).toBe('Khôi phục')
    })

    it('old widget and template routes land on the widget library', () => {
        const child = (name: string) => siteLayoutRouteConfig.children.find((route: { name?: string }) => route.name === name)

        expect(child('site-layout-widgets').redirect).toEqual({ name: 'widget-library' })
        expect(child('site-layout-templates').redirect).toEqual({ name: 'widget-library' })
        expect(child('site-layout-widgets').load).toBeUndefined()
        expect(child('site-layout-placements').meta.rule).toContain('widget_layout.update')
    })
})
