<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Chunk 3 Task 30b, redesigned (IA pass): Release History is now a REAL
    right-hand drawer (wrapping the module's own widget-layout-drawer.vue
    slide-over, which owns backdrop/Escape/focus-return), opened from the
    command bar's History button -- no longer an i-box card living inline at
    the bottom of the page with its own duplicate Publish button.

    PUBLISH MOVED OUT: the publish confirm + mutation + stale-draft recovery
    now live in the host page's own confirm dialog (pages/placements.vue),
    right where the single command-bar Publish button is -- one publish
    entry point, confirmed in place, never a scrollIntoView() jump. This
    drawer manages HISTORY + ROLLBACK only.

    INITIAL-LOAD FIX: the old component fetched releases in onMounted, but
    at mount time the parent's layout.bootstrap() has not resolved yet, so
    `layoutUuid` was still null and the fetch silently no-opped -- "No
    releases yet" could show for a layout that HAS releases. Loading is now
    driven by a watcher on `layout.state.layoutUuid` (immediate) plus a
    refresh whenever the drawer opens, with an explicit loading state.

    Release History (`GET /layouts/{uuid}/releases`) stays a plain read this
    component calls via api.js directly -- the SAME "page-level GET calls
    api.js directly, mutations go through the composable" split established
    across this module. Rollback wiring is unchanged from Task 30c: this
    drawer only tracks WHICH release was clicked; widget-layout-rollback-dialog.vue
    owns the confirmation/mutation and this drawer refreshes the list after
    its `rolled-back` emit.

    PERMISSION: the list is visible to anyone who can reach the page (Task
    28's "eligible catalog visible to any viewer" precedent); only Rollback
    is gated (`widget_layout.rollback`). The backend stays authoritative.
-->
<template>
    <widget-layout-drawer
        :open="open"
        side="right"
        :title="$t('nav.a.site-layout-release-history')"
        wide
        @close="$emit('close')"
    >
        <div class="space-y-3" data-test="release-drawer">
            <p v-if="loading" class="m-0 text-sm text-slate-400" data-test="release-history-loading">
                {{ $t('widget_site_layout.editor.loading') }}
            </p>

            <ul v-else class="m-0 list-none space-y-2 p-0" data-test="release-history">
                <li
                    v-for="row in releases"
                    :key="row.version"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-2.5 text-xs"
                    :data-test="`release-row-${row.version}`"
                >
                    <div class="min-w-0">
                        <p class="m-0 flex flex-wrap items-center gap-1.5">
                            <span class="text-sm font-semibold text-slate-800" :data-test="`release-time-${row.version}`">
                                {{ formatLocalDateTime(row.published_at) }}
                            </span>
                            <span
                                v-if="row.is_live"
                                class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800"
                                :data-test="`release-live-${row.version}`"
                            >
                                {{ $t('widget_site_layout.area.live') }}
                            </span>
                        </p>
                        <p v-if="row.published_by_name" class="m-0 text-slate-500" :data-test="`release-by-${row.version}`">
                            {{ $t('widget_site_layout.area.published_by', { name: row.published_by_name }) }}
                        </p>
                        <p v-if="row.rollback_of_version" class="m-0 text-amber-700">
                            {{ $t('widget_site_layout.area.restored_from') }}
                        </p>
                    </div>
                    <button
                        v-if="canRollback && !row.is_live"
                        type="button"
                        class="rounded-lg border border-amber-300 px-3 py-1.5 text-sm text-amber-800 hover:bg-amber-50"
                        :data-test="`rollback-to-version-${row.version}`"
                        @click="openRollback(row)"
                    >
                        {{ $t('nav.a.site-layout-rollback-to-version') }}
                    </button>
                </li>
                <li v-if="!releases.length" class="text-xs italic text-slate-400" data-test="release-history-empty">
                    {{ $t('nav.a.site-layout-release-history-empty') }}
                </li>
            </ul>

            <widget-layout-rollback-dialog
                :open="showRollbackDialog"
                :releases="releases"
                :initial-release-version="rollbackSourceVersion"
                :layout="layout"
                @close="showRollbackDialog = false"
                @rolled-back="onRolledBack"
            />
        </div>
    </widget-layout-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { can } from '@/mixins'
import * as api from '../api'
import { iWidgetError } from '../widget-error-toast'
import { formatLocalDateTime } from '../area-format'
import WidgetLayoutDrawer from './widget-layout-drawer.vue'
import WidgetLayoutRollbackDialog from './widget-layout-rollback-dialog.vue'

defineOptions({ name: 'WidgetLayoutReleaseDrawer' })

const props = defineProps({
    /** The shared useWidgetSiteLayout() instance placements.vue owns. */
    layout: {
        type: Object,
        required: true,
    },
    /** Drawer visibility -- owned by the host page (command bar History button). */
    open: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['close'])

const canRollback = computed(() => can('widget_layout.rollback'))

// ---------------------------------------------------------------------------
// Release History -- plain read. Loaded as soon as bootstrap resolves the
// layout UUID (see file docblock's initial-load fix), refreshed on every
// drawer open and after every successful rollback.
// ---------------------------------------------------------------------------

const releases = ref([])
const loading = ref(false)

async function loadReleases() {
    if (!props.layout.state.layoutUuid) return
    loading.value = true
    try {
        const result = await api.listWidgetLayoutReleases(props.layout.state.layoutUuid)
        releases.value = result?.data ?? []
    } catch (error) {
        iWidgetError(error)
    } finally {
        loading.value = false
    }
}

watch(
    () => props.layout.state.layoutUuid,
    (uuid) => {
        if (uuid) loadReleases()
    },
    { immediate: true }
)

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) loadReleases()
    }
)

// ---------------------------------------------------------------------------
// Rollback entry point (Task 30c) -- this drawer only owns the trigger +
// tracks which release was clicked; widget-layout-rollback-dialog.vue owns
// the confirmation/selection/mutation itself.
// ---------------------------------------------------------------------------

const showRollbackDialog = ref(false)
const rollbackSourceVersion = ref(null)

function openRollback(row) {
    rollbackSourceVersion.value = row.version
    showRollbackDialog.value = true
}

async function onRolledBack() {
    showRollbackDialog.value = false
    await loadReleases()
}

defineExpose({ loadReleases })
</script>
