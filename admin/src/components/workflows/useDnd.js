/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { useVueFlow } from '@vue-flow/core'
import { ref } from 'vue'
import { i18n } from '@/config/i18n'
import { uniqueStepCode, useNodeCatalog } from './nodeCatalog'
import { stepCodeOf, withCard } from './graphRules'
import { CARD_HEIGHT, CARD_WIDTH } from './graphLayout'

const DRAG_MIME = 'application/vueflow'
/** Rough half size of a step card, so a dropped card sits under the pointer. */
const CARD_OFFSET = { x: CARD_WIDTH / 2, y: 40 }
/** Space between a card added with a click and the card above it. */
const CARD_GAP = 64

// One drag at a time, shared by the palette (drag source) and the canvas (drop target).
const state = {
    draggedType: ref(null),
    isDragOver: ref(false),
    isDragging: ref(false),
}

/** Listeners told about every node the palette adds (drop or click). */
const addedListeners = new Set()

const parsePayload = (raw) => {
    if (typeof raw !== 'string' || raw === '') return null
    try {
        return JSON.parse(raw)
    } catch (error) {
        return raw
    }
}

/**
 * Build a canvas node from a palette payload.
 * `payload` is a card type (`input`, `output`, `decision`) or `{ type, data }` from the node catalog / blueprints.
 * The node id is its step code, unique on the canvas, so links keep their ends after a save.
 */
export const buildCanvasNode = (payload, position, usedCodes = [], byType = {}) => {
    const t = i18n.global.t
    const isObject = payload && typeof payload === 'object'
    const cardType = isObject ? payload.type || payload.nodeType || 'custom' : String(payload || 'custom')
    let data

    if (isObject && payload.data) {
        data = { ...payload.data, step_config: { ...(payload.data.step_config || {}) } }
    } else if (cardType === 'decision') {
        data = {
            name: t('text.workflow.node.decision.label'),
            step_type: 'decision',
            step_config: { condition: '', yes_output: 'target-a', no_output: 'target-b' },
        }
    } else if (cardType === 'input') {
        data = { name: t('text.workflow.node.input.label'), step_type: 'input', step_config: { node_type: 'input' } }
    } else if (cardType === 'output') {
        data = { name: t('text.workflow.node.output.label'), step_type: 'output', step_config: { node_type: 'output' } }
    } else {
        data = { name: '', step_config: {} }
    }

    const baseCode = data.step_code || data.step_type || cardType
    data.step_code = uniqueStepCode(baseCode, usedCodes)

    return withCard({ id: data.step_code, type: cardType, position, data }, byType)
}

const useDragAndDrop = () => {
    const { draggedType, isDragOver, isDragging } = state
    const { addNodes, screenToFlowCoordinate, getNodes, setCenter, getViewport } = useVueFlow()
    const { catalog } = useNodeCatalog()

    const usedCodes = () => (getNodes.value || []).map((node) => stepCodeOf(node))

    function endDrag() {
        isDragging.value = false
        isDragOver.value = false
        draggedType.value = null
        document.body.style.userSelect = ''
        document.removeEventListener('drop', endDrag)
        document.removeEventListener('dragend', endDrag)
    }

    function onDragStart(event, payload) {
        if (event.dataTransfer) {
            event.dataTransfer.setData(DRAG_MIME, typeof payload === 'string' ? payload : JSON.stringify(payload))
            event.dataTransfer.effectAllowed = 'move'
        }
        draggedType.value = typeof payload === 'string' ? payload : payload?.type || payload?.nodeType || 'custom'
        isDragging.value = true
        document.body.style.userSelect = 'none'
        // `drop` ends a drag that landed, `dragend` one that was cancelled (Esc, or released outside the canvas).
        document.addEventListener('drop', endDrag)
        document.addEventListener('dragend', endDrag)
    }

    function onDragOver(event) {
        if (!draggedType.value) return
        event.preventDefault()
        isDragOver.value = true
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
    }

    function onDragLeave() {
        isDragOver.value = false
    }

    function addNode(payload, position) {
        if (!payload) return null
        const node = buildCanvasNode(payload, position, usedCodes(), catalog.byType)
        addNodes(node)
        addedListeners.forEach((listener) => listener(node))
        return node
    }

    /** Drop from the palette: the card lands under the pointer. */
    function onDrop(event) {
        event.preventDefault()
        const payload = parsePayload(event.dataTransfer?.getData(DRAG_MIME)) || draggedType.value
        endDrag()
        if (!payload) return null
        const point = screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
        return addNode(payload, { x: Math.round(point.x - CARD_OFFSET.x), y: Math.round(point.y - CARD_OFFSET.y) })
    }

    /** Click (or Enter) on a palette item: the card goes under the lowest one and is scrolled into view. */
    function addAtFreeSpot(payload) {
        const nodes = getNodes.value || []
        let position = { x: 80, y: 80 }
        if (nodes.length) {
            const bottom = (node) => node.position.y + (node.dimensions?.height || CARD_HEIGHT)
            const last = nodes.reduce((best, node) => (bottom(node) > bottom(best) ? node : best), nodes[0])
            position = { x: Math.round(last.position.x), y: Math.round(bottom(last) + CARD_GAP) }
        }
        const node = addNode(payload, position)
        if (node) {
            const zoom = getViewport().zoom || 1
            setCenter(position.x + CARD_OFFSET.x, position.y + CARD_OFFSET.y, { zoom, duration: 200 })
        }
        return node
    }

    /** Run `listener(node)` for every node added from the palette. Returns the function that stops it. */
    function onNodeAdded(listener) {
        addedListeners.add(listener)
        return () => addedListeners.delete(listener)
    }

    return {
        draggedType,
        isDragOver,
        isDragging,
        onDragStart,
        onDragLeave,
        onDragOver,
        onDrop,
        addAtFreeSpot,
        addStepAt: addNode,
        onNodeAdded,
    }
}

export default useDragAndDrop
