<template>
    <div class="space-y-2" data-testid="node-palette">
        <div v-if="loading" class="flex justify-center py-2">
            <a-spin size="small" />
        </div>

        <a-empty
            v-if="!groups.length && !loading"
            :description="search ? t('text.workflow.palette.no_match') : t('text.workflow.palette.empty')"
            :image-style="{ height: '32px' }"
        />

        <a-collapse v-else v-model:activeKey="openKeys" size="small" :bordered="false" class="bg-transparent">
            <a-collapse-panel v-for="group in groups" :key="group.key">
                <template #header>
                    <span class="inline-flex min-w-0 items-center gap-2 text-sm font-medium text-slate-800">
                        <i-icon :icon="group.icon" class="h-4 w-4 shrink-0 text-slate-500" />
                        <span class="break-words">{{ group.label }}</span>
                        <span class="text-xs font-normal text-gray-400">{{ group.nodes.length }}</span>
                    </span>
                </template>
                <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0">
                    <li
                        v-for="node in group.nodes"
                        :key="node.type"
                        class="cursor-grab rounded-lg border border-solid border-slate-200 bg-white px-3 py-2 shadow-sm transition hover:border-sky-300 hover:shadow focus-visible:border-sky-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-200"
                        :class="{ 'cursor-not-allowed opacity-60': disabled }"
                        :draggable="!disabled"
                        role="button"
                        :tabindex="disabled ? -1 : 0"
                        :aria-disabled="disabled"
                        :data-node-type="node.type"
                        :title="t('text.workflow.palette.add_hint')"
                        @dragstart="handleDragStart($event, node)"
                        @click="handleAdd(node)"
                        @keydown.enter.prevent="handleAdd(node)"
                        @keydown.space.prevent="handleAdd(node)"
                    >
                        <div class="flex items-start gap-2">
                            <i-icon :icon="iconOf(node, group)" class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
                            <div class="min-w-0 flex-1">
                                <p class="mb-0 break-words text-sm font-semibold text-gray-800">{{ node.title }}</p>
                                <p v-if="node.summary" class="mb-0 line-clamp-2 break-words text-xs text-gray-500">
                                    {{ node.summary }}
                                </p>
                                <NodeSafetyBadges :node="node" compact class="mt-1" />
                            </div>
                        </div>
                    </li>
                </ul>
            </a-collapse-panel>
        </a-collapse>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iconMaps } from '@/config/icon-maps'
import useDragAndDrop from '@/components/workflows/useDnd.js'
import { buildDropPayload } from '@/components/workflows/nodeCatalog'
import NodeSafetyBadges from './NodeSafetyBadges.vue'

const props = defineProps({
    /** `[{ key, label, icon, nodes: [{ type, title, summary, default_config, safety, icon, version }] }]` */
    groups: {
        type: Array,
        default: () => [],
    },
    search: {
        type: String,
        default: '',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    /** Step codes already on the canvas, so a dropped node gets a new one. */
    usedCodes: {
        type: Array,
        default: () => [],
    },
    /** A workflow that cannot be edited: nothing can be dragged or added. */
    disabled: {
        type: Boolean,
        default: false,
    },
})

const { t } = useI18n()
const { onDragStart, addAtFreeSpot } = useDragAndDrop()

const openKeys = ref([])

// Searching opens every group that still has a match; without a search the first group is open.
watch(
    () => [props.search, props.groups.map((group) => group.key).join('|')],
    ([term]) => {
        if (term) {
            openKeys.value = props.groups.map((group) => group.key)
        } else if (!openKeys.value.length && props.groups.length) {
            openKeys.value = [props.groups[0].key]
        }
    },
    { immediate: true }
)

const iconOf = (node, group) => (node.icon && iconMaps[node.icon] ? node.icon : group.icon)

const handleDragStart = (event, node) => {
    if (props.disabled) return event.preventDefault()
    onDragStart(event, buildDropPayload(node, node.title, props.usedCodes))
}

// Clicking (or Enter / Space) adds the step next to the last one: no dragging needed on a small screen.
const handleAdd = (node) => {
    if (props.disabled) return
    addAtFreeSpot?.(buildDropPayload(node, node.title, props.usedCodes))
}
</script>
