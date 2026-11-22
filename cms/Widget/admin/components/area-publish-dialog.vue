<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    "Xuất bản thay đổi lên site?" — the one place a draft of the shared areas goes live. Before confirming, the user
    reads what will change in plain words, one line per change ("Thêm “Banner” vào Đầu trang · Toàn website"), from
    GET .../draft/changes (`layout.extras.changes`, read again every time the dialog opens). A label the server could
    not translate is translated here from its key.

    The mutation stays layout.publish(). A draft someone else changed meanwhile (409 WIDGET_DRAFT_STALE) gets its own
    panel with "Tải lại bản mới nhất"; every other failure is shown by the command bar's toast. Never retried
    automatically; `if (publishing) return` stops a double submit.
-->
<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-test="area-publish-dialog">
        <div class="absolute inset-0 bg-slate-900/40" data-test="publish-dialog-backdrop" @click="close"></div>
        <div
            aria-labelledby="area-publish-title"
            aria-modal="true"
            class="relative z-10 flex max-h-full w-full max-w-lg flex-col rounded-xl border border-slate-200 bg-white shadow-xl"
            data-test="publish-confirm-panel"
            role="alertdialog"
            @keydown.esc="close"
        >
            <h2 id="area-publish-title" class="m-0 px-4 pt-4 text-base font-semibold text-slate-900">
                {{ $t('widget_site_layout.area.publish_title') }}
            </h2>

            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
                <p v-if="status === 'loading' && !rows.length" class="m-0 text-sm text-slate-500" data-test="publish-changes-loading">
                    {{ $t('widget_site_layout.editor.loading') }}
                </p>
                <p v-else-if="status === 'failed'" class="m-0 text-sm text-amber-800" data-test="publish-changes-failed">
                    {{ $t('widget_site_layout.area.publish_changes_failed') }}
                </p>
                <p v-else-if="!rows.length" class="m-0 text-sm text-slate-600" data-test="publish-changes-none">
                    {{ $t('widget_site_layout.area.publish_none') }}
                </p>
                <ul v-else class="m-0 list-none space-y-2 p-0" data-test="publish-changes">
                    <li
                        v-for="(row, index) in rows"
                        :key="index"
                        class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm"
                        :data-kind="row.kind"
                        data-test="publish-change"
                    >
                        <span aria-hidden="true" class="mt-0.5 font-semibold" :class="kindClass(row.kind)">{{ kindMark(row.kind) }}</span>
                        <span class="min-w-0">
                            <span class="block break-words text-slate-900">{{ row.text }}</span>
                            <span class="block text-xs text-slate-500">{{ row.scope }}</span>
                        </span>
                    </li>
                </ul>

                <div
                    v-if="isStaleDraftError"
                    class="space-y-2 rounded-lg border border-rose-200 bg-rose-50 p-3"
                    data-test="publish-stale-error"
                    role="alert"
                >
                    <p class="m-0 text-sm text-rose-800">{{ $t('widget_site_layout.area.stale_reloaded_publish') }}</p>
                    <button
                        class="rounded-lg bg-rose-600 px-3 py-1.5 text-sm font-medium text-white"
                        data-test="publish-stale-refresh"
                        type="button"
                        @click="refreshAfterStale"
                    >
                        {{ $t('nav.a.site-layout-conflict-reload') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3">
                <button
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    data-test="publish-cancel"
                    type="button"
                    @click="close"
                >
                    {{ $t('nav.b.cancel') }}
                </button>
                <button
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="publish-confirm"
                    :disabled="publishing || nothingToPublish"
                    type="button"
                    @click="confirmPublish"
                >
                    {{ $t('widget_site_layout.area.publish_confirm') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { describeChange } from '../area-format'

defineOptions({ name: 'AreaPublishDialog' })

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    /** The shared useWidgetSiteLayout() instance of the editor. */
    layout: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['close', 'published'])

const { t } = useI18n()

const publishing = ref(false)

const status = computed(() => props.layout.extras?.changesStatus ?? 'idle')
const rows = computed(() => (props.layout.extras?.changes ?? []).map((row) => describeChange(t, row)))
const nothingToPublish = computed(() => status.value === 'ready' && rows.value.length === 0)

const isStaleDraftError = computed(
    () => props.layout.state.status === 'error' && props.layout.state.lastError?.code === 'WIDGET_DRAFT_STALE'
)

const kindMark = (kind) => ({ added: '+', removed: '−', changed: '~' })[kind] ?? '~'
const kindClass = (kind) => ({ added: 'text-emerald-700', removed: 'text-rose-700', changed: 'text-amber-700' })[kind] ?? 'text-slate-500'

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) props.layout.loadChanges?.()
    },
    { immediate: true }
)

function close() {
    if (publishing.value) return
    emit('close')
}

async function confirmPublish() {
    if (publishing.value || nothingToPublish.value) return
    publishing.value = true
    try {
        await props.layout.publish()
    } finally {
        publishing.value = false
    }

    // Failures stay in the dialog: the stale panel above, or the command bar's toast for anything else.
    if (props.layout.state.status === 'ready') emit('published')
}

/** Someone else changed the draft: read it again; the list above then shows what would really be published. */
async function refreshAfterStale() {
    try {
        await props.layout.reloadDraft()
    } catch {
        emit('close')
        return
    }
    props.layout.loadChanges()
}
</script>
