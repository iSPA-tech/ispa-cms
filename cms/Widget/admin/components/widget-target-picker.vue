<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Chunk 3 Task 27: generic Target Context picker for a target-scoped
    Placement override. Wraps api.js's listTargetContexts/searchWidgetTargets/
    getWidgetTargetDetail (Task 25) directly -- these are read-only reference
    lookups, not part of the mutating draft state machine.

    Contract (plan Task 27 DoD): capped pagination, debounce + cancellation,
    keyboard selection, safe labels only. target_id is an OPAQUE string/UUID
    end-to-end -- NEVER coerced to a Number (unlike the legacy
    select-input-post.vue's `parseInt(e.id)`). Never branches on target_type;
    the exact same code path handles every registered type. Only the safe
    `label` field is ever rendered -- route_parameters (or any other raw
    metadata) is never displayed.

    A hand-rolled WAI-ARIA combobox (input + listbox) is used instead of
    <a-select> so keyboard selection (ArrowDown/ArrowUp/Enter/Escape) is
    directly, deterministically implemented and testable, rather than
    delegated to a third-party component's internals. Styled with the same
    Tailwind/slate conventions already used elsewhere in this module.
-->
<template>
    <div class="relative">
        <select
            v-if="targetContexts.length > 1"
            v-model="selectedType"
            :aria-label="$t('nav.a.site-layout-target-type')"
            class="mb-1.5 block w-full rounded-lg border border-slate-300 px-2 py-1 text-sm text-slate-700"
            data-test="target-picker-type"
        >
            <option v-for="context in targetContexts" :key="context.target_type" :value="context.target_type">
                {{ $t(context.label_key) }}
            </option>
        </select>

        <input
            :aria-activedescendant="highlightedIndex >= 0 ? `target-option-${highlightedIndex}` : undefined"
            :aria-expanded="isOpen ? 'true' : 'false'"
            aria-autocomplete="list"
            aria-controls="widget-target-picker-listbox"
            :disabled="disabled"
            :placeholder="$t('widget_site_layout.area.page_search')"
            class="block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            data-test="target-picker-input"
            role="combobox"
            type="text"
            v-model="query"
            @focus="onFocus"
            @keydown="onKeydown"
        />

        <ul
            v-if="isOpen"
            id="widget-target-picker-listbox"
            class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
            data-test="target-picker-listbox"
            role="listbox"
        >
            <li v-if="loading" class="px-3 py-1.5 text-sm text-slate-400">{{ $t('nav.b.search') }}...</li>
            <li v-else-if="results.length === 0" class="px-3 py-1.5 text-sm text-slate-400">
                {{ $t('nav.a.site-layout-no-targets-found') }}
            </li>
            <li
                v-for="(result, index) in results"
                :id="`target-option-${index}`"
                :key="result.target_id"
                :aria-selected="index === highlightedIndex ? 'true' : 'false'"
                :class="[
                    'cursor-pointer px-3 py-1.5 text-sm',
                    index === highlightedIndex ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700',
                ]"
                :data-test="`target-option-${index}`"
                role="option"
                @click="selectResult(result)"
                @mouseenter="highlightedIndex = index"
            >
                {{ result.label }}
            </li>
        </ul>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import { debounce } from 'lodash-es'
import * as api from '../api'

const PER_PAGE_CAP = 20

const props = defineProps({
    modelValue: {
        type: Object,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    /** Optional pre-fetched Target Context list. When the host page already
     * called listTargetContexts() (placements.vue fetches it exactly once and
     * shares it), passing it here skips this component's own duplicate fetch. */
    contexts: {
        type: Array,
        default: null,
    },
})
const emit = defineEmits(['update:modelValue'])

const targetContexts = ref(props.contexts ?? [])
const selectedType = ref(props.modelValue?.target_type ?? null)
const query = ref(props.modelValue?.label ?? '')
const results = ref([])
const loading = ref(false)
const isOpen = ref(false)
const highlightedIndex = ref(-1)

let activeController = null
let requestSequence = 0

async function runSearch() {
    if (!selectedType.value) return

    activeController?.abort()
    const controller = new AbortController()
    activeController = controller
    const sequence = ++requestSequence

    loading.value = true
    try {
        const response = await api.searchWidgetTargets(
            selectedType.value,
            // Nothing typed yet lists the first pages; an empty `search` is left out (the server refuses an empty one).
            { ...(String(query.value ?? '').trim() ? { search: query.value } : {}), per_page: PER_PAGE_CAP },
            { signal: controller.signal }
        )
        if (sequence !== requestSequence) return // superseded by a newer search; discard
        results.value = response?.data ?? []
        isOpen.value = true
    } catch {
        if (sequence !== requestSequence) return
        results.value = []
    } finally {
        if (sequence === requestSequence) loading.value = false
    }
}

const debouncedSearch = debounce(runSearch, 500)

watch([query, selectedType], () => {
    highlightedIndex.value = -1
    debouncedSearch()
})

function onFocus() {
    if (results.value.length > 0) isOpen.value = true
}

function selectResult(result) {
    emit('update:modelValue', {
        target_type: selectedType.value,
        target_id: result.target_id,
        label: result.label,
    })
    query.value = result.label
    isOpen.value = false
    highlightedIndex.value = -1
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault()
        if (results.value.length === 0) return
        isOpen.value = true
        highlightedIndex.value = Math.min(highlightedIndex.value + 1, results.value.length - 1)
        return
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault()
        highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
        return
    }

    if (event.key === 'Enter') {
        if (isOpen.value && highlightedIndex.value >= 0) {
            event.preventDefault()
            selectResult(results.value[highlightedIndex.value])
        }
        return
    }

    if (event.key === 'Escape') {
        isOpen.value = false
        highlightedIndex.value = -1
    }
}

onMounted(async () => {
    if (!targetContexts.value.length) {
        targetContexts.value = (await api.listTargetContexts()) ?? []
    }

    if (!selectedType.value) {
        if (targetContexts.value.length === 1) {
            selectedType.value = targetContexts.value[0].target_type
        } else if (targetContexts.value.length > 1) {
            selectedType.value = targetContexts.value[0].target_type
        }
    }

    if (props.modelValue?.target_id && !props.modelValue?.label) {
        const detail = await api.getWidgetTargetDetail(props.modelValue.target_type, props.modelValue.target_id, {})
        if (detail?.label) query.value = detail.label
    }
})
</script>
