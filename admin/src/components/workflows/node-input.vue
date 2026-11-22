<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="input-node" :class="{ 'ring-2 ring-red-400 border-red-300': data?.__error }">
        <div class="content">
            <div class="title">
                <span class="icon">🚀</span>
                <span class="text">{{ data.name || $t('text.workflow.node.input.label') }}</span>
            </div>
            <div class="meta">{{ data.step_code || id }}</div>
            <div class="meta">{{ $t('text.workflow.node.input.meta') }}</div>
            <div class="meta font-mono text-[11px]">
                x: {{ coordinates.x }}, y: {{ coordinates.y }}
            </div>
        </div>
        <Handle
            type="source"
            :position="Position.Bottom"
            id="output"
            class="handle-output"
            :connectable="true"
        />
    </div>
</template>

<script setup>
import { Handle, Position } from '@vue-flow/core'
import { computed, defineComponent } from 'vue'

defineComponent({
    name: 'NodeInput',
})

const props = defineProps({
    id: String,
    data: Object,
    position: Object,
    positionAbsolute: Object,
    xPos: Number,
    yPos: Number,
})

const coordinates = computed(() => {
    const toNumber = (value) => {
        const num = typeof value === 'number' ? value : Number(value)
        return Number.isFinite(num) ? num : 0
    }

    const x = toNumber(props.position?.x ?? props.xPos ?? props.positionAbsolute?.x)
    const y = toNumber(props.position?.y ?? props.yPos ?? props.positionAbsolute?.y)

    return {
        x: Math.round(x),
        y: Math.round(y),
    }
})
</script>

<style scoped>
.input-node {
    width: 180px;
    min-height: 80px;
    position: relative;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #ffffff;
    padding: 12px 14px 22px;
    box-shadow: 0 10px 30px -12px rgba(15, 23, 42, 0.18);
}

.content {
    display: grid;
    gap: 4px;
    text-align: center;
}

.title {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-weight: 700;
    color: #0f172a;
}

.title .icon {
    font-size: 18px;
}

.title .text {
    font-size: 14px;
}

.meta {
    font-size: 12px;
    color: #64748b;
}

.handle-output {
    width: 16px;
    height: 16px;
    background-color: v-bind('data?.__error ? "#ef4444" : "#2563eb"');
    border-radius: 999px;
    box-shadow: 0 0 0 3px #f8fafc;
    position: absolute;
    left: 50%;
    bottom: -8px;
    transform: translateX(-50%);
}
</style>
