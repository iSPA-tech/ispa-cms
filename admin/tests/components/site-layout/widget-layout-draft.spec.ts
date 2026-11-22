/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 25: the deterministic Site Layout draft state machine
// (src/modules/site-layout/admin/site-layout-state.js, pure) and the
// composable that orchestrates it against api.js
// (src/modules/site-layout/admin/use-widget-site-layout.js). Covers DoD
// 1357-1360: explicit idempotent bootstrap, the six separated draft-state
// axes, save/conflict semantics, and never-auto-retry idempotency-key reuse.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as state from '../../../../cms/Widget/admin/site-layout-state'

// =============================================================================
// PART A -- the pure state machine (no mocks; every transition is a plain
// (state, payload) -> newState function, so behavior is asserted directly).
// =============================================================================

const layoutResource = (overrides = {}) => ({
    uuid: 'layout-1',
    project_name: 'default',
    key: 'site',
    current_draft: { version: 1, lock_version: 0 },
    current_published: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
})

describe('createInitialSiteLayoutDraftState', () => {
    it('starts idle with every draft/preview/conflict axis empty and sequence at zero', () => {
        const initial = state.createInitialSiteLayoutDraftState()
        expect(initial.status).toBe('idle')
        expect(initial.baselineDraft).toBeNull()
        expect(initial.localDraft).toBeNull()
        expect(initial.serverConflictDraft).toBeNull()
        expect(initial.previewFingerprint).toBeNull()
        expect(initial.requestSequence).toBe(0)
        expect(initial.inFlightSequence).toBeNull()
        expect(initial.abortController).toBeNull()
        expect(initial.lastError).toBeNull()
        expect(initial.instanceFingerprints).toEqual({})
    })
})

describe('bootstrap installs either the 201 or 200 Resource as baseline (DoD 1357)', () => {
    it('applyBootstrapSuccess installs baseline AND local draft from the resource, status ready', () => {
        const controller = new AbortController()
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller })
        expect(s.status).toBe('bootstrapping')
        expect(s.abortController).toBe(controller)
        expect(s.inFlightSequence).toBe(1)

        s = state.applyBootstrapSuccess(s, { sequence: 1, resource: layoutResource() })
        expect(s.status).toBe('ready')
        expect(s.layoutUuid).toBe('layout-1')
        expect(s.baselineDraft).toEqual({ version: 1, lockVersion: 0, placements: [] })
        expect(s.localDraft).toEqual({ version: 1, lockVersion: 0, placements: [] })
        expect(s.abortController).toBeNull()
        expect(s.inFlightSequence).toBeNull()
    })

    it('treats a 200 (already existing) response identically to a 201 -- never a special no-op path', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, {
            sequence: 1,
            resource: layoutResource({ current_draft: { version: 4, lock_version: 9 } }),
        })
        expect(s.status).toBe('ready')
        expect(s.baselineDraft).toEqual({ version: 4, lockVersion: 9, placements: [] })
    })

    it('seeds baseline AND local draft placements from an explicit placements argument (load-on-entry)', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, {
            sequence: 1,
            resource: layoutResource(),
            placements: [{ slot_key: 'site_header_before', position: 0 }],
        })
        expect(s.status).toBe('ready')
        expect(s.baselineDraft).toEqual({
            version: 1,
            lockVersion: 0,
            placements: [{ slot_key: 'site_header_before', position: 0 }],
        })
        expect(s.localDraft).toEqual(s.baselineDraft)
    })

    it('installs the route-31 instance_fingerprints map (repin usage_fingerprint per referenced Instance)', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, {
            sequence: 1,
            resource: layoutResource(),
            placements: [{ uuid: 'p-1', slot_key: 'site_header_before', position: 0 }],
            instanceFingerprints: { 'instance-1': 'repin-fp-1' },
        })
        expect(s.instanceFingerprints).toEqual({ 'instance-1': 'repin-fp-1' })
    })

    it('defaults instance_fingerprints to an empty map when the load omits it', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, { sequence: 1, resource: layoutResource() })
        expect(s.instanceFingerprints).toEqual({})
    })

    it('ignores a stale bootstrap response whose sequence no longer matches the in-flight one', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() }) // sequence 1
        s = state.beginBootstrap(s, { controller: new AbortController() }) // superseded to sequence 2
        const beforeStaleApply = s

        s = state.applyBootstrapSuccess(s, { sequence: 1, resource: layoutResource() })

        expect(s).toEqual(beforeStaleApply)
        expect(s.status).toBe('bootstrapping')
    })

    it('applyBootstrapFailure records the normalized error and returns to a retryable error status', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        const error = { status: 500, code: null, retryable: false, recovery: 'none' }
        s = state.applyBootstrapFailure(s, { sequence: 1, error })
        expect(s.status).toBe('error')
        expect(s.lastError).toEqual(error)
        expect(s.abortController).toBeNull()
    })
})

describe('six separated draft-state axes stay independent (DoD 1358)', () => {
    const readyState = () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, {
            sequence: 1,
            resource: layoutResource({ current_draft: { version: 1, lock_version: 0 } }),
        })
        return s
    }

    it('editing the local draft never touches the baseline draft', () => {
        const s0 = readyState()
        const s1 = state.updateLocalDraftPlacements(s0, [{ slot_key: 'site_header_before', position: 0 }])

        expect(s1.localDraft.placements).toEqual([{ slot_key: 'site_header_before', position: 0 }])
        expect(s1.baselineDraft).toEqual(s0.baselineDraft)
        expect(s1.baselineDraft.placements).toEqual([])
    })

    it('hasUnsavedDraftChanges reflects local-vs-baseline divergence, not a separate dirty flag', () => {
        const s0 = readyState()
        expect(state.hasUnsavedDraftChanges(s0)).toBe(false)

        const s1 = state.updateLocalDraftPlacements(s0, [{ slot_key: 'x', position: 0 }])
        expect(state.hasUnsavedDraftChanges(s1)).toBe(true)
    })

    it('a second beginSaveDraft while one is already in flight is a no-op (never a duplicate mutation)', () => {
        const controller1 = new AbortController()
        const s0 = readyState()
        const s1 = state.beginSaveDraft(s0, { controller: controller1 })
        expect(s1.status).toBe('saving')

        const s2 = state.beginSaveDraft(s1, { controller: new AbortController() })
        expect(s2).toEqual(s1)
        expect(s2.abortController).toBe(controller1)
    })

    it('save success installs the accepted server snapshot/lock, deriving lock_version deterministically (+1)', () => {
        const s0 = readyState()
        const edited = state.updateLocalDraftPlacements(s0, [{ slot_key: 'x', position: 0 }])
        const saving = state.beginSaveDraft(edited, { controller: new AbortController() })

        const saved = state.applySaveDraftSuccess(saving, {
            sequence: saving.inFlightSequence,
            placements: [{ slot_key: 'x', position: 0 }],
        })

        expect(saved.status).toBe('ready')
        expect(saved.baselineDraft).toEqual({ version: 1, lockVersion: 1, placements: [{ slot_key: 'x', position: 0 }] })
        expect(saved.localDraft).toEqual(saved.baselineDraft)
        expect(saved.serverConflictDraft).toBeNull()
    })

    it('a 409 conflict preserves local AND server versions separately (DoD 1359) -- neither clobbers the other', () => {
        const s0 = readyState()
        const edited = state.updateLocalDraftPlacements(s0, [{ slot_key: 'mine', position: 0 }])
        const saving = state.beginSaveDraft(edited, { controller: new AbortController() })

        const conflictError = { status: 409, code: 'WIDGET_DRAFT_STALE', retryable: true, recovery: 'refresh_draft' }
        const serverDraft = { version: 1, lockVersion: 3, placements: [{ slot_key: 'theirs', position: 0 }] }

        const conflicted = state.applySaveDraftConflict(saving, {
            sequence: saving.inFlightSequence,
            error: conflictError,
            serverDraft,
        })

        expect(conflicted.status).toBe('conflict')
        expect(conflicted.localDraft.placements).toEqual([{ slot_key: 'mine', position: 0 }])
        expect(conflicted.serverConflictDraft).toEqual(serverDraft)
        expect(conflicted.localDraft).not.toEqual(conflicted.serverConflictDraft)
        expect(conflicted.lastError).toEqual(conflictError)
    })

    it('resolveConflictKeepLocal clears only the conflict axis, leaving the local draft untouched', () => {
        const s0 = readyState()
        const edited = state.updateLocalDraftPlacements(s0, [{ slot_key: 'mine', position: 0 }])
        const saving = state.beginSaveDraft(edited, { controller: new AbortController() })
        const conflicted = state.applySaveDraftConflict(saving, {
            sequence: saving.inFlightSequence,
            error: { status: 409, code: 'WIDGET_DRAFT_STALE', retryable: true, recovery: 'refresh_draft' },
            serverDraft: { version: 1, lockVersion: 3, placements: [] },
        })

        const resolved = state.resolveConflictKeepLocal(conflicted)
        expect(resolved.status).toBe('ready')
        expect(resolved.serverConflictDraft).toBeNull()
        expect(resolved.localDraft.placements).toEqual([{ slot_key: 'mine', position: 0 }])
    })

    it('resolveConflictAcceptServer adopts the server draft as the new baseline AND local draft', () => {
        const s0 = readyState()
        const edited = state.updateLocalDraftPlacements(s0, [{ slot_key: 'mine', position: 0 }])
        const saving = state.beginSaveDraft(edited, { controller: new AbortController() })
        const serverDraft = { version: 1, lockVersion: 3, placements: [{ slot_key: 'theirs', position: 0 }] }
        const conflicted = state.applySaveDraftConflict(saving, {
            sequence: saving.inFlightSequence,
            error: { status: 409, code: 'WIDGET_DRAFT_STALE', retryable: true, recovery: 'refresh_draft' },
            serverDraft,
        })

        const resolved = state.resolveConflictAcceptServer(conflicted)
        expect(resolved.status).toBe('ready')
        expect(resolved.baselineDraft).toEqual(serverDraft)
        expect(resolved.localDraft).toEqual(serverDraft)
        expect(resolved.serverConflictDraft).toBeNull()
    })

    it('a non-conflict save failure preserves the local draft so the user can retry explicitly', () => {
        const s0 = readyState()
        const edited = state.updateLocalDraftPlacements(s0, [{ slot_key: 'mine', position: 0 }])
        const saving = state.beginSaveDraft(edited, { controller: new AbortController() })
        const error = { status: 422, code: 'WIDGET_PERMISSION_DENIED', retryable: false, recovery: 'none' }

        const failed = state.applySaveDraftFailure(saving, { sequence: saving.inFlightSequence, error })
        expect(failed.status).toBe('error')
        expect(failed.localDraft.placements).toEqual([{ slot_key: 'mine', position: 0 }])
        expect(failed.lastError).toEqual(error)
    })

    it('ignores a stale save response whose sequence has been superseded', () => {
        const s0 = readyState()
        const saving = state.beginSaveDraft(s0, { controller: new AbortController() })
        const staleSequence = saving.inFlightSequence

        // A newer save started (e.g. after an abort-and-retry), superseding the sequence.
        const supersededSaving = state.beginSaveDraft(
            { ...saving, status: 'ready' },
            { controller: new AbortController() }
        )

        const result = state.applySaveDraftSuccess(supersededSaving, {
            sequence: staleSequence,
            placements: [],
        })
        expect(result).toEqual(supersededSaving)
    })
})

describe('publish/rollback install dual Release/fresh-Draft identities into state', () => {
    const readyState = () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, {
            sequence: 1,
            resource: layoutResource({ current_draft: { version: 1, lock_version: 0 } }),
        })
        return state.updateLocalDraftPlacements(s, [{ slot_key: 'x', position: 0 }])
    }

    it('applyPublishSuccess installs the new Release AND the fresh Draft identity/lock', () => {
        const s0 = readyState()
        const publishing = state.beginPublish(s0, { controller: new AbortController() })
        const published = state.applyPublishSuccess(publishing, {
            sequence: publishing.inFlightSequence,
            release: { version: 2, based_on_version: null, rollback_of_version: null },
            draft: { version: 3, lock_version: 0 },
        })

        expect(published.status).toBe('ready')
        expect(published.currentPublished).toEqual({ version: 2 })
        expect(published.baselineDraft).toEqual({ version: 3, lockVersion: 0, placements: [{ slot_key: 'x', position: 0 }] })
        expect(published.localDraft).toEqual(published.baselineDraft)
    })

    it('applyRollbackSuccess never reactivates the historical release in place -- installs a brand new identity', () => {
        const s0 = readyState()
        const rollingBack = state.beginRollback(s0, { controller: new AbortController() })
        const rolledBack = state.applyRollbackSuccess(rollingBack, {
            sequence: rollingBack.inFlightSequence,
            release: { version: 10, rollback_of_version: 4 },
            draft: { version: 11, lock_version: 0 },
        })

        expect(rolledBack.currentPublished).toEqual({ version: 10 })
        expect(rolledBack.baselineDraft.version).toBe(11)
        expect(rolledBack.baselineDraft.version).not.toBe(4)
    })
})

describe('repin installs the returned Draft identity/lock/placements verbatim (shared-instance repin)', () => {
    const readyState = () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, {
            sequence: 1,
            resource: layoutResource({ current_draft: { version: 1, lock_version: 0 } }),
        })
        return s
    }

    it('applyRepinDraftInstalled installs the response draft version/lock_version/placements verbatim into baseline AND local', () => {
        const s0 = readyState()

        const installed = state.applyRepinDraftInstalled(s0, {
            draft: { version: 1, lock_version: 7, placements: [{ slot_key: 'repinned', position: 0 }] },
        })

        // lock_version is installed VERBATIM (7) off the response, never derived
        // as a `+1` off the previous lock (applySaveDraftSuccess's convention,
        // which would wrongly yield 1 here).
        expect(installed.baselineDraft).toEqual({
            version: 1,
            lockVersion: 7,
            placements: [{ slot_key: 'repinned', position: 0 }],
        })
        expect(installed.localDraft).toEqual(installed.baselineDraft)
    })

    it('applies unconditionally -- repin has no begin/sequence step, so a null inFlightSequence never discards it', () => {
        const s0 = readyState()
        expect(s0.inFlightSequence).toBeNull()

        const installed = state.applyRepinDraftInstalled(s0, {
            draft: { version: 2, lock_version: 9, placements: [] },
        })

        // Unlike the sequence-guarded applySaveDraftSuccess (which early-returns the
        // unchanged state when inFlightSequence is null), this commit always lands.
        expect(installed.baselineDraft).toEqual({ version: 2, lockVersion: 9, placements: [] })
        expect(installed.localDraft).toEqual({ version: 2, lockVersion: 9, placements: [] })
    })
})

describe('preview fingerprint guards against stale/out-of-order responses', () => {
    it('draftFingerprint is stable for identical content and differs when placements change', () => {
        const a = state.draftFingerprint({ version: 1, lockVersion: 0, placements: [{ slot_key: 'x' }] })
        const b = state.draftFingerprint({ version: 1, lockVersion: 0, placements: [{ slot_key: 'x' }] })
        const c = state.draftFingerprint({ version: 1, lockVersion: 0, placements: [{ slot_key: 'y' }] })
        expect(a).toBe(b)
        expect(a).not.toBe(c)
    })

    it('applyPreviewSuccess installs preview_url/expiry/fingerprint only when the requested fingerprint is still current', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, { sequence: 1, resource: layoutResource() })
        const currentFingerprint = state.draftFingerprint(s.localDraft)

        const applied = state.applyPreviewSuccess(s, {
            requestedFingerprint: currentFingerprint,
            previewUrl: 'https://preview.example/a',
            previewExpiresAt: '2026-01-01T00:05:00Z',
            fingerprint: 'server-fp-1',
        })
        expect(applied.previewUrl).toBe('https://preview.example/a')
        expect(applied.previewFingerprint).toBe('server-fp-1')
    })

    it('discards a preview response whose requested fingerprint no longer matches the current local draft', () => {
        let s = state.createInitialSiteLayoutDraftState()
        s = state.beginBootstrap(s, { controller: new AbortController() })
        s = state.applyBootstrapSuccess(s, { sequence: 1, resource: layoutResource() })
        const staleFingerprint = state.draftFingerprint(s.localDraft)

        // The user kept editing after the preview request was sent.
        s = state.updateLocalDraftPlacements(s, [{ slot_key: 'changed', position: 0 }])

        const applied = state.applyPreviewSuccess(s, {
            requestedFingerprint: staleFingerprint,
            previewUrl: 'https://preview.example/stale',
            previewExpiresAt: '2026-01-01T00:05:00Z',
            fingerprint: 'server-fp-stale',
        })
        expect(applied).toEqual(s)
        expect(applied.previewUrl).toBeNull()
    })
})

describe('idempotency-key ledger: EXPLICIT replay of the SAME fingerprint only (DoD 1360)', () => {
    it('reuses the same key for a second call with an unchanged fingerprint', () => {
        let ledger = state.createIdempotencyLedger()
        const first = state.getOrCreateIdempotencyKey(ledger, 'instance_create', 'fp-a')
        ledger = first.ledger
        const second = state.getOrCreateIdempotencyKey(ledger, 'instance_create', 'fp-a')

        expect(second.key).toBe(first.key)
    })

    it('mints a new key once the fingerprint changes (a genuinely different mutation)', () => {
        let ledger = state.createIdempotencyLedger()
        const first = state.getOrCreateIdempotencyKey(ledger, 'instance_create', 'fp-a')
        ledger = first.ledger
        const second = state.getOrCreateIdempotencyKey(ledger, 'instance_create', 'fp-b')

        expect(second.key).not.toBe(first.key)
    })

    it('tracks each action independently -- publish and rollback never share a key', () => {
        let ledger = state.createIdempotencyLedger()
        const publish = state.getOrCreateIdempotencyKey(ledger, 'layout_publish', 'fp-x')
        ledger = publish.ledger
        const rollback = state.getOrCreateIdempotencyKey(ledger, 'layout_rollback', 'fp-x')

        expect(rollback.key).not.toBe(publish.key)
    })
})

describe('emergency runtime-control state (DoD 1356)', () => {
    it('a 503 topology-unavailable failure preserves the expected control version untouched', () => {
        let ledger = state.createManifestControlLedger()
        ledger = state.applyManifestControlSuccess(ledger, 'hash-1', { controlVersion: 1, blocked: false })
        const before = ledger['hash-1']

        ledger = state.applyManifestControlTopologyUnavailable(ledger, 'hash-1')

        expect(ledger['hash-1'].expectedControlVersion).toBe(before.expectedControlVersion)
        expect(ledger['hash-1'].topologyUnavailable).toBe(true)
    })

    it('never introduces a pending/acknowledged-partial status for emergency control', () => {
        let ledger = state.createManifestControlLedger()
        ledger = state.applyManifestControlTopologyUnavailable(ledger, 'hash-1')
        expect(Object.values(ledger['hash-1'])).not.toContain('pending')

        ledger = state.applyManifestControlVersionStale(ledger, 'hash-1')
        expect(Object.values(ledger['hash-1'])).not.toContain('pending')
    })

    it('a 409 version-stale failure requires an explicit refresh before another attempt is allowed', () => {
        let ledger = state.createManifestControlLedger()
        ledger = state.applyManifestControlSuccess(ledger, 'hash-1', { controlVersion: 1, blocked: false })
        ledger = state.applyManifestControlVersionStale(ledger, 'hash-1')

        expect(ledger['hash-1'].versionStale).toBe(true)

        ledger = state.applyManifestControlSuccess(ledger, 'hash-1', { controlVersion: 2, blocked: true })
        expect(ledger['hash-1'].versionStale).toBe(false)
        expect(ledger['hash-1'].expectedControlVersion).toBe(2)
    })
})

// =============================================================================
// PART B -- the composable orchestrating api.js + the state machine.
// =============================================================================

const apiMocks = vi.hoisted(() => ({
    bootstrapWidgetLayoutByKey: vi.fn(),
    saveWidgetLayoutDraft: vi.fn(),
    publishWidgetLayout: vi.fn(),
    listWidgetLayouts: vi.fn(),
    getWidgetLayout: vi.fn(),
    getWidgetLayoutDraft: vi.fn(),
    repinWidgetLayoutDraftInstance: vi.fn(),
}))

vi.mock('../../../../cms/Widget/admin/api', () => apiMocks)

describe('useWidgetSiteLayout composable', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('bootstrap() calls the explicit PUT by-key route and never a GET first', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()

        expect(apiMocks.bootstrapWidgetLayoutByKey).toHaveBeenCalledWith('site', expect.any(Object))
        expect(apiMocks.getWidgetLayout).not.toHaveBeenCalled()
        expect(apiMocks.listWidgetLayouts).not.toHaveBeenCalled()
        expect(layout.state.status).toBe('ready')
        expect(layout.state.baselineDraft).toEqual({ version: 1, lockVersion: 0, placements: [] })
    })

    it('bootstrap() with readOnly reads GET /layouts and never sends the creating PUT', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.listWidgetLayouts.mockResolvedValueOnce({ data: [layoutResource({ uuid: 'other', key: 'other' }), layoutResource()] })
        apiMocks.getWidgetLayoutDraft.mockResolvedValueOnce({ placements: [], instance_fingerprints: {} })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap('site', { readOnly: true })

        expect(apiMocks.bootstrapWidgetLayoutByKey).not.toHaveBeenCalled()
        expect(apiMocks.listWidgetLayouts).toHaveBeenCalledTimes(1)
        expect(layout.state.status).toBe('ready')
        expect(layout.state.layoutUuid).toBe('layout-1')
    })

    it('bootstrap() with readOnly fails without a write when the project has no layout yet', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.listWidgetLayouts.mockResolvedValueOnce({ data: [] })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap('site', { readOnly: true })

        expect(apiMocks.bootstrapWidgetLayoutByKey).not.toHaveBeenCalled()
        expect(layout.state.status).not.toBe('ready')
    })

    it('bootstrap() loads the current Draft\'s saved placements after the PUT bootstrap and installs them as baseline (load-on-entry)', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.getWidgetLayoutDraft.mockResolvedValueOnce({
            version: 1,
            lock_version: 0,
            placements: [{ uuid: 'p-1', slot_key: 'site_header_before', position: 0 }],
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()

        expect(apiMocks.getWidgetLayoutDraft).toHaveBeenCalledWith('layout-1', expect.any(Object))
        expect(layout.state.status).toBe('ready')
        expect(layout.state.baselineDraft.placements).toEqual([
            { uuid: 'p-1', slot_key: 'site_header_before', position: 0 },
        ])
        expect(layout.state.localDraft.placements).toEqual(layout.state.baselineDraft.placements)
    })

    it('bootstrap() installs the route-31 instance_fingerprints map into shared state', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.getWidgetLayoutDraft.mockResolvedValueOnce({
            version: 1,
            lock_version: 0,
            placements: [{ uuid: 'p-1', slot_key: 'site_header_before', position: 0 }],
            instance_fingerprints: { 'instance-1': 'repin-fp-1' },
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()

        expect(layout.state.instanceFingerprints).toEqual({ 'instance-1': 'repin-fp-1' })
    })

    it('bootstrap() falls back to an empty Draft when loading saved placements fails -- never blocks entry', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.getWidgetLayoutDraft.mockRejectedValueOnce({
            status: 403,
            code: 'WIDGET_PERMISSION_DENIED',
            message: 'denied',
            retryable: false,
            recovery: 'none',
            aborted: false,
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()

        expect(layout.state.status).toBe('ready')
        expect(layout.state.baselineDraft.placements).toEqual([])
        expect(layout.state.localDraft.placements).toEqual([])
    })

    it('never auto-retries a failed save -- only an explicit saveDraft() call issues another request', async () => {
        vi.useFakeTimers()
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.saveWidgetLayoutDraft.mockRejectedValueOnce({
            status: 500,
            code: null,
            message: 'boom',
            retryable: false,
            recovery: 'none',
            aborted: false,
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()
        layout.updateLocalDraft([{ slot_key: 'x', position: 0 }])
        await layout.saveDraft()

        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)
        expect(layout.state.status).toBe('error')

        // Advance well past any plausible retry/backoff window; no timer-driven
        // retry should exist because this composable must never auto-retry.
        await vi.advanceTimersByTimeAsync(60_000)
        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledTimes(1)

        vi.useRealTimers()
    })

    it('a 409 on saveDraft() keeps local edits AND exposes the server conflict draft distinctly', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.saveWidgetLayoutDraft.mockRejectedValueOnce({
            status: 409,
            code: 'WIDGET_DRAFT_STALE',
            message: 'stale',
            retryable: true,
            recovery: 'refresh_draft',
            aborted: false,
        })
        apiMocks.getWidgetLayout.mockResolvedValueOnce(
            layoutResource({ current_draft: { version: 1, lock_version: 5 } })
        )

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()
        layout.updateLocalDraft([{ slot_key: 'mine', position: 0 }])
        await layout.saveDraft()

        expect(layout.state.status).toBe('conflict')
        expect(layout.state.localDraft.placements).toEqual([{ slot_key: 'mine', position: 0 }])
        expect(layout.state.serverConflictDraft).not.toBeNull()
        expect(layout.state.serverConflictDraft.lockVersion).toBe(5)
    })

    it('a 409 on saveDraft() fetches the server draft\'s REAL placements (route 31), so Accept Server never installs an empty array', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.saveWidgetLayoutDraft.mockRejectedValueOnce({
            status: 409,
            code: 'WIDGET_DRAFT_STALE',
            message: 'stale',
            retryable: true,
            recovery: 'refresh_draft',
            aborted: false,
        })
        apiMocks.getWidgetLayout.mockResolvedValueOnce(
            layoutResource({ current_draft: { version: 1, lock_version: 5 } })
        )
        // First getWidgetLayoutDraft resolution is consumed by bootstrap()'s own
        // load-on-entry read; the second is consumed by fetchCurrentDraftIdentity()
        // during 409 handling -- this is the one that must carry real placements.
        apiMocks.getWidgetLayoutDraft.mockResolvedValueOnce({ version: 1, lock_version: 0, placements: [] })
        apiMocks.getWidgetLayoutDraft.mockResolvedValueOnce({
            version: 1,
            lock_version: 5,
            placements: [{ uuid: 'server-placement', slot_key: 'theirs', position: 0 }],
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()
        layout.updateLocalDraft([{ slot_key: 'mine', position: 0 }])
        await layout.saveDraft()

        expect(layout.state.status).toBe('conflict')
        expect(layout.state.serverConflictDraft.placements).toEqual([
            { uuid: 'server-placement', slot_key: 'theirs', position: 0 },
        ])

        layout.resolveConflictAcceptServer()

        expect(layout.state.status).toBe('ready')
        expect(layout.state.localDraft.placements).toEqual([
            { uuid: 'server-placement', slot_key: 'theirs', position: 0 },
        ])
        expect(layout.state.baselineDraft.placements).toEqual(layout.state.localDraft.placements)
    })

    it('saveDraft() success installs the accepted server snapshot/lock as the new baseline', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.saveWidgetLayoutDraft.mockResolvedValueOnce([{ slot_key: 'x', position: 0 }])

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()
        layout.updateLocalDraft([{ slot_key: 'x', position: 0 }])
        await layout.saveDraft()

        expect(apiMocks.saveWidgetLayoutDraft).toHaveBeenCalledWith(
            'layout-1',
            { expected_current_draft_id: 1, expected_lock_version: 0, placements: [{ slot_key: 'x', position: 0 }] },
            expect.any(Object)
        )
        expect(layout.state.status).toBe('ready')
        expect(layout.state.baselineDraft).toEqual({
            version: 1,
            lockVersion: 1,
            placements: [{ slot_key: 'x', position: 0 }],
        })
    })

    it('publish() reuses the same Idempotency-Key on an explicit replay of the same fingerprint', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.publishWidgetLayout.mockRejectedValueOnce({
            status: 503,
            code: 'WIDGET_PROVIDER_UNAVAILABLE',
            retryable: true,
            recovery: 'retry_after',
            aborted: false,
        })
        apiMocks.publishWidgetLayout.mockResolvedValueOnce({
            release: { version: 2 },
            draft: { version: 3, lock_version: 0 },
            sync_status: 'ready',
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()

        await layout.publish() // fails 503
        const firstKey = apiMocks.publishWidgetLayout.mock.calls[0][2].idempotencyKey

        await layout.publish() // explicit replay, same fingerprint (nothing changed)
        const secondKey = apiMocks.publishWidgetLayout.mock.calls[1][2].idempotencyKey

        expect(secondKey).toBe(firstKey)
        expect(apiMocks.publishWidgetLayout).toHaveBeenCalledTimes(2)
    })

    it('repinDraftInstance() installs the returned Draft identity/lock/placements as the new baseline AND local draft', async () => {
        const { useWidgetSiteLayout } = await import('../../../../cms/Widget/admin/use-widget-site-layout')
        apiMocks.bootstrapWidgetLayoutByKey.mockResolvedValueOnce(layoutResource())
        apiMocks.repinWidgetLayoutDraftInstance.mockResolvedValueOnce({
            instance: { uuid: 'inst-1' },
            draft: { version: 1, lock_version: 4, placements: [{ slot_key: 'repinned', position: 0 }] },
            sync_status: 'pending',
        })

        const layout = useWidgetSiteLayout()
        await layout.bootstrap()
        // Baseline/local start at the bootstrap identity/lock.
        expect(layout.state.baselineDraft).toEqual({ version: 1, lockVersion: 0, placements: [] })

        await layout.repinDraftInstance('inst-1', {
            expected_current_instance_version: 1,
            expected_draft_id: 1,
            expected_draft_lock_version: 0,
            usage_fingerprint: 'fp-1',
            widget_id: 10,
            widget_theme_id: 20,
            values: {},
            provider_contract: null,
        })

        // The atomic versions-and-repin response carries the fresh Draft
        // identity/lock AND its placements; all three are committed verbatim into
        // BOTH axes. (The previous applySaveDraftSuccess path silently discarded
        // this: repin never runs a begin step, so inFlightSequence was null and
        // that reducer's sequence guard always early-returned.)
        expect(apiMocks.repinWidgetLayoutDraftInstance).toHaveBeenCalledTimes(1)
        expect(layout.state.baselineDraft).toEqual({
            version: 1,
            lockVersion: 4,
            placements: [{ slot_key: 'repinned', position: 0 }],
        })
        expect(layout.state.localDraft).toEqual(layout.state.baselineDraft)
    })
})
