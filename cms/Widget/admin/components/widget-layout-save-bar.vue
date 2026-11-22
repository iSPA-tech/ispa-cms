<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Command bar of the "Khu vực chung" editor: [status chip] [page being viewed / language / screen size] [Lịch sử]
    [Xuất bản…], plus the recovery panel when a save met someone else's change.

    The draft saves itself (the host page autosaves an arrangement change; adding, editing and duplicating a widget
    save as they happen), so there is no Save button. The chip tells the user where things stand in plain words:
      - "● N thay đổi chưa xuất bản" while the saved draft differs from what the site shows (N = rows of
        GET .../draft/changes, held in `layout.extras.changes`);
      - "Chưa có thay đổi" when there is nothing to publish;
      - "Đang lưu…" while a change is on its way, and one label per other in-flight action;
      - a retry button only after a failed save that left unsaved changes.

    "Xuất bản…" (emits `publish`; the host opens the publish dialog) is disabled while a change is still being saved,
    while any action is in flight, and when the change list is known to be empty.

    Conflict (409 on save): one way out, "Tải lại bản mới nhất" = layout.bootstrap() (the server's draft with real
    placements). There is no "keep my changes": the local draft still carries the old revision, so the next autosave
    would be refused again, and sending it under the new revision would overwrite what the other change did (for
    example the widget a site copy just moved the area to). No version numbers on screen.
-->
<template>
    <div :class="flat ? 'space-y-3' : 'i-box space-y-3'" data-test="save-bar">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="flex min-w-0 items-center gap-2" data-test="save-bar-status">
                <span
                    v-if="viewState === 'clean' && changeCount > 0"
                    class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900"
                    data-test="unpublished-chip"
                    role="status"
                >
                    {{ $t('widget_site_layout.area.changes_chip', { count: changeCount }) }}
                </span>
                <span
                    v-else-if="viewState === 'clean'"
                    class="whitespace-nowrap text-xs text-slate-500"
                    data-test="save-bar-state-clean"
                >
                    {{ $t('widget_site_layout.area.no_changes') }}
                </span>
                <span
                    v-else-if="viewState === 'dirty' || viewState === 'saving'"
                    class="whitespace-nowrap text-xs text-slate-500"
                    :data-test="viewState === 'dirty' ? 'save-bar-state-dirty' : 'save-bar-state-saving'"
                    role="status"
                >
                    {{ $t('nav.a.site-layout-save-saving') }}
                </span>
                <span
                    v-else-if="viewState === 'conflict'"
                    class="whitespace-nowrap rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800"
                    data-test="save-bar-state-conflict"
                    role="status"
                >
                    {{ $t('nav.a.site-layout-save-conflict') }}
                </span>
                <span
                    v-else-if="viewState === 'bootstrapping'"
                    class="whitespace-nowrap text-xs text-slate-500"
                    data-test="save-bar-state-bootstrapping"
                    role="status"
                >
                    {{ $t('widget_site_layout.editor.status_bootstrapping') }}
                </span>
                <span
                    v-else-if="viewState === 'publishing'"
                    class="whitespace-nowrap text-xs text-slate-500"
                    data-test="save-bar-state-publishing"
                    role="status"
                >
                    {{ $t('widget_site_layout.editor.status_publishing') }}
                </span>
                <span
                    v-else-if="viewState === 'rolling_back'"
                    class="whitespace-nowrap text-xs text-slate-500"
                    data-test="save-bar-state-rolling-back"
                    role="status"
                >
                    {{ $t('widget_site_layout.editor.status_rolling_back') }}
                </span>
                <span
                    v-else-if="viewState === 'error'"
                    class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700"
                    data-test="save-bar-state-error"
                    role="status"
                >
                    {{ $t('widget_site_layout.editor.status_error') }}
                </span>
                <button
                    v-if="viewState === 'error' && isDirty"
                    class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    data-test="save-draft-button"
                    type="button"
                    @click="handleSave"
                >
                    {{ $t('widget_site_layout.area.retry') }}
                </button>
            </div>

            <!-- Page being viewed / language / screen size, host-provided. Below 640 px it takes its own row. -->
            <div class="min-w-0 basis-full sm:flex-1 sm:basis-0">
                <slot name="context" />
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                <button
                    class="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    data-test="open-history"
                    type="button"
                    @click="$emit('open-history')"
                >
                    <i-icon class="size-4" icon="history" />
                    {{ $t('widget_site_layout.editor.history') }}
                </button>
                <button
                    v-if="canPublish"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:cursor-not-allowed"
                    :class="
                        publishDisabled
                            ? 'border border-slate-300 text-slate-400'
                            : 'bg-emerald-600 text-white hover:bg-emerald-500'
                    "
                    data-test="command-publish"
                    :disabled="publishDisabled"
                    type="button"
                    @click="$emit('publish')"
                >
                    {{ $t('widget_site_layout.area.publish_button') }}
                </button>
            </div>
        </div>

        <div
            v-if="viewState === 'conflict'"
            class="space-y-3 rounded-lg border border-rose-200 bg-rose-50 p-3"
            data-test="save-conflict-panel"
            role="alertdialog"
        >
            <p class="m-0 text-sm text-rose-800">{{ $t('nav.a.site-layout-save-conflict-explain') }}</p>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white"
                    data-test="conflict-reload"
                    @click="reload"
                >
                    {{ $t('nav.a.site-layout-conflict-reload') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { can } from '@/mixins'
import { iWidgetError } from '../widget-error-toast'

defineOptions({ name: 'WidgetLayoutSaveBar' })

const props = defineProps({
    /** The shared useWidgetSiteLayout() instance the host page owns. */
    layout: {
        type: Object,
        required: true,
    },
    /** Render without the i-box card chrome (the host supplies the surface). */
    flat: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['publish', 'open-history'])

const canPublish = computed(() => can('widget_layout.publish'))
const isDirty = computed(() => props.layout.hasUnsavedChanges())

/** Rows of the change list; 0 while it is unknown. */
const changeCount = computed(() => props.layout.extras?.changes?.length ?? 0)
const changesKnownEmpty = computed(() => props.layout.extras?.changesStatus === 'ready' && changeCount.value === 0)

// ONE toast channel for every failed primary action (save, publish, rollback, bootstrap). WIDGET_DRAFT_STALE is left
// to the publish dialog / rollback dialog, which show their own recovery panel.
watch(
    () => props.layout.state.status,
    (status) => {
        if (status === 'error' && props.layout.state.lastError && props.layout.state.lastError.code !== 'WIDGET_DRAFT_STALE') {
            iWidgetError(props.layout.state.lastError)
        }
    }
)

const viewState = computed(() => {
    const status = props.layout.state.status
    if (status === 'conflict') return 'conflict'
    if (status === 'saving') return 'saving'
    if (status === 'bootstrapping') return 'bootstrapping'
    if (status === 'publishing') return 'publishing'
    if (status === 'rolling_back') return 'rolling_back'
    if (status === 'error') return 'error'
    if (isDirty.value) return 'dirty'
    return 'clean'
})

const publishDisabled = computed(
    () =>
        isDirty.value ||
        changesKnownEmpty.value ||
        !props.layout.state.layoutUuid ||
        ['bootstrapping', 'saving', 'publishing', 'rolling_back', 'conflict'].includes(props.layout.state.status)
)

async function handleSave() {
    await props.layout.saveDraft()
}

async function reload() {
    await props.layout.bootstrap()
}

defineExpose({ handleSave, reload })
</script>
