/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 29: signed full-page preview UI. PART A covers
// site-layout-context-toolbar.vue (project/Template Context/Target Context/
// locale/viewport selection surface for the preview). PART B covers
// widget-layout-preview.vue (the sandboxed iframe consuming Task 25's
// createWidgetLayoutPreviewSession wrapper directly -- read-only-from-the-
// draft-state-machine's point of view, matching the SAME self-contained
// "call api.js directly" precedent widget-target-picker.vue/
// widget-placement-inspector.vue already established, rather than routing
// through use-widget-site-layout.js's own requestPreview() orchestration,
// which is a THIN pass-through the shared composable's fingerprint guard
// already covers at the reducer level (tests/components/site-layout/
// widget-layout-draft.spec.ts) and whose `lastError` field is shared/
// overloaded across bootstrap/save/publish/rollback -- too coarse a signal
// for THIS component's own "clean recovery, never a broken frame" contract.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const apiMocks = vi.hoisted(() => ({
    listTargetContexts: vi.fn(),
    searchWidgetTargets: vi.fn(),
    getWidgetTargetDetail: vi.fn(),
    createWidgetLayoutPreviewSession: vi.fn(),
}))

vi.mock('../../../../cms/Widget/admin/api', () => apiMocks)

import SiteLayoutContextToolbar from '../../../../cms/Widget/admin/components/site-layout-context-toolbar.vue'
import WidgetTargetPicker from '../../../../cms/Widget/admin/components/widget-target-picker.vue'
import WidgetLayoutPreview from '../../../../cms/Widget/admin/components/widget-layout-preview.vue'

const targetContexts = () => [
    { target_type: 'page', label_key: 'nav.a.pages', template_context_key: 'page.static', project_applicable: true },
    {
        target_type: 'global_setting',
        label_key: 'nav.a.site-settings',
        template_context_key: 'site.shell',
        project_applicable: false,
    },
]

const placement = (overrides = {}) => ({
    uuid: 'placement-uuid-1',
    slot_key: 'site_header_before',
    scope_type: 'global',
    scope_key: null,
    target_type: null,
    target_id: null,
    widget_instance_uuid: 'instance-1',
    widget_instance_version: 1,
    position: 0,
    enabled: true,
    rules: {},
    ...overrides,
})

// =============================================================================
// PART A -- site-layout-context-toolbar.vue
// =============================================================================

describe('site-layout-context-toolbar.vue', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        apiMocks.listTargetContexts.mockResolvedValue(targetContexts())
    })

    // Redesign: the host page owns the ONE listTargetContexts() fetch and
    // passes it down; the toolbar (and its embedded picker) never re-fetch.
    const mountToolbar = (props = {}) =>
        mount(SiteLayoutContextToolbar, { props: { modelValue: {}, targetContexts: targetContexts(), ...props } })

    /** The page control is now a chip + popover; open it to reach the
     * embedded target picker. */
    const openPagePopover = async (wrapper: ReturnType<typeof mountToolbar>) => {
        await wrapper.get('[data-test="context-page-chip"]').trigger('click')
    }

    it('never re-fetches Target Contexts itself when the host provides them', async () => {
        const wrapper = mountToolbar()
        await flushPromises()
        await openPagePopover(wrapper)
        await flushPromises()

        expect(apiMocks.listTargetContexts).not.toHaveBeenCalled()
    })

    it('renders every control (page chip, locale, viewport) under one root; the picker lives in the chip popover', async () => {
        const wrapper = mountToolbar()
        await flushPromises()

        expect(wrapper.findAll('[data-test="context-toolbar"]')).toHaveLength(1)
        const root = wrapper.get('[data-test="context-toolbar"]')
        expect(root.find('[data-test="context-page-chip"]').exists()).toBe(true)
        expect(root.find('[data-test="context-locale"]').exists()).toBe(true)
        expect(root.find('[data-test="context-viewport-desktop"]').exists()).toBe(true)
        expect(root.find('[data-test="context-viewport-mobile"]').exists()).toBe(true)

        await openPagePopover(wrapper)
        expect(root.find('[data-test="target-picker-input"]').exists()).toBe(true)
    })

    it('derives Template Context and a read-only project-scope indicator from the selected Target Context type, never emitting a raw project field', async () => {
        const wrapper = mountToolbar()
        await flushPromises()
        await openPagePopover(wrapper)

        const picker = wrapper.findComponent(WidgetTargetPicker)
        picker.vm.$emit('update:modelValue', { target_type: 'page', target_id: 'uuid-a', label: 'About Us' })
        await flushPromises()

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeTruthy()
        const payload = emitted![emitted!.length - 1][0] as Record<string, unknown>
        expect(payload.targetType).toBe('page')
        expect(payload.targetId).toBe('uuid-a')
        expect(payload.templateContext).toBe('page.static')
        expect(payload).not.toHaveProperty('project')
        expect(payload).not.toHaveProperty('project_name')

        // Technical context moved into the details disclosure popover.
        await wrapper.get('[data-test="context-details-toggle"]').trigger('click')
        expect(wrapper.get('[data-test="context-project-badge"]').text()).toBe(
            'nav.a.site-layout-context-project-scoped'
        )
    })

    it('shows the global-context indicator when the selected Target Context type is not project-applicable', async () => {
        const wrapper = mountToolbar()
        await flushPromises()
        await openPagePopover(wrapper)

        const picker = wrapper.findComponent(WidgetTargetPicker)
        picker.vm.$emit('update:modelValue', { target_type: 'global_setting', target_id: 'gs-1', label: 'Global Setting' })
        await flushPromises()

        const emitted = wrapper.emitted('update:modelValue')
        const payload = emitted![emitted!.length - 1][0] as Record<string, unknown>
        expect(payload.templateContext).toBe('site.shell')

        await wrapper.get('[data-test="context-details-toggle"]').trigger('click')
        expect(wrapper.get('[data-test="context-project-badge"]').text()).toBe(
            'nav.a.site-layout-context-project-global'
        )
    })

    it('emits a locale change from the selector', async () => {
        const wrapper = mountToolbar()
        await flushPromises()

        const select = wrapper.get('[data-test="context-locale"]')
        expect(select.attributes('aria-label')).toBeTruthy()
        await select.setValue('en')

        const emitted = wrapper.emitted('update:modelValue')
        const payload = emitted![emitted!.length - 1][0] as Record<string, unknown>
        expect(payload.locale).toBe('en')
    })

    it('emits a viewport change from the responsive control surface', async () => {
        const wrapper = mountToolbar()
        await flushPromises()

        await wrapper.get('[data-test="context-viewport-mobile"]').trigger('click')

        const emitted = wrapper.emitted('update:modelValue')
        const payload = emitted![emitted!.length - 1][0] as Record<string, unknown>
        expect(payload.viewport).toBe('mobile')
        expect(wrapper.get('[data-test="context-viewport-mobile"]').attributes('aria-pressed')).toBe('true')
        expect(wrapper.get('[data-test="context-viewport-desktop"]').attributes('aria-pressed')).toBe('false')
    })
})

// =============================================================================
// PART B -- widget-layout-preview.vue
// =============================================================================

describe('widget-layout-preview.vue', () => {
    const baseContext = () => ({
        targetType: 'page',
        targetId: 'uuid-a',
        templateContext: 'page.static',
        locale: 'vi',
        viewport: 'desktop',
    })

    const mintResponse = (overrides = {}) => ({
        preview_url: 'https://preview.example/session-a',
        expires_at: '2026-07-18T00:05:00+07:00',
        fingerprint: 'fp-a',
        diagnostics: { project_name: 'ExampleSite' },
        ...overrides,
    })

    beforeEach(() => {
        vi.clearAllMocks()
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-07-18T00:00:00+07:00'))
        apiMocks.createWidgetLayoutPreviewSession.mockResolvedValue(mintResponse())
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    const mountPreview = (props = {}) =>
        mount(WidgetLayoutPreview, {
            props: {
                layoutUuid: 'layout-uuid-1',
                placements: [placement()],
                isDraftDirty: false,
                context: baseContext(),
                ...props,
            },
        })

    it('shows a placeholder and never mints while there is no layout yet', async () => {
        const wrapper = mountPreview({ layoutUuid: null })
        await flushPromises()

        expect(apiMocks.createWidgetLayoutPreviewSession).not.toHaveBeenCalled()
        expect(wrapper.find('[data-test="preview-placeholder"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(false)
    })

    it('without a picked page it previews the home page: no target field is sent', async () => {
        const wrapper = mountPreview({ context: { ...baseContext(), targetId: null } })
        await flushPromises()

        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(1)
        const body = apiMocks.createWidgetLayoutPreviewSession.mock.calls[0][1]
        expect(body).not.toHaveProperty('target_id')
        expect(body).not.toHaveProperty('target_type')
        expect(body).not.toHaveProperty('template_context')
        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(true)
    })

    it('mints a preview session once the context is complete and renders the returned preview_url', async () => {
        const wrapper = mountPreview()
        await flushPromises()

        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(1)
        const [uuid, body] = apiMocks.createWidgetLayoutPreviewSession.mock.calls[0]
        expect(uuid).toBe('layout-uuid-1')
        expect(body.locale).toBe('vi')
        expect(body.template_context).toBe('page.static')
        expect(body.target_type).toBe('page')
        expect(body.target_id).toBe('uuid-a')
        expect(body.viewport).toBe('desktop')

        const frame = wrapper.get('[data-test="preview-frame"]')
        expect(frame.attributes('src')).toBe('https://preview.example/session-a')
    })

    it('never includes credentials or an extra project field in the mint request body', async () => {
        mountPreview()
        await flushPromises()

        const [, body] = apiMocks.createWidgetLayoutPreviewSession.mock.calls[0]
        expect(Object.keys(body).sort()).toEqual([
            'locale',
            'overlay',
            'target_id',
            'target_type',
            'template_context',
            'viewport',
        ])
    })

    it('iframe carries the exact required security attributes', async () => {
        const wrapper = mountPreview()
        await flushPromises()

        const frame = wrapper.get('[data-test="preview-frame"]')
        expect(frame.attributes('title')).toBeTruthy()
        const sandbox = frame.attributes('sandbox') ?? ''
        expect(sandbox).not.toContain('allow-scripts')
        expect(sandbox).not.toContain('allow-same-origin')
        expect(sandbox).not.toContain('allow-forms')
        expect(sandbox).not.toContain('allow-top-navigation')
        expect(frame.attributes('referrerpolicy')).toBe('no-referrer')
    })

    it('persisted-Instance-only contract: builds the overlay strictly from the persisted placements prop, dropping any row without a resolvable persisted instance reference', async () => {
        mountPreview({
            placements: [
                placement({ widget_instance_uuid: 'instance-1', position: 0 }),
                // widget_instance_uuid IS a frozen field on the real read path
                // (WidgetLayoutDraftController::placementPayload()), but this
                // defensive filter must still hold for any malformed/partial row
                // this component is handed -- must never crash and must never
                // ship a half-invalid overlay row.
                { ...placement({ position: 1 }), widget_instance_uuid: undefined },
            ],
        })
        await flushPromises()

        const [, body] = apiMocks.createWidgetLayoutPreviewSession.mock.calls[0]
        expect(body.overlay).toHaveLength(1)
        expect(body.overlay[0].widget_instance_uuid).toBe('instance-1')
        expect(body.overlay[0]).not.toHaveProperty('values')
    })

    it('stale-response guard: a late-resolving superseded mint response never overwrites the newer preview_url', async () => {
        let resolveFirst!: (value: unknown) => void
        apiMocks.createWidgetLayoutPreviewSession.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve
                })
        )
        const wrapper = mountPreview()
        await flushPromises() // first mint in flight, deliberately left unresolved

        apiMocks.createWidgetLayoutPreviewSession.mockResolvedValueOnce(
            mintResponse({ preview_url: 'https://preview.example/session-b', fingerprint: 'fp-b' })
        )
        await wrapper.setProps({ context: { ...baseContext(), locale: 'en' } }) // supersedes the first
        await flushPromises()

        expect(wrapper.get('[data-test="preview-frame"]').attributes('src')).toBe(
            'https://preview.example/session-b'
        )

        // The stale first request finally resolves AFTER the second already won.
        resolveFirst(mintResponse({ preview_url: 'https://preview.example/session-a-stale', fingerprint: 'fp-a' }))
        await flushPromises()

        expect(wrapper.get('[data-test="preview-frame"]').attributes('src')).toBe(
            'https://preview.example/session-b'
        )
    })

    it('stays visible but marked stale after a draft edit, without blanking the iframe', async () => {
        const wrapper = mountPreview()
        await flushPromises()
        expect(wrapper.find('[data-test="preview-stale-badge"]').exists()).toBe(false)

        await wrapper.setProps({ isDraftDirty: true })

        expect(wrapper.get('[data-test="preview-frame"]').attributes('src')).toBe(
            'https://preview.example/session-a'
        )
        expect(wrapper.find('[data-test="preview-stale-badge"]').exists()).toBe(true)
    })

    it('does not auto-remint just because isDraftDirty flips (no implicit retry on unsaved edits)', async () => {
        const wrapper = mountPreview()
        await flushPromises()
        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(1)

        await wrapper.setProps({ isDraftDirty: true })
        await flushPromises()

        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(1)
    })

    it('re-mints automatically when the selected context (e.g. viewport) changes', async () => {
        const wrapper = mountPreview()
        await flushPromises()
        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(1)

        apiMocks.createWidgetLayoutPreviewSession.mockResolvedValueOnce(
            mintResponse({ preview_url: 'https://preview.example/session-viewport' })
        )
        await wrapper.setProps({ context: { ...baseContext(), viewport: 'mobile' } })
        await flushPromises()

        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(2)
        expect(wrapper.get('[data-test="preview-frame"]').attributes('src')).toBe(
            'https://preview.example/session-viewport'
        )
        expect(wrapper.get('[data-test="preview-frame"]').attributes('data-viewport')).toBe('mobile')
    })

    it('detects expiry and swaps the iframe for a clean, explicit-refresh recovery -- never a dead frame', async () => {
        apiMocks.createWidgetLayoutPreviewSession.mockResolvedValueOnce(
            mintResponse({ expires_at: '2026-07-18T00:00:30+07:00' })
        )
        const wrapper = mountPreview()
        await flushPromises()
        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(true)

        vi.setSystemTime(new Date('2026-07-18T00:01:00+07:00'))
        vi.advanceTimersByTime(2000)
        await flushPromises()

        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="preview-recovery"]').exists()).toBe(true)

        apiMocks.createWidgetLayoutPreviewSession.mockResolvedValueOnce(
            mintResponse({
                preview_url: 'https://preview.example/session-fresh',
                expires_at: '2026-07-18T00:10:00+07:00',
            })
        )
        await wrapper.get('[data-test="preview-refresh"]').trigger('click')
        await flushPromises()

        expect(apiMocks.createWidgetLayoutPreviewSession).toHaveBeenCalledTimes(2)
        expect(wrapper.get('[data-test="preview-frame"]').attributes('src')).toBe(
            'https://preview.example/session-fresh'
        )
    })

    it('renders a clean recovery (never a broken frame) when the mint call fails', async () => {
        apiMocks.createWidgetLayoutPreviewSession.mockRejectedValueOnce({
            aborted: false,
            status: 422,
            code: 'WIDGET_MANIFEST_APPROVAL_REQUIRED',
            message: 'A Manifest referenced by this overlay is pending approval.',
        })
        const wrapper = mountPreview()
        await flushPromises()

        expect(wrapper.find('[data-test="preview-frame"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="preview-recovery"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="preview-refresh"]').exists()).toBe(true)
    })

    it('recovers cleanly after an error once the explicit refresh succeeds', async () => {
        apiMocks.createWidgetLayoutPreviewSession.mockRejectedValueOnce({
            aborted: false,
            status: 503,
            code: 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE',
            message: 'Topology unavailable.',
        })
        const wrapper = mountPreview()
        await flushPromises()
        expect(wrapper.find('[data-test="preview-recovery"]').exists()).toBe(true)

        apiMocks.createWidgetLayoutPreviewSession.mockResolvedValueOnce(mintResponse())
        await wrapper.get('[data-test="preview-refresh"]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-test="preview-recovery"]').exists()).toBe(false)
        expect(wrapper.get('[data-test="preview-frame"]').attributes('src')).toBe(
            'https://preview.example/session-a'
        )
    })

    it('always labels the preview as visual/structural only, never interactive-JS parity', async () => {
        const wrapper = mountPreview()
        await flushPromises()

        expect(wrapper.find('[data-test="preview-mvp-note"]').exists()).toBe(true)
    })
})
