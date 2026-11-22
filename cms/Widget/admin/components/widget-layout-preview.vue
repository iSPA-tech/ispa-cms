<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Chunk 3 Task 29: the sandboxed signed full-page preview iframe. Calls
    api.js's createWidgetLayoutPreviewSession (Task 25's typed wrapper for
    POST /layouts/{uuid}/preview-sessions) DIRECTLY -- like Task 27/28's
    self-contained precedent (widget-target-picker.vue,
    widget-placement-inspector.vue) -- rather than routing through
    use-widget-site-layout.js's own requestPreview() orchestration. Two
    reasons: (1) that composable's `state.lastError` field is shared/
    overloaded across bootstrap/save/publish/rollback/preview, too coarse a
    signal for this component's own "clean recovery, never a broken frame"
    contract; (2) its `requestPreview()` swallows every failure internally
    (never rethrows), so a caller cannot distinguish "this specific mint
    failed" from "nothing happened yet". This component owns its own
    loading/error/sequence state instead, using the SAME monotonic-sequence
    stale-response-guard pattern already proven throughout this module
    (widget-target-picker.vue's runSearch, widget-placement-inspector.vue's
    save/applyToDraft).

    PERSISTED-INSTANCE-ONLY CONTRACT: `placements` must be the Layout's
    PERSISTED placements (baselineDraft, never localDraft/unsaved edits) --
    the overlay this component sends is built EXCLUSIVELY from that prop.
    A placement missing `widget_instance_uuid` is dropped from the overlay
    rather than sent half-invalid: `widget_instance_uuid` (flat, alongside a
    separate integer `widget_instance_version`) IS part of both GET
    /layouts/{uuid}/draft's WidgetLayoutDraftResource and PUT .../draft's
    WidgetPlacementResource (confirmed against WidgetLayoutDraftController::
    placementPayload() in the sibling ispa-cms repo -- ispa-cms commit
    ea3bfebe2 flattened both projections so read now equals write); this
    filter is defensive/backward-compatible only, not a workaround for a
    currently-missing field.

    STALE-AFTER-EDIT: `isDraftDirty` (the host page's
    layout.hasUnsavedChanges() -- localDraft diverging from baselineDraft)
    drives a visible "stale" badge WITHOUT ever blanking the iframe or
    re-minting -- the overlay is persisted-only, so a local, unsaved canvas
    edit cannot change what a fresh mint would even return until it is
    Saved; the badge exists purely so the admin is never misled into
    thinking the iframe reflects their current, unsaved canvas arrangement.

    EXPIRY: the signed preview_url is short-lived server-side
    (WidgetPreviewSessionService::DEFAULT_TTL_SECONDS). Client-side expiry
    is detected against `expires_at` and swaps the iframe for an explicit
    "Refresh" recovery affordance -- never auto-polled/auto-retried (this
    module's standing "never auto-retry" rule), matching a mint FAILURE's
    own recovery panel (same button, same clean-recovery contract).

    IFRAME SECURITY (exact, plan DoD): `title` set; `sandbox=""` (no
    allow-scripts / allow-same-origin / allow-forms / allow-top-navigation
    -- nothing is allowlisted at all, matching "visual/structural MVP, not
    interactive-JS parity"); `referrerpolicy="no-referrer"`. No admin
    credential is ever attached here -- the iframe `src` is simply the
    already-fully-self-authorizing signed URL the backend minted; a browser
    still applies its OWN same-site cookie rules to that request
    independently of anything this component does. Proving the admin's own
    session cookie never personalizes the anonymous public response is a
    browser-level, backend+infra concern
    (WidgetPreviewInfrastructureContractTest already proves no `Set-Cookie`/
    project-name-in-plaintext server-side) -- deferred to a manual/E2E check
    per this task's own brief, not something a component test can observe.
-->
<!--
    Area editor (Unified Widget Catalog Part 2, Task 9): the page target is optional. Without one the backend binds the
    home page (global and page-type rows apply); with one, the picked page. In editor mode the bridge script also marks
    every shared area of the rendered page: this component hands it the area names (`areaLabels`, `addLabel`) so each
    area gets a labelled bar with an add button in place, reports which areas the page has (`areas` event, null until
    known) and relays a click on an add button (`add` event with the area key).
-->
<template>
    <div class="space-y-2" data-test="widget-layout-preview">
        <!-- Task 31: a polite live region announcing preview mint success/
             failure to screen readers WITHOUT moving focus. The visible
             stale badge/recovery panel below already have their own
             role="status"/role="alert"; this dedicated announcer additionally
             narrates the transient "generated / unavailable" outcome the host
             page (placements.vue) also folds into its own page-level
             announcer, so a keyboard-only preview refresh is never silent. -->
        <p aria-atomic="true" aria-live="polite" class="sr-only" data-test="preview-announcer">{{ announcement }}</p>

        <p v-if="!editorMode" class="m-0 text-xs italic text-slate-500" data-test="preview-mvp-note">
            {{ $t('nav.a.site-layout-preview-mvp-note') }}
        </p>

        <p v-if="showPlaceholder" class="m-0 text-sm text-slate-400" data-test="preview-placeholder">
            {{ $t('nav.a.site-layout-preview-select-target') }}
        </p>

        <p v-else-if="loading && !previewUrl" class="m-0 text-sm text-slate-400" data-test="preview-loading">
            {{ $t('nav.b.search') }}...
        </p>

        <div
            v-else-if="showRecovery"
            class="i-box space-y-2 border-amber-200 bg-amber-50"
            data-test="preview-recovery"
            role="alert"
        >
            <p class="m-0 text-sm text-amber-800">{{ $t('nav.a.site-layout-preview-unavailable') }}</p>
            <button
                class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-500"
                data-test="preview-refresh"
                type="button"
                @click="mintPreview"
            >
                {{ $t('nav.a.site-layout-preview-refresh') }}
            </button>
        </div>

        <div v-else-if="previewUrl" class="space-y-2">
            <p
                v-if="isDraftDirty"
                class="m-0 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm text-amber-800"
                data-test="preview-stale-badge"
                role="status"
            >
                {{ $t('nav.a.site-layout-preview-stale') }}
            </p>

            <iframe
                ref="frameEl"
                :data-viewport="context?.viewport ?? 'desktop'"
                :sandbox="editorMode ? 'allow-scripts' : ''"
                :src="previewUrl"
                :style="frameStyle"
                :title="$t('nav.a.site-layout-preview-frame-title')"
                class="max-w-full rounded-lg border border-slate-200 bg-white"
                data-test="preview-frame"
                referrerpolicy="no-referrer"
            />

            <button
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                data-test="preview-refresh"
                type="button"
                @click="mintPreview"
            >
                {{ $t('nav.a.site-layout-preview-refresh') }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '../api'
import * as stateMachine from '../site-layout-state'

defineOptions({ name: 'WidgetLayoutPreview' })

const { t } = useI18n()

const props = defineProps({
    layoutUuid: {
        type: String,
        default: null,
    },
    /** PERSISTED placements only (host page's baselineDraft.placements) --
     * never the localDraft/unsaved canvas arrangement. See file docblock. */
    placements: {
        type: Array,
        default: () => [],
    },
    /** host page's layout.hasUnsavedChanges() -- drives the stale badge only. */
    isDraftDirty: {
        type: Boolean,
        default: false,
    },
    /** { targetType, targetId, templateContext, locale, viewport } from
     * site-layout-context-toolbar.vue's `update:modelValue`. */
    context: {
        type: Object,
        default: () => ({}),
    },
    /** Clickable-preview (editor) mode: mints with an editor nonce so the
     * backend wraps placements in hit-box markers and injects the bridge
     * script, and relaxes the sandbox to `allow-scripts` ONLY (still an
     * opaque origin: no cookies, no storage, no same-origin powers, no
     * top-navigation). Default false keeps the plain preview byte-for-byte
     * identical to before, including `sandbox=""`. */
    editorMode: {
        type: Boolean,
        default: false,
    },
    /** The host page's currently-selected placement composite key
     * (`slot_key::scope_identity::widget_instance_uuid`); echoed into the
     * iframe as a highlight command whenever it changes. */
    selectedKey: {
        type: String,
        default: null,
    },
    /** Editor mode: area names by area key, drawn on the page as a bar at the top of each area. */
    areaLabels: {
        type: Object,
        default: () => ({}),
    },
    /** Editor mode: text of the add button on each area bar. */
    addLabel: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['select', 'areas', 'add'])

const previewUrl = ref(null)
const previewExpiresAt = ref(null)
const loading = ref(false)
const error = ref(null)
const now = ref(Date.now())
const announcement = ref('')
const frameEl = ref(null)

/** Editor-bridge message-pairing token (never a credential): one per
 * component lifetime, embedded server-side into the injected bridge script
 * at mint; both directions of the postMessage channel are filtered on it. */
const editorNonce = stateMachine.generateUuidV4()

let abortController = null
let requestSequence = 0
let lastRequestKey = null
let tickHandle = null

/** A page is picked only when its three parts are all known; the backend takes them together or not at all. */
const hasTarget = computed(
    () =>
        Boolean(props.context?.targetType) &&
        Boolean(props.context?.targetId) &&
        Boolean(props.context?.templateContext)
)

// No page picked = the home page (the backend binds it), so only the layout and a language are required.
const canRequestPreview = computed(() => Boolean(props.layoutUuid) && Boolean(props.context?.locale))

/** Strictly derived from the `placements` PROP (persisted-only) -- a row
 * missing `widget_instance_uuid` (defensive only; see file docblock) is
 * dropped rather than sent half-invalid. */
const overlay = computed(() =>
    props.placements
        .filter((placementItem) => Boolean(placementItem?.widget_instance_uuid))
        .map((placementItem) => ({
            slot_key: placementItem.slot_key,
            scope_type: placementItem.scope_type,
            scope_key: placementItem.scope_key ?? null,
            target_type: placementItem.target_type ?? null,
            target_id: placementItem.target_id ?? null,
            widget_instance_uuid: placementItem.widget_instance_uuid,
            widget_instance_version: placementItem.widget_instance_version,
            position: placementItem.position,
            enabled: placementItem.enabled,
            rules: placementItem.rules ?? {},
        }))
)

const isExpired = computed(() => {
    if (!previewExpiresAt.value) return false
    return now.value >= new Date(previewExpiresAt.value).getTime()
})

const showRecovery = computed(() => Boolean(error.value) || isExpired.value)
const showPlaceholder = computed(() => !canRequestPreview.value && !previewUrl.value && !loading.value && !error.value)

const frameStyle = computed(() => {
    // The editor gives the page most of the screen height; the plain preview keeps its fixed frame.
    const height = props.editorMode ? { height: '75vh', minHeight: '480px' } : { height: '640px' }
    return props.context?.viewport === 'mobile'
        ? { width: '375px', maxWidth: '100%', margin: '0 auto', display: 'block', ...height }
        : { width: '100%', ...height }
})

/**
 * Mints a fresh preview session (never idempotency-scoped by design --
 * "mints a fresh session every call", api.js's own docblock). Guards against
 * a late/out-of-order response overwriting a newer one via a monotonic
 * sequence check, and aborts the previous in-flight request when superseded
 * -- identical pattern to widget-target-picker.vue's runSearch(). A failure
 * (mint error OR the caller re-invoking this as an explicit "Refresh" after
 * client-side expiry) clears any existing preview_url so the recovery panel
 * takes over cleanly -- never leaves a stale/dead iframe visible next to an
 * error.
 */
async function mintPreview() {
    if (!canRequestPreview.value) return

    abortController?.abort()
    const controller = new AbortController()
    abortController = controller
    const sequence = ++requestSequence

    loading.value = true
    emit('areas', null) // a new page is coming: which areas it has is unknown until its bridge reports
    const body = {
        overlay: overlay.value,
        locale: props.context.locale,
        viewport: props.context.viewport ?? null,
    }
    if (hasTarget.value) {
        body.template_context = props.context.templateContext
        body.target_type = props.context.targetType
        body.target_id = props.context.targetId
    }
    if (props.editorMode) {
        body.editor_nonce = editorNonce
    }

    try {
        const result = await api.createWidgetLayoutPreviewSession(props.layoutUuid, body, {
            signal: controller.signal,
        })
        if (sequence !== requestSequence) return // superseded/out-of-order, discard

        previewUrl.value = result.preview_url
        previewExpiresAt.value = result.expires_at
        error.value = null
        // Live-region text for screen readers (the frame itself is silent).
        announcement.value = t('text.widget.preview_generated')
    } catch (thrown) {
        if (sequence !== requestSequence) return
        if (thrown?.aborted) return // superseded by a newer request; not a real failure

        error.value = thrown
        previewUrl.value = null
        previewExpiresAt.value = null
        announcement.value = t('nav.a.site-layout-preview-unavailable')
    } finally {
        if (sequence === requestSequence) loading.value = false
    }
}

/** Auto-mints once the context is complete, and re-mints whenever the
 * SELECTED CONTEXT (target/locale/viewport) or the PERSISTED placements
 * change -- a deliberate new selection is clear intent, unlike an
 * unsaved draft edit (isDraftDirty), which never triggers this. Dedup via
 * a request-key snapshot avoids re-minting for a no-op reactivity tick. */
watch(
    [() => props.layoutUuid, () => props.context, () => overlay.value],
    () => {
        if (!canRequestPreview.value) return
        const key = JSON.stringify({ layoutUuid: props.layoutUuid, context: props.context, overlay: overlay.value })
        if (key === lastRequestKey) return
        lastRequestKey = key
        mintPreview()
    },
    { deep: true, immediate: true }
)

// ---------------------------------------------------------------------------
// Editor bridge (clickable preview). Inbound messages are accepted ONLY when
// both the fixed source marker AND this component's own editor nonce match --
// origin cannot be checked (the sandboxed iframe is an opaque origin, so its
// messages arrive with origin "null"). Outbound highlight commands target
// '*' for the same reason; they carry nothing but a placement identity key.
// ---------------------------------------------------------------------------

function onBridgeMessage(event) {
    const data = event?.data
    if (!props.editorMode || !data || data.source !== 'wpe-layout-preview' || data.nonce !== editorNonce) return

    if (data.type === 'placement-click' && typeof data.key === 'string') {
        emit('select', { key: data.key, slotKey: data.slotKey ?? null })
    } else if (data.type === 'area-add' && typeof data.slotKey === 'string') {
        emit('add', data.slotKey)
    } else if (data.type === 'ready') {
        // Fresh document: draw the area bars, report which areas this page has, re-assert the selection.
        postAreas()
        emit('areas', Array.isArray(data.areas) ? data.areas.filter((key) => typeof key === 'string') : [])
        postToFrame({ type: 'select', key: props.selectedKey })
    }
}

function postAreas() {
    postToFrame({ type: 'areas', labels: { ...props.areaLabels }, addLabel: props.addLabel })
}

/** Scroll the preview to an area (no-op when the page does not have it). */
function focusArea(slotKey) {
    postToFrame({ type: 'focus-area', slotKey })
}

function postToFrame(payload) {
    const target = frameEl.value?.contentWindow
    if (!target) return
    try {
        target.postMessage({ source: 'wpe-layout-admin', nonce: editorNonce, ...payload }, '*')
    } catch {
        // Frame not ready yet -- the bridge re-syncs itself via 'ready'.
    }
}

watch(
    () => props.selectedKey,
    (key) => {
        if (!props.editorMode) return
        postToFrame(key ? { type: 'select', key } : { type: 'clear' })
    }
)

watch([() => props.areaLabels, () => props.addLabel], () => props.editorMode && postAreas(), { deep: true })

onMounted(() => {
    tickHandle = setInterval(() => {
        now.value = Date.now()
    }, 1000)
    window.addEventListener('message', onBridgeMessage)
})

onUnmounted(() => {
    if (tickHandle) clearInterval(tickHandle)
    abortController?.abort()
    window.removeEventListener('message', onBridgeMessage)
})

defineExpose({ mintPreview, focusArea })
</script>
