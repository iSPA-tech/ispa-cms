<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Content form of one widget placed in a shared area ("Khu vực chung"), used by the add drawer (step "Nội dung") and
    the edit drawer. The host owns the buttons and every request; this component only edits:

    - the content (`content.values`, emitted as `update:values`): the fields of the placement's design
      (`content.schema`), dressed with the widget's real catalog inputs (labels, image picker, options) through the
      shared `widget-input-fields`. The catalog read is silent and asked once per widget; a refused read keeps the
      plain fields.
    - the design, when the widget has more than one (`designs`, emitted as `update:themeId`);
    - where the placement shows (`scope`, emitted as `update:scope`), when `showScope` is set: "Toàn website",
      "Theo loại trang" (a page type picked by name) or "Riêng trang này" (a page picked by name).

    Nothing here names a slot key, an id or a version.
-->
<template>
    <div class="flex flex-col gap-5" data-test="placement-inspector">
        <p
            v-if="content?.is_blocked"
            class="m-0 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            data-test="inspector-blocked-note"
            role="status"
        >
            <span class="mr-1.5 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold">
                {{ $t('widget_site_layout.area.blocked') }}
            </span>
            {{ $t('widget_site_layout.area.blocked_hint') }}
        </p>

        <div v-if="designs.length > 1">
            <label class="mb-1 block text-sm font-medium text-slate-700" for="placement-design-select">
                {{ $t('widget_site_layout.area.design') }}
            </label>
            <select
                id="placement-design-select"
                class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                data-test="placement-design-select"
                :value="themeId"
                @change="$emit('update:themeId', Number($event.target.value))"
            >
                <option v-for="design in designs" :key="design.id" :value="design.id">{{ designLabel(design) }}</option>
            </select>
        </div>

        <div v-if="showContent" data-test="instance-fields">
            <!-- Labels above their fields, as in the page editor (the shared fields are form items). -->
            <a-form v-if="inputs.length" layout="vertical" :model="{ value: values }">
                <widget-input-fields hide-tokens :inputs="inputs" :model-value="values" @update:model-value="onValuesChange" />
            </a-form>
            <p
                v-else
                class="m-0 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm text-slate-500"
                data-test="instance-fields-empty-hint"
            >
                {{ $t('widget_site_layout.area.no_fields') }}
            </p>
        </div>

        <fieldset v-if="showScope" class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0" data-test="placement-scope-editor">
            <legend class="mb-1 p-0 text-sm font-medium text-slate-700">
                {{ $t('widget_site_layout.area.step_scope') }}
            </legend>
            <label
                v-for="scopeType in scopeTypes"
                :key="scopeType"
                class="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 text-sm"
                :class="
                    scopeDraft.scope_type === scopeType
                        ? 'border-cyan-400 bg-cyan-50 text-slate-900'
                        : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                "
            >
                <input
                    :checked="scopeDraft.scope_type === scopeType"
                    class="mt-0.5 accent-cyan-600"
                    :data-test="`placement-scope-${scopeType}`"
                    name="placement-scope-type"
                    type="radio"
                    :value="scopeType"
                    @change="onScopeTypeChange(scopeType)"
                />
                <span class="min-w-0">
                    <span class="block font-medium">{{ $t(`widget_site_layout.scope.${scopeType}`) }}</span>
                    <span class="block text-xs text-slate-500">{{ $t(`widget_site_layout.area.scope_${scopeType}_hint`) }}</span>
                </span>
            </label>

            <div v-if="scopeDraft.scope_type === 'template'">
                <label class="mb-1 block text-sm font-medium text-slate-700" for="placement-page-type-select">
                    {{ $t('widget_site_layout.area.page_type') }}
                </label>
                <select
                    id="placement-page-type-select"
                    class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    data-test="placement-page-type-select"
                    :value="scopeDraft.scope_key ?? ''"
                    @change="onPageTypeChange($event.target.value)"
                >
                    <option disabled value="">{{ $t('widget_site_layout.area.pick_page_type') }}</option>
                    <option v-for="pageType in pageTypes" :key="pageType.key" :value="pageType.key">
                        {{ pageType.label }}
                    </option>
                </select>
            </div>

            <div v-if="scopeDraft.scope_type === 'target'">
                <p class="m-0 mb-1 text-sm font-medium text-slate-700">{{ $t('widget_site_layout.area.page') }}</p>
                <widget-target-picker
                    :contexts="targetContexts"
                    :model-value="targetModelValue"
                    @update:model-value="onTargetPicked"
                />
            </div>
        </fieldset>
    </div>
</template>

<script>
// Widgets whose input rows could not be read in this admin session (see loadCatalogRows).
const refusedCatalogReads = new Set()
</script>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getInputByWidget } from '@/libs'
import { designLabel } from '@/libs/widget-names'
import WidgetInputFields from '@/components/widget-input-fields.vue'
import { resolveInputValues, sortWidgetInputs } from '@/components/widget-editor-state'
import WidgetTargetPicker from './widget-target-picker.vue'

defineOptions({ name: 'WidgetPlacementInspector' })

const props = defineProps({
    /** What the placement shows: `{widget_id, widget_theme_id, schema, values, widget_name, is_blocked}`. */
    content: {
        type: Object,
        default: null,
    },
    /** Designs of the widget, `[{id, name}]`; the design select shows only when there are several. */
    designs: {
        type: Array,
        default: () => [],
    },
    /** Selected design id (v-model:theme-id). */
    themeId: {
        type: Number,
        default: null,
    },
    /** Hide the content fields (the add drawer's last step shows the scope only). */
    showContent: {
        type: Boolean,
        default: true,
    },
    /** Show the "Hiển thị ở đâu" choice. */
    showScope: {
        type: Boolean,
        default: false,
    },
    /** `{scope_type, scope_key, target_type, target_id, target_label}` (v-model:scope). */
    scope: {
        type: Object,
        default: () => ({ scope_type: 'global', scope_key: null, target_type: null, target_id: null }),
    },
    /** Scope types the area accepts (the area's `supported_scope_types`). */
    scopeTypes: {
        type: Array,
        default: () => ['global'],
    },
    targetContexts: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['update:values', 'update:themeId', 'update:scope'])

const { t } = useI18n()

// ---------------------------------------------------------------------------
// Content fields
// ---------------------------------------------------------------------------

const values = ref({})
const inputs = ref([])

/**
 * Builds the form fields of a design. The design's schema decides WHICH fields show; the widget's catalog rows
 * (when read) give each one its real type, label and options, exactly as the page-block form shows them.
 *
 * @param {Record<string, {type?: string}>} schema Field set of the pinned design.
 * @param {Array<{name: string, type?: string, options?: object}>} catalogRows Widget input rows; may be empty.
 * @returns {object[]} Inputs for `widget-input-fields`, in the page-block form's order.
 */
function buildInputs(schema = {}, catalogRows = []) {
    const rowsByName = new Map(catalogRows.filter((row) => row?.name).map((row) => [row.name, row]))

    return sortWidgetInputs(
        Object.entries(schema).map(([fieldName, def]) => {
            const row = rowsByName.get(fieldName)

            return {
                ...(row ?? {}),
                name: fieldName,
                type: row?.type || def?.type || 'text',
                options: row?.options ?? [],
            }
        })
    )
}

const catalogRowsByWidget = new Map()

/** Catalog input rows of a widget, asked once; a refused or failed read is remembered and answers []. */
function loadCatalogRows(widgetId) {
    if (refusedCatalogReads.has(widgetId)) return Promise.resolve([])

    if (!catalogRowsByWidget.has(widgetId)) {
        catalogRowsByWidget.set(
            widgetId,
            getInputByWidget(widgetId, { silent: true })
                .then((res) => (Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : []))
                .catch(() => {
                    refusedCatalogReads.add(widgetId)
                    return []
                })
        )
    }

    return catalogRowsByWidget.get(widgetId)
}

let inputsSequence = 0

function applySchema(schema, widgetId, sourceValues) {
    const sequence = ++inputsSequence
    const safeSchema = schema && typeof schema === 'object' ? schema : {}

    inputs.value = buildInputs(safeSchema)
    values.value = resolveInputValues(inputs.value, sourceValues ?? {}, {})

    if (!widgetId || !Object.keys(safeSchema).length) return

    loadCatalogRows(widgetId).then((rows) => {
        // A slower answer for another widget never replaces the current fields.
        if (sequence !== inputsSequence || !rows.length) return
        inputs.value = buildInputs(safeSchema, rows)
        values.value = resolveInputValues(inputs.value, values.value, {})
    })
}

watch(
    () => props.content,
    (content) => applySchema(content?.schema ?? {}, content?.widget_id ?? null, content?.values ?? {}),
    { immediate: true }
)

function onValuesChange(newValues) {
    values.value = newValues
    emit('update:values', newValues)
}

// ---------------------------------------------------------------------------
// Where it shows
// ---------------------------------------------------------------------------

const scopeDraft = reactive({ scope_type: 'global', scope_key: null, target_type: null, target_id: null, target_label: null })

watch(
    () => props.scope,
    (next) => {
        Object.assign(scopeDraft, {
            scope_type: next?.scope_type ?? 'global',
            scope_key: next?.scope_key ?? null,
            target_type: next?.target_type ?? null,
            target_id: next?.target_id ?? null,
            target_label: next?.target_label ?? null,
        })
    },
    { immediate: true, deep: true }
)

/** Page types by name: one per registered page kind that has its own layout. */
const pageTypes = computed(() => {
    const seen = new Set()
    return props.targetContexts
        .filter((context) => context?.template_context_key && !seen.has(context.template_context_key) && seen.add(context.template_context_key))
        .map((context) => {
            const packKey = `widget_site_layout.template_context.${String(context.template_context_key).replace(/[.:]+/g, '_')}`
            const translated = t(packKey)
            return { key: context.template_context_key, label: translated === packKey ? t(context.label_key) : translated }
        })
})

const targetModelValue = computed(() =>
    scopeDraft.target_id
        ? { target_type: scopeDraft.target_type, target_id: scopeDraft.target_id, label: scopeDraft.target_label }
        : null
)

function emitScope() {
    emit('update:scope', { ...scopeDraft })
}

function onScopeTypeChange(scopeType) {
    scopeDraft.scope_type = scopeType
    if (scopeType !== 'template') scopeDraft.scope_key = null
    if (scopeType !== 'target') {
        scopeDraft.target_type = null
        scopeDraft.target_id = null
        scopeDraft.target_label = null
    }
    emitScope()
}

function onPageTypeChange(key) {
    scopeDraft.scope_key = key || null
    emitScope()
}

function onTargetPicked(value) {
    scopeDraft.target_type = value?.target_type ?? null
    scopeDraft.target_id = value?.target_id ?? null
    scopeDraft.target_label = value?.label ?? null
    emitScope()
}

/** Current content values (the host reads them when it saves). */
defineExpose({ getValues: () => values.value })
</script>
