/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 30c: rollback-by-copy UI. Mounts widget-layout-rollback-dialog.vue
// STANDALONE against a REAL useWidgetSiteLayout() instance (Task 25,
// unmodified) with only api.js mocked -- the same pattern Task 30a/30b's own
// specs already established -- so idempotency-key reuse/minting and the
// dual-identity (Release + fresh Draft/lock) install are proven through the
// ACTUAL composable, not a shallow mock of it.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const permissionState = vi.hoisted(() => ({ denied: new Set<string>() }))

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
    return {
        ...actual,
        can: (a: unknown, b?: string, c?: string) => {
            const key = c !== undefined ? `${a}.${b}.${c}` : b !== undefined ? `${a}.${b}` : String(a)
            return !permissionState.denied.has(key)
        },
        iAlert: iAlertMock,
    }
})

import WidgetLayoutRollbackDialog from '../../../../cms/Widget/admin/components/widget-layout-rollback-dialog.vue'
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

const threeReleases = () => [release({ version: 3, published_at: 't3' }), release({ version: 2, published_at: 't2' }), release({ version: 1, published_at: 't1' })]

async function readyLayout() {
    apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValue({
        uuid: 'layout-1',
        current_draft: { version: 1, lock_version: 0 },
        current_published: { version: 4 },
    })
    apiMocks.getWidgetLayoutDraft.mockResolvedValue({ version: 1, lock_version: 0, placements: [placement()] })
    const layout = useWidgetSiteLayout()
    await layout.bootstrap()
    return layout
}

describe('widget-layout-rollback-dialog.vue', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        permissionState.denied.clear()
    })

    const mountDialog = (layout: ReturnType<typeof useWidgetSiteLayout>, props = {}) =>
        mount(WidgetLayoutRollbackDialog, {
            props: { open: true, releases: threeReleases(), initialReleaseVersion: 2, layout, ...props },
        })

    it('renders nothing when closed', async () => {
        const layout = await readyLayout()
        const wrapper = mountDialog(layout, { open: false })
        expect(wrapper.find('[data-test="rollback-dialog"]').exists()).toBe(false)
    })

    it('always explains that rollback restores the layout snapshot, not historical menu/post/provider rows', async () => {
        const layout = await readyLayout()
        const wrapper = mountDialog(layout)
        expect(wrapper.get('[data-test="rollback-scope-note"]').exists()).toBe(true)
    })

    it('hides the Confirm action and shows a denial note without widget_layout.rollback', async () => {
        permissionState.denied.add('widget_layout.rollback')
        const layout = await readyLayout()
        const wrapper = mountDialog(layout)

        expect(wrapper.find('[data-test="rollback-confirm"]').exists()).toBe(false)
        expect(wrapper.get('[data-test="rollback-permission-denied"]').exists()).toBe(true)
    })

    it('defaults the source Release selection to the initial release version', async () => {
        const layout = await readyLayout()
        const wrapper = mountDialog(layout)

        const select = wrapper.get('[data-test="rollback-source-select"]')
        expect((select.element as HTMLSelectElement).value).toBe('2')
        // The release is named by its publish time in the reader's own time, never by a version number.
        expect(wrapper.get('[data-test="rollback-confirm-summary"]').text()).not.toMatch(/v2\b/)
    })

    it('allows changing the source Release selection away from the initial default', async () => {
        const layout = await readyLayout()
        const wrapper = mountDialog(layout)

        await wrapper.get('[data-test="rollback-source-select"]').setValue(1)

        const select = wrapper.get('[data-test="rollback-source-select"]')
        expect((select.element as HTMLSelectElement).value).toBe('1')
        expect(wrapper.get('[data-test="rollback-confirm-summary"]').text()).not.toMatch(/v1\b/)
    })

    it('Cancel emits close without calling rollback', async () => {
        const layout = await readyLayout()
        const wrapper = mountDialog(layout)

        await wrapper.get('[data-test="rollback-cancel"]').trigger('click')

        expect(wrapper.emitted('close')).toBeTruthy()
        expect(apiMocks.rollbackWidgetLayout).not.toHaveBeenCalled()
    })

    it('Confirm calls layout.rollback with the SELECTED source version and the draft pointers CURRENT at click time', async () => {
        const layout = await readyLayout()
        // Simulate a Save that happened between mount and the rollback click --
        // proves the dialog reads layout.state live, not a stale snapshot.
        apiMocks.saveWidgetLayoutDraft.mockResolvedValueOnce([placement()])
        await layout.saveDraft()
        expect(layout.state.localDraft?.lockVersion).toBe(1)

        apiMocks.rollbackWidgetLayout.mockResolvedValue({
            release: release({ version: 5, rollback_of_version: 1 }),
            draft: { version: 5, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        const wrapper = mountDialog(layout)
        await wrapper.get('[data-test="rollback-source-select"]').setValue(1)

        await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
        await flushPromises()

        expect(apiMocks.rollbackWidgetLayout).toHaveBeenCalledTimes(1)
        const [uuid, body] = apiMocks.rollbackWidgetLayout.mock.calls[0]
        expect(uuid).toBe('layout-1')
        expect(body).toEqual({ source_release_version: 1, expected_draft_id: 1, expected_lock_version: 1 })
    })

    it('on success, installs the returned Release plus fresh Draft/lock and emits rolled-back', async () => {
        const layout = await readyLayout()
        // The fresh draft the rollback left is read again right after it.
        apiMocks.getWidgetLayoutDraft.mockResolvedValue({ version: 5, lock_version: 0, placements: [] })
        apiMocks.rollbackWidgetLayout.mockResolvedValue({
            release: release({ version: 5, rollback_of_version: 2 }),
            draft: { version: 5, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        const wrapper = mountDialog(layout)

        await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
        await flushPromises()

        expect(layout.state.status).toBe('ready')
        expect(layout.state.currentPublished?.version).toBe(5)
        expect(layout.state.baselineDraft?.version).toBe(5)
        expect(layout.state.baselineDraft?.lockVersion).toBe(0)
        expect(wrapper.emitted('rolled-back')).toBeTruthy()
    })

    it('reuses the same Idempotency-Key when retrying an identical rollback request after a transient failure', async () => {
        const layout = await readyLayout()
        apiMocks.rollbackWidgetLayout.mockRejectedValueOnce({
            aborted: false,
            status: 503,
            code: 'WIDGET_PROVIDER_UNAVAILABLE',
            message: 'Provider unavailable.',
        })
        const wrapper = mountDialog(layout)

        await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
        await flushPromises()
        const firstKey = apiMocks.rollbackWidgetLayout.mock.calls[0][2].idempotencyKey
        expect(firstKey).toBeTruthy()

        apiMocks.rollbackWidgetLayout.mockResolvedValueOnce({
            release: release({ version: 5, rollback_of_version: 2 }),
            draft: { version: 5, lock_version: 0, placements: [] },
            sync_status: 'ready',
        })
        await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
        await flushPromises()

        expect(apiMocks.rollbackWidgetLayout).toHaveBeenCalledTimes(2)
        const secondKey = apiMocks.rollbackWidgetLayout.mock.calls[1][2].idempotencyKey
        expect(secondKey).toBe(firstKey)
    })

    it('shows a dedicated stale-draft message on a 409 WIDGET_DRAFT_STALE rejection, and never auto-retries', async () => {
        const layout = await readyLayout()
        apiMocks.rollbackWidgetLayout.mockRejectedValueOnce({
            aborted: false,
            status: 409,
            code: 'WIDGET_DRAFT_STALE',
            message: 'Draft changed by someone else.',
        })
        const wrapper = mountDialog(layout)

        await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
        await flushPromises()

        expect(wrapper.get('[data-test="rollback-stale-error"]').exists()).toBe(true)
        expect(apiMocks.rollbackWidgetLayout).toHaveBeenCalledTimes(1)

        await flushPromises()
        await flushPromises()
        expect(apiMocks.rollbackWidgetLayout).toHaveBeenCalledTimes(1)
        expect(wrapper.emitted('rolled-back')).toBeFalsy()
    })

    it('a generic (non-409) rollback failure surfaces via the shared alert, not the stale panel', async () => {
        const layout = await readyLayout()
        apiMocks.rollbackWidgetLayout.mockRejectedValueOnce({
            aborted: false,
            status: 404,
            code: null,
            message: 'Source release not found.',
        })
        const wrapper = mountDialog(layout)

        await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
        await flushPromises()

        expect(iAlertMock).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[data-test="rollback-stale-error"]').exists()).toBe(false)
    })
})
