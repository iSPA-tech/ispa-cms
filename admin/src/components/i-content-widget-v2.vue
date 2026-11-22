<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form
        :model="innerForm"
        layout="vertical"
        :class="flagOn ? 'overflow-hidden rounded-lg border border-[#e5e7eb] bg-white' : 'rounded-md border border-gray-400 p-2'"
    >
        <!-- The widget is blocked on the whole site: it shows nothing on the page until someone unblocks it. -->
        <div
            v-if="isBlocked"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 max-md:px-2"
            :class="flagOn ? '' : 'mb-2 rounded-sm border'"
            data-test="widget-blocked"
            role="status"
        >
            <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">
                {{ t('text.widget_library.blocked') }}
            </span>
            <span class="min-w-0">{{ t('text.widget_library.blocked_preview') }}</span>
            <span v-if="blockedReason" class="min-w-0 break-words">
                {{ t('text.widget_library.block_reason') }}: {{ blockedReason }}
            </span>
        </div>
        <template v-if="!flagOn">
            <div class="grid grid-cols-3 gap-y-4 md:gap-y-0 md:gap-x-4">
                <div class="col-span-3 md:col-span-1">
                    <select-input-widget
                        v-model="innerForm.widget_id"
                        name="widget_id"
                        :label="$t('nav.a.widgets')"
                        :is-admin="true"
                        @change="(value) => changeWidget(value, true)"
                    />
                </div>
                <div class="col-span-3 md:col-span-1">
                    <a-form-item
                        v-if="innerForm.widget_id > 0"
                        name="template_id"
                        :label="$t('validation.attributes.widget_template')"
                    >
                        <a-select
                            v-model:value="innerForm.template_id"
                            :options="
                                (widgetTemplates || []).map((item) => ({
                                    label: designLabel(item),
                                    value: item.id,
                                }))
                            "
                            @change="(value) => changeTemplate(value, true)"
                        />
                    </a-form-item>
                </div>
            </div>
            <details open class="border border-rose-900/35 rounded-sm px-3 pb-3 pt-3 relative">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-rose-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-600">
                    <span class="flex items-center gap-2 font-medium">
                        <span>{{ $t('text.widget.inputs') }}</span>
                        <span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700">
                            {{ widgetInputs.length }}
                        </span>
                    </span>
                    <span
                        v-if="isFormDirty"
                        class="text-xs text-amber-600"
                        aria-live="polite"
                    >
                        {{ $t('text.form.unsaved_changes') }}
                    </span>
                </summary>
                <widget-input-fields
                    v-if="!confirmingChange"
                    :inputs="innerForm.inputs"
                    :model-value="innerForm.value"
                    @update:model-value="onFieldValuesChange"
                />
            </details>
            <div class="relative bg-white p-3 pt-4 mt-4 border border-cyan-900/35 border-dashed rounded-sm">
                <span class="absolute -top-3 left-4 bg-white px-2 text-cyan-600">
                    {{ $t('validation.attributes.widget_template_preview') }}
                </span>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-gray-800">
                            {{ $t('validation.attributes.widget_template_preview') }}
                        </p>
                        <p
                            class="mt-1 text-xs"
                            :class="previewStatus === 'error' ? 'text-red-600' : 'text-gray-500'"
                            aria-live="polite"
                        >
                            {{ previewStatusLabel }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="bg-cyan-600 text-white text-sm rounded-xs px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
                        :aria-label="$t('nav.b.preview')"
                        @click="openPreview"
                        :disabled="isBlocked || innerForm.widget_id < 1 || innerForm.template_id < 1"
                    >
                        {{ $t('nav.b.preview') }}
                    </button>
                </div>
                <div
                    v-if="previewStatus === 'error'"
                    class="mt-3 flex flex-wrap items-center gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                    role="alert"
                >
                    <span>{{ previewError }}</span>
                    <button type="button" class="font-medium underline" @click="refreshPreview">
                        {{ $t('nav.b.retry') }}
                    </button>
                </div>
            </div>
        </template>
        <template v-else>
            <!-- Approved mockup: one header row (grip, block, template, mode, preview, delete) over the live preview. -->
            <div class="flex min-h-[52px] flex-wrap items-center gap-x-3 gap-y-1 border-b border-[#e5e7eb] px-4 py-1.5 max-md:gap-x-1 max-md:px-2">
                <span
                    v-if="removable"
                    class="drag-handle flex size-8 shrink-0 cursor-move items-center justify-center max-md:size-11"
                    aria-hidden="true"
                >
                    <svg viewBox="0 0 16 16" class="size-4" fill="#8b929b"><circle cx="5.5" cy="3.5" r="1.3" /><circle cx="10.5" cy="3.5" r="1.3" /><circle cx="5.5" cy="8" r="1.3" /><circle cx="10.5" cy="8" r="1.3" /><circle cx="5.5" cy="12.5" r="1.3" /><circle cx="10.5" cy="12.5" r="1.3" /></svg>
                </span>
                <button
                    type="button"
                    class="flex min-w-0 cursor-pointer items-baseline gap-x-3 rounded-md border-0 bg-transparent p-0 text-left focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0b6f7f] max-md:min-h-11 max-md:grow max-md:basis-3/5 max-md:flex-col max-md:justify-center"
                    :aria-expanded="pickerOpen"
                    :aria-label="`${blockTitle} — ${t('text.widget.edit_on_preview.change_block')}`"
                    :title="t('text.widget.edit_on_preview.change_block')"
                    @click="pickerShown = !pickerOpen"
                >
                    <span class="truncate text-[15px] font-semibold leading-5 text-[#1f2933]">{{ blockTitle }}</span>
                    <span v-if="templateName" class="truncate text-[13px] leading-4 text-[#5b6470] max-md:text-xs">{{ templateName }}</span>
                </button>
                <span v-if="headerCount" class="shrink-0 text-[13px] text-[#0b6f7f] max-md:text-xs" data-test="repeater-header-count">{{ headerCount }}</span>
                <div class="flex-1 max-md:hidden" />
                <a-segmented
                    v-if="editOnPreview && !isBlocked"
                    class="overflow-hidden rounded-md! border border-solid border-[#d0d5db] bg-white! p-0! [&_.ant-segmented-item+.ant-segmented-item]:border-0 [&_.ant-segmented-item+.ant-segmented-item]:border-l [&_.ant-segmented-item+.ant-segmented-item]:border-solid [&_.ant-segmented-item+.ant-segmented-item]:border-[#d0d5db] [&_.ant-segmented-item-label]:min-h-0! [&_.ant-segmented-item-label]:px-3! [&_.ant-segmented-item-label]:text-[13px] [&_.ant-segmented-item-label]:leading-[30px]! max-md:[&_.ant-segmented-item-label]:leading-[44px]! max-md:[&_.ant-segmented-item-label]:px-2! [&_.ant-segmented-item-selected]:bg-[#e6f5f8]! [&_.ant-segmented-item-selected]:font-semibold [&_.ant-segmented-item-selected]:text-[#0b6f7f]! [&_.ant-segmented-item-selected]:shadow-none! [&_.ant-segmented-item:focus-within]:outline-2 [&_.ant-segmented-item:focus-within]:-outline-offset-2 [&_.ant-segmented-item:focus-within]:outline-[#0b6f7f] [&_.ant-segmented-item]:rounded-none! [&_.ant-segmented-item]:text-[#4b5563] [&_.ant-segmented-thumb]:hidden!"
                    :aria-label="t('text.widget.edit_on_preview.edit_mode')"
                    :value="editMode"
                    :options="[
                        { label: t(`text.widget.edit_on_preview.${narrow ? 'mode_preview_short' : 'mode_preview'}`), value: 'preview' },
                        { label: t('text.widget.edit_on_preview.mode_form'), value: 'form' },
                    ]"
                    @update:value="(mode) => (editMode = mode)"
                />
                <button
                    type="button"
                    class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent text-[#4b5563] hover:bg-[#eef1f4] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f] disabled:cursor-not-allowed disabled:opacity-50 max-md:ml-auto max-md:size-11"
                    :aria-label="t('text.widget.edit_on_preview.preview_sizes')"
                    :title="t('text.widget.edit_on_preview.preview_sizes')"
                    :disabled="isBlocked || innerForm.widget_id < 1 || innerForm.template_id < 1"
                    data-test="widget-preview-sizes"
                    @click="openPreview"
                >
                    <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="4.5" width="13" height="10" rx="1.5" /><path d="M6 18.5h6M9 14.5v4" /><rect x="17" y="9.5" width="5" height="10" rx="1.2" /></svg>
                </button>
                <button
                    v-if="removable"
                    type="button"
                    class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent text-[#4b5563] hover:bg-[#fdecec] hover:text-[#b42318] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f] max-md:size-11"
                    :aria-label="$t('text.editor.contents.delete_block')"
                    :title="$t('text.editor.contents.delete_block')"
                    @click="emits('remove')"
                >
                    <svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5M10 11v5M14 11v5" /></svg>
                </button>
            </div>
            <!-- Kept mounted while closed (class, not v-show: the admin's utilities are !important). -->
            <div :class="pickerOpen ? 'grid' : 'hidden'" class="grid-cols-1 gap-x-4 border-b border-[#e5e7eb] bg-[#fafbfc] px-4 pt-3 md:grid-cols-3">
                <select-input-widget
                    v-model="innerForm.widget_id"
                    name="widget_id"
                    :label="$t('nav.a.widgets')"
                    :is-admin="true"
                    @change="(value) => changeWidget(value, true)"
                />
                <a-form-item
                    v-if="innerForm.widget_id > 0"
                    name="template_id"
                    :label="$t('validation.attributes.widget_template')"
                >
                    <a-select
                        v-model:value="innerForm.template_id"
                        :options="
                            (widgetTemplates || []).map((item) => ({
                                label: designLabel(item),
                                value: item.id,
                            }))
                        "
                        @change="(value) => changeTemplate(value, true)"
                    />
                </a-form-item>
            </div>
            <widget-preview-editor
                v-if="editOnPreview && editMode === 'preview' && !confirmingChange && !isBlocked && !lookupPending"
                :key="`${innerForm.widget_id}:${innerForm.template_id}`"
                :widget-id="innerForm.widget_id"
                :template-id="innerForm.template_id"
                :inputs="templateOrderInputs"
                :model-value="innerForm.value"
                :block-name="widgetName"
                @update:model-value="onFieldValuesChange"
                @fields-ready="onFieldsReady"
                @blocked="serverBlocked = true"
            />
            <div v-else-if="!confirmingChange" class="px-4 py-4 md:px-6 md:py-5">
                <widget-input-fields
                    :inputs="innerForm.inputs"
                    :model-value="innerForm.value"
                    @update:model-value="onFieldValuesChange"
                />
            </div>
            <div
                v-if="previewStatus === 'error'"
                class="mx-4 mb-4 flex flex-wrap items-center gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                role="alert"
            >
                <span>{{ previewError }}</span>
                <button type="button" class="font-medium underline" @click="refreshPreview">
                    {{ $t('nav.b.retry') }}
                </button>
            </div>
        </template>
        <a-modal
            v-model:open="isFrameExpand"
            :title="$t('validation.attributes.widget_template_preview')"
            :footer="null"
            :width="1100"
            @cancel="closePreview"
        >
            <div class="bg-gray-100 p-3 rounded shadow-sm border border-gray-300">
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_240px]">
                    <div class="min-w-0">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-gray-700">
                                {{ $t('nav.b.preview') }}
                            </span>
                            <span class="text-xs text-gray-500">{{ frameSize }}</span>
                        </div>
                        <div class="w-full max-h-[70vh] overflow-auto">
                            <div
                                class="mx-auto bg-white border rounded shadow-md"
                                :style="{ width: frameSize }"
                            >
                                <a-spin :spinning="innerLoading">
                                    <iframe
                                        :srcdoc="innerPreview || placeholderPreview"
                                        :title="$t('validation.attributes.widget_template_preview')"
                                        sandbox="allow-scripts"
                                        referrerpolicy="no-referrer"
                                        class="w-full h-[min(65vh,600px)] border shadow-inner rounded-sm overflow-hidden"
                                    />
                                </a-spin>
                            </div>
                        </div>
                    </div>
                    <aside class="rounded border border-gray-300 bg-white p-3">
                        <h3 class="text-sm font-medium text-gray-800">
                            {{ t('text.widget.responsive_test_size') }}
                        </h3>
                        <div
                            class="mt-3 grid grid-cols-2 gap-2"
                            role="group"
                            :aria-label="t('text.widget.responsive_test_size')"
                        >
                            <button
                                v-for="option in previewSizeOptions"
                                :key="option.value"
                                type="button"
                                class="min-h-10 rounded border px-2 py-2 text-left text-xs transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-600"
                                :class="
                                    frameSize === option.value
                                        ? 'border-cyan-600 bg-cyan-50 text-cyan-800 ring-1 ring-cyan-600'
                                        : 'border-gray-300 bg-white text-gray-700 hover:border-cyan-400'
                                "
                                :aria-label="option.label"
                                :aria-pressed="frameSize === option.value"
                                @click="frameSize = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <div
                            class="mt-3 text-xs"
                            :class="previewStatus === 'error' ? 'text-red-600' : 'text-gray-500'"
                            aria-live="polite"
                        >
                            {{ previewStatusLabel }}
                        </div>
                        <button
                            type="button"
                            class="mt-3 w-full border border-cyan-600 text-cyan-700 bg-white text-sm rounded-xs px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="innerLoading"
                            :aria-label="$t('text.refresh')"
                            @click="refreshPreview"
                        >
                            {{ $t('text.refresh') }}
                        </button>
                    </aside>
                </div>
            </div>
        </a-modal>
    </a-form>
</template>

<script setup>
import { computed, defineComponent, ref, watch, onBeforeMount, onBeforeUnmount } from 'vue'
import WidgetInputFields from '@/components/widget-input-fields.vue'
import WidgetPreviewEditor from '@/components/widget-preview-editor.vue'
import { useInputs, useTemplates, useWidgets } from '@/hooks'
import { contentWidgetObjectDefault } from '@/mixins/types'
import { getInputVariableByTemplate, iAlert } from '@/mixins'
import { debounce } from 'lodash-es'
import { useI18n } from 'vue-i18n'
import { getWidgetPreview, searchFilterWidgets } from '@/libs'
import { designLabel, widgetLabel } from '@/libs/widget-names'
import { Modal } from 'ant-design-vue'
import { initialItems, normalizeItemIds, repeaterDefinition } from '@/components/widget-repeater-state'
import {
    cloneWidgetDraft,
    hasWidgetInputData,
    isCurrentPreviewRequest,
    resolveInputValues,
    sortWidgetInputs,
    widgetSnapshot,
} from '@/components/widget-editor-state'

defineComponent({
    name: 'IContentWidgetV2',
})

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => {
            return contentWidgetObjectDefault()
        },
    },
    targetId: {
        type: Number,
        default: 0,
    },
    // The block list allows moving and deleting this block: show the grip and the delete button.
    removable: {
        type: Boolean,
        default: false,
    },
    // The block was just placed for the operator (library "Thêm vào… → Trang"): once the widget data is loaded it
    // fills its fields the way a widget picked in this block does, and sends them to the page.
    pickOnLoad: {
        type: Boolean,
        default: false,
    },
})

const emits = defineEmits(['update:modelValue', 'change', 'remove'])

const { t } = useI18n()
const placeholderPreview = `<p style="color: #888; margin: 0;">${t('text.form.select-option', {
    a: t('nav.a.widgets'),
})}</p>`
const innerForm = ref(contentWidgetObjectDefault()) // Inner form data
const originalObject = ref({}) //  original object from parent, maybe edit object or new object
const widgetInputs = ref([]) // all inputs belong to widget after filter
const widgetTemplates = ref([]) //  all templates belong to widget after filter
const { data: allInputs } = useInputs()
const { data: allTemplates } = useTemplates()
const { data: allWidgets } = useWidgets({ isAdmin: true }) // same query key as select-input-widget: shared cache
// Edit-on-preview also brings the mockup's card: header row over the preview. On by default;
// a build with VITE_WIDGET_EDIT_ON_PREVIEW=false keeps the old field grid.
const flagOn = computed(() => import.meta.env.VITE_WIDGET_EDIT_ON_PREVIEW !== 'false')
// Edit-on-preview mode: the toggle needs a saved widget and template.
const editOnPreview = computed(
    () =>
        flagOn.value &&
        innerForm.value.widget_id > 0 &&
        innerForm.value.template_id > 0 &&
        templateOrderInputs.value.length > 0
)
const editMode = ref('preview')
let fellBackToForm = false
// A new widget or template gets its own automatic fallback (the editor remounts via :key).
watch(
    () => [innerForm.value.widget_id, innerForm.value.template_id],
    () => {
        if (!fellBackToForm) return
        fellBackToForm = false
        editMode.value = 'preview'
    }
)
// Inputs in template-variable order (not sortWidgetInputs order), derived straight from the query data
// so it does not depend on changeWidget having run.
const templateOrderInputs = computed(() =>
    getInputVariableByTemplate(
        (allInputs.value || []).filter((item) => item.widget_id === innerForm.value.widget_id),
        (allTemplates.value || []).filter((item) => item.id === innerForm.value.template_id)
    )
)
const widgetName = computed(() => widgetLabel(currentWidget.value))
const templateName = computed(
    () => designLabel((allTemplates.value || []).find((item) => item.id === innerForm.value.template_id))
)
// A block with exactly one repeater shows "<count> <item label> · tối đa <max>" in its header.
const headerCount = computed(() => {
    const repeaters = templateOrderInputs.value.filter((i) => i.type === 'repeater')
    if (repeaters.length !== 1) return ''
    const def = repeaterDefinition(repeaters[0])
    const items = innerForm.value.value?.[repeaters[0].name]
    return t('text.widget.repeater.count', { count: Array.isArray(items) ? items.length : 0, item: def.itemLabel, max: def.max })
})
// Blocked on the whole site (Thư viện widget → Chặn). The picker list says so; a list that does not hold this widget,
// or is older than the block, is corrected by the preview answering WIDGET_BLOCKED.
const serverBlocked = ref(false)
const listedWidget = computed(() =>
    (allWidgets.value?.data || []).find((item) => item.id === innerForm.value.widget_id)
)
// The picker list is paged, so an older widget is missing from it: that one is read by id, once, for its name, its
// block state and the reason. While that read is on its way no preview is requested.
const lookedUpWidget = ref(null)
const lookupPending = ref(false)
let lookedUpId = 0
const currentWidget = computed(
    () =>
        listedWidget.value ||
        (Number(lookedUpWidget.value?.id) === Number(innerForm.value.widget_id) ? lookedUpWidget.value : undefined)
)
watch(
    [() => innerForm.value.widget_id, () => allWidgets.value],
    async ([widgetId, list]) => {
        const id = Number(widgetId)
        if (!(id > 0) || !list || listedWidget.value || lookedUpId === id) return
        lookedUpId = id
        lookupPending.value = true
        try {
            const found = await searchFilterWidgets({ id, limit: 1 }, { silent: true })
            const rows = Array.isArray(found) ? found : found?.data || []
            const row = rows.find((item) => Number(item.id) === id)
            if (row) lookedUpWidget.value = { ...row, is_blocked: row.is_blocked === true || !!row.blocked_at }
        } catch {
            // Not readable: the block keeps its plain title, and a blocked widget is still caught by the preview answer.
        } finally {
            lookupPending.value = false
        }
    },
    { immediate: true }
)
const isBlocked = computed(() => currentWidget.value?.is_blocked === true || serverBlocked.value)
const blockedReason = computed(() => (currentWidget.value?.is_blocked ? currentWidget.value.blocked_reason || '' : ''))
watch(
    () => innerForm.value.widget_id,
    () => (serverBlocked.value = false)
)
const blockTitle = computed(() => widgetName.value || t('nav.a.widgets'))
// The widget and template selects sit behind the block name; a block with nothing chosen shows them.
const pickerShown = ref(false)
const pickerOpen = computed(
    () => pickerShown.value || !(innerForm.value.widget_id > 0) || !(innerForm.value.template_id > 0)
)
// Phones get the short mode label ("Trên giao diện") so the header keeps to two rows.
const narrowQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 767px)') : null
const narrow = ref(!!narrowQuery?.matches)
const onNarrowChange = (event) => (narrow.value = event.matches)
narrowQuery?.addEventListener?.('change', onNarrowChange)
const onFieldsReady = ({ onPreview = [] } = {}) => {
    // Nothing editable on the preview: show the form once, the toggle stays available.
    if (!onPreview.length && !fellBackToForm) {
        fellBackToForm = true
        editMode.value = 'form'
    }
}
const innerPreview = ref('') //  result of template after render
const isFrameExpand = ref(false)
const innerLoading = ref(false)
const previewStatus = ref('idle')
const previewError = ref('')
const previewSnapshot = ref('')
const previewSequence = ref(0)
const previewController = ref(null)
const formBaselineSnapshot = ref('')
const lastEmittedSnapshot = ref('')
// True while loadWidget rebuilds a clean block: updateFormData then emits nothing.
let quietRebuild = false
const loadedTargetId = ref(null)
const hasLoadedModel = ref(false)
const frameSize = ref('100%') // Default to full width
const lastWidgetId = ref(0)
const lastTemplateId = ref(0)
const previewSizeOptions = computed(() => [
    { label: t('text.widget.preview_size.small_mobile'), value: '375px' },
    { label: t('text.widget.preview_size.mobile'), value: '768px' },
    { label: t('text.widget.preview_size.tablet_desktop'), value: '1024px' },
    { label: t('text.widget.preview_size.full_width'), value: '100%' },
    { label: t('text.widget.preview_size.wide_screen'), value: '1440px' },
    { label: t('text.widget.preview_size.wider_screen'), value: '1560px' },
])
const formSnapshot = computed(() => widgetSnapshot(innerForm.value))
const previewRequestSnapshot = computed(() =>
    widgetSnapshot({
        widget_id: innerForm.value.widget_id,
        template_id: innerForm.value.template_id,
        value: innerForm.value.value,
        inputs: innerForm.value.inputs,
    })
)
const isFormDirty = computed(() => formSnapshot.value !== formBaselineSnapshot.value)
const isPreviewDirty = computed(
    () => previewStatus.value !== 'success' || previewSnapshot.value !== previewRequestSnapshot.value
)
const previewStatusLabel = computed(() => {
    if (previewStatus.value === 'loading') return `${t('text.refresh')}...`
    if (previewStatus.value === 'error') return previewError.value || t('nav.b.retry')
    if (isPreviewDirty.value) return t('text.form.unsaved_changes')
    return t('nav.b.preview')
})

// True while the discard confirm is open: the select already holds the new widget but the template is
// still the old one, so nothing may request a preview for that mismatched pair.
const confirmingChange = ref(false)

// The server resolves a saved widget block by widget_key + theme_key when both are present (ids only as
// a fallback), so keys kept from the loaded block would override a changed widget or template.
const dropStaleIdentityKeys = () => {
    delete innerForm.value.widget_key
    delete innerForm.value.theme_key
}

const askDiscardChanges = () =>
    new Promise((resolve) => {
        confirmingChange.value = true
        let settled = false
        const settle = (value) => {
            if (settled) return
            settled = true
            confirmingChange.value = false
            resolve(value)
        }

        Modal.confirm({
            title: t('text.form.unsaved_changes'),
            content: t('mgs.confirm.discard_confirm'),
            okText: t('nav.b.confirm'),
            cancelText: t('nav.b.cancel'),
            onOk: () => settle(true),
            onCancel: () => settle(false),
        })
    })

const invalidatePreview = () => {
    previewController.value?.abort()
    previewController.value = null
    previewSequence.value += 1
    previewStatus.value = 'idle'
    previewError.value = ''
    previewSnapshot.value = ''
    innerLoading.value = false
}

// `seed`: the operator just picked this widget or template, so new repeaters get their default
// items. Never on the load path (confirmChange false), which would make a saved block dirty.
const changeTemplate = async (templateId = 0, confirmChange = true, seed = confirmChange) => {
    const previousTemplateId = lastTemplateId.value || props.modelValue.template_id || 0

    if (
        confirmChange &&
        previousTemplateId > 0 &&
        previousTemplateId !== templateId &&
        hasWidgetInputData(innerForm.value.value) &&
        !(await askDiscardChanges())
    ) {
        innerForm.value.template_id = previousTemplateId
        return
    }

    if (previousTemplateId !== templateId) dropStaleIdentityKeys()
    innerForm.value.template_id = templateId
    lastTemplateId.value = templateId
    resetInputsByTemplate(templateId, seed)
    innerPreview.value = ''
    invalidatePreview()

    //  Update form data
    updateFormData()
}

const resetInputsByTemplate = (template_id = 0, seed = false) => {
    if (template_id === 0) return

    const templateInputs = getInputVariableByTemplate(
        widgetInputs.value,
        widgetTemplates.value.filter((item) => item.id === template_id)
    )

    // Compatible-value preservation + sorting are now the SAME pure rules
    // widget-input-fields.vue's other caller (the Site Layout Inspector's
    // definition-switch flow) uses -- see widget-editor-state.js.
    const parsedValues = resolveInputValues(
        templateInputs,
        innerForm.value.value || {},
        originalObject.value?.value || {}
    )
    // A repeater the block has never stored starts with its default_count empty items; a stored
    // (even empty) list is kept as it is.
    templateInputs.forEach((input) => {
        const current = parsedValues[input.name]
        if (!seed || input.type !== 'repeater' || (Array.isArray(current) && current.length)) return
        if (originalObject.value?.value?.[input.name] !== undefined) return
        parsedValues[input.name] = initialItems(repeaterDefinition(input))
    })
    // Items need valid unique ids (the server keeps them, the preview addresses items by them). On the
    // load path the dirty baseline is refreshed afterwards (loadWidget), so this never looks like an edit.
    templateInputs.forEach((input) => {
        if (input.type === 'repeater') parsedValues[input.name] = normalizeItemIds(parsedValues[input.name])
    })
    const sortedInputs = sortWidgetInputs(templateInputs)

    innerForm.value.value = parsedValues
    innerForm.value.inputs = sortedInputs
}

const changeWidget = async (widgetId = 0, confirmChange = true) => {
    const previousWidgetId = lastWidgetId.value || props.modelValue.widget_id || 0
    const widgetChanged = previousWidgetId !== widgetId
    const previousForm = cloneWidgetDraft({
        ...innerForm.value,
        widget_id: previousWidgetId,
        template_id: lastTemplateId.value || props.modelValue.template_id || null,
    })

    if (
        previousWidgetId > 0 &&
        confirmChange &&
        widgetChanged &&
        hasWidgetInputData(innerForm.value.value) &&
        !(await askDiscardChanges())
    ) {
        innerForm.value = previousForm
        updateFormData()
        return
    }

    innerForm.value.widget_id = widgetId
    if (widgetChanged) {
        dropStaleIdentityKeys()
        // A different widget owns a different input contract. Do not carry
        // values into same-named fields from the previous widget.
        innerForm.value.value = {}
    }
    // get inputs list of widget content
    widgetInputs.value = [...allInputs.value.filter((item) => item.widget_id === widgetId)]
    // get new template list
    widgetTemplates.value = [
        ...allTemplates.value.filter((item) => {
            return item.widget_id === widgetId
        }),
    ]
    const selectedTemplateBelongsToWidget = widgetTemplates.value.some(
        (item) => item.id === innerForm.value.template_id
    )
    if (!selectedTemplateBelongsToWidget) {
        innerForm.value.template_id = widgetTemplates.value[0]?.id || null
    }
    lastWidgetId.value = widgetId
    if (!innerForm.value.template_id) {
        innerForm.value.inputs = []
        innerForm.value.value = {}
        invalidatePreview()
        updateFormData()
        return
    }
    //  Call changeTemplate to update the template and inputs
    changeTemplate(innerForm.value.template_id, false, confirmChange)
}

const updateFormData = () => {
    if (quietRebuild) return
    const nextValue = cloneWidgetDraft(innerForm.value)
    const nextSnapshot = widgetSnapshot(nextValue)

    if (nextSnapshot !== widgetSnapshot(props.modelValue)) {
        lastEmittedSnapshot.value = nextSnapshot
        emits('update:modelValue', nextValue)
        emits('change', cloneWidgetDraft(nextValue))
    }
}

const updateFormDataDebounce = debounce(updateFormData, 500)

/**
 * widget-input-fields.vue's ONLY update path: it emits a full, NEW merged
 * values object on every field change (never mutates a prop). Mirrors the
 * original's exact two-tier behavior -- innerForm.value.value is reassigned
 * SYNCHRONOUSLY (so typing still feels instant / other computed properties
 * reading it stay current) while the outer emit upstream stays on the SAME
 * 500ms debounce every other field change already used.
 *
 * (Textarea fields no longer get their OWN separate extra debounce layer --
 * i-content-textarea-editor.vue only ever fires update:modelValue ONCE per
 * explicit in-modal Save click, not per keystroke, so the original's
 * `updateTextAreaContentDebounce` wrapping was redundant; this still
 * propagates within the SAME 500ms window as every other field, never
 * slower.)
 */
const onFieldValuesChange = (newValues) => {
    innerForm.value.value = newValues
    updateFormDataDebounce()
}

const buildPreviewParams = () => {
    const params = {
        template_id: innerForm.value.template_id,
    }

    Object.keys(innerForm.value.value).forEach((key) => {
        const input = innerForm.value.inputs.find((item) => item.name === key)
        if (input) {
            // if (['image', 'upload'].includes(input.type)) {
            //     params[key] = innerForm.value.value[key][0]
            // } else {
            params[key] = innerForm.value.value[key]
        }
    })

    return params
}

const getPreview = async ({ force = false } = {}) => {
    if (innerForm.value.widget_id < 1 || innerForm.value.template_id < 1) return
    if (!force && previewStatus.value === 'success' && !isPreviewDirty.value) return

    previewController.value?.abort()
    const controller = new AbortController()
    previewController.value = controller
    const sequence = ++previewSequence.value
    const requestSnapshot = previewRequestSnapshot.value
    innerLoading.value = true
    previewStatus.value = 'loading'
    previewError.value = ''

    try {
        const res = await getWidgetPreview(
            innerForm.value.widget_id,
            buildPreviewParams(),
            // Silent: a refused preview is reported here, in our own words.
            { signal: controller.signal, silent: true }
        )

        if (
            isCurrentPreviewRequest(
                sequence,
                previewSequence.value,
                requestSnapshot,
                previewRequestSnapshot.value
            )
        ) {
            innerPreview.value = res
            previewSnapshot.value = requestSnapshot
            previewStatus.value = 'success'
        }
    } catch (err) {
        const isAborted = err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED'
        if (err?.response?.data?.error_code === 'WIDGET_BLOCKED') {
            // The server message is English and technical: the blocked notice on the block says it instead.
            serverBlocked.value = true
            isFrameExpand.value = false
            if (sequence === previewSequence.value) previewStatus.value = 'idle'
        } else if (!isAborted && sequence === previewSequence.value) {
            previewStatus.value = 'error'
            previewError.value = err?.response?.data?.message || err?.message || t('text.ui.content-widget.preview_failed')
            iAlert(err?.response?.data || err, true)
        }
    } finally {
        if (sequence === previewSequence.value) {
            innerLoading.value = false
            previewController.value = null
        }
    }
}

const openPreview = () => {
    isFrameExpand.value = true
    getPreview()
}

const closePreview = () => {
    isFrameExpand.value = false
    previewController.value?.abort()
}

const refreshPreview = () => getPreview({ force: true })

watch(
    [() => props.modelValue, () => props.targetId],
    ([newValue = {}, newTargetId = 0]) => {
        const incomingSnapshot = widgetSnapshot(newValue)
        const targetChanged = loadedTargetId.value !== newTargetId

        if (!targetChanged && incomingSnapshot === lastEmittedSnapshot.value) return
        if (!hasLoadedModel.value || targetChanged || incomingSnapshot !== formSnapshot.value) {
            innerForm.value = cloneWidgetDraft({
                ...contentWidgetObjectDefault(),
                ...newValue,
            })
            originalObject.value = cloneWidgetDraft(newValue)
            formBaselineSnapshot.value = widgetSnapshot(innerForm.value)
            loadedTargetId.value = newTargetId
            lastWidgetId.value = innerForm.value.widget_id || 0
            lastTemplateId.value = innerForm.value.template_id || 0
            hasLoadedModel.value = true
            lastEmittedSnapshot.value = ''
            innerPreview.value = ''
            invalidatePreview()
        }
    },
    {
        immediate: true, // optional: run on first load
    }
)

// System rebuild of inputs/values for the loaded block (queries arrived, or the parent switched widget).
// Not an operator edit: a block that was clean before stays clean, so its baseline follows the rebuild.
// It is not sent to the parent either (quietRebuild): the page must not look edited on open. The rebuilt
// form goes up with the operator's first real change.
let pickedOnLoad = false
const loadWidget = async (widgetId) => {
    if (props.pickOnLoad && !pickedOnLoad && widgetId > 0 && allInputs.value && allTemplates.value) {
        pickedOnLoad = true
        await changeWidget(widgetId, true)
        return
    }
    const wasClean = !isFormDirty.value
    quietRebuild = wasClean
    try {
        await changeWidget(widgetId, false)
    } finally {
        quietRebuild = false
    }
    if (wasClean) formBaselineSnapshot.value = formSnapshot.value
}

// Immediate: the queries may already be cached when the block mounts, and then never change again.
watch(
    [() => allInputs.value, () => allTemplates.value],
    ([newInputs, newTemplates]) => {
        if (newInputs && newTemplates && newInputs.length > 0 && newTemplates.length > 0) {
            loadWidget(innerForm.value.widget_id)
        }
    },
    { immediate: true }
)

watch(
    () => props.modelValue,
    (value) => {
        if (
            value &&
            value.widget_id &&
            value.widget_id > 0 &&
            value.widget_id !== innerForm.value.widget_id
        ) {
            loadWidget(value.widget_id)
        }
    },
    {
        immediate: true,
    }
)

onBeforeMount(() => {
    if (!hasLoadedModel.value) {
        innerForm.value = cloneWidgetDraft({
            ...contentWidgetObjectDefault(),
            ...props.modelValue,
        })
        originalObject.value = cloneWidgetDraft(props.modelValue)
        formBaselineSnapshot.value = widgetSnapshot(innerForm.value)
        hasLoadedModel.value = true
    }
    lastWidgetId.value = innerForm.value.widget_id || 0
    lastTemplateId.value = innerForm.value.template_id || 0
})
onBeforeUnmount(() => {
    narrowQuery?.removeEventListener?.('change', onNarrowChange)
    previewController.value?.abort()
    previewSequence.value += 1
    innerForm.value = { ...contentWidgetObjectDefault() }
    originalObject.value = {}
})
</script>
