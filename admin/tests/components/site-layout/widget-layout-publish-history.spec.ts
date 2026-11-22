/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 30b, redesigned (IA pass): publish moved OUT of the release
// drawer into the page's own command-bar confirm dialog (one publish entry
// point), and the drawer became a REAL slide-over owning History + Rollback
// only. PART A mounts widget-layout-release-drawer.vue directly (history
// loading incl. the late-bootstrap watcher fix). PART B mounts the FULL
// pages/placements.vue against a real useWidgetSiteLayout() with only api.js
// mocked -- the same pattern widget-site-layout-accessibility.spec.ts uses --
// so publish confirmation, idempotency-key reuse/minting, the stale-draft
// panel, and post-publish history refresh are proven through the ACTUAL
// composable + the actual dialog wiring.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const permissionState = vi.hoisted(() => ({ denied: new Set<string>() }))

const apiMocks = vi.hoisted(() => ({
    listSlots: vi.fn(),
    listSlotsWithAreaCount: vi.fn(),
    getWidgetLayoutDraftChanges: vi.fn(),
    listWidgetInstances: vi.fn(),
    listTargetContexts: vi.fn(),
    searchWidgetTargets: vi.fn(),
    getWidgetTargetDetail: vi.fn(),
    listWidgetDefinitionCatalog: vi.fn(),
    getWidgetInstanceUsage: vi.fn(),
    listWidgetLayoutReleases: vi.fn(),
    bootstrapWidgetLayoutByKey: vi.fn(),
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

const iAlertMock = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...actual,
        can: (a: unknown, b?: string, c?: string) => {
            const key = c !== undefined ? `${a}.${b}.${c}` : b !== undefined ? `${a}.${b}` : String(a)
            return !permissionState.denied.has(key)
        },
        iAlert: iAlertMock,
    }
})

import PagePlacements from '../../../../cms/Widget/admin/pages/placements.vue'
import WidgetLayoutReleaseDrawer from '../../../../cms/Widget/admin/components/widget-layout-release-drawer.vue'
import { useWidgetSiteLayout } from '../../../../cms/Widget/admin/use-widget-site-layout'

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

const release = (overrides = {}) => ({
    version: 1,
    based_on_version: 1,
    rollback_of_version: null,
    published_at: '2026-07-18T10:00:00+07:00',
    published_by: 'webmaster@ispa.io',
    placements_count: 1,
    registry_contract_hash: 'hash-a',
    ...overrides,
})

const slotFixture = () => ({
    key: 'site_header_before',
    label_key: 'nav.a.site-layout-slot-header-before',
    cardinality: 'stack',
    supported_scope_types: ['global', 'template', 'target'],
    fallback: 'empty',
    trust_lane: 'standard',
    availability: 'available',
})

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

function setWideViewport() {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
        matches: true,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })) as unknown as typeof window.matchMedia
}

function mockDefaults({ currentPublished = null }: { currentPublished?: unknown } = {}) {
    vi.clearAllMocks()
    permissionState.denied.clear()
    window.localStorage.clear()
    apiMocks.listSlots.mockResolvedValue([slotFixture()])
    apiMocks.listSlotsWithAreaCount.mockResolvedValue({ slots: [slotFixture()], areaCount: 1 })
    apiMocks.getWidgetLayoutDraftChanges.mockResolvedValue([
        { kind: 'added', slot_key: 'site_header_before', slot_label: 'Header', widget_name: 'Hero Banner', scope_type: 'global', scope_label: 'Entire site' },
    ])
    apiMocks.listWidgetInstances.mockResolvedValue({ data: [instanceFixture()], meta: null, links: null })
    apiMocks.listTargetContexts.mockResolvedValue([])
    apiMocks.searchWidgetTargets.mockResolvedValue({ data: [], meta: null, links: null })
    apiMocks.getWidgetTargetDetail.mockResolvedValue(null)
    apiMocks.listWidgetDefinitionCatalog.mockResolvedValue({ data: [], meta: null, links: null })
    apiMocks.getWidgetInstanceUsage.mockResolvedValue({ in_use: false, placement_count: 0, usage_fingerprint: 'fp' })
    apiMocks.listWidgetLayoutReleases.mockResolvedValue({ data: [], meta: null, links: null })
    apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValue({
        uuid: 'layout-1',
        key: 'site',
        current_draft: { version: 1, lock_version: 0 },
        current_published: currentPublished,
        sync_status: 'ready',
    })
    apiMocks.getWidgetLayoutDraft.mockResolvedValue({
        version: 1,
        lock_version: 0,
        placements: [placement()],
        instance_fingerprints: {},
    })
    apiMocks.saveWidgetLayoutDraft.mockResolvedValue([placement()])
    apiMocks.getWidgetLayout.mockResolvedValue({ uuid: 'layout-1', current_draft: { version: 1, lock_version: 0 } })
    apiMocks.createWidgetLayoutPreviewSession.mockResolvedValue({
        preview_url: 'https://example.com/preview/abc',
        expires_at: new Date(Date.now() + 60_000).toISOString(),
        fingerprint: 'preview-fp',
    })
}

// =============================================================================
// PART A -- widget-layout-release-drawer.vue: history + rollback drawer.
// =============================================================================

describe('widget-layout-release-drawer.vue', () => {
    beforeEach(() => mockDefaults())

    async function readyLayout() {
        const layout = useWidgetSiteLayout()
        await layout.bootstrap()
        return layout
    }

    const mountDrawer = (layout: ReturnType<typeof useWidgetSiteLayout>, open = true) =>
        mount(WidgetLayoutReleaseDrawer, { props: { layout, open } })

    it('loads Release History once the layout UUID is available', async () => {
        const layout = await readyLayout()
        apiMocks.listWidgetLayoutReleases.mockResolvedValue({
            data: [release({ version: 2, rollback_of_version: 1 }), release({ version: 1 })],
            meta: null,
            links: null,
        })
        const wrapper = mountDrawer(layout)
        await flushPromises()

        expect(apiMocks.listWidgetLayoutReleases).toHaveBeenCalledWith('layout-1')
        // A release is named by when it was published, in the reader's own time: no version number, no ISO string.
        const history = wrapper.get('[data-test="release-history"]')
        expect(history.find('[data-test="release-row-2"]').exists()).toBe(true)
        expect(history.find('[data-test="release-row-1"]').exists()).toBe(true)
        expect(history.text()).not.toContain('2026-07-18T10:00:00+07:00')
        expect(history.text()).not.toMatch(/v[12]\b/)
    })

    it('initial-load fix: mounted BEFORE bootstrap resolves, it still loads once the UUID appears (watcher, not onMounted)', async () => {
        const layout = useWidgetSiteLayout()
        const wrapper = mountDrawer(layout)
        await flushPromises()
        // No UUID yet: nothing fetched, and the empty state is honest.
        expect(apiMocks.listWidgetLayoutReleases).not.toHaveBeenCalled()

        apiMocks.listWidgetLayoutReleases.mockResolvedValue({ data: [release({ version: 1 })], meta: null, links: null })
        await layout.bootstrap()
        await flushPromises()

        expect(apiMocks.listWidgetLayoutReleases).toHaveBeenCalledWith('layout-1')
        expect(wrapper.find('[data-test="release-row-1"]').exists()).toBe(true)
    })

    it('refreshes the list every time the drawer opens', async () => {
        const layout = await readyLayout()
        const wrapper = mountDrawer(layout, false)
        await flushPromises()
        const callsAfterMount = apiMocks.listWidgetLayoutReleases.mock.calls.length

        await wrapper.setProps({ open: true })
        await flushPromises()

        expect(apiMocks.listWidgetLayoutReleases.mock.calls.length).toBe(callsAfterMount + 1)
    })

    it('renders inside an accessible slide-over when open, nothing when closed', async () => {
        const layout = await readyLayout()
        const closed = mountDrawer(layout, false)
        await flushPromises()
        expect(closed.find('[data-test="drawer-panel"]').exists()).toBe(false)

        const open = mountDrawer(layout, true)
        await flushPromises()
        const panel = open.get('[data-test="drawer-panel"]')
        expect(panel.attributes('role')).toBe('dialog')
        expect(panel.find('[data-test="release-history"]').exists()).toBe(true)
    })

    it('owns NO publish trigger anymore (single publish entry point lives on the command bar)', async () => {
        const layout = await readyLayout()
        const wrapper = mountDrawer(layout)
        await flushPromises()

        expect(wrapper.find('[data-test="release-drawer-publish-trigger"]').exists()).toBe(false)
    })

    it('gates the per-release Rollback trigger on widget_layout.rollback', async () => {
        apiMocks.listWidgetLayoutReleases.mockResolvedValue({ data: [release({ version: 1 })], meta: null, links: null })
        const layout = await readyLayout()

        const allowed = mountDrawer(layout)
        await flushPromises()
        expect(allowed.find('[data-test="rollback-to-version-1"]').exists()).toBe(true)

        permissionState.denied.add('widget_layout.rollback')
        const denied = mountDrawer(layout)
        await flushPromises()
        expect(denied.find('[data-test="rollback-to-version-1"]').exists()).toBe(false)
    })
})

// =============================================================================
// PART B -- publish flow through the page's own command bar + confirm dialog.
// =============================================================================

describe('pages/placements.vue -- publish confirm dialog', () => {
    beforeEach(() => mockDefaults())

    async function mountPage() {
        setWideViewport()
        const wrapper = mount(PagePlacements)
        await flushPromises()
        await flushPromises()
        return wrapper
    }

    it('clicking Publish lists what will change before calling the API', async () => {
        mockDefaults({ currentPublished: { version: 3 } })
        const wrapper = await mountPage()

        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await flushPromises()

        expect(apiMocks.publishWidgetLayout).not.toHaveBeenCalled()
        const summary = wrapper.get('[data-test="publish-confirm-panel"]')
        expect(summary.findAll('[data-test="publish-change"]')).toHaveLength(1)
        expect(summary.text()).toContain('Entire site')
    })

    it('cancelling the confirmation dialog never calls publish', async () => {
        const wrapper = await mountPage()
        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await flushPromises()

        await wrapper.get('[data-test="publish-cancel"]').trigger('click')
        await flushPromises()

        expect(apiMocks.publishWidgetLayout).not.toHaveBeenCalled()
        expect(wrapper.find('[data-test="publish-confirm-panel"]').exists()).toBe(false)
    })

    it('confirming Publish calls the composable with the expected identity and reaches a committed ready state', async () => {
        apiMocks.publishWidgetLayout.mockResolvedValue({
            release: release({ version: 2 }),
            draft: { version: 2, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        const wrapper = await mountPage()

        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(apiMocks.publishWidgetLayout).toHaveBeenCalledTimes(1)
        const [uuid, body] = apiMocks.publishWidgetLayout.mock.calls[0]
        expect(uuid).toBe('layout-1')
        expect(body).toEqual({ expected_draft_id: 1, expected_lock_version: 0 })
        expect(wrapper.find('[data-test="publish-confirm-panel"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="save-bar-state-error"]').exists()).toBe(false)
        // The fresh draft the publish left is read again (new placement ids and contents).
        expect(apiMocks.getWidgetLayoutDraft).toHaveBeenCalledTimes(2)
    })

    it('reuses the same Idempotency-Key when retrying an identical publish after a transient failure', async () => {
        apiMocks.publishWidgetLayout.mockRejectedValueOnce({
            aborted: false,
            status: 503,
            code: 'WIDGET_PROVIDER_UNAVAILABLE',
            message: 'Provider unavailable.',
        })
        const wrapper = await mountPage()

        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(apiMocks.publishWidgetLayout).toHaveBeenCalledTimes(1)
        const firstKey = apiMocks.publishWidgetLayout.mock.calls[0][2].idempotencyKey
        expect(firstKey).toBeTruthy()

        apiMocks.publishWidgetLayout.mockResolvedValueOnce({
            release: release({ version: 2 }),
            draft: { version: 2, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        // Explicit retry -- same underlying draft identity/fingerprint. The
        // dialog stays open after a non-stale failure precisely for this.
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(apiMocks.publishWidgetLayout).toHaveBeenCalledTimes(2)
        expect(apiMocks.publishWidgetLayout.mock.calls[1][2].idempotencyKey).toBe(firstKey)
    })

    it('mints a fresh Idempotency-Key for a new publish after a prior successful one changed the draft identity', async () => {
        apiMocks.publishWidgetLayout.mockResolvedValueOnce({
            release: release({ version: 2 }),
            draft: { version: 2, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        const wrapper = await mountPage()
        // The draft read after the publish carries the fresh draft's identity.
        apiMocks.getWidgetLayoutDraft.mockResolvedValue({ version: 2, lock_version: 0, placements: [placement()], instance_fingerprints: {} })

        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()
        const firstKey = apiMocks.publishWidgetLayout.mock.calls[0][2].idempotencyKey

        apiMocks.publishWidgetLayout.mockResolvedValueOnce({
            release: release({ version: 3 }),
            draft: { version: 3, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(apiMocks.publishWidgetLayout.mock.calls[1][2].idempotencyKey).not.toBe(firstKey)
    })

    it('shows the dedicated stale-draft recovery inside the dialog on 409 WIDGET_DRAFT_STALE, never auto-retrying', async () => {
        apiMocks.publishWidgetLayout.mockRejectedValueOnce({
            aborted: false,
            status: 409,
            code: 'WIDGET_DRAFT_STALE',
            message: 'Draft changed by someone else.',
        })
        const wrapper = await mountPage()

        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-test="publish-stale-error"]').exists()).toBe(true)
        expect(apiMocks.publishWidgetLayout).toHaveBeenCalledTimes(1)

        await flushPromises()
        await flushPromises()
        expect(apiMocks.publishWidgetLayout).toHaveBeenCalledTimes(1)
    })

    it('a generic (non-409) publish failure surfaces via the shared alert, not the stale panel', async () => {
        apiMocks.publishWidgetLayout.mockRejectedValueOnce({
            aborted: false,
            status: 422,
            code: 'WIDGET_REGISTRY_COMPATIBILITY_DRIFT',
            message: 'Registry drift detected.',
        })
        const wrapper = await mountPage()

        await wrapper.get('[data-test="command-publish"]').trigger('click')
        await wrapper.get('[data-test="publish-confirm"]').trigger('click')
        await flushPromises()

        expect(iAlertMock).toHaveBeenCalled()
        expect(wrapper.find('[data-test="publish-stale-error"]').exists()).toBe(false)
    })
})
