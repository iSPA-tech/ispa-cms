<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    The one way to add a widget to a shared area ("Khu vực chung"): a drawer with three steps.

      1. "Chọn mẫu"         pick a widget of this site by name and picture; a blocked one is shown, disabled, with
                            "Đang bị chặn".
      2. "Nội dung"         fill in what it shows (the shared widget form: real labels, media picker for images).
      3. "Hiển thị ở đâu"   "Toàn website" (default), "Theo loại trang" or "Riêng trang này" — only the choices the
                            area accepts.

    "Đặt vào khu vực" sends ONE request (layout.addPlacement: the widget, its content and where it shows together);
    the widget is then in the draft, and the site changes only when the user publishes.

    `preset` ({widgetId, themeId}) opens the drawer on step 2 with that widget chosen — the hand-off from the widget
    library ("Thêm vào… → Khu vực chung"). The list holds only what a shared area of this site accepts (the designs pages
    accept: this site's project or Default), so a handed-over
    widget or design can be missing from it. That is always said in words: a widget with no usable design stays on
    step 1 with the reason, and a design that cannot be used is replaced by a usable one of the same widget, named in
    the notice, with the design select right below to pick another.

    A refused request never shows a server message: a blocked widget, a draft someone else changed and any other
    failure each have their own plain sentence. `if (submitting) return` stops a double submit.
-->
<template>
    <widget-layout-drawer :open="open" side="right" :title="title" wide @close="close">
        <div class="flex flex-col gap-4" data-test="area-add-drawer">
            <ol class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-sm" data-test="area-add-steps">
                <li
                    v-for="(label, index) in stepLabels"
                    :key="label"
                    :aria-current="step === index + 1 ? 'step' : undefined"
                    class="flex items-center gap-1.5"
                    :class="step === index + 1 ? 'font-semibold text-cyan-800' : 'text-slate-500'"
                >
                    <span
                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs"
                        :class="step === index + 1 ? 'bg-cyan-600 text-white' : 'bg-slate-200 text-slate-600'"
                    >
                        {{ index + 1 }}
                    </span>
                    {{ label }}
                </li>
            </ol>

            <p
                v-if="errorText"
                class="m-0 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                data-test="area-add-error"
                role="alert"
            >
                {{ errorText }}
            </p>

            <p
                v-if="noticeText"
                class="m-0 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                data-test="area-add-notice"
                role="status"
            >
                {{ noticeText }}
            </p>

            <!-- Step 1: choose a widget -->
            <div v-show="step === 1" class="space-y-3" data-test="area-add-step-pick">
                <input
                    v-model="search"
                    :aria-label="$t('widget_site_layout.area.search')"
                    class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    data-test="area-add-search"
                    :placeholder="$t('widget_site_layout.area.search')"
                    type="search"
                />

                <p v-if="listState === 'loading'" class="m-0 text-sm text-slate-500" data-test="area-add-loading">
                    {{ $t('widget_site_layout.editor.loading') }}
                </p>
                <div v-else-if="listState === 'failed'" class="flex flex-col items-start gap-2" data-test="area-add-failed">
                    <p class="m-0 text-sm text-slate-600">{{ $t('widget_site_layout.area.library_failed') }}</p>
                    <button
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                        data-test="area-add-retry"
                        type="button"
                        @click="loadWidgets"
                    >
                        {{ $t('widget_site_layout.area.retry') }}
                    </button>
                </div>
                <p v-else-if="!filteredWidgets.length" class="m-0 text-sm text-slate-500" data-test="area-add-none">
                    {{ $t('widget_site_layout.area.no_widgets') }}
                </p>

                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button
                        v-for="widget in filteredWidgets"
                        :key="widget.id"
                        :aria-disabled="widget.is_blocked ? 'true' : undefined"
                        class="flex flex-col gap-1.5 rounded-lg border p-2 text-left"
                        :class="
                            widget.is_blocked
                                ? 'cursor-not-allowed border-slate-200 bg-slate-50 opacity-70'
                                : 'border-slate-200 hover:border-cyan-400 hover:bg-cyan-50/40'
                        "
                        :data-test="`area-add-widget-${widget.id}`"
                        :disabled="widget.is_blocked || !firstDesignId(widget)"
                        type="button"
                        @click="pickWidget(widget)"
                    >
                        <area-widget-thumbnail
                            :disabled="!!widget.is_blocked"
                            :theme-id="firstDesignId(widget)"
                            :version="Number(widget.version ?? 0)"
                            :widget-id="widget.id"
                        />
                        <span class="break-words text-sm font-medium text-slate-900">{{ widgetLabel(widget) }}</span>
                        <span
                            v-if="widget.is_blocked"
                            class="self-start rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800"
                            :data-test="`area-add-blocked-${widget.id}`"
                        >
                            {{ $t('widget_site_layout.area.blocked') }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Step 2: content -->
            <!-- v-show needs a wrapper without a display utility (utilities are !important here). -->
            <div v-if="picked" v-show="step === 2" data-test="area-add-step-content">
                <p class="mb-3 mt-0 break-words text-sm font-semibold text-slate-900">{{ widgetLabel(picked) }}</p>
                <widget-placement-inspector
                    :content="content"
                    :designs="picked.template ?? []"
                    :theme-id="themeId"
                    @update:theme-id="onDesignChange"
                    @update:values="values = $event"
                />
            </div>

            <!-- Step 3: where it shows -->
            <div v-if="picked" v-show="step === 3" data-test="area-add-step-scope">
                <widget-placement-inspector
                    :content="null"
                    :scope="scope"
                    :scope-types="scopeTypes"
                    :show-content="false"
                    show-scope
                    :target-contexts="targetContexts"
                    @update:scope="scope = $event"
                />
            </div>
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <button
                    v-if="step > 1"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    data-test="area-add-back"
                    :disabled="submitting"
                    type="button"
                    @click="step -= 1"
                >
                    {{ $t('widget_site_layout.area.back') }}
                </button>
                <button
                    v-else
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    data-test="area-add-cancel"
                    type="button"
                    @click="close"
                >
                    {{ $t('nav.b.cancel') }}
                </button>

                <button
                    v-if="step === 2"
                    class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700"
                    data-test="area-add-next"
                    type="button"
                    @click="step = 3"
                >
                    {{ $t('widget_site_layout.area.next') }}
                </button>
                <button
                    v-else-if="step === 3"
                    class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="area-add-submit"
                    :disabled="submitting || !scopeComplete"
                    type="button"
                    @click="submit"
                >
                    {{ $t('widget_site_layout.area.place') }}
                </button>
            </div>
        </template>
    </widget-layout-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '../api'
import { designLabel, widgetLabel } from '@/libs/widget-names'
import { isScopeComplete } from '../area-format'
import { resolveSlotLabel } from '../slot-label'
import AreaWidgetThumbnail from './area-widget-thumbnail.vue'
import WidgetLayoutDrawer from './widget-layout-drawer.vue'
import WidgetPlacementInspector from './widget-placement-inspector.vue'

defineOptions({ name: 'AreaAddDrawer' })

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    /** The area the widget goes into (a GET /slots row). */
    slot: {
        type: Object,
        default: null,
    },
    /** The shared useWidgetSiteLayout() instance of the editor. */
    layout: {
        type: Object,
        required: true,
    },
    targetContexts: {
        type: Array,
        default: () => [],
    },
    /** `{widgetId, themeId}` from the widget library: open on step 2 with this widget chosen. */
    preset: {
        type: Object,
        default: null,
    },
})

const emit = defineEmits(['close', 'added'])

const { t } = useI18n()

const areaLabel = computed(() => resolveSlotLabel(t, props.slot))
const title = computed(() => t('widget_site_layout.area.add_title', { area: areaLabel.value }))
const stepLabels = computed(() => [
    t('widget_site_layout.area.step_pick'),
    t('widget_site_layout.area.step_content'),
    t('widget_site_layout.area.step_scope'),
])

const step = ref(1)
const search = ref('')
const widgets = ref([])
const listState = ref('idle') // idle | loading | ready | failed
const picked = ref(null)
const themeId = ref(null)
const values = ref({})
const content = ref(null)
const scope = ref({ scope_type: 'global', scope_key: null, target_type: null, target_id: null })
const submitting = ref(false)
const errorText = ref('')
/** Why a widget or design handed over by the library is not the one on screen. */
const noticeText = ref('')

const firstDesignId = (widget) => Number(widget?.template?.[0]?.id ?? 0) || null

const filteredWidgets = computed(() => {
    const term = search.value.trim().toLowerCase()
    if (!term) return widgets.value
    // Found by the name on the row and by the original name.
    return widgets.value.filter((widget) => `${widgetLabel(widget)} ${widget.name ?? ''}`.toLowerCase().includes(term))
})

// "Toàn website" first: it is the default and what most widgets in a shared area want.
const scopeTypes = computed(() => {
    const supported = props.slot?.supported_scope_types ?? ['global']
    return ['global', 'template', 'target'].filter((type) => supported.includes(type))
})

const scopeComplete = computed(() => isScopeComplete(scope.value))

/**
 * The widget list of this site (with pictures and blocked widgets). An area editor who may not read the widget
 * library still gets the widgets that can be placed, from this module's own catalog (no blocked ones, no pictures
 * of blocked ones needed): one row per widget, its designs carrying their own field set.
 */
async function loadWidgets() {
    listState.value = 'loading'
    try {
        widgets.value = await api.listPlaceableWidgets()
        listState.value = 'ready'
        return
    } catch {
        // Fall through to the module's own catalog.
    }

    try {
        const { data } = await api.listWidgetDefinitionCatalog()
        const byWidget = new Map()
        for (const row of data ?? []) {
            if (!byWidget.has(row.widget_id)) {
                byWidget.set(row.widget_id, {
                    id: row.widget_id,
                    name: row.name || row.label,
                    display_name: row.display_name,
                    is_blocked: false,
                    template: [],
                })
            }
            byWidget.get(row.widget_id).template.push({
                id: row.widget_theme_id,
                name: row.theme_name || row.label,
                display_name: row.theme_display_name,
                schema: row.canonical_schema ?? {},
            })
        }
        widgets.value = [...byWidget.values()]
        listState.value = 'ready'
    } catch {
        widgets.value = []
        listState.value = 'failed'
    }
}

/** The form's field set comes from the widget's own inputs (or the design's, when the list came from the module's
 * catalog); values typed so far survive a design change. */
function buildContent() {
    const design = (picked.value?.template ?? []).find((item) => Number(item.id) === Number(themeId.value))
    const schema =
        design?.schema && typeof design.schema === 'object'
            ? design.schema
            : Object.fromEntries(
                  (picked.value?.inputs ?? [])
                      .filter((input) => input?.name)
                      .map((input) => [input.name, { type: input.type || 'text' }])
              )
    content.value = {
        widget_id: picked.value.id,
        widget_theme_id: themeId.value,
        widget_name: picked.value.name,
        widget_display_name: widgetLabel(picked.value),
        schema,
        values: values.value,
    }
}

function pickWidget(widget, designId = null) {
    if (!widget || widget.is_blocked) return
    const designs = widget.template ?? []
    picked.value = widget
    themeId.value = designs.some((design) => Number(design.id) === Number(designId)) ? Number(designId) : firstDesignId(widget)
    values.value = {}
    errorText.value = ''
    noticeText.value = ''
    buildContent()
    step.value = 2
}

function onDesignChange(nextThemeId) {
    themeId.value = nextThemeId
    noticeText.value = ''
    buildContent()
}

function reset() {
    step.value = 1
    search.value = ''
    picked.value = null
    themeId.value = null
    values.value = {}
    content.value = null
    scope.value = { scope_type: scopeTypes.value[0] ?? 'global', scope_key: null, target_type: null, target_id: null }
    errorText.value = ''
    noticeText.value = ''
}

/**
 * Hand-off from the widget library: go straight to the content of the chosen widget. The list holds only widgets and
 * designs a shared area accepts, so a missing one is explained, never skipped or swapped without a word.
 */
function applyPreset(preset) {
    if (!preset?.widgetId || listState.value !== 'ready') return
    const widget = widgets.value.find((item) => Number(item.id) === Number(preset.widgetId))
    if (!widget) {
        noticeText.value = t('widget_site_layout.area.not_placeable_widget')
        return
    }
    if (widget.is_blocked) {
        errorText.value = t('widget_site_layout.area.blocked_error')
        return
    }

    const wanted = Number(preset.themeId ?? 0)
    const designs = widget.template ?? []
    const offered = !wanted || designs.some((design) => Number(design.id) === wanted)
    pickWidget(widget, wanted)
    if (!offered) {
        const used = designs.find((design) => Number(design.id) === Number(themeId.value))
        noticeText.value = t('widget_site_layout.area.not_placeable_design', { design: designLabel(used) })
    }
}

watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen) return
        reset()
        if (listState.value !== 'ready') await loadWidgets()
        applyPreset(props.preset)
    },
    { immediate: true }
)

function close() {
    if (submitting.value) return
    emit('close')
}

async function submit() {
    if (submitting.value || !picked.value || !props.slot || !scopeComplete.value) return
    submitting.value = true
    errorText.value = ''

    try {
        const result = await props.layout.addPlacement({
            slot_key: props.slot.key,
            scope_type: scope.value.scope_type,
            scope_key: scope.value.scope_type === 'template' ? scope.value.scope_key : null,
            target_type: scope.value.scope_type === 'target' ? scope.value.target_type : null,
            target_id: scope.value.scope_type === 'target' ? scope.value.target_id : null,
            widget_id: picked.value.id,
            widget_theme_id: themeId.value,
            values: values.value,
        })

        if (result.ok) {
            emit('added', { placementUuid: result.placementUuid, areaLabel: areaLabel.value })
            return
        }

        if (result.reason === 'blocked') {
            errorText.value = t('widget_site_layout.area.blocked_error')
            listState.value = 'idle' // the list is out of date: read it again next time
        } else if (result.reason === 'stale') {
            errorText.value = t('widget_site_layout.area.stale_reloaded')
        } else {
            errorText.value = t('widget_site_layout.area.add_failed')
        }
    } finally {
        submitting.value = false
    }
}
</script>
