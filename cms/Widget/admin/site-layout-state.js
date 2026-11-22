/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Chunk 3 Task 25: the deterministic Site Layout draft state machine. Every
// export here is a PURE function -- (state, payload) -> new state, never a
// mutation, no network/timer/DOM access -- so behavior is fully described by
// tests/components/site-layout/widget-layout-draft.spec.ts without mocking
// anything. use-widget-site-layout.js is the
// only caller that performs I/O (calling api.js) and feeds the results back
// through these transitions.
//
// DoD 1358 names six axes that must stay separated rather than collapsed
// into one blob: baselineDraft (last known-good server snapshot), localDraft
// (the user's in-progress edits), previewFingerprint (+ url/expiry, what the
// current preview reflects), serverConflictDraft (the OTHER draft a 409
// revealed, kept distinct from localDraft), requestSequence (a monotonic
// counter guarding against out-of-order responses), and abortController (the
// in-flight primary request's cancellation handle). All six are top-level
// fields on the state object below.

/**
 * @typedef {{version: number, lockVersion: number, placements: unknown[]}} SiteLayoutDraft
 */

/**
 * @typedef {Object} SiteLayoutDraftState
 * @property {'idle'|'bootstrapping'|'ready'|'saving'|'publishing'|'rolling_back'|'conflict'|'error'} status
 * @property {string|null} layoutUuid
 * @property {{version: number}|null} currentPublished
 * @property {'ready'|'pending'|null} syncStatus
 * @property {SiteLayoutDraft|null} baselineDraft
 * @property {SiteLayoutDraft|null} localDraft
 * @property {SiteLayoutDraft|null} serverConflictDraft
 * @property {string|null} previewFingerprint
 * @property {string|null} previewUrl
 * @property {string|null} previewExpiresAt
 * @property {number} requestSequence
 * @property {number|null} inFlightSequence
 * @property {AbortController|null} abortController
 * @property {unknown|null} lastError
 * @property {Record<string, string>} instanceFingerprints - map<widget_instance_uuid, repin usage_fingerprint> from the route-31 draft read; echoed back verbatim on Apply-to-Draft (never recomputed client-side)
 */

/** @returns {SiteLayoutDraftState} */
export function createInitialSiteLayoutDraftState() {
    return {
        status: 'idle',
        layoutUuid: null,
        currentPublished: null,
        syncStatus: null,
        baselineDraft: null,
        localDraft: null,
        serverConflictDraft: null,
        previewFingerprint: null,
        previewUrl: null,
        previewExpiresAt: null,
        requestSequence: 0,
        inFlightSequence: null,
        abortController: null,
        lastError: null,
        instanceFingerprints: {},
    }
}

/** Stable JSON fingerprint -- same content always fingerprints identically,
 * regardless of prior object identity. Used both for preview staleness and
 * as the building block for idempotency-key mutation fingerprints. */
export const stableFingerprint = (value) => JSON.stringify(value ?? null)

/** A draft's fingerprint is its PLACEMENTS content only -- rendering (and
 * therefore preview relevance) depends on placements, not on the
 * version/lockVersion bookkeeping fields. */
export const draftFingerprint = (draft) => stableFingerprint(draft ? draft.placements : null)

const draftFromLayoutResource = (resource, placements = []) =>
    resource.current_draft
        ? {
              version: resource.current_draft.version,
              lockVersion: resource.current_draft.lock_version,
              placements,
          }
        : null

const publishedFromLayoutResource = (resource) =>
    resource.current_published ? { version: resource.current_published.version } : null

// ---------------------------------------------------------------------------
// Bootstrap (DoD 1357): PUT /layouts/by-key/site is the only entry point;
// both its 201 and 200 outcomes install the SAME way below. applyBootstrapSuccess
// optionally seeds the initial placements[] too (from a load-on-entry
// GET /layouts/{uuid}/draft use-widget-site-layout.js's bootstrap() performs
// right after this PUT resolves, disclosed post-freeze contract evolution
// routes 30->31) -- defaults to [] when omitted, preserving the exact prior
// behavior for every existing caller.
// ---------------------------------------------------------------------------

export function beginBootstrap(state, { controller }) {
    const sequence = state.requestSequence + 1
    return {
        ...state,
        status: 'bootstrapping',
        requestSequence: sequence,
        inFlightSequence: sequence,
        abortController: controller,
    }
}

export function applyBootstrapSuccess(state, { sequence, resource, placements = [], instanceFingerprints = {} }) {
    if (sequence !== state.inFlightSequence) return state // stale/out-of-order response, ignored

    const draft = draftFromLayoutResource(resource, placements)
    return {
        ...state,
        status: 'ready',
        layoutUuid: resource.uuid,
        currentPublished: publishedFromLayoutResource(resource),
        syncStatus: 'ready',
        baselineDraft: draft,
        localDraft: draft,
        abortController: null,
        inFlightSequence: null,
        lastError: null,
        // Route-31 read exposes one repin usage_fingerprint per referenced
        // Instance (WidgetLayoutDraftResource.instance_fingerprints). Installed
        // verbatim so the Inspector's Apply-to-Draft can echo the exact token
        // POST .../versions-and-repin validates. Defaults to {} for every
        // pre-existing caller that omits it, preserving prior behavior.
        instanceFingerprints,
    }
}

export function applyBootstrapFailure(state, { sequence, error }) {
    if (sequence !== state.inFlightSequence) return state
    return { ...state, status: 'error', lastError: error, abortController: null, inFlightSequence: null }
}

// ---------------------------------------------------------------------------
// Local editing + save (DoD 1358, 1359)
// ---------------------------------------------------------------------------

/** Pure local edit. Never touches baselineDraft -- that only ever changes
 * once the server has accepted a new snapshot (applySaveDraftSuccess /
 * applyPublishSuccess / applyRollbackSuccess / resolveConflictAcceptServer). */
export function updateLocalDraftPlacements(state, placements) {
    if (!state.localDraft) return state
    return { ...state, localDraft: { ...state.localDraft, placements } }
}

/** Selector: local-vs-baseline divergence IS the dirty flag -- no separate
 * boolean to drift out of sync with the two drafts it describes. */
export function hasUnsavedDraftChanges(state) {
    if (!state.baselineDraft || !state.localDraft) return false
    return draftFingerprint(state.baselineDraft) !== draftFingerprint(state.localDraft)
}

/** A second beginSaveDraft while one is already in flight is a deliberate
 * no-op -- DoD 1360's "never repeat a blind mutation" applies just as much
 * to a double-click as to a network retry. The caller decides whether to
 * surface that as "still saving, please wait" UI; this reducer just refuses
 * to fork a second in-flight save. */
export function beginSaveDraft(state, { controller }) {
    if (state.status === 'saving') return state
    const sequence = state.requestSequence + 1
    return {
        ...state,
        status: 'saving',
        requestSequence: sequence,
        inFlightSequence: sequence,
        abortController: controller,
    }
}

/**
 * Installs the accepted server snapshot/lock (DoD 1359). The Draft Save
 * response body carries ONLY the normalized placements -- WidgetLayoutDraftService
 * increments `lock_version` by exactly one on every successful save and never
 * changes the Draft's own `version` number in place, and the frozen contract
 * deliberately never re-exposes either through this route's response (no raw
 * row id/lock is ever returned here -- see api.js's saveWidgetLayoutDraft
 * docblock). The new lock_version is therefore derived deterministically as
 * `+1`, not read off a field that does not exist in this response.
 */
export function applySaveDraftSuccess(state, { sequence, placements, sentPlacements }) {
    if (sequence !== state.inFlightSequence) return state

    const nextDraft = {
        version: state.localDraft.version,
        lockVersion: state.localDraft.lockVersion + 1,
        placements,
    }
    // A change made while the request was on its way is NOT in the server's answer. When the caller says what it
    // sent (`sentPlacements`) and the local draft has moved on since, the answer becomes the baseline only: the
    // local placements stay as the user left them, on the new revision, so the draft is still dirty and the next
    // save sends the change. Without `sentPlacements` the answer replaces the local draft, as before.
    const changedSinceSent =
        sentPlacements !== undefined && stableFingerprint(state.localDraft.placements) !== stableFingerprint(sentPlacements)
    return {
        ...state,
        status: 'ready',
        baselineDraft: nextDraft,
        localDraft: changedSinceSent ? { ...nextDraft, placements: state.localDraft.placements } : nextDraft,
        serverConflictDraft: null,
        abortController: null,
        inFlightSequence: null,
        lastError: null,
    }
}

/**
 * A 409 preserves local AND server versions SEPARATELY (DoD 1359): localDraft
 * is left exactly as the user had it, serverConflictDraft carries the OTHER
 * (server-side) snapshot as its own distinct field. Neither is merged into
 * the other; an explicit resolveConflictKeepLocal/resolveConflictAcceptServer
 * call is required to collapse them back to one.
 */
export function applySaveDraftConflict(state, { sequence, error, serverDraft = null }) {
    if (sequence !== state.inFlightSequence) return state
    return {
        ...state,
        status: 'conflict',
        serverConflictDraft: serverDraft,
        lastError: error,
        abortController: null,
        inFlightSequence: null,
    }
}

/** A non-conflict failure preserves the local draft untouched so the user
 * can retry explicitly -- this reducer never clears localDraft. */
export function applySaveDraftFailure(state, { sequence, error }) {
    if (sequence !== state.inFlightSequence) return state
    return { ...state, status: 'error', lastError: error, abortController: null, inFlightSequence: null }
}

/** Explicit "discard the server's conflicting version, I'll retry with mine"
 * action. Clears only the conflict axis; localDraft is untouched. */
export function resolveConflictKeepLocal(state) {
    return { ...state, status: 'ready', serverConflictDraft: null, lastError: null }
}

/** Explicit "discard my local edits, adopt the server's version" action. */
export function resolveConflictAcceptServer(state) {
    if (!state.serverConflictDraft) return state
    const server = state.serverConflictDraft
    return { ...state, status: 'ready', baselineDraft: server, localDraft: server, serverConflictDraft: null, lastError: null }
}

// ---------------------------------------------------------------------------
// Shared-instance repin (DoD 1358). POST .../draft/instances/{uuid}/versions-and-repin
// is atomic and, UNLIKE saveWidgetLayoutDraft, DOES echo the fresh Draft's own
// identity/lock in its response (draft.version/draft.lock_version) alongside the
// re-pinned placements[]. So this installer takes all three VERBATIM -- it must
// never re-derive lock_version as the `+1` guess applySaveDraftSuccess uses for
// the save route that deliberately omits it. Repin also has no begin*()/sequence
// step of its own (the composable calls it straight from a ready canvas), so --
// exactly like updateLocalDraftPlacements -- it applies UNCONDITIONALLY rather
// than through a requestSequence guard, which (inFlightSequence being null here)
// would otherwise always discard the commit.
// ---------------------------------------------------------------------------

/**
 * @param {SiteLayoutDraftState} state
 * @param {{draft: {version: number, lock_version: number, placements: unknown[]}}} payload
 */
export function applyRepinDraftInstalled(state, { draft }) {
    const nextDraft = {
        version: draft.version,
        lockVersion: draft.lock_version,
        placements: draft.placements,
    }
    return {
        ...state,
        baselineDraft: nextDraft,
        localDraft: nextDraft,
    }
}

// ---------------------------------------------------------------------------
// Publish / rollback (DoD 1355 dual Release/fresh-Draft identities)
// ---------------------------------------------------------------------------

const PRIMARY_MUTATION_IN_FLIGHT_STATUSES = ['bootstrapping', 'saving', 'publishing', 'rolling_back']

const beginPrimaryMutation = (state, controller, status) => {
    if (PRIMARY_MUTATION_IN_FLIGHT_STATUSES.includes(state.status)) return state
    const sequence = state.requestSequence + 1
    return { ...state, status, requestSequence: sequence, inFlightSequence: sequence, abortController: controller }
}

export const beginPublish = (state, { controller }) => beginPrimaryMutation(state, controller, 'publishing')

export const beginRollback = (state, { controller }) => beginPrimaryMutation(state, controller, 'rolling_back')

/** Installs BOTH the new Release identity and the fresh Draft identity/lock
 * -- never only one of the two (mirrors the backend's own disclosed
 * one-response-two-identities convention). The fresh draft's placements are
 * not echoed by this route either, so the content that was JUST published
 * (the local draft at call time) carries forward as the new draft's starting
 * content -- it is, semantically, a copy of what publish/rollback just
 * committed. */
const applyDualIdentitySuccess = (state, { sequence, release, draft }) => {
    if (sequence !== state.inFlightSequence) return state
    const nextDraft = {
        version: draft.version,
        lockVersion: draft.lock_version,
        placements: state.localDraft ? state.localDraft.placements : [],
    }
    return {
        ...state,
        status: 'ready',
        currentPublished: { version: release.version },
        baselineDraft: nextDraft,
        localDraft: nextDraft,
        serverConflictDraft: null,
        abortController: null,
        inFlightSequence: null,
        lastError: null,
    }
}

export const applyPublishSuccess = applyDualIdentitySuccess

export const applyRollbackSuccess = applyDualIdentitySuccess

const applyPrimaryMutationFailure = (state, { sequence, error }) => {
    if (sequence !== state.inFlightSequence) return state
    return { ...state, status: 'error', lastError: error, abortController: null, inFlightSequence: null }
}

export const applyPublishFailure = applyPrimaryMutationFailure

export const applyRollbackFailure = applyPrimaryMutationFailure

// ---------------------------------------------------------------------------
// Preview (fingerprint-guarded, mirrors the existing
// src/components/widget-editor-state.js `isCurrentPreviewRequest` pattern
// already proven elsewhere in this codebase, rather than a second parallel
// sequence counter).
// ---------------------------------------------------------------------------

/**
 * Installs preview_url/expiry/fingerprint ONLY when `requestedFingerprint`
 * (captured from the local draft at request time) still matches the local
 * draft's CURRENT fingerprint -- if the user kept editing while the request
 * was in flight, the response is stale and silently discarded.
 */
export function applyPreviewSuccess(state, { requestedFingerprint, previewUrl, previewExpiresAt, fingerprint }) {
    if (requestedFingerprint !== draftFingerprint(state.localDraft)) return state
    return { ...state, previewUrl, previewExpiresAt, previewFingerprint: fingerprint }
}

/** Preview failures are informational only -- they never move the primary
 * draft status (a broken preview does not block editing/saving). */
export function applyPreviewFailure(state, { error }) {
    return { ...state, lastError: error }
}

// ---------------------------------------------------------------------------
// Idempotency-key ledger (DoD 1360): generic, reused by every one of the six
// idempotency_scope actions (instance_create/instance_update/instance_clone/
// layout_repin/layout_publish/layout_rollback). Preserves the SAME key for an
// EXPLICIT replay of the SAME fingerprint only; a changed fingerprint (the
// user edited the payload) always mints a fresh key.
// ---------------------------------------------------------------------------

/** @returns {Record<string, {fingerprint: string, key: string}>} */
export function createIdempotencyLedger() {
    return {}
}

/**
 * @param {Record<string, {fingerprint: string, key: string}>} ledger
 * @param {string} action - one of the six frozen idempotency_scope values
 * @param {string} fingerprint - stableFingerprint() of the request payload
 * @returns {{ledger: Record<string, {fingerprint: string, key: string}>, key: string}}
 */
/**
 * UUID v4 that also works on non-secure origins (http:// dev hosts):
 * `crypto.randomUUID` is secure-context-only, `crypto.getRandomValues` is
 * not — falling back keeps every idempotency-scoped mutation usable there.
 */
export function generateUuidV4() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)
    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'))
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`
}

export function getOrCreateIdempotencyKey(ledger, action, fingerprint) {
    const existing = ledger[action]
    if (existing && existing.fingerprint === fingerprint) {
        return { ledger, key: existing.key }
    }

    const key = generateUuidV4()
    return { ledger: { ...ledger, [action]: { fingerprint, key } }, key }
}

// ---------------------------------------------------------------------------
// Emergency runtime-control ledger (DoD 1356), keyed by manifest_hash.
// Models ONLY 200 completion or a thrown normalized error -- never a
// pending/acknowledged-partial state (D-008: no 202 anywhere in this
// contract). A 503 topology-unavailable failure happens BEFORE any row
// lookup on the server, so the caller's existing `expectedControlVersion`
// is preserved untouched for an explicit retry once capability recovers. A
// 409 version-stale failure means the caller's assumption WAS wrong, so it
// is flagged distinctly and requires an explicit refresh_manifest before
// another attempt is meaningful.
// ---------------------------------------------------------------------------

/** @returns {Record<string, {expectedControlVersion: number|null, blocked: boolean|null, topologyUnavailable: boolean, versionStale: boolean}>} */
export function createManifestControlLedger() {
    return {}
}

const emptyManifestControlEntry = () => ({
    expectedControlVersion: null,
    blocked: null,
    topologyUnavailable: false,
    versionStale: false,
})

export function applyManifestControlSuccess(ledger, manifestHash, { controlVersion, blocked }) {
    return {
        ...ledger,
        [manifestHash]: {
            expectedControlVersion: controlVersion,
            blocked,
            topologyUnavailable: false,
            versionStale: false,
        },
    }
}

export function applyManifestControlTopologyUnavailable(ledger, manifestHash) {
    const existing = ledger[manifestHash] ?? emptyManifestControlEntry()
    return { ...ledger, [manifestHash]: { ...existing, topologyUnavailable: true } }
}

export function applyManifestControlVersionStale(ledger, manifestHash) {
    const existing = ledger[manifestHash] ?? emptyManifestControlEntry()
    return { ...ledger, [manifestHash]: { ...existing, versionStale: true, topologyUnavailable: false } }
}
