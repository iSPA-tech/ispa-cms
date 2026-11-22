<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Chunk 3 Task 30c: rollback-by-copy UI. Standalone, testable in isolation
    (mounted directly by its own spec) and hosted by widget-layout-release-drawer.vue
    (Task 30b, modified by this task to add the trigger + this mount).

    Consumes the SAME shared `useWidgetSiteLayout()` instance (`layout` prop)
    for the mutation itself -- `layout.rollback(sourceReleaseVersion)` (Task
    25, unmodified) already implements idempotency-key reuse/minting and the
    dual-identity (Release + fresh Draft/lock) install faithfully, reading
    `expected_draft_id`/`expected_lock_version` off `state.localDraft` LIVE at
    call time -- so this dialog never caches/pre-computes those pointers
    itself; it only ever passes the user-selected `source_release_version`.

    SOURCE RELEASE SELECTION: `releases` (Task 30b's already-fetched Release
    History) is passed in as a prop rather than re-fetched here, avoiding a
    duplicate `GET /layouts/{uuid}/releases`. `initialReleaseVersion`
    pre-selects whichever row's "Rollback to this version" trigger opened the
    dialog, but the select remains changeable -- confirmed against
    RollbackWidgetLayoutRequest/WidgetLayoutRollbackService that the backend
    itself does not forbid rolling back to any state=release version scoped
    to this Layout, so no extra client-side restriction (e.g. excluding the
    current published version) is invented beyond what the backend enforces.

    SCOPE COPY (plan DoD): a fixed, always-visible note explains rollback
    restores the LAYOUT SNAPSHOT (placements/scope/order) only -- never
    historical menu/post/provider rows from that time -- mirroring
    widget-layout-preview.vue's own always-visible MVP note pattern.

    STALE CONFLICT / NO AUTO-RETRY: identical pattern to Task 30b's publish
    drawer -- rollback shares publish's single generic `status: 'error'`
    outcome for every failure (no dedicated 'conflict' status), so this
    dialog inspects `layout.state.lastError.code === 'WIDGET_DRAFT_STALE'`
    itself for a dedicated recovery panel (Refresh -> `layout.bootstrap()`);
    every other failure (403/404/422/503 -- including rollback's own
    code-less 404 for "source release not found", confirmed against
    WidgetLayoutRollbackController's plain `notFound()` helper) routes
    through the SAME shared `iWidgetError(error)` toast. Neither path is
    ever auto-retried.
-->
<template>
    <div v-if="open" class="i-box space-y-3" data-test="rollback-dialog" role="alertdialog">
        <h3 class="m-0 text-sm font-semibold text-slate-900">{{ $t('nav.a.site-layout-rollback-title') }}</h3>
        <p class="m-0 text-xs text-slate-600" data-test="rollback-scope-note">
            {{ $t('nav.a.site-layout-rollback-scope-note') }}
        </p>

        <template v-if="canRollback">
            <div>
                <label class="mb-1 block text-xs font-medium uppercase text-slate-500">
                    {{ $t('nav.a.site-layout-rollback-source-label') }}
                </label>
                <select
                    v-model="selectedVersion"
                    class="block w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                    data-test="rollback-source-select"
                >
                    <option v-for="row in releases" :key="row.version" :value="row.version">
                        {{ releaseLabel(row) }}
                    </option>
                </select>
            </div>

            <p v-if="selectedRelease" class="m-0 text-xs text-slate-600" data-test="rollback-confirm-summary">
                {{ $t('nav.a.site-layout-rollback-confirm-summary') }}: {{ releaseLabel(selectedRelease) }}
            </p>

            <div
                v-if="isStaleDraftError"
                class="space-y-2 rounded-lg border border-rose-200 bg-rose-50 p-3"
                data-test="rollback-stale-error"
                role="alert"
            >
                <p class="m-0 text-sm text-rose-800">{{ $t('nav.a.site-layout-rollback-stale') }}</p>
                <button
                    type="button"
                    class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white"
                    data-test="rollback-stale-refresh"
                    @click="layout.bootstrap()"
                >
                    {{ $t('nav.a.site-layout-conflict-reload') }}
                </button>
            </div>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    data-test="rollback-confirm"
                    :disabled="!selectedVersion || isPrimaryMutationInFlight"
                    @click="confirmRollback"
                >
                    {{ $t('widget_site_layout.action.confirm') }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700"
                    data-test="rollback-cancel"
                    @click="cancel"
                >
                    {{ $t('nav.b.cancel') }}
                </button>
            </div>
        </template>
        <p v-else class="m-0 text-xs text-rose-700" data-test="rollback-permission-denied">
            {{ $t('nav.a.site-layout-rollback-denied') }}
        </p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { can, iAlert } from '@/mixins'
import { iWidgetError } from '../widget-error-toast'
import { formatLocalDateTime } from '../area-format'

defineOptions({ name: 'WidgetLayoutRollbackDialog' })

/** A release named by when it was published (reader's own date and time) and by whom. */
const releaseLabel = (row) =>
    [formatLocalDateTime(row?.published_at), row?.published_by_name].filter(Boolean).join(' · ')

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    /** Task 30b's already-fetched Release History rows. */
    releases: {
        type: Array,
        default: () => [],
    },
    /** Pre-selects whichever row's trigger opened this dialog. */
    initialReleaseVersion: {
        type: [Number, String],
        default: null,
    },
    /** The shared useWidgetSiteLayout() instance placements.vue owns. */
    layout: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['close', 'rolled-back'])

const canRollback = computed(() => can('widget_layout.rollback'))

const selectedVersion = ref(props.initialReleaseVersion)

// Re-seed the selection whenever the dialog is (re)opened for a different row.
watch(
    () => [props.open, props.initialReleaseVersion],
    ([isOpen]) => {
        if (isOpen) selectedVersion.value = props.initialReleaseVersion
    }
)

const selectedRelease = computed(
    () => props.releases.find((row) => String(row.version) === String(selectedVersion.value)) ?? null
)

/** Mirrors the SAME (private, unexported) in-flight status list Task 30b's
 * release drawer already duplicates from site-layout-state.js -- Confirm
 * stays disabled only while a primary mutation is truly in flight, never
 * permanently after a failed attempt. */
const isPrimaryMutationInFlight = computed(() =>
    ['bootstrapping', 'saving', 'publishing', 'rolling_back'].includes(props.layout.state.status)
)

const isStaleDraftError = computed(
    () => props.layout.state.status === 'error' && props.layout.state.lastError?.code === 'WIDGET_DRAFT_STALE'
)

/** rollback() never throws -- resolves after committing either success or a
 * `status: 'error'` failure internally. `expected_draft_id`/
 * `expected_lock_version` are read by the composable off `state.localDraft`
 * LIVE at call time (never cached here), so they always reflect the current
 * pointers even if a Save happened between this dialog opening and Confirm. */
async function confirmRollback() {
    if (!selectedVersion.value) return
    await props.layout.rollback(Number(selectedVersion.value))

    if (props.layout.state.status === 'ready') {
        emit('rolled-back')
        return
    }
    if (props.layout.state.lastError?.code !== 'WIDGET_DRAFT_STALE') {
        iWidgetError(props.layout.state.lastError)
    }
}

function cancel() {
    emit('close')
}

defineExpose({ confirmRollback, cancel })
</script>
