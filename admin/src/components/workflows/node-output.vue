<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="output-node" :class="{ 'ring-2 ring-red-400 border-red-300': data?.__error }">
        <div class="content">
            <div class="title">
                <span class="icon">🎯</span>
                <span class="text">{{ data.name || $t('text.workflow.node.output.label') }}</span>
            </div>
            <div class="meta">{{ data.step_code || id }}</div>
            <div class="meta accent">{{ $t('text.workflow.node.output.meta') }}</div>
            <div class="meta font-mono text-[11px]">
                x: {{ coordinates.x }}, y: {{ coordinates.y }}
            </div>
        </div>
        <Handle
            type="target"
            :position="Position.Top"
            id="input"
            class="handle-input"
            :connectable="true"
        />
    </div>
</template>

<script setup>
import { Handle, Position } from '@vue-flow/core'
import { computed, defineComponent } from 'vue'

defineComponent({
    name: 'NodeOutput',
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
.output-node {
    width: 180px;
    min-height: 80px;
    position: relative;
    border: 1px solid #d1fae5;
    border-radius: 16px;
    background: #ecfdf3;
    padding: 12px 14px 22px;
    box-shadow: 0 10px 30px -12px rgba(16, 185, 129, 0.35);
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
    color: #065f46;
}

.title .icon {
    font-size: 18px;
}

.title .text {
    font-size: 14px;
}

.meta {
    font-size: 12px;
    color: #047857;
}

.meta.accent {
    color: #0d9488;
    font-weight: 600;
}

.handle-input {
    width: 16px;
    height: 16px;
    background-color: v-bind('data?.__error ? "#ef4444" : "#10b981"');
    border-radius: 999px;
    box-shadow: 0 0 0 3px #f0fdf4;
    position: absolute;
    left: 50%;
    top: -8px;
    transform: translateX(-50%);
}
</style>
