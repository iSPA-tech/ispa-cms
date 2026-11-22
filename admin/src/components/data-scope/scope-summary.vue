<!--
  Plain-words summary of a data scope.
  Props:
    - compareTo: optional second summary; nodes missing from it are marked "new", nodes only in it are listed as "removed"
    - summary: { level: 'all'|'own'|'nodes'|'mixed'|'none', own, dimensions: [{ name, label, label_key?, nodes, more }] } | null
  Renders nothing when summary is null. Node keys are never shown.
  Nodes beyond the first five sit behind an "and N more" expander; nodes only counted by the server stay a plain note.
  When a compared summary is given and collapsed nodes are new, a "N new" tag sits next to the expander.
-->
<template>
    <div v-if="summary" class="space-y-1 text-sm text-ink break-words" data-testid="scope-summary">
        <div v-if="summary.level === 'all'">{{ t('text.data_scope.level_all') }}</div>
        <div v-if="summary.level === 'none'">{{ t('text.data_scope.level_none') }}</div>
        <div v-if="summary.level === 'own' || summary.level === 'mixed'">{{ t('text.data_scope.level_own') }}</div>

        <template v-if="summary.level === 'nodes' || summary.level === 'mixed'">
            <div v-for="dim in shownDimensions" :key="dim.name">
                <div class="font-medium">{{ dimLabel(dim) }}</div>
                <ul class="m-0 list-none space-y-0.5 p-0 text-ink-muted">
                    <li v-for="node in visibleNodes(dim)" :key="node.key">
                        {{ nodeText(node) }}
                        <a-tag v-if="isAdded(dim, node)" color="success" class="ml-1" data-testid="scope-added">
                            {{ t('text.data_scope.added') }}
                        </a-tag>
                    </li>
                </ul>
                <ul v-if="removedNodes(dim).length" class="m-0 list-none space-y-0.5 p-0 text-ink-muted" data-testid="scope-removed-list">
                    <li v-for="node in removedNodes(dim)" :key="node.key" data-testid="scope-removed">
                        <span class="line-through">{{ nodeText(node) }}</span>
                        <a-tag color="error" class="ml-1">{{ t('text.data_scope.removed') }}</a-tag>
                    </li>
                </ul>
                <span v-if="!isExpanded(dim) && presentHidden(dim) > 0">
                    <button
                        type="button"
                        class="m-0 cursor-pointer border-0 bg-transparent p-0 text-xs text-cyan-700 hover:underline focus-visible:underline"
                        aria-expanded="false"
                        data-testid="scope-more"
                        @click="expand(dim)"
                    >
                        {{ t('text.data_scope.more', { n: hiddenCount(dim) }) }}
                    </button>
                    <a-tag
                        v-if="hiddenAdded(dim) > 0"
                        color="success"
                        class="ml-1"
                        data-testid="scope-more-added"
                    >
                        {{ t('text.data_scope.more_added', { n: hiddenAdded(dim) }) }}
                    </a-tag>
                </span>
                <div
                    v-else-if="remainder(dim) > 0"
                    class="text-xs text-ink-faint"
                    data-testid="scope-more-static"
                >
                    {{ t('text.data_scope.more', { n: remainder(dim) }) }}
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { scopeLabel } from '@/libs/data-scope'

defineOptions({ name: 'ScopeSummary' })

const props = defineProps({
    summary: { type: Object, default: null },
    compareTo: { type: Object, default: null },
})

const MAX_NODES = 5
const { t, locale } = useI18n()

/** Dimension names whose hidden nodes the operator has expanded. */
const expanded = ref(new Set())

/** Dimension of the compared summary with the same name, if any. */
const otherDim = (dim) => (props.compareTo?.dimensions || []).find((d) => d.name === dim.name) || null
const keysOf = (dim) => new Set((dim?.nodes || []).map((n) => n.key))

/** Own dimensions, plus dimensions that exist only in the compared summary (so removed nodes can be listed). */
const shownDimensions = computed(() => {
    const own = props.summary?.dimensions || []
    if (!props.compareTo) return own
    const names = new Set(own.map((d) => d.name))
    const extra = (props.compareTo.dimensions || [])
        .filter((d) => !names.has(d.name))
        .map((d) => ({ ...d, nodes: [], more: 0 }))
    return [...own, ...extra]
})

/** True when the node is not present in the compared summary. */
const isAdded = (dim, node) => Boolean(props.compareTo) && !keysOf(otherDim(dim)).has(node.key)
/** Nodes the compared summary has but this one lacks. */
const removedNodes = (dim) => {
    if (!props.compareTo) return []
    const own = keysOf(dim)
    return (otherDim(dim)?.nodes || []).filter((n) => !own.has(n.key))
}

const isExpanded = (dim) => expanded.value.has(dim.name)
const expand = (dim) => {
    expanded.value = new Set([...expanded.value, dim.name])
}

/** Nodes the client holds beyond the visible limit. */
const presentHidden = (dim) => Math.max(0, (dim.nodes || []).length - MAX_NODES)
/** Nodes counted only by the server (truncated, not present here); they can never be shown. */
const remainder = (dim) => dim.more || 0
const visibleNodes = (dim) => (isExpanded(dim) ? dim.nodes || [] : (dim.nodes || []).slice(0, MAX_NODES))
const hiddenCount = (dim) => presentHidden(dim) + remainder(dim)
/** Added nodes among the ones collapsed behind "and N more" (so the "new" mark is visible before expanding). */
const hiddenAdded = (dim) => (dim.nodes || []).slice(MAX_NODES).filter((node) => isAdded(dim, node)).length

// Snapshots freeze `label` in the locale of the run; translate `label_key` when it resolves, else fall back to `label`.
const dimLabel = (dim) => scopeLabel(dim, t, locale?.value)

function nodeText(node) {
    const label = node.trail?.length > 1 ? node.trail.join(' › ') : node.label
    const expansion = node.expansion ? ` — ${t(`text.data_scope.expansion_${node.expansion}`)}` : ''
    return `${label}${expansion}`
}
</script>
