<template>
    <div class="core-node-palette space-y-3">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-semibold text-sm text-slate-900 mb-0">{{ $t('text.workflow.palette.title') }}</p>
                <p class="text-xs text-gray-500">{{ $t('text.workflow.palette.hint') }}</p>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-2">
            <div
                v-for="node in filteredNodes"
                :key="node.step_type"
                class="core-node-card"
                draggable="true"
                @dragstart="handleDragStart($event, node)"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-800 mb-1">{{ node.title }}</p>
                        <p class="text-xs text-gray-500 mb-0">{{ node.description }}</p>
                    </div>
                    <span class="text-[11px] text-gray-400 uppercase">{{ node.step_type }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import useDragAndDrop from '@/components/workflows/useDnd.js'

const props = defineProps({
    nodes: {
        type: Array,
        default: () => [],
    },
    search: {
        type: String,
        default: '',
    },
})

const { onDragStart } = useDragAndDrop()

const filteredNodes = computed(() => {
    const term = (props.search || '').toLowerCase()
    if (!term) return props.nodes || []
    return (props.nodes || []).filter((n) => {
        const hay = `${n.step_type} ${n.title} ${n.description}`.toLowerCase()
        return hay.includes(term)
    })
})

const handleDragStart = (event, node) => {
    const payload = {
        type: 'custom',
        data: {
            name: node.title,
            step_code: node.step_type,
            step_type: node.step_type,
            step_config: node.default_config || {},
            display_name: node.title,
        },
    }
    onDragStart(event, payload)
}
</script>

<style scoped>
.core-node-palette {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px;
}

.core-node-card {
    cursor: grab;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #ffffff;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    box-shadow: 0 6px 12px -10px rgba(15, 23, 42, 0.25);
}

.core-node-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 18px -10px rgba(15, 23, 42, 0.35);
}
</style>
