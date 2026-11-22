<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div
        class="decision-node relative"
        :class="{ 'ring-2 ring-red-400 border-red-400': data.__error }"
    >
        <span class="absolute top-2 right-2 text-xs text-gray-500 cursor-pointer" @click="onClick">
            {{ t('nav.b.edit') }}
        </span>
        <div class="header">
            <span class="icon">🔀</span>
            <span class="label">{{ data.name || t('text.workflow.node.decision.label') }}</span>
        </div>
        <div class="details">{{ data.step_code || t('text.workflow.node.decision.no_code') }}</div>
        <div class="text-[11px] text-gray-600 font-mono mt-1">
            x: {{ coordinates.x }}, y: {{ coordinates.y }}
        </div>

        <!-- Input from LEFT -->
        <Handle
            type="target"
            :position="Position.Left"
            id="input"
            class="handle handle-left"
            :connectable="true"
        />

        <!-- Output A and B on RIGHT -->
        <Handle
            id="target-a"
            type="source"
            :position="Position.Right"
            class="handle handle-right top"
            :connectable="true"
        />
        <Handle
            id="target-b"
            type="source"
            :position="Position.Right"
            class="handle handle-right bottom"
            :connectable="true"
        />
    </div>

    <a-modal v-model:open="isEditingNode" :title="t('text.workflow.node.decision.edit_condition')">
        <a-select
            v-model:value="selectedField"
            :placeholder="selectFieldPlaceholder"
            style="width: 100%; margin-bottom: 10px"
        >
            <a-select-option v-for="field in availableFields" :key="field" :value="field">
                {{ field }}
            </a-select-option>
        </a-select>

        <a-select
            v-model:value="selectedOperator"
            :placeholder="selectOperatorPlaceholder"
            style="width: 100%; margin-bottom: 10px"
        >
            <a-select-option
                v-for="option in operatorOptions"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </a-select-option>
        </a-select>

        <a-input v-model:value="selectedValue" :placeholder="enterValuePlaceholder" />

        <div style="margin-top: 10px">
            <span>{{ t('text.workflow.node.decision.condition_preview') }}:</span>
            <pre>$order.{{ selectedField }} {{ selectedOperator }} '{{ selectedValue }}'</pre>
        </div>
        <template #footer>
            <app-modal-footer @cancel="isEditingNode = false" @submit="onSaveCondition" />
        </template>
    </a-modal>
</template>

<script setup>
import { Handle, Position, useNodeConnections } from '@vue-flow/core'
import { computed, defineComponent, defineEmits, defineProps, ref } from 'vue'
import AppModalFooter from '@/components/common/AppModalFooter.vue'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'DecisionNode',
})
const isEditingNode = ref(false)
const selectedField = ref('')
const selectedOperator = ref('==')
const selectedValue = ref('')
const { t } = useI18n()
const props = defineProps({
    id: String,
    type: String,
    data: Object,
    position: Object,
    positionAbsolute: Object,
    xPos: Number,
    yPos: Number,
})

const availableFields = computed(() => {
    if (!props.data.input?.$order) return []
    return Object.keys(props.data.input.$order)
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

const operatorOptions = computed(() => [
    { value: '==', label: t('text.workflow.node.decision.operator_option.equals') },
    { value: '!=', label: t('text.workflow.node.decision.operator_option.not_equals') },
    { value: '>', label: t('text.workflow.node.decision.operator_option.greater_than') },
    { value: '<', label: t('text.workflow.node.decision.operator_option.less_than') },
    { value: '>=', label: t('text.workflow.node.decision.operator_option.greater_or_equal') },
    { value: '<=', label: t('text.workflow.node.decision.operator_option.less_or_equal') },
])

const selectFieldPlaceholder = computed(() =>
    t('text.form.select-option', { a: t('validation.attributes.field') })
)

const selectOperatorPlaceholder = computed(() =>
    t('text.form.select-option', { a: t('text.workflow.node.decision.operator_label') })
)

const enterValuePlaceholder = computed(() =>
    t('text.form.input-placeholder', { a: t('validation.attributes.value') })
)

const conditionModel = computed({
    get: () => props.data.step_config?.condition || '',
    set: (val) => {
        if (!props.data.step_config) props.data.step_config = {}
        props.data.step_config.condition = val
    },
})

// Get the source connections of the result node. In this example it's only one operator node.
const sourceConnections = useNodeConnections({
    // type target means all connections where *this* node is the target
    // that means we go backwards in the graph to find the source of the connection(s)
    handleType: 'target',
})

// Get the source connections of the operator node
const operatorSourceConnections = useNodeConnections({
    handleType: 'target',
    nodeId: () => sourceConnections.value[0]?.source,
})

function onClick() {
    emit('edit', {
        ...props,
        sourceConnections: operatorSourceConnections.value,
        operator: operatorSourceConnections,
    })
}

const emit = defineEmits(['edit'])

function onSaveCondition() {
    isEditingNode.value = false

    const incomingEdge = edges.value.find(
        (edge) =>
            edge.target === selectedNode.value.id &&
            (edge.targetHandle === 'input' || !edge.targetHandle)
    )

    if (!incomingEdge) {
        return
    }
    const inputNode = nodes.value.find((n) => n.id === incomingEdge?.source)

    const context = {
        $order: inputNode?.data?.mock_output || { status: 'paid' },
    }

    try {
        const result = new Function(
            ...Object.keys(context),
            `return ${selectedNode.value.data.step_config.condition}`
        )(...Object.values(context))

        const yesId = selectedNode.value.data.step_config.yes_output || 'target-a'
        const noId = selectedNode.value.data.step_config.no_output || 'target-b'

        // Update edges
        edges.value = edges.value.map((edge) => {
            if (edge.source === selectedNode.value.id) {
                if (edge.sourceHandle === yesId) {
                    return {
                        ...edge,
                        label: result
                            ? t('text.workflow.node.decision.edge_true')
                            : t('text.workflow.node.decision.edge_false'),
                        style: { stroke: 'green', strokeWidth: 2 },
                    }
                } else if (edge.sourceHandle === noId) {
                    return {
                        ...edge,
                        label: result
                            ? t('text.workflow.node.decision.edge_false')
                            : t('text.workflow.node.decision.edge_true'),
                    }
                }
            }
            return edge
        })
    } catch (error) {
        console.error(error)
    }
}
</script>

<style scoped>
.decision-node {
    width: 180px;
    min-height: 90px;
    background: #fefce8;
    border: 2px solid #eab308;
    border-radius: 8px;
    padding: 10px;
    position: relative;
    font-family: sans-serif;
    font-size: 13px;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.header {
    display: flex;
    align-items: center;
    font-weight: bold;
    margin-bottom: 4px;
}

.icon {
    font-size: 18px;
    margin-right: 6px;
}

.details {
    color: #444;
    font-size: 12px;
}

/* Handle styling */
.handle {
    width: 10px;
    height: 10px;
    background: #facc15;
    border: 2px solid #000;
    border-radius: 50%;
}

.handle-left {
    top: 50%;
    transform: translateY(-50%);
    left: -5px;
}

.handle-right.top {
    top: 25%;
    right: -5px;
}

.handle-right.bottom {
    top: 75%;
    right: -5px;
}
</style>
