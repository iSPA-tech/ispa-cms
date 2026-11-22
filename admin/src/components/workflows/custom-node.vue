<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div
        class="custom-node shadow-md border rounded-md px-3 py-2 text-sm font-medium bg-white"
        :style="{ borderColor: data.__error ? '#ef4444' : colorMap[stepType] || '#999' }"
        :class="{ 'ring-2 ring-red-400': data.__error }"
        @dblclick="onEditNode"
    >
        <!-- Header: Icon + Title -->
        <div class="flex items-center gap-2 mb-1">
            <span class="text-lg">{{ iconMap[stepType] || '🔧' }}</span>
            <span class="font-semibold truncate">{{ data.name || $t('text.agentic.run.unnamed_step') }}</span>
        </div>

        <!-- Body: Step Code -->
        <div class="text-xs text-gray-500 truncate">{{ $t('text.workflow.node.code_label', { code: data.step_code }) }}</div>
        <div class="text-[11px] text-gray-400 font-mono">
            x: {{ coordinates.x }}, y: {{ coordinates.y }}
        </div>

        <!-- Inputs and Outputs for VueFlow -->
        <Handle type="target" position="top" :id="'input'" class="handle-input" />
        <Handle type="source" position="bottom" :id="'output'" class="handle-output" />
    </div>
</template>

<script setup>
import { Handle } from '@vue-flow/core'
import { computed, defineComponent } from 'vue'

defineComponent({
    name: 'CustomNode',
})

const props = defineProps({
    id: String,
    data: Object,
    position: Object,
    positionAbsolute: Object,
    xPos: Number,
    yPos: Number,
})

const emits = defineEmits(['edit'])

const stepType = computed(() => props.data.step_type || 'unknown')

const coordinates = computed(() => {
    const toNumber = (value) => {
        const num = typeof value === 'number' ? value : Number(value)
        return Number.isFinite(num) ? num : 0
    }

    // Prefer flow-space position to avoid viewport pan/zoom offsets
    const x = toNumber(props.position?.x ?? props.xPos ?? props.positionAbsolute?.x)
    const y = toNumber(props.position?.y ?? props.yPos ?? props.positionAbsolute?.y)

    return {
        x: Math.round(x),
        y: Math.round(y),
    }
})

const iconMap = {
    event: '📣',
    job: '🔧',
    command: '💻',
    ai_action: '🤖',
    webhook: '🌐',
    decision: '🔀',
    script: '📜',
    set_variable: '📝',
    delay: '⏱️',
    schedule_at: '📅',
    approval: '✅',
    loop: '🔁',
    parallel: '⚙️',
}

const colorMap = {
    event: '#38bdf8',
    job: '#10b981',
    command: '#f59e0b',
    ai_action: '#a855f7',
    webhook: '#f87171',
    decision: '#facc15',
    script: '#60a5fa',
    set_variable: '#94a3b8',
    delay: '#f97316',
    schedule_at: '#0ea5e9',
    approval: '#22c55e',
    loop: '#7c3aed',
    parallel: '#6b7280',
}

function onEditNode() {
    emits('edit', props.data)
}
</script>

<style scoped>
.custom-node {
    width: 180px;
    min-height: 70px;
    cursor: pointer;
    position: relative;
}

.handle-input,
.handle-output {
    width: 10px;
    height: 10px;
    background-color: #555;
    border-radius: 999px;
}
</style>
