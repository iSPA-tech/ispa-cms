/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Accessibility of the shared pieces of the "Khu vực chung" editor: the slide-over drawer (focus capture and
// return, Escape, backdrop) and the preview's status announcer. The assembled page is covered by
// src/__tests__/widget/area-editor.spec.ts. jsdom has no layout engine, so rendered-pixel checks (no horizontal
// overflow at narrow widths) need a browser.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, nextTick } from 'vue'

// ---------------------------------------------------------------------------
// Shared, hoisted mocks. Union of every api.js export transitively reachable
// from the assembled page (Catalog, Canvas + its Target Picker, Inspector +
// its Target Picker, Context Toolbar + its Target Picker, Preview, Save Bar,
// Release Drawer + Rollback Dialog) -- mirrors the SAME
// vi.hoisted/vi.mock('../../../../cms/Widget/admin/api', ...) precedent every
// other spec file in this directory already uses.
// ---------------------------------------------------------------------------
const permissionState = vi.hoisted(() => ({
    denied: new Set<string>(),
}))

const apiMocks = vi.hoisted(() => ({
    listSlots: vi.fn(),
    listWidgetInstances: vi.fn(),
    listTargetContexts: vi.fn(),
    searchWidgetTargets: vi.fn(),
    getWidgetTargetDetail: vi.fn(),
    listWidgetDefinitionCatalog: vi.fn(),
    getWidgetInstanceUsage: vi.fn(),
    listWidgetLayoutReleases: vi.fn(),
    bootstrapWidgetLayoutByKey: vi.fn(),
    listWidgetLayouts: vi.fn(),
    getWidgetLayoutDraft: vi.fn(),
    saveWidgetLayoutDraft: vi.fn(),
    getWidgetLayout: vi.fn(),
    createWidgetLayoutPreviewSession: vi.fn(),
    publishWidgetLayout: vi.fn(),
    rollbackWidgetLayout: vi.fn(),
    createWidgetInstance: vi.fn(),
    updateWidgetInstance: vi.fn(),
    cloneWidgetInstance: vi.fn(),
    archiveWidgetInstance: vi.fn(),
    deleteWidgetInstance: vi.fn(),
    repinWidgetLayoutDraftInstance: vi.fn(),
    blockWidgetManifestRuntime: vi.fn(),
    clearWidgetManifestRuntimeBlock: vi.fn(),
}))

vi.mock('../../../../cms/Widget/admin/api', () => apiMocks)

vi.mock('@/mixins', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...actual,
        can: (a: unknown, b?: string, c?: string) => {
            const key = c !== undefined ? `${a}.${b}.${c}` : b !== undefined ? `${a}.${b}` : String(a)
            return !permissionState.denied.has(key)
        },
        iAlert: vi.fn(),
    }
})

import WidgetLayoutPreview from '../../../../cms/Widget/admin/components/widget-layout-preview.vue'
import WidgetLayoutDrawer from '../../../../cms/Widget/admin/components/widget-layout-drawer.vue'

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const slotFixture = (overrides = {}) => ({
    key: 'site_header_before',
    label_key: 'nav.a.site-layout-slot-header-before',
    cardinality: 'stack',
    supported_scope_types: ['global', 'template', 'target'],
    fallback: 'empty',
    trust_lane: 'standard',
    availability: 'available',
    ...overrides,
})

// Matches the REAL WidgetInstanceResource shape (nested current_version
// object) -- the same fixture shape widget-placement-inspector.spec.ts and
// the other site-layout specs already establish, since this page
// mounts the Inspector for real and it reads current_version.widget_id/
// .widget_theme_id/.schema/.values/.version.
const instanceFixture = (overrides = {}) => ({
    uuid: 'instance-1',
    project_name: 'default',
    name: 'Hero Banner',
    status: 'active',
    sync_status: 'ready',
    current_version: {
        version: 3,
        widget_id: 10,
        widget_theme_id: 100,
        renderer_key: 'blade',
        renderer_version: 1,
        trust_classification: 'trusted',
        schema: { title: { type: 'text' } },
        values: { title: 'Hello' },
        provider_contract: null,
        created_at: '2026-01-01T00:00:00Z',
    },
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
})

const placementFixture = (overrides = {}) => ({
    slot_key: 'site_header_before',
    scope_type: 'global',
    scope_key: null,
    target_type: null,
    target_id: null,
    widget_instance_uuid: 'instance-1',
    widget_instance_version: 3,
    position: 0,
    enabled: true,
    rules: {},
    ...overrides,
})

const layoutResourceFixture = (overrides = {}) => ({
    uuid: 'layout-1',
    key: 'site',
    current_draft: { version: 1, lock_version: 1 },
    current_published: null,
    sync_status: 'ready',
    ...overrides,
})

/** Forces useMediaQuery to a known value for the duration of a test -- the
 * ONLY reliable way to drive the width tiers under jsdom, which has no real
 * layout engine. The redesigned page uses TWO queries (min-width: 1440px and
 * 1024px); answering both with the same boolean lands on the 'wide' tier
 * (three columns) or the 'narrow' tier (drawers) -- the 'mid' tier is the
 * same DOM with the inspector drawn as a drawer, covered implicitly. */
function setWideViewport(wide: boolean) {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
        matches: wide,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })) as unknown as typeof window.matchMedia
}

function mockDefaults() {
    vi.clearAllMocks()
    permissionState.denied.clear()
    // The redesigned page persists last-used page/locale/viewport/viewMode --
    // clear it so one test's stored 'list' viewMode never leaks into the next.
    window.localStorage.clear()
    apiMocks.listSlots.mockResolvedValue([slotFixture()])
    apiMocks.listWidgetInstances.mockResolvedValue({
        data: [instanceFixture(), instanceFixture({ uuid: 'instance-2', name: 'Promo Card' })],
        meta: null,
        links: null,
    })
    apiMocks.listTargetContexts.mockResolvedValue([
        { target_type: 'page', label_key: 'nav.a.site-layout-target-page', template_context_key: 'page.static', project_applicable: true },
    ])
    apiMocks.searchWidgetTargets.mockResolvedValue({ data: [], meta: null, links: null })
    apiMocks.getWidgetTargetDetail.mockResolvedValue(null)
    apiMocks.listWidgetDefinitionCatalog.mockResolvedValue({
        data: [{ widget_id: 10, widget_theme_id: 100, name: 'Hero', theme_name: 'Default', label: 'Hero / Default', eligible: true, manifest_hash: 'h', attestation_version: 1, applicability: [] }],
        meta: null,
        links: null,
    })
    apiMocks.getWidgetInstanceUsage.mockResolvedValue({ in_use: false, placement_count: 0, usage_fingerprint: 'fp' })
    apiMocks.listWidgetLayoutReleases.mockResolvedValue({ data: [], meta: null, links: null })
    apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValue(layoutResourceFixture())
    apiMocks.getWidgetLayoutDraft.mockResolvedValue({
        version: 1,
        lock_version: 1,
        placements: [placementFixture()],
        instance_fingerprints: { 'instance-1': 'fp-instance-1' },
    })
    apiMocks.saveWidgetLayoutDraft.mockResolvedValue([placementFixture()])
    apiMocks.getWidgetLayout.mockResolvedValue(layoutResourceFixture())
    apiMocks.createWidgetLayoutPreviewSession.mockResolvedValue({
        preview_url: 'https://example.com/preview/abc',
        expires_at: new Date(Date.now() + 60_000).toISOString(),
        fingerprint: 'preview-fp',
    })
    apiMocks.publishWidgetLayout.mockResolvedValue({
        release: { version: 1 },
        draft: { version: 2, lock_version: 1 },
        sync_status: 'ready',
    })
    apiMocks.rollbackWidgetLayout.mockResolvedValue({
        release: { version: 2 },
        draft: { version: 3, lock_version: 1 },
        sync_status: 'ready',
    })
    apiMocks.repinWidgetLayoutDraftInstance.mockResolvedValue({
        instance: instanceFixture(),
        draft: { version: 1, lock_version: 2, placements: [placementFixture(), placementFixture({ widget_instance_uuid: 'instance-2', enabled: false })] },
        sync_status: 'ready',
    })
    apiMocks.updateWidgetInstance.mockResolvedValue(instanceFixture({ name: 'Hero Banner Updated' }))
    apiMocks.createWidgetInstance.mockResolvedValue(instanceFixture({ uuid: 'instance-new' }))
    apiMocks.cloneWidgetInstance.mockResolvedValue(instanceFixture({ uuid: 'instance-clone' }))
    apiMocks.archiveWidgetInstance.mockResolvedValue(instanceFixture({ status: 'archived' }))
    apiMocks.deleteWidgetInstance.mockResolvedValue({ status: 'success' })
}



// =============================================================================
// widget-layout-drawer.vue -- standalone accessible dialog behavior.
// =============================================================================
describe('widget-layout-drawer.vue (Task 31)', () => {
    const Host = defineComponent({
        components: { WidgetLayoutDrawer },
        props: { open: { type: Boolean, default: false } },
        emits: ['close'],
        template: `
            <div>
                <button data-test="host-trigger" type="button">open</button>
                <widget-layout-drawer :open="open" title="Widgets" @close="$emit('close')">
                    <p data-test="drawer-content">content</p>
                </widget-layout-drawer>
            </div>
        `,
    })

    it('renders nothing when closed', () => {
        const wrapper = mount(Host, { props: { open: false } })
        expect(wrapper.find('[data-test="drawer-panel"]').exists()).toBe(false)
    })

    it('renders an accessible dialog with the given title and slot content when open', () => {
        const wrapper = mount(Host, { props: { open: true } })
        const panel = wrapper.get('[data-test="drawer-panel"]')
        expect(panel.attributes('role')).toBe('dialog')
        expect(panel.attributes('aria-modal')).toBe('true')
        expect(panel.attributes('aria-label')).toBe('Widgets')
        expect(wrapper.find('[data-test="drawer-content"]').exists()).toBe(true)
    })

    it('emits close when the close button or the backdrop is activated', async () => {
        const wrapper = mount(Host, { props: { open: true } })

        await wrapper.get('[data-test="drawer-close"]').trigger('click')
        expect(wrapper.emitted('close')).toHaveLength(1)

        await wrapper.get('[data-test="drawer-backdrop"]').trigger('click')
        expect(wrapper.emitted('close')).toHaveLength(2)
    })

    it('emits close on Escape from within the panel', async () => {
        const wrapper = mount(Host, { props: { open: true } })
        await wrapper.get('[data-test="drawer-panel"]').trigger('keydown.esc')
        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('moves focus into the panel on open and returns it to the trigger on close', async () => {
        const wrapper = mount(Host, { props: { open: false }, attachTo: document.body })
        const trigger = wrapper.get('[data-test="host-trigger"]')
        ;(trigger.element as HTMLElement).focus()
        expect(document.activeElement).toBe(trigger.element)

        await wrapper.setProps({ open: true })
        await flushPromises()
        await nextTick()

        expect(document.activeElement).toBe(wrapper.get('[data-test="drawer-panel"]').element)

        await wrapper.setProps({ open: false })
        await flushPromises()

        expect(document.activeElement).toBe(trigger.element)

        wrapper.unmount()
    })
})

// =============================================================================
// widget-layout-preview.vue -- status announcer (Task 31 addition on top of
// the already-covered Task 29 preview behavior in widget-layout-preview.spec.ts).
// =============================================================================
describe('widget-layout-preview.vue -- status announcer (Task 31)', () => {
    beforeEach(mockDefaults)

    const completeContext = { targetType: 'page', targetId: 'page-1', locale: 'en', templateContext: 'page.static', viewport: 'desktop' }

    it('has a polite live region that announces once a preview mints successfully', async () => {
        const wrapper = mount(WidgetLayoutPreview, {
            props: { layoutUuid: 'layout-1', placements: [placementFixture()], isDraftDirty: false, context: completeContext },
        })
        await flushPromises()

        const announcer = wrapper.get('[data-test="preview-announcer"]')
        expect(announcer.attributes('aria-live')).toBe('polite')
        expect(announcer.text().length).toBeGreaterThan(0)
    })

    it('announces when a preview mint fails', async () => {
        apiMocks.createWidgetLayoutPreviewSession.mockRejectedValue({ response: { status: 422, data: { code: 'WIDGET_PREVIEW_SESSION_EXPIRED', message: 'nope' } } })

        const wrapper = mount(WidgetLayoutPreview, {
            props: { layoutUuid: 'layout-1', placements: [placementFixture()], isDraftDirty: false, context: completeContext },
        })
        await flushPromises()

        expect(wrapper.find('[data-test="preview-recovery"]').exists()).toBe(true)
        expect(wrapper.get('[data-test="preview-announcer"]').text().length).toBeGreaterThan(0)
    })
})
