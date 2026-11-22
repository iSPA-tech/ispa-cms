<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Chunk 3 Task 28: the SHARED typed field renderer (plan DoD 1441). Renders
    ONE grid of typed controls for whatever `inputs` (typed input definitions:
    {name, type, label?, title?, options?}) + `modelValue` (the values object,
    keyed by input name) it is given -- used by BOTH the existing Page Widget
    editor (i-content-widget-v2.vue) and the new Site Layout Placement
    Inspector (widget-placement-inspector.vue), so a change to one typed
    control renders identically in both places.

    Extracted VERBATIM (byte-identical markup/behavior) from
    i-content-widget-v2.vue's original inline field-rendering block --  not a
    redesign. The only structural change is HOW a change propagates: instead
    of directly mutating a parent's nested reactive object, every field
    change computes a NEW merged values object and emits it via
    `update:modelValue` (standard one-way prop/emit, never mutates the
    `modelValue` prop). i-content-widget-v2.vue's own handler re-assigns its
    local `innerForm.value.value` synchronously on every emit (so typing
    still feels instant) and keeps its EXISTING 500ms debounce for upstream
    propagation to ITS OWN parent -- see onFieldValuesChange there.

    An image input always renders the media picker (it no longer needs
    `options.src === 'unknown'`), so page blocks and shared areas pick images
    the same way.

    Only image/upload fields moved from a hardcoded media-selection `limit=1`
    to an input-declared limit (`item.media_limit` or `item.options.limit`),
    defaulting to 1 when neither is set -- the ONE generalization plan DoD
    1442 ("media limit") asks for, backward compatible with every existing
    legacy widget_input row (which has neither field, so keeps limit=1).

    Props: `item.name in modelValue` gates rendering per field (unchanged
    from the original) -- callers MUST pre-seed `modelValue` with every input
    name before mounting (e.g. via widget-editor-state.js's
    resolveInputValues), exactly like i-content-widget-v2.vue's own
    resetInputsByTemplate always did.
-->
<template>
    <div v-if="inputs.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div
            v-for="(item, ind) in inputs"
            :key="item.name || item.id || ind"
            class="col-span-1 min-w-0"
            :class="{ 'md:col-span-2': exceptInputTypes.includes(item.type) }"
        >
            <template v-if="item.name in modelValue">
                <template v-if="['upload', 'image'].includes(item.type)">
                    <div>
                        <span class="mb-2 block text-sm font-medium text-gray-700">
                            {{ inputLabel(item) }}
                            <span v-if="!hideTokens && inputToken(item)" class="ml-1 font-mono text-[10px] font-normal text-gray-400">
                                {{ inputToken(item) }}
                            </span>
                        </span>
                        <media-selection
                            :model-value="mediaValue(item)"
                            :limit="mediaLimit(item)"
                            :type="item.type === 'upload' ? 'all' : 'image'"
                            @update:model-value="(value) => onMediaChange(item, value)"
                        />
                    </div>
                </template>
                <template v-else-if="item.type === 'select'">
                    <a-form-item :name="['value', item.name]" :label="inputLabel(item)" size="small">
                        <a-select
                            :value="modelValue[item.name]"
                            :options="item.options || []"
                            size="small"
                            @change="(value) => setValue(item.name, value)"
                        />
                    </a-form-item>
                </template>
                <template v-else-if="item.type === 'checkbox'">
                    <a-form-item :name="['value', item.name]" :label="inputLabel(item)" size="small">
                        <a-checkbox-group
                            :value="modelValue[item.name]"
                            :options="item.options || []"
                            multiple
                            size="small"
                            @change="(value) => setValue(item.name, value)"
                        />
                    </a-form-item>
                </template>
                <template v-else-if="item.type === 'radio'">
                    <a-form-item :name="['value', item.name]" :label="inputLabel(item)" size="small">
                        <a-radio-group
                            :value="modelValue[item.name]"
                            :options="item.options || []"
                            size="small"
                            @change="(value) => setValue(item.name, value)"
                        />
                    </a-form-item>
                </template>
                <template v-else-if="item.type === 'query'">
                    <a-form-item :name="['value', item.name]" :label="inputLabel(item)" size="small">
                        <a-input
                            :value="modelValue[item.name]"
                            :placeholder="$t('text.form.input-placeholder', { a: item.name })"
                            type="number"
                            @change="(e) => setValue(item.name, e.target.value)"
                        />
                    </a-form-item>
                </template>
                <template v-else-if="item.type === 'textarea'">
                    <i-content-textarea-editor
                        :label="inputLabel(item)"
                        :model-value="modelValue[item.name]"
                        @update:model-value="(value) => setValue(item.name, value)"
                    />
                </template>
                <template v-else-if="item.type === 'iSeo'">
                    <a-form-item :name="['value', item.name]" :label="inputLabel(item)" size="small">
                        <a-input :value="modelValue[item.name]" :placeholder="$t('text.widget.from_site_settings')" disabled />
                    </a-form-item>
                </template>
                <template v-else-if="item.type === 'repeater'">
                    <span class="mb-2 block text-sm font-medium text-gray-700">{{ inputLabel(item) }}</span>
                    <widget-repeater-field
                        :input="item"
                        :model-value="modelValue[item.name]"
                        @update:model-value="(value) => setValue(item.name, value)"
                    />
                </template>
                <template v-else-if="item.type === 'list'">
                    <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                        <span class="mb-2 block text-sm font-medium text-gray-700">
                            {{ inputLabel(item) }}
                            <span v-if="!hideTokens && inputToken(item)" class="ml-1 font-mono text-[10px] font-normal text-gray-400">
                                {{ inputToken(item) }}
                            </span>
                        </span>
                        <div class="flex-row gap-3 space-y-1.5">
                            <a-form-item
                                v-for="entry in listInputEntries[item.name] || []"
                                :key="entry.key"
                                :name="['value', item.name, entry.index]"
                                no-style
                            >
                                <div class="flex items-center gap-1.5" :data-index="entry.index">
                                    <a-input
                                        :value="entry.value"
                                        @input="(e) => updateListValue(item.name, entry.index, e.target.value)"
                                        :placeholder="entry.placeholder"
                                        size="small"
                                    />
                                    <a-button
                                        size="small"
                                        danger
                                        :aria-label="`${$t('nav.b.delete')} ${entry.index + 1}`"
                                        :data-test="`list-remove-${item.name}-${entry.index}`"
                                        @click="removeValueOption(item.name, entry.index)"
                                    >
                                        <span class="!size-3 inline-flex items-center">
                                            <i-icon class="size-4" icon="delete" />
                                        </span>
                                    </a-button>
                                </div>
                            </a-form-item>
                            <p
                                v-if="!(listInputEntries[item.name] || []).length"
                                class="py-1 text-xs italic text-gray-500"
                            >
                                {{ $t('text.widget.list_empty') }}
                            </p>
                            <a-button
                                size="small"
                                :data-test="`list-add-${item.name}`"
                                class="text-xs btn-success-outline hover:!text-white"
                                @click="addValueOption(item.name)"
                            >
                                <span><i-icon class="size-4" icon="plus-thick" /></span>
                                <span>{{ $t('nav.b.add') }} {{ $t('nav.a.contents') }}</span>
                            </a-button>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <a-form-item :name="['value', item.name]" :label="inputLabel(item)" size="small">
                        <a-input
                            :value="modelValue[item.name]"
                            :placeholder="$t('text.form.input-placeholder', { a: item.name })"
                            @change="(e) => setValue(item.name, e.target.value)"
                        />
                    </a-form-item>
                </template>
            </template>
        </div>
    </div>
    <p v-else class="py-6 text-center text-sm text-gray-500">
        {{ $t('text.no-data') }}
    </p>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import IContentTextAreaEditor from '@/components/i-content-textarea-editor.vue'
import WidgetRepeaterField from '@/components/widget-repeater-field.vue'
import { formatInputLabel, formatInputToken, removeWidgetListItem } from '@/components/widget-editor-state'

defineOptions({ name: 'WidgetInputFields' })

const props = defineProps({
    inputs: {
        type: Array,
        default: () => [],
    },
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    // Screens for site owners (shared areas) leave out the `{NAME}` template token beside image labels.
    hideTokens: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t, te } = useI18n()

// Same "media/upload/textarea render widest, last" grid rule as the
// original i-content-widget-v2.vue.
const exceptInputTypes = ['textarea', 'image', 'upload', 'repeater']

const listKeySequence = ref(0)
const listEntryKeys = ref({})

const getListEntryKey = (name, index) => {
    if (!listEntryKeys.value[name]) listEntryKeys.value[name] = []
    if (!listEntryKeys.value[name][index]) {
        listEntryKeys.value[name][index] = `${name}-${++listKeySequence.value}`
    }
    return listEntryKeys.value[name][index]
}

const listInputEntries = computed(() =>
    Object.fromEntries(
        props.inputs
            .filter((item) => item.type === 'list')
            .map((item) => [
                item.name,
                (props.modelValue[item.name] || []).map((value, index) => ({
                    key: getListEntryKey(item.name, index),
                    index,
                    value,
                    placeholder: `${item.name} ${index}`,
                })),
            ])
    )
)

// Clean human label; the `{NAME}` template token renders separately as a
// de-emphasized mono hint (and survives in text-input placeholders), so the
// label row stops reading like machine output.
const inputLabel = (item = {}) => formatInputLabel(item, t('text.unknown.title'), { withToken: false, translate: (key) => (te(key) ? t(key) : '') })

const inputToken = (item = {}) => formatInputToken(item)

/** DoD 1442 "media limit": prefers an explicit per-input limit
 * (`media_limit`, or the legacy-shaped `options.limit`) and otherwise
 * preserves the original hardcoded single-file limit -- no backend field
 * for this exists today (confirmed absent from WidgetDefinitionCatalogResource),
 * so 1 stays the safe, backward-compatible default. */
const mediaLimit = (item) => item.media_limit ?? item.options?.limit ?? 1

/** An image or file input always uses the media picker, whatever `options.src`
 * says (a template `{image source="..."}` used to get a plain text box).
 *
 * The picker takes a list and echoes every new list it is given back as a
 * change, so a stored value that is not a list (a text address from that old
 * text box, null, a single object) must reach it as ONE list that stays the
 * same object across renders: a stored address shows as the current image,
 * a single stored file as that file, anything else as "nothing picked". The stored value itself is only replaced
 * when the admin picks or removes an image (see onMediaChange). */
const NOTHING_PICKED = []
const shownMedia = new Map()

const looksLikeAddress = (value) =>
    typeof value === 'string' && (/^(https?:)?\/\//i.test(value) || value.startsWith('/') || /\.[a-z0-9]{2,5}([?#].*)?$/i.test(value))

const mediaValue = (item) => {
    const stored = props.modelValue[item.name]
    if (Array.isArray(stored)) return stored

    const shown = shownMedia.get(item.name)
    if (shown && shown.stored === stored) return shown.list

    let list = NOTHING_PICKED
    if (looksLikeAddress(stored)) list = [{ url: stored, name: stored.split(/[?#]/)[0].split('/').pop() || stored }]
    // A single stored file ({url, ...}) is what the site renders too.
    else if (stored && typeof stored === 'object' && stored.url) list = [stored]
    shownMedia.set(item.name, { stored, list })

    return list
}

/** Only a real pick or removal writes: the picker's echo of the list it was
 * shown, and "still nothing" for a value that is not a list, are ignored. */
function onMediaChange(item, value) {
    const shown = mediaValue(item)
    if (value === shown) return
    if (!Array.isArray(props.modelValue[item.name]) && !shown.length && !(value || []).length) return

    setValue(item.name, value)
}

/** Every field change funnels through here: computes a NEW values object
 * (never mutates the `modelValue` prop) and emits it immediately. Callers
 * own their own upstream debounce/propagation strategy. */
function setValue(name, value) {
    emit('update:modelValue', { ...props.modelValue, [name]: value })
}

function updateListValue(name, index, value) {
    const next = [...(props.modelValue[name] || [])]
    next.splice(index, 1, value)
    setValue(name, next)
}

function removeValueOption(name = '', index = -1) {
    const nextValues = removeWidgetListItem(props.modelValue, name, index)
    listEntryKeys.value[name]?.splice(index, 1)
    setValue(name, nextValues[name])
}

function addValueOption(name = '') {
    if (!name) return
    const next = Array.isArray(props.modelValue[name]) ? [...props.modelValue[name]] : []
    next.push('')
    if (!listEntryKeys.value[name]) listEntryKeys.value[name] = []
    listEntryKeys.value[name].push(`${name}-${++listKeySequence.value}`)
    setValue(name, next)
}
</script>
