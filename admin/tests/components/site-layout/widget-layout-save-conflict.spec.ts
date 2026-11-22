/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 30a: Draft Save and conflict recovery UI. Mounts
// widget-layout-save-bar.vue against a REAL useWidgetSiteLayout() instance
// (Task 25, unmodified) with only api.js mocked -- the SAME pattern
// tests/components/site-layout/widget-layout-draft.spec.ts (Task 25) and
// widget-placement-inspector.spec.ts (Task 28) already use -- rather than a
// hand-rolled fake `layout` object, so 409 conflict handling, the
// clean/dirty/saving derivation, and the request body shape are all proven
// through the ACTUAL composable/state-machine, not a shallow mock of it.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const apiMocks = vi.hoisted(() => ({
    bootstrapWidgetLayoutByKey: vi.fn(),
    getWidgetLayoutDraft: vi.fn(),
    saveWidgetLayoutDraft: vi.fn(),
    getWidgetLayout: vi.fn(),
    publishWidgetLayout: vi.fn(),
    rollbackWidgetLayout: vi.fn(),
}))

vi.mock('../../../../cms/Widget/admin/api', () => apiMocks)

const iAlertMock = vi.hoisted(() => vi.fn())
vi.mock('@/mixins', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return { ...actual, iAlert: iAlertMock }
})

import WidgetLayoutSaveBar from '../../../../cms/Widget/admin/components/widget-layout-save-bar.vue'
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

async function readyLayout(placements = [placement()]) {
    apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValue({
        uuid: 'layout-1',
        current_draft: { version: 1, lock_version: 0 },
        current_published: null,
    })
    apiMocks.getWidgetLayoutDraft.mockResolvedValue({ version: 1, lock_version: 0, placements })
    const layout = useWidgetSiteLayout()
    await layout.bootstrap()
    return layout
}

describe('widget-layout-save-bar.vue', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    const mountBar = (layout: ReturnType<typeof useWidgetSiteLayout>) =>
        mount(WidgetLayoutSaveBar, { props: { layout } })

    it('renders a clean state when the draft has never been edited', async () => {
        const layout = await readyLayout()
        const wrapper = mountBar(layout)

        expect(wrapper.get('[data-test="save-bar-state-clean"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="save-bar-state-dirty"]').exists()).toBe(false)
        // The draft saves itself: there is no Save button to press.
        expect(wrapper.find('[data-test="save-draft-button"]').exists()).toBe(false)
    })

    it('renders a pending state after a local placements edit (the host autosaves it)', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        const wrapper = mountBar(layout)

        expect(wrapper.get('[data-test="save-bar-state-dirty"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="save-draft-button"]').exists()).toBe(false)
    })

    it('renders a saving state while the save request is in flight', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        let resolveSave!: (value: unknown) => void
        apiMocks.saveWidgetLayoutDraft.mockImplementationOnce(
            () => new Promise((resolve) => { resolveSave = resolve })
        )
        const wrapper = mountBar(layout)

        layout.saveDraft()
        await flushPromises()

        expect(wrapper.get('[data-test="save-bar-state-saving"]').exists()).toBe(true)

        resolveSave([placement({ position: 1 })])
        await flushPromises()
        expect(wrapper.find('[data-test="save-bar-state-saving"]').exists()).toBe(false)
    })

    it('leaves the pending state right after a successful save', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        apiMocks.saveWidgetLayoutDraft.mockResolvedValue([placement({ position: 1 })])
        const wrapper = mountBar(layout)

        await layout.saveDraft()
        await flushPromises()

        expect(wrapper.find('[data-test="save-bar-state-dirty"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="save-bar-state-saving"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="save-bar-state-error"]').exists()).toBe(false)
    })

    it('sends a Draft Save payload containing only expected_current_draft_id/expected_lock_version/placements -- never Instance values or a version allocation', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        apiMocks.saveWidgetLayoutDraft.mockResolvedValue([placement({ position: 1 })])
        const wrapper = mountBar(layout)

        await layout.saveDraft()
        await flushPromises()

        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)
        const [uuid, body] = apiMocks.saveWidgetLayoutDraft.mock.calls[0]
        expect(uuid).toBe('layout-1')
        expect(Object.keys(body).sort()).toEqual(['expected_current_draft_id', 'expected_lock_version', 'placements'])
        expect(body.expected_current_draft_id).toBe(1)
        expect(body.expected_lock_version).toBe(0)
    })

    it('a draft save never triggers publish, rollback, or repin -- it stays separate from those mutations', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        apiMocks.saveWidgetLayoutDraft.mockResolvedValue([placement({ position: 1 })])
        const wrapper = mountBar(layout)

        await layout.saveDraft()
        await flushPromises()

        expect(apiMocks.publishWidgetLayout).not.toHaveBeenCalled()
        expect(apiMocks.rollbackWidgetLayout).not.toHaveBeenCalled()
    })

    it('a second save while one is already in flight never fires a duplicate mutation', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        let resolveSave!: (value: unknown) => void
        apiMocks.saveWidgetLayoutDraft.mockImplementationOnce(
            () => new Promise((resolve) => { resolveSave = resolve })
        )
        const wrapper = mountBar(layout)

        expect(wrapper.exists()).toBe(true)
        layout.saveDraft()
        layout.saveDraft()
        await flushPromises()

        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)
        resolveSave([placement({ position: 1 })])
        await flushPromises()
    })

    it('on a 409 conflict, shows the recovery panel without overwriting either the local or the server snapshot', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 }), placement({ uuid: 'placement-2', position: 2 })])
        apiMocks.saveWidgetLayoutDraft.mockRejectedValueOnce({
            aborted: false,
            status: 409,
            code: 'WIDGET_DRAFT_STALE',
            message: 'Draft changed by someone else.',
        })
        apiMocks.getWidgetLayout.mockResolvedValueOnce({
            uuid: 'layout-1',
            current_draft: { version: 2, lock_version: 5 },
        })
        const wrapper = mountBar(layout)

        await layout.saveDraft()
        await flushPromises()

        expect(wrapper.get('[data-test="save-conflict-panel"]').exists()).toBe(true)
        // Plain words and one way out: no version or lock numbers on screen.
        expect(wrapper.get('[data-test="save-conflict-panel"]').text()).not.toMatch(/v\d|lock/)
        expect(wrapper.find('[data-test="conflict-reload"]').exists()).toBe(true)
        // "Keep my changes" is not offered: the next save would be refused again, or overwrite the other change.
        expect(wrapper.find('[data-test="conflict-keep-local"]').exists()).toBe(false)

        // Neither snapshot was overwritten just by viewing the compare panel.
        expect(layout.state.localDraft?.version).toBe(1)
        expect(layout.state.localDraft?.placements).toHaveLength(2)
        expect(layout.state.serverConflictDraft?.version).toBe(2)
        expect(layout.state.serverConflictDraft?.lockVersion).toBe(5)
    })

    it('Reload adopts the server draft identity by re-bootstrapping (never leaves the canvas on a content-less snapshot)', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        apiMocks.saveWidgetLayoutDraft.mockRejectedValueOnce({
            aborted: false,
            status: 409,
            code: 'WIDGET_DRAFT_STALE',
            message: 'Draft changed by someone else.',
        })
        apiMocks.getWidgetLayout.mockResolvedValueOnce({
            uuid: 'layout-1',
            current_draft: { version: 2, lock_version: 5 },
        })
        const wrapper = mountBar(layout)
        await layout.saveDraft()
        await flushPromises()

        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce({
            uuid: 'layout-1',
            current_draft: { version: 2, lock_version: 5 },
            current_published: null,
        })
        apiMocks.getWidgetLayoutDraft.mockResolvedValueOnce({
            version: 2,
            lock_version: 5,
            placements: [placement({ uuid: 'server-placement', position: 0 })],
        })

        await wrapper.get('[data-test="conflict-reload"]').trigger('click')
        await flushPromises()

        expect(layout.state.status).toBe('ready')
        expect(layout.state.localDraft?.version).toBe(2)
        expect(layout.state.localDraft?.lockVersion).toBe(5)
        expect(layout.state.localDraft?.placements).toEqual([placement({ uuid: 'server-placement', position: 0 })])
        expect(wrapper.find('[data-test="save-conflict-panel"]').exists()).toBe(false)
    })

    it('surfaces a non-conflict save failure via the shared alert without crashing or auto-retrying', async () => {
        const layout = await readyLayout()
        layout.updateLocalDraft([placement({ position: 1 })])
        apiMocks.saveWidgetLayoutDraft.mockRejectedValueOnce({
            aborted: false,
            status: 422,
            code: 'WIDGET_PROVIDER_INVALID',
            message: 'Invalid provider payload.',
        })
        const wrapper = mountBar(layout)

        await layout.saveDraft()
        await flushPromises()

        expect(iAlertMock).toHaveBeenCalledTimes(1)
        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[data-test="save-conflict-panel"]').exists()).toBe(false)

        // The unsaved change is still there: the bar offers an explicit retry (the user's own click).
        apiMocks.saveWidgetLayoutDraft.mockResolvedValueOnce([placement({ position: 1 })])
        await wrapper.get('[data-test="save-draft-button"]').trigger('click')
        await flushPromises()
        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledTimes(2)
        expect(wrapper.find('[data-test="save-bar-state-error"]').exists()).toBe(false)
    })
})
