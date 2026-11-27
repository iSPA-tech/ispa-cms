<!--
  Link between two steps. It leaves an output pill and ends, with an arrow, on the top of the next card.
  - colour of the output it leaves (green approved / yes, red rejected / no, amber rewrite, slate otherwise)
  - a link that goes back up is dashed amber and runs on the right of the cards
  - an "on error" link is dashed red and shows only while error paths are on (or its step is selected)
  - "go on with the next item" of a loop is not drawn: the card says it under the output
  A selected link shows a remove button, so it can be deleted without the keyboard.
  The arrowhead can be dragged onto another step: the same output then leads there (still one link per output).
-->
<script setup>
import { BaseEdge, EdgeLabelRenderer, useVueFlow } from '@vue-flow/core'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CARD_DECISION, ERROR_HANDLE, decisionLinkTone, errorLinkVisible, outputTone } from './graphRules'
import { arrowHead, linkPath } from './graphLayout'
import { LINK_COLORS, canvasUi as ui, requestRelink } from './canvasUi'

defineOptions({
    name: 'StepEdge',
    inheritAttrs: false,
})

const props = defineProps({
    id: { type: String, required: true },
    source: { type: String, default: '' },
    target: { type: String, default: '' },
    sourceX: { type: Number, required: true },
    sourceY: { type: Number, required: true },
    targetX: { type: Number, required: true },
    targetY: { type: Number, required: true },
    sourceNode: { type: Object, default: null },
    targetNode: { type: Object, default: null },
    sourceHandleId: { type: String, default: null },
    selected: { type: Boolean, default: false },
})

const { t } = useI18n()
const { removeEdges, nodesConnectable, getNodes, getEdges, screenToFlowCoordinate } = useVueFlow()

const isError = computed(() => props.sourceHandleId === ERROR_HANDLE)
const isNextItem = computed(() => ui.loopOf[props.source] === props.target)
const isBack = computed(() => !isError.value && props.targetY < props.sourceY)
const visible = computed(() => {
    if (isNextItem.value) return false
    return errorLinkVisible({ sourceHandle: props.sourceHandleId, target: props.target }, getEdges.value, {
        showErrors: ui.showErrors,
        sourceSelected: props.sourceNode?.selected === true,
        targetSelected: props.targetNode?.selected === true,
    })
})

const tone = computed(() => {
    if (isError.value) return 'error'
    if (isBack.value) return 'back'
    if (props.sourceNode?.type === CARD_DECISION) return decisionLinkTone(props.sourceNode.data?.step_config, props.sourceHandleId)
    return outputTone(props.sourceHandleId)
})
const color = computed(() => LINK_COLORS[tone.value])

// A link that goes back up, or an error link, runs right of every card it passes (and of the error outputs
// with their notes, while those are shown).
const laneX = computed(() => {
    if (!isBack.value && !isError.value) return null
    const top = Math.min(props.sourceY, props.targetY)
    const bottom = Math.max(props.sourceY, props.targetY)
    const passed = (getNodes.value || []).filter((node) => {
        const y = node.computedPosition?.y ?? node.position.y
        return y + (node.dimensions?.height || 0) >= top && y <= bottom
    })
    if (!passed.length) return null
    return Math.max(...passed.map((node) => (node.computedPosition?.x ?? node.position.x) + (node.dimensions?.width || 0))) + (ui.showErrors ? 190 : 32)
})

// The arrow stops just above the "way in" dot of the target card.
const endY = computed(() => props.targetY - 9)

// ---------------------------------------------------------------------------------------------------------------------
// Dragging the arrowhead to another step
// ---------------------------------------------------------------------------------------------------------------------

/** Where the arrowhead is while it is dragged (canvas coordinates), else null. */
const dragPoint = ref(null)
const stepUnder = (event) =>
    (document.elementsFromPoint?.(event.clientX, event.clientY) || [])
        .map((element) => element.closest?.('.vue-flow__node'))
        .find(Boolean)
        ?.getAttribute('data-id') || null

const onGrab = (event) => {
    if (!nodesConnectable.value || ui.readOnly || event.button > 0) return
    event.preventDefault()
    event.stopPropagation()
    event.currentTarget.setPointerCapture?.(event.pointerId)
    dragPoint.value = screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
    ui.relink = { edgeId: props.id, source: props.source, over: null }
}
const onDragMove = (event) => {
    if (!dragPoint.value) return
    dragPoint.value = screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
    ui.relink = { edgeId: props.id, source: props.source, over: stepUnder(event) }
}
const onRelease = (event) => {
    if (!dragPoint.value) return
    const over = event.type === 'pointercancel' ? null : stepUnder(event)
    dragPoint.value = null
    ui.relink = null
    if (over && over !== props.target && over !== props.source) requestRelink(props.id, over)
}

const shape = computed(() =>
    dragPoint.value
        ? linkPath({ kind: 'forward', sourceX: props.sourceX, sourceY: props.sourceY, targetX: dragPoint.value.x, targetY: dragPoint.value.y, laneX: null })
        : linkPath({
        kind: isError.value ? 'error' : isBack.value ? 'back' : 'forward',
        sourceX: props.sourceX,
        sourceY: props.sourceY,
        targetX: props.targetX,
              targetY: endY.value,
              laneX: laneX.value,
          })
)
const tipX = computed(() => (dragPoint.value ? dragPoint.value.x : shape.value.endX ?? props.targetX))
const tipY = computed(() => (dragPoint.value ? dragPoint.value.y : endY.value))
const style = computed(() => ({
    stroke: color.value,
    strokeWidth: props.selected ? 4 : 2.5,
    strokeDasharray: isBack.value || isError.value || dragPoint.value ? '7 5' : undefined,
}))
</script>

<template>
    <template v-if="visible">
        <BaseEdge :id="id" :path="shape.path" :style="style" :interaction-width="24" />
        <path :d="arrowHead(tipX, tipY)" :fill="color" class="pointer-events-none" />
        <EdgeLabelRenderer v-if="nodesConnectable && !ui.readOnly">
            <button
                type="button"
                class="nodrag nopan pointer-events-auto absolute h-6 w-6 touch-none rounded-full border-0 bg-transparent p-0 hover:bg-teal-700/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700"
                :class="dragPoint ? 'cursor-grabbing bg-teal-700/20' : 'cursor-grab'"
                :style="{ transform: `translate(-50%, -50%) translate(${tipX}px, ${tipY - 5}px)` }"
                :title="t('text.workflow.canvas.relink_hint')"
                :aria-label="t('text.workflow.canvas.relink_hint')"
                data-testid="edge-relink"
                :data-edge="id"
                @pointerdown="onGrab"
                @pointermove="onDragMove"
                @pointerup="onRelease"
                @pointercancel="onRelease"
            />
        </EdgeLabelRenderer>
        <EdgeLabelRenderer v-if="selected && nodesConnectable">
            <button
                type="button"
                class="nodrag nopan pointer-events-auto absolute flex h-7 w-7 cursor-pointer items-center justify-center rounded-full border border-solid border-red-300 bg-white p-0 text-red-600 shadow hover:bg-red-50"
                :style="{ transform: `translate(-50%, -50%) translate(${shape.labelX}px, ${shape.labelY}px)` }"
                :title="t('text.workflow.builder.remove_link')"
                :aria-label="t('text.workflow.builder.remove_link')"
                data-testid="edge-remove"
                @click.stop="removeEdges([id])"
            >
                <i-icon icon="close" class="h-4 w-4" />
            </button>
        </EdgeLabelRenderer>
    </template>
</template>
