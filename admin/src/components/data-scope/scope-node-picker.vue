<!--
  Search picker for hierarchy nodes of one data-scope dimension.
  Props:
    - dimension: dimension name to search in
  Emits:
    - select({ key, label, trail }) when the operator picks a node
  Searches by label with a 300 ms debounce; each option shows its trail under the label. Node keys are never shown.
-->
<template>
    <div class="space-y-1" data-testid="scope-node-picker">
        <a-select
            :value="undefined"
            :filter-option="false"
            :loading="loading"
            :not-found-content="null"
            :options="options"
            :placeholder="t('text.data_scope.search_nodes')"
            class="w-full"
            show-search
            @focus="onFocus"
            @search="onSearch"
            @select="onSelect"
        >
            <template #option="{ label, trail }">
                <div class="leading-tight">
                    <div>{{ label }}</div>
                    <div v-if="trail && trail.length > 1" class="text-xs text-ink-faint">
                        {{ trail.join(' › ') }}
                    </div>
                </div>
            </template>
        </a-select>

        <div v-if="loading" class="text-xs text-ink-muted" data-testid="picker-loading">
            {{ t('text.loading') }}
        </div>
        <div v-else-if="failed" class="flex items-center gap-2 text-xs text-red-600" data-testid="picker-error">
            <span>{{ t('text.data_scope.load_failed') }}</span>
            <a-button size="small" type="link" data-testid="picker-retry" @click="runSearch(query)">
                {{ t('text.data_scope.retry') }}
            </a-button>
        </div>
        <div v-else-if="searched && results.length === 0" class="text-xs text-ink-muted" data-testid="picker-empty">
            {{ t('text.data_scope.no_nodes_found') }}
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { debounce } from 'lodash-es'
import { useI18n } from 'vue-i18n'
import { searchScopeNodes } from '@/libs/data-scope'

defineOptions({ name: 'ScopeNodePicker' })

const props = defineProps({
    dimension: { type: String, required: true },
})
const emit = defineEmits(['select'])

const { t } = useI18n()

const query = ref('')
const results = ref([])
const loading = ref(false)
const failed = ref(false)
const searched = ref(false)
// Guards against a slow earlier response overwriting a newer one.
let requestSeq = 0

const options = computed(() =>
    results.value.map((node) => ({ value: node.key, label: node.label, trail: node.trail || [] }))
)

/** Run one search against the API and update the states. */
async function runSearch(q) {
    const seq = ++requestSeq
    loading.value = true
    failed.value = false
    try {
        const nodes = await searchScopeNodes(props.dimension, q)
        if (seq !== requestSeq) return
        results.value = Array.isArray(nodes) ? nodes : []
        searched.value = true
    } catch (_error) {
        if (seq !== requestSeq) return
        failed.value = true
    } finally {
        if (seq === requestSeq) loading.value = false
    }
}

const runSearchDebounced = debounce(runSearch, 300)

/** Typing in the select: one request after the operator pauses. */
function onSearch(value) {
    query.value = value || ''
    runSearchDebounced(query.value)
}

/** First focus loads the initial list so the operator sees nodes before typing. */
function onFocus() {
    if (!searched.value && !loading.value) runSearch(query.value)
}

/** Emit the chosen node as `{ key, label, trail }`. */
function onSelect(key) {
    const node = results.value.find((n) => n.key === key)
    if (node) emit('select', { key: node.key, label: node.label, trail: node.trail || [] })
}

// A new dimension starts a fresh search.
watch(
    () => props.dimension,
    () => {
        runSearchDebounced.cancel()
        results.value = []
        searched.value = false
        failed.value = false
        query.value = ''
    }
)

onBeforeUnmount(() => runSearchDebounced.cancel())
</script>
