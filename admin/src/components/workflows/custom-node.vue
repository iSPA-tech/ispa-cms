<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
  Step card of the workflow designer: the start node, every step and the legacy end node share it.
  A workflow reads top to bottom. The link comes in at the top of the card (the whole card takes a dropped link);
  each output is a named pill on the bottom edge, and the "when it fails" output sits on the right edge.
-->
<template>
    <div
        class="custom-node relative flex cursor-pointer flex-col text-[13.5px]"
        :class="widthClass"
        :data-step-code="data.step_code || id"
        :data-step-type="stepType"
        :title="definitionSummary || undefined"
    >
        <div
            class="relative rounded-[10px] border border-solid bg-white shadow-sm transition-shadow hover:shadow-md"
            :class="[cardClass, mainHandles.length ? 'pb-4' : '']"
            data-testid="node-card"
        >
            <div class="flex items-start gap-2.5 px-3 pt-3" :class="mainHandles.length ? 'pb-1' : 'pb-3'">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="iconClass">
                    <i-icon :icon="icon" class="h-[18px] w-[18px]" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="mb-0 line-clamp-2 break-words text-[15px] font-semibold leading-snug text-slate-900" data-testid="node-name">
                        {{ data.name || typeLabel || $t('text.agentic.run.unnamed_step') }}
                    </p>
                    <p class="mb-0 flex flex-wrap items-center gap-x-2 gap-y-1 font-normal leading-snug text-slate-600">
                        <span v-if="subtitle" class="min-w-0 break-words" data-testid="node-type-label">{{ subtitle }}</span>
                        <span
                            v-for="badge in badges"
                            :key="badge.key"
                            class="rounded-full px-2 py-px"
                            :class="badge.className"
                            :data-safety="badge.key"
                            :title="$t(badge.hintKey)"
                        >
                            {{ $t(badge.labelKey) }}
                        </span>
                    </p>
                    <!-- A review step that reads a JEV verdict says who decides: a person, or JEV when it is sure -->
                    <p
                        v-if="autoPublishLine"
                        class="mb-0 mt-0.5 break-words font-normal leading-snug"
                        :class="autoPublishLine.paused ? 'text-amber-800' : 'text-teal-800'"
                        data-testid="node-auto-publish"
                    >
                        {{ autoPublishLine.text }}
                    </p>
                </div>
                <span
                    v-if="needsSetup"
                    class="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-px text-xs font-semibold text-amber-900"
                    data-testid="node-needs-setup"
                    :title="$t('text.workflow.setup.step_hint')"
                >
                    <span aria-hidden="true" class="h-2 w-2 rounded-full bg-amber-500" />
                    {{ $t('text.workflow.setup.step_badge') }}
                </span>
                <span
                    v-if="hasErrorLink"
                    class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-red-600"
                    data-testid="node-error-dot"
                    :title="$t('text.workflow.canvas.has_error_path')"
                />
            </div>

            <p
                v-if="errors.length"
                class="mx-3 mb-2 mt-1 line-clamp-3 break-words font-normal text-red-700"
                data-testid="node-error"
                :title="errorText"
            >
                {{ errorText }}
            </p>
            <p
                v-else-if="warning"
                class="mx-3 mb-2 mt-1 line-clamp-3 break-words font-normal text-amber-800"
                data-testid="node-warning"
            >
                {{ $t('text.workflow.warning.taint_node') }}
            </p>

            <!-- Way in: the dot is what the operator sees, the handle covers the card so a link can be dropped anywhere on it -->
            <template v-if="!isStart">
                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 rounded-full border-solid transition-all"
                    :class="isDropTarget ? 'h-5 w-5 border-[3px] border-white bg-teal-700 ring-2 ring-teal-700' : 'h-3.5 w-3.5 border-2 border-slate-500 bg-white'"
                />
                <Handle
                    :id="TARGET_HANDLE"
                    type="target"
                    :position="Position.Top"
                    :connectable="true"
                    class="!absolute !inset-0 !h-auto !w-auto !transform-none !rounded-[10px] !border-0 !bg-transparent"
                    :class="linking ? '' : '!pointer-events-none'"
                />
            </template>
        </div>

        <p
            v-if="isDropTarget"
            class="pointer-events-none absolute left-1/2 top-full z-20 mb-0 mt-1 -translate-x-1/2 whitespace-nowrap rounded-md bg-teal-700 px-2 py-0.5 font-semibold text-white"
            data-testid="node-drop-hint"
        >
            {{ $t('text.workflow.canvas.drop_to_link') }}
        </p>

        <!-- Ways out, on the bottom edge: one pill per output, in catalog order -->
        <ul
            v-if="mainHandles.length"
            class="relative z-10 m-0 -mt-[13px] flex list-none items-start justify-center gap-2 p-0 px-2"
            :data-testid="showLabels ? 'node-output-labels' : 'node-outputs'"
        >
            <li v-for="handle in mainHandles" :key="handle.id" class="flex flex-col items-center gap-1" :data-output="handle.id">
                <Handle
                    :id="handle.id"
                    type="source"
                    :position="Position.Bottom"
                    :connectable="!ui.readOnly"
                    class="!static !inline-flex !h-[26px] !w-auto !min-w-0 !transform-none items-center whitespace-nowrap !rounded-full !border-[1.5px] !border-solid px-2.5 font-bold leading-none"
                    :class="pillClass(handle)"
                    :title="ui.readOnly ? undefined : $t('text.workflow.canvas.output_hint')"
                >
                    <span class="pointer-events-none">{{ pillText(handle) }}</span>
                </Handle>
                <span
                    v-if="returnsToLoop(handle)"
                    class="nodrag nopan inline-flex items-center gap-0.5 whitespace-nowrap font-medium text-amber-800"
                    data-testid="node-next-item"
                    :title="loopHint"
                >
                    ↺ {{ $t('text.workflow.canvas.next_item') }}
                    <button
                        v-if="!ui.readOnly"
                        type="button"
                        class="inline-flex h-5 w-5 cursor-pointer items-center justify-center rounded-full border-0 bg-transparent p-0 text-amber-800 hover:bg-amber-100"
                        :aria-label="$t('text.workflow.builder.remove_link')"
                        :title="$t('text.workflow.builder.remove_link')"
                        data-testid="node-next-item-remove"
                        @click.stop="removeLink(handle)"
                    >
                        <i-icon icon="close" class="h-3.5 w-3.5" />
                    </button>
                </span>
                <button
                    v-else-if="!ui.readOnly && !isLinked(handle)"
                    type="button"
                    class="nodrag nopan cursor-pointer whitespace-nowrap rounded-md border border-dashed border-teal-700/60 bg-white/80 px-1.5 py-px font-medium text-teal-800 opacity-70 hover:opacity-100 focus-visible:opacity-100"
                    data-testid="node-add-step"
                    @click.stop="requestStepPicker(id, handle.id, $event)"
                >
                    + {{ $t('text.workflow.canvas.add_step') }}
                </button>
            </li>
        </ul>

        <!-- "When it fails" output, on the right edge: shown while error paths are on or the step is selected -->
        <div
            v-if="errorHandle"
            class="absolute left-full top-7 z-10 -ml-3 flex -translate-y-1/2 items-center gap-1"
            :class="errorVisible ? '' : 'pointer-events-none invisible'"
            data-testid="node-error-port"
            :data-output="errorHandle.id"
        >
            <Handle
                :id="errorHandle.id"
                type="source"
                :position="Position.Right"
                :connectable="!ui.readOnly"
                class="!static !inline-flex !h-[26px] !w-auto !min-w-0 !transform-none items-center whitespace-nowrap !rounded-full !border-[1.5px] px-2 font-bold leading-none"
                :class="pillClass(errorHandle)"
                :title="$t('text.workflow.output.error')"
            >
                <span class="pointer-events-none">⚡ {{ $t('text.workflow.canvas.error_port') }}</span>
            </Handle>
            <span
                v-if="returnsToLoop(errorHandle)"
                class="nodrag nopan inline-flex items-center gap-0.5 whitespace-nowrap rounded-md bg-white/90 px-1 font-medium text-red-700"
                data-testid="node-error-next-item"
                :title="loopHint"
            >
                ↺ {{ $t('text.workflow.canvas.next_item') }}
                <button
                    v-if="!ui.readOnly"
                    type="button"
                    class="inline-flex h-5 w-5 cursor-pointer items-center justify-center rounded-full border-0 bg-transparent p-0 text-red-700 hover:bg-red-100"
                    :aria-label="$t('text.workflow.builder.remove_link')"
                    :title="$t('text.workflow.builder.remove_link')"
                    @click.stop="removeLink(errorHandle)"
                >
                    <i-icon icon="close" class="h-3.5 w-3.5" />
                </button>
            </span>
        </div>
    </div>
</template>

<script setup>
import { Handle, Position, useVueFlow } from '@vue-flow/core'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { iconMaps } from '@/config/icon-maps'
import { GROUP_ICONS, cardBadges, labelOr, nodeDescription, nodeLabel, useNodeCatalog, useNodeIssues } from './nodeCatalog'
import { MAIN_HANDLE, OUTPUT_GLYPHS, TARGET_HANDLE, handleLabelKey, isEndNode, isStartNode, outputTone, stepHandles } from './graphRules'
import { PILL_CLASSES, canvasUi as ui, requestStepPicker } from './canvasUi'
import { cardSubtitle } from '@/components/workflow/widgets/autoPublishRules.js'

defineOptions({
    name: 'CustomNode',
})

const props = defineProps({
    id: { type: String, default: '' },
    type: { type: String, default: 'custom' },
    data: { type: Object, default: () => ({}) },
    selected: { type: Boolean, default: false },
})

const { t, locale } = useI18n()
const { catalog, findNode } = useNodeCatalog()
const { errorsFor, warningFor } = useNodeIssues()
const { getEdges, findNode: findCanvasNode, removeEdges, connectionStartHandle, connectionEndHandle } = useVueFlow()

const stepType = computed(() => props.data.step_type || '')
const nodeRef = computed(() => ({ id: props.id, type: props.type, data: props.data }))

// Node definition from the catalog: label, icon, safety badges and named outputs.
const definition = computed(() => findNode(stepType.value))
const isStart = computed(() => isStartNode(nodeRef.value, catalog.byType))
const isEnd = computed(() => isEndNode(nodeRef.value))

const typeLabel = computed(() => {
    if (definition.value) return nodeLabel(t, definition.value)
    if (isStart.value) return t('text.workflow.node.input.label')
    if (isEnd.value) return t('text.workflow.node.output.label')
    return ''
})
const definitionSummary = computed(() => nodeDescription(t, definition.value))
// The type is the second line only when the operator gave the step its own name.
const subtitle = computed(() => (props.data.name && typeLabel.value && typeLabel.value !== props.data.name ? typeLabel.value : ''))
const badges = computed(() => (isStart.value ? [] : cardBadges(definition.value, catalog.approvalTypes)))
// Who decides on a review step that reads a JEV verdict (the modal asks the server where auto-publish stands).
const autoPublishLine = computed(() => {
    if (!catalog.approvalTypes.includes(stepType.value)) return null
    const line = cardSubtitle(props.data.step_config || {}, ui.autoApproval[props.data.step_code] ?? null, locale?.value)
    return line ? { text: t(line.key, line.params), paused: line.key.endsWith('card_paused') } : null
})

const icon = computed(() => {
    const own = definition.value?.icon
    if (own && iconMaps[own]) return own
    if (isStart.value) return 'play'
    if (isEnd.value) return 'flag-outline'
    return GROUP_ICONS[definition.value?.group] || 'puzzle-outline'
})
const iconClass = computed(() =>
    isStart.value ? 'bg-emerald-100 text-emerald-700' : isEnd.value ? 'bg-slate-200 text-slate-700' : 'bg-sky-100 text-sky-700'
)

const ownEdges = computed(() => (getEdges.value || []).filter((edge) => edge.source === props.id))
const handles = computed(() =>
    stepHandles(
        definition.value,
        props.data.step_config || {},
        ownEdges.value.map((edge) => edge.sourceHandle),
        { start: isStart.value, end: isEnd.value }
    )
)
const mainHandles = computed(() => handles.value.filter((handle) => handle.kind !== 'error'))
const errorHandle = computed(() => handles.value.find((handle) => handle.kind === 'error') || null)
// A card whose only output is the plain "next" one.
const showLabels = computed(() => mainHandles.value.some((handle) => handle.kind !== 'main'))

const edgeOf = (handle) => ownEdges.value.find((edge) => (edge.sourceHandle || MAIN_HANDLE) === handle.id) || null
const isLinked = (handle) => !!edgeOf(handle)
const returnsToLoop = (handle) => {
    const edge = edgeOf(handle)
    return !!edge && ui.loopOf[props.id] === edge.target
}
const removeLink = (handle) => {
    const edge = edgeOf(handle)
    if (edge && !ui.readOnly) removeEdges([edge.id])
}
const loopHint = computed(() => {
    const loop = findCanvasNode(ui.loopOf[props.id])
    return loop ? t('text.workflow.canvas.next_item_hint', { name: loop.data?.name || loop.id }) : undefined
})

const handleLabel = (handle) => labelOr(t, handleLabelKey(handle), handle.name)
const pillText = (handle) => [OUTPUT_GLYPHS[outputTone(handle)], handleLabel(handle)].filter(Boolean).join(' ')
const pillClass = (handle) => PILL_CLASSES[outputTone(handle)][isLinked(handle) ? 1 : 0]

const hasErrorLink = computed(() => !!errorHandle.value && isLinked(errorHandle.value))
const errorVisible = computed(() => ui.showErrors || props.selected)

// A link is being dragged from another card: this card is where it can be dropped.
const linking = computed(() => !!connectionStartHandle.value && connectionStartHandle.value.nodeId !== props.id)
const isDropTarget = computed(
    () => (linking.value && connectionEndHandle.value?.nodeId === props.id) || (!!ui.relink && ui.relink.over === props.id && ui.relink.source !== props.id && !isStart.value)
)
const needsSetup = computed(() => !ui.readOnly && ui.needsSetup[props.id] === true)

// Room for the output pills and what hangs under them.
const widthClass = computed(() => {
    const count = mainHandles.value.length
    if (count <= 2) return 'w-[300px]'
    return count === 3 ? 'w-max min-w-[340px] max-w-[560px]' : 'w-max min-w-[420px] max-w-[720px]'
})

// Problems from the last refused save and from the approval check.
const errors = computed(() => errorsFor(nodeRef.value))
const hasError = computed(() => !!props.data.__error || errors.value.length > 0)
const errorText = computed(() =>
    errors.value.map((row) => row.message || t(`text.workflow.issue.${row.code}`)).join(' · ')
)
const warning = computed(() => warningFor(nodeRef.value))

const cardClass = computed(() => {
    if (isDropTarget.value) return 'border-teal-700 bg-teal-50 ring-4 ring-teal-700/20'
    if (hasError.value) return 'border-red-400 ring-2 ring-red-400'
    if (warning.value) return 'border-amber-400 ring-2 ring-amber-300'
    if (props.selected) return 'border-sky-600 ring-2 ring-sky-200'
    return isStart.value ? 'border-emerald-300' : 'border-slate-300'
})
</script>
