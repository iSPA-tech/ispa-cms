/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 25: the Vue composable orchestrating api.js + the pure
// site-layout-state.js reducers. This is the ONLY layer that performs I/O
// (calls api.js, owns AbortController instances) or touches Vue reactivity;
// every state TRANSITION it applies is computed by a pure function from
// site-layout-state.js, so the actual decision logic stays covered by
// tests/components/site-layout/widget-layout-draft.spec.ts's direct,
// mock-free reducer tests. Uses only `reactive`/`readonly` (no lifecycle
// hooks), so it is safely callable outside a component `setup()` -- a
// consuming page (Task 26) is responsible for calling `dispose()` from its
// own `onUnmounted` if it wants in-flight requests aborted on navigation.

import { reactive, readonly } from 'vue'
import * as api from './api'
import * as stateMachine from './site-layout-state'

/**
 * @param {SiteLayoutDraft|null} draft
 */
const draftRequestBody = (draft) => ({
    expected_current_draft_id: draft.version,
    expected_lock_version: draft.lockVersion,
    placements: draft.placements,
})

export function useWidgetSiteLayout() {
    const state = reactive(stateMachine.createInitialSiteLayoutDraftState())
    const manifestControl = reactive(stateMachine.createManifestControlLedger())

    // Area editor data that is not part of the draft state machine:
    // - `contents`: what each placement shows, keyed `"<widget_instance_uuid>:<widget_instance_version>"` (the draft
    //   read's `contents` map), so the editor never needs the `/instances` routes;
    // - `changes`: what publishing the draft would change (GET .../draft/changes), for the status chip and the
    //   publish dialog. `changesStatus` is idle | loading | ready | failed.
    const extras = reactive({ contents: {}, changes: [], changesStatus: 'idle' })
    let changesSequence = 0

    // Idempotency-key ledger + a preview-only abort handle are orchestration
    // bookkeeping, not part of the deterministic draft state itself (see
    // site-layout-state.js's module docblock for why preview does not need
    // its own request-sequence field).
    let idempotencyLedger = stateMachine.createIdempotencyLedger()
    let previewAbortController = null
    let saveInFlight = null

    const withIdempotencyKey = (action, body) => {
        const fingerprint = stateMachine.stableFingerprint(body)
        const { ledger, key } = stateMachine.getOrCreateIdempotencyKey(idempotencyLedger, action, fingerprint)
        idempotencyLedger = ledger
        return key
    }

    /** Applies a reducer's result onto the reactive `state` object in place
     * (reducers themselves stay pure and return plain objects). */
    const commit = (next) => {
        if (next !== state) Object.assign(state, next)
        return next
    }

    // -------------------------------------------------------------------
    // Bootstrap (DoD 1357)
    // -------------------------------------------------------------------

    /** Best-effort load of the Layout's CURRENTLY SAVED Draft placements (GET
     * /layouts/{uuid}/draft -- disclosed post-freeze contract evolution,
     * routes 30->31) so the canvas renders a previously-saved Draft on entry
     * instead of always starting empty. Pure read, no side effect. A
     * non-abort failure (403/404/network) falls back to an empty Draft --
     * exactly bootstrap's own behavior before this route existed -- rather
     * than blocking entry over this supplementary read; an ABORT (component
     * disposed mid-flight) propagates so bootstrap() below still ends in its
     * normal error path instead of silently completing after cancel. */
    async function loadDraftPlacements(uuid, signal) {
        try {
            const draft = await api.getWidgetLayoutDraft(uuid, { signal })
            return {
                placements: draft?.placements ?? [],
                instanceFingerprints: draft?.instance_fingerprints ?? {},
                contents: draft?.contents ?? {},
            }
        } catch (error) {
            if (error?.aborted) throw error
            return { placements: [], instanceFingerprints: {}, contents: {} }
        }
    }

    /** Read-only entry for a role without `widget_layout.update`: the PUT bootstrap would be refused, so this reads the
     * project's layouts (GET /layouts) and picks the one with the requested key. A missing layout is a 404-style failure
     * (nothing is created on a read-only entry). */
    async function findLayoutByKey(layoutKey, signal) {
        const { data } = await api.listWidgetLayouts({}, { signal })
        const resource = (Array.isArray(data) ? data : []).find((layout) => layout?.key === layoutKey)
        if (!resource) {
            throw Object.assign(new Error('Widget site layout not found.'), { status: 404, code: 'WIDGET_LAYOUT_NOT_FOUND' })
        }

        return resource
    }

    /** Calls the explicit idempotent PUT /layouts/by-key/{layoutKey} directly
     * -- never a GET first, never a locally fabricated Draft. Immediately
     * follows a successful bootstrap with a load-on-entry GET of the SAME
     * Layout's current Draft placements (loadDraftPlacements above), so the
     * baseline/local draft applyBootstrapSuccess installs reflects a
     * previously-saved Draft rather than always starting empty. */
    async function bootstrap(layoutKey = 'site', { readOnly = false } = {}) {
        const controller = new AbortController()
        const next = commit(stateMachine.beginBootstrap(state, { controller }))
        if (next.status !== 'bootstrapping') return // already bootstrapping; no duplicate call

        const sequence = next.inFlightSequence
        try {
            const resource = readOnly
                ? await findLayoutByKey(layoutKey, controller.signal)
                : await api.bootstrapWidgetLayoutByKey(layoutKey, { signal: controller.signal })
            const { placements, instanceFingerprints, contents } = await loadDraftPlacements(resource.uuid, controller.signal)
            commit(stateMachine.applyBootstrapSuccess(state, { sequence, resource, placements, instanceFingerprints }))
            if (state.status === 'ready') {
                extras.contents = contents
                loadChanges()
            }
        } catch (error) {
            commit(stateMachine.applyBootstrapFailure(state, { sequence, error }))
        }
    }

    // -------------------------------------------------------------------
    // Draft editing + save (DoD 1358, 1359)
    // -------------------------------------------------------------------

    function updateLocalDraft(placements) {
        commit(stateMachine.updateLocalDraftPlacements(state, placements))
    }

    function hasUnsavedChanges() {
        return stateMachine.hasUnsavedDraftChanges(state)
    }

    /**
     * PUT /layouts/{uuid}/draft. Never auto-retried; a second concurrent call
     * while one is already in flight is a no-op (DoD 1360). A 409 follows up
     * with a single GET /layouts/{uuid} to populate serverConflictDraft with
     * the server's REAL current identity (the 409 envelope itself carries no
     * draft snapshot -- see WidgetLayoutDraftController), then stops; it
     * never re-submits automatically.
     */
    function saveDraft() {
        // One save at a time: a call made while a save is in flight (the autosave timer, a flush before a placement
        // request, a second click) waits for that save instead of sending a duplicate PUT. A change made during
        // that save is not lost: it stays in the local draft (applySaveDraftSuccess keeps it), the draft stays
        // dirty, and the caller — the autosave, or flushDraft() below — sends it with a follow-up save.
        if (saveInFlight) return saveInFlight
        saveInFlight = performSaveDraft().finally(() => {
            saveInFlight = null
        })
        return saveInFlight
    }

    async function performSaveDraft() {
        const controller = new AbortController()
        const next = commit(stateMachine.beginSaveDraft(state, { controller }))
        if (next.status !== 'saving') return // already saving; no duplicate mutation

        const sequence = next.inFlightSequence
        const body = draftRequestBody(state.localDraft)

        try {
            const placements = await api.saveWidgetLayoutDraft(state.layoutUuid, body, { signal: controller.signal })
            commit(stateMachine.applySaveDraftSuccess(state, { sequence, placements, sentPlacements: body.placements }))
            loadChanges()
        } catch (error) {
            if (error?.status === 409) {
                const serverDraft = await fetchCurrentDraftIdentity()
                commit(stateMachine.applySaveDraftConflict(state, { sequence, error, serverDraft }))
                return
            }
            commit(stateMachine.applySaveDraftFailure(state, { sequence, error }))
        }
    }

    /** Best-effort refresh of the server's current draft identity for
     * conflict display; a failure here still lands the conflict (with a null
     * serverConflictDraft) rather than losing the user's local edits. Also
     * loads the server draft's REAL placements via the SAME route 31 helper
     * bootstrap() already uses (loadDraftPlacements) -- so a later
     * resolveConflictAcceptServer() (Accept Server) installs the server's
     * actual content instead of an empty array (the 409 error envelope
     * itself carries no placements, only the follow-up GET .../draft does). */
    async function fetchCurrentDraftIdentity() {
        try {
            const resource = await api.getWidgetLayout(state.layoutUuid)
            if (!resource.current_draft) return null

            const { placements } = await loadDraftPlacements(resource.uuid)
            return {
                version: resource.current_draft.version,
                lockVersion: resource.current_draft.lock_version,
                placements,
            }
        } catch {
            return null
        }
    }

    function resolveConflictKeepLocal() {
        commit(stateMachine.resolveConflictKeepLocal(state))
    }

    function resolveConflictAcceptServer() {
        commit(stateMachine.resolveConflictAcceptServer(state))
    }

    // -------------------------------------------------------------------
    // Preview -- fingerprint-guarded against out-of-order responses;
    // superseding requests abort the previous one instead of racing it.
    // -------------------------------------------------------------------

    async function requestPreview(overlayParams) {
        if (!state.localDraft) return

        previewAbortController?.abort()
        const controller = new AbortController()
        previewAbortController = controller

        const requestedFingerprint = stateMachine.draftFingerprint(state.localDraft)
        try {
            const result = await api.createWidgetLayoutPreviewSession(state.layoutUuid, overlayParams, {
                signal: controller.signal,
            })
            commit(
                stateMachine.applyPreviewSuccess(state, {
                    requestedFingerprint,
                    previewUrl: result.preview_url,
                    previewExpiresAt: result.expires_at,
                    fingerprint: result.fingerprint,
                })
            )
        } catch (error) {
            if (error?.aborted) return
            commit(stateMachine.applyPreviewFailure(state, { error }))
        }
    }

    // -------------------------------------------------------------------
    // Publish / rollback (DoD 1355, 1360) -- idempotency-key scoped; never
    // auto-retried, EXPLICIT replay of the same fingerprint reuses the key.
    // -------------------------------------------------------------------

    async function publish() {
        const controller = new AbortController()
        const next = commit(stateMachine.beginPublish(state, { controller }))
        if (next.status !== 'publishing') return

        const sequence = next.inFlightSequence
        const body = { expected_draft_id: state.localDraft.version, expected_lock_version: state.localDraft.lockVersion }
        const idempotencyKey = withIdempotencyKey('layout_publish', body)

        try {
            const result = await api.publishWidgetLayout(state.layoutUuid, body, {
                idempotencyKey,
                signal: controller.signal,
            })
            commit(stateMachine.applyPublishSuccess(state, { sequence, release: result.release, draft: result.draft }))
            await afterReleaseSwitch()
        } catch (error) {
            commit(stateMachine.applyPublishFailure(state, { sequence, error }))
        }
    }

    async function rollback(sourceReleaseVersion) {
        const controller = new AbortController()
        const next = commit(stateMachine.beginRollback(state, { controller }))
        if (next.status !== 'rolling_back') return

        const sequence = next.inFlightSequence
        const body = {
            source_release_version: sourceReleaseVersion,
            expected_draft_id: state.localDraft.version,
            expected_lock_version: state.localDraft.lockVersion,
        }
        const idempotencyKey = withIdempotencyKey('layout_rollback', body)

        try {
            const result = await api.rollbackWidgetLayout(state.layoutUuid, body, {
                idempotencyKey,
                signal: controller.signal,
            })
            commit(stateMachine.applyRollbackSuccess(state, { sequence, release: result.release, draft: result.draft }))
            await afterReleaseSwitch()
        } catch (error) {
            commit(stateMachine.applyRollbackFailure(state, { sequence, error }))
        }
    }

    // -------------------------------------------------------------------
    // Area editor: placement content (Unified Widget Catalog Part 2, Task 3
    // routes). Each placement owns its content; the editor never calls the
    // `/instances` routes. Every call sends the current draft revision
    // (`lock_version`) and an Idempotency-Key, and answers
    // `{ok: true, placementUuid}` or `{ok: false, reason, error}`:
    //   - 'stale'    the draft changed elsewhere (409 WIDGET_DRAFT_STALE): the
    //                draft was reloaded, nothing was applied by this call;
    //   - 'reloaded' edit/duplicate answered 404/409 on a retry that may
    //                already have been applied: the draft was reloaded so the
    //                user sees the real state instead of a blind failure;
    //   - 'blocked'  the widget is blocked (409 WIDGET_BLOCKED);
    //   - 'error'    anything else (`error` is the normalized API error).
    // Never auto-retried.
    // -------------------------------------------------------------------

    /** What a placement shows: `{widget_id, widget_theme_id, widget_name, schema, values, is_blocked}` or null. */
    function contentFor(placement) {
        if (!placement) return null
        return extras.contents[`${placement.widget_instance_uuid}:${placement.widget_instance_version}`] ?? null
    }

    /** Installs a draft in the GET .../draft shape (version, lock_version, placements, contents, fingerprints). */
    function installDraft(draft) {
        commit(stateMachine.applyRepinDraftInstalled(state, { draft }))
        Object.assign(state, { instanceFingerprints: draft.instance_fingerprints ?? state.instanceFingerprints })
        extras.contents = draft.contents ?? {}
    }

    /** Reads the current draft again and installs it (drops unsaved local arrangement). */
    async function reloadDraft() {
        if (!state.layoutUuid) return
        const draft = await api.getWidgetLayoutDraft(state.layoutUuid)
        installDraft({ ...draft, placements: draft?.placements ?? [] })
        if (state.status === 'conflict' || state.status === 'error') {
            Object.assign(state, { status: 'ready', serverConflictDraft: null, lastError: null })
        }
    }

    /** Best-effort read of what publishing would change; a failure only sets `changesStatus`. */
    async function loadChanges() {
        if (!state.layoutUuid) return
        const sequence = ++changesSequence
        extras.changesStatus = 'loading'
        try {
            const rows = await api.getWidgetLayoutDraftChanges(state.layoutUuid)
            if (sequence !== changesSequence) return
            extras.changes = Array.isArray(rows) ? rows : []
            extras.changesStatus = 'ready'
        } catch {
            if (sequence === changesSequence) extras.changesStatus = 'failed'
        }
    }

    /** Publish and rollback leave a fresh draft: read it (new placement ids, contents) and the change list again. */
    async function afterReleaseSwitch() {
        if (state.status !== 'ready') return
        try {
            await reloadDraft()
        } catch {
            // The publish itself succeeded; the next action reloads.
        }
        loadChanges()
    }

    /**
     * Saves until nothing is pending: every arrangement change the user made is on the server when this resolves
     * true. A first wait may join a save that started before the latest change, so a follow-up save sends what is
     * left (bounded, in case the user keeps changing things). False when a save failed or met a conflict — nothing
     * is retried on its own.
     */
    async function flushDraft() {
        for (let attempt = 0; attempt < 5 && hasUnsavedChanges(); attempt++) {
            await saveDraft()
            if (state.status !== 'ready') return false
        }
        return state.status === 'ready' && !hasUnsavedChanges()
    }

    async function runPlacementMutation(request, { reloadOnStatus = [] } = {}) {
        // Arrangement changes still waiting for their autosave go first, so the revision sent below is current.
        // A failed or conflicting save is the draft's own problem (the command bar shows it): nothing was reloaded.
        if (!(await flushDraft()) || !state.baselineDraft) {
            return { ok: false, reason: 'error', error: state.lastError ?? null }
        }

        try {
            const draft = await request(state.baselineDraft.lockVersion)
            installDraft(draft)
            loadChanges()
            return { ok: true, placementUuid: draft.placementUuid ?? null }
        } catch (error) {
            const stale = error?.code === 'WIDGET_DRAFT_STALE'
            if (stale || reloadOnStatus.includes(error?.status)) {
                try {
                    await reloadDraft()
                } catch {
                    // Keep what is on screen; the message still tells the user to check.
                }
                loadChanges()
                return { ok: false, reason: stale && reloadOnStatus.length === 0 ? 'stale' : 'reloaded', error }
            }
            if (error?.code === 'WIDGET_BLOCKED') return { ok: false, reason: 'blocked', error }
            return { ok: false, reason: 'error', error }
        }
    }

    /**
     * POST .../draft/placements: add a widget with its content to an area.
     * @param {{slot_key: string, scope_type: string, scope_key?: ?string, target_type?: ?string, target_id?: ?string, widget_id: number, widget_theme_id: number, values: object}} body
     */
    function addPlacement(body) {
        return runPlacementMutation((revision) => {
            const payload = { ...body, expected_draft_revision: revision }
            return api.createWidgetLayoutDraftPlacement(state.layoutUuid, payload, {
                idempotencyKey: withIdempotencyKey('placement_create', payload),
            })
        })
    }

    /** PATCH .../placements/{uuid}/content: new content (and optionally another design) for one placement. */
    function updatePlacementContent(placementUuid, body) {
        return runPlacementMutation(
            (revision) => {
                const payload = { ...body, expected_draft_revision: revision }
                return api.updateWidgetLayoutDraftPlacementContent(state.layoutUuid, placementUuid, payload, {
                    idempotencyKey: withIdempotencyKey('placement_content_update', { placementUuid, ...payload }),
                })
            },
            { reloadOnStatus: [404, 409] }
        )
    }

    /** POST .../placements/{uuid}/duplicate: a copy with its own content, placed right after the source. */
    function duplicatePlacement(placementUuid) {
        return runPlacementMutation(
            (revision) => {
                const payload = { expected_draft_revision: revision }
                return api.duplicateWidgetLayoutDraftPlacement(state.layoutUuid, placementUuid, payload, {
                    idempotencyKey: withIdempotencyKey('placement_duplicate', { placementUuid, ...payload }),
                })
            },
            { reloadOnStatus: [404, 409] }
        )
    }

    async function repinDraftInstance(instanceUuid, body) {
        const idempotencyKey = withIdempotencyKey('layout_repin', { instanceUuid, ...body })
        const result = await api.repinWidgetLayoutDraftInstance(state.layoutUuid, instanceUuid, body, {
            idempotencyKey,
        })
        // Atomic per the frozen contract: a failure never reaches here with a
        // partial result. The versions-and-repin response DOES echo the fresh
        // Draft's own version/lock_version/placements, so install them verbatim
        // via applyRepinDraftInstalled -- NOT applySaveDraftSuccess, whose
        // sequence guard would discard this commit (repin runs no begin step, so
        // inFlightSequence is null) and whose `+1` lock derivation is meant for
        // the save route that omits lock_version.
        if (result?.draft) {
            commit(stateMachine.applyRepinDraftInstalled(state, { draft: result.draft }))
        }
        return result
    }

    // -------------------------------------------------------------------
    // Emergency runtime-control (DoD 1356) -- 200 completion or a thrown
    // normalized error only, never a pending state. A 503 preserves the
    // caller's existing expected_control_version; a 409 flags versionStale
    // so the caller refreshes before another attempt.
    // -------------------------------------------------------------------

    async function setManifestRuntimeBlock(manifestHash, reason) {
        return mutateManifestRuntimeBlock(api.blockWidgetManifestRuntime, manifestHash, reason)
    }

    async function clearManifestRuntimeBlock(manifestHash, reason) {
        return mutateManifestRuntimeBlock(api.clearWidgetManifestRuntimeBlock, manifestHash, reason)
    }

    async function mutateManifestRuntimeBlock(call, manifestHash, reason) {
        const current = manifestControl[manifestHash]
        const expectedControlVersion = current ? current.expectedControlVersion : null

        try {
            const resource = await call(manifestHash, { expected_control_version: expectedControlVersion, reason })
            Object.assign(
                manifestControl,
                stateMachine.applyManifestControlSuccess(manifestControl, manifestHash, {
                    controlVersion: resource.control_version,
                    blocked: resource.blocked,
                })
            )
            return resource
        } catch (error) {
            if (error?.code === 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE') {
                Object.assign(
                    manifestControl,
                    stateMachine.applyManifestControlTopologyUnavailable(manifestControl, manifestHash)
                )
            } else if (error?.code === 'WIDGET_RUNTIME_CONTROL_VERSION_STALE') {
                Object.assign(
                    manifestControl,
                    stateMachine.applyManifestControlVersionStale(manifestControl, manifestHash)
                )
            }
            throw error
        }
    }

    function dispose() {
        state.abortController?.abort()
        previewAbortController?.abort()
    }

    return {
        state: readonly(state),
        manifestControl: readonly(manifestControl),

        bootstrap,
        updateLocalDraft,
        hasUnsavedChanges,
        saveDraft,
        resolveConflictKeepLocal,
        resolveConflictAcceptServer,
        requestPreview,
        publish,
        rollback,

        extras: readonly(extras),
        contentFor,
        flushDraft,
        reloadDraft,
        loadChanges,
        addPlacement,
        updatePlacementContent,
        duplicatePlacement,
        repinDraftInstance,

        setManifestRuntimeBlock,
        clearManifestRuntimeBlock,

        dispose,
    }
}
