/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useVueFlow } from '@vue-flow/core'
import { ref, watch } from 'vue'
import { i18n } from '@/config/i18n'

let id = 0

/**
 * @returns {string} - A unique id.
 */
function getId() {
    return `dndnode_${id++}`
}

/**
 * Reset the id seed so new nodes don't collide with existing ones.
 * @param {number} nextId
 */
function setIdSeed(nextId = 0) {
    id = Math.max(0, nextId)
}

// In a real world scenario you'd want to avoid creating refs in a global scope like this
// as they might not be cleaned up properly.
const state = {
    /**
     * The type of the node being dragged.
     */
    draggedType: ref(null),
    isDragOver: ref(false),
    isDragging: ref(false),
}

const useDragAndDrop = () => {
    const { draggedType, isDragOver, isDragging } = state

    const { addNodes, screenToFlowCoordinate, onNodesInitialized, updateNode } = useVueFlow()

    watch(isDragging, (dragging) => {
        document.body.style.userSelect = dragging ? 'none' : ''
    })

    function onDragStart(event, payload) {
        const serialized = typeof payload === 'string' ? payload : JSON.stringify(payload)

        if (event.dataTransfer) {
            event.dataTransfer.setData('application/vueflow', serialized)
            event.dataTransfer.effectAllowed = 'move'
        }

        draggedType.value =
            typeof payload === 'string' ? payload : payload?.type || payload?.nodeType || 'custom'
        isDragging.value = true

        document.addEventListener('drop', onDragEnd)
    }

    /**
     * Handles the drag over event.
     *
     * @param {DragEvent} event
     */
    function onDragOver(event) {
        event.preventDefault()

        if (draggedType.value) {
            isDragOver.value = true

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move'
            }
        }
    }

    function onDragLeave() {
        isDragOver.value = false
    }

    function onDragEnd() {
        isDragging.value = false
        isDragOver.value = false
        draggedType.value = null
        document.removeEventListener('drop', onDragEnd)
    }

    /**
     * Handles the drop event.
     *
     * @param {DragEvent} event
     */
    function onDrop(event) {
        const position = screenToFlowCoordinate({
            x: event.clientX,
            y: event.clientY,
        })

        const nodeId = getId()

        let transferData = event.dataTransfer?.getData('application/vueflow')
        let parsedData = transferData

        try {
            parsedData = JSON.parse(transferData)
        } catch (error) {
            // Keep original string fallback
        }

        const droppedType =
            typeof parsedData === 'string'
                ? parsedData
                : parsedData?.type || parsedData?.nodeType || draggedType.value

        let nodeData = { label: nodeId }

        if (droppedType === 'decision') {
            nodeData = {
                name: i18n.global.t('text.workflow.node.decision.label'),
                step_code: nodeId,
                step_type: 'decision',
                step_config: {
                    condition: '',
                    yes_output: 'target-a',
                    no_output: 'target-b',
                },
            }
        }

        if (typeof parsedData === 'object' && parsedData?.data) {
            nodeData = {
                step_code: nodeId,
                ...parsedData.data,
                step_config: {
                    ...(parsedData.data.step_config || {}),
                },
            }
        }

        const newNode = {
            id: nodeId,
            type: droppedType || 'custom',
            position,
            data: nodeData,
            dragHandle: '.vue-flow__node',
        }

        /**
         * Align node position after drop, so it's centered to the mouse
         *
         * We can hook into events even in a callback, and we can remove the event listener after it's been called.
         */
        const { off } = onNodesInitialized(() => {
            updateNode(nodeId, (node) => ({
                position: {
                    x: node.position.x - node.dimensions.width / 2,
                    y: node.position.y - node.dimensions.height / 2,
                },
            }))

            off()
        })

        addNodes(newNode)
    }

    return {
        draggedType,
        isDragOver,
        isDragging,
        onDragStart,
        onDragLeave,
        onDragOver,
        onDrop,
        setIdSeed,
    }
}

export default useDragAndDrop
