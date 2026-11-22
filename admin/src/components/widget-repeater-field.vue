<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Repeater field for the widget form: an ordered list of items, each holding
    the sub-fields declared in `input.options.fields`. All list logic lives in
    widget-repeater-state.js (pure helpers); this component only wires them to
    the UI and emits a NEW array on every change (never mutates the prop).
-->
<template>
    <div class="space-y-2" data-test="repeater">
        <draggable
            :model-value="items"
            item-key="_id"
            handle=".repeater-handle"
            :animation="150"
            class="space-y-2"
            @update:model-value="emitItems"
        >
            <template #item="{ element, index }">
                <div
                    class="rounded-lg border border-[#17a2b8]/40 bg-white"
                    data-test="repeater-row"
                >
                    <div class="flex items-center gap-1 px-2 py-1.5">
                        <span
                            class="repeater-handle inline-flex size-7 cursor-grab select-none items-center justify-center rounded text-gray-400 hover:text-[#0b6f7f]"
                            :title="t('text.widget.repeater.drag')"
                            role="img"
                            :aria-label="t('text.widget.repeater.drag')"
                        >
                            ⋮⋮
                        </span>
                        <button
                            type="button"
                            class="min-w-0 flex-1 truncate rounded px-1 py-1 text-left text-sm font-medium text-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#17a2b8]"
                            :aria-expanded="isOpen(element._id)"
                            data-test="repeater-toggle"
                            @click="toggle(element._id)"
                        >
                            {{ itemTitle(element, definition, index, t) }}
                        </button>
                        <button
                            type="button"
                            :class="iconBtn"
                            :disabled="index === 0"
                            :aria-label="t('text.widget.repeater.up')"
                            :title="t('text.widget.repeater.up')"
                            data-test="repeater-up"
                            @click="move(element._id, index - 1)"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            :class="iconBtn"
                            :disabled="index === items.length - 1"
                            :aria-label="t('text.widget.repeater.down')"
                            :title="t('text.widget.repeater.down')"
                            data-test="repeater-down"
                            @click="move(element._id, index + 1)"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            :class="textBtn"
                            :disabled="items.length >= definition.max"
                            data-test="repeater-duplicate"
                            @click="duplicate(element._id)"
                        >
                            {{ t('text.widget.repeater.duplicate') }}
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-400"
                            data-test="repeater-remove"
                            @click="remove(element._id)"
                        >
                            {{ t('text.widget.repeater.remove') }}
                        </button>
                    </div>
                    <div v-show="isOpen(element._id)" class="border-t border-gray-100 p-3">
                        <widget-input-fields
                            :inputs="definition.fields"
                            :model-value="element"
                            @update:model-value="(value) => update(element._id, value)"
                        />
                        <div class="mt-2 text-right">
                            <button type="button" :class="textBtn" @click="toggle(element._id)">
                                {{ t('text.widget.edit_on_preview.done') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </draggable>

        <div role="status" data-test="repeater-undo-bar">
            <button
                v-if="undo"
                type="button"
                class="w-full rounded-lg bg-gray-800 px-3 py-2 text-left text-sm text-white hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#17a2b8]"
                data-test="repeater-undo"
                @click="undoRemove"
            >
                {{ t('text.widget.repeater.undo', { item: definition.itemLabel }) }}
            </button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <button
                type="button"
                class="rounded-lg border border-dashed border-[#17a2b8] px-3 py-1.5 text-sm font-medium text-[#0b6f7f] hover:bg-[#17a2b8]/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#17a2b8] disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400 disabled:hover:bg-transparent"
                :disabled="items.length >= definition.max"
                data-test="repeater-add"
                @click="add"
            >
                {{ t('text.widget.repeater.add', { item: definition.itemLabel }) }}
            </button>
            <span class="text-xs text-gray-500" data-test="repeater-count">
                {{
                    t('text.widget.repeater.count', {
                        count: items.length,
                        item: definition.itemLabel,
                        max: definition.max,
                    })
                }}
            </span>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import WidgetInputFields from '@/components/widget-input-fields.vue'
import {
    addItem,
    duplicateItem,
    itemTitle,
    moveItem,
    removeItem,
    repeaterDefinition,
    restoreItem,
} from '@/components/widget-repeater-state.js'

defineOptions({ name: 'WidgetRepeaterField' })

const props = defineProps({
    input: { type: Object, default: () => ({}) },
    modelValue: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const iconBtn =
    'inline-flex size-7 items-center justify-center rounded text-sm text-[#0b6f7f] hover:bg-[#17a2b8]/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#17a2b8] disabled:cursor-not-allowed disabled:text-gray-300 disabled:hover:bg-transparent'
const textBtn =
    'rounded px-2 py-1 text-xs text-[#0b6f7f] hover:bg-[#17a2b8]/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#17a2b8] disabled:cursor-not-allowed disabled:text-gray-300 disabled:hover:bg-transparent'

const definition = computed(() => repeaterDefinition(props.input))
const items = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []))

const UNDO_MS = 8000
const openIds = ref(new Set())
const undo = ref(null)
let undoTimer = null

const isOpen = (id) => openIds.value.has(id)
const toggle = (id) => {
    const next = new Set(openIds.value)
    next.has(id) ? next.delete(id) : next.add(id)
    openIds.value = next
}
const open = (id) => {
    openIds.value = new Set(openIds.value).add(id)
}

const emitItems = (next) => emit('update:modelValue', next)

const add = () => {
    const next = addItem(items.value, definition.value)
    if (next.length === items.value.length) return
    open(next[next.length - 1]._id)
    emitItems(next)
}
const duplicate = (id) => emitItems(duplicateItem(items.value, id, definition.value))
const move = (id, toIndex) => emitItems(moveItem(items.value, id, toIndex))
const update = (id, value) => emitItems(items.value.map((i) => (i._id === id ? { ...value, _id: id } : i)))

const clearUndo = () => {
    clearTimeout(undoTimer)
    undoTimer = null
    undo.value = null
}
const remove = (id) => {
    const result = removeItem(items.value, id)
    if (!result.removed) return
    clearTimeout(undoTimer)
    undo.value = { removed: result.removed, index: result.index }
    undoTimer = setTimeout(clearUndo, UNDO_MS)
    emitItems(result.items)
}
const undoRemove = () => {
    if (!undo.value) return
    emitItems(restoreItem(items.value, undo.value.removed, undo.value.index))
    clearUndo()
}

onBeforeUnmount(() => clearTimeout(undoTimer))
</script>
