<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
  The yes/no card of workflows saved before named outputs: its links are stored on the handles `target-a` and
  `target-b`, and the step settings say which of the two means "yes". It looks and connects like every other card.
-->
<template>
    <div
        class="decision-node relative flex w-[300px] cursor-pointer flex-col text-[13.5px]"
        :data-step-code="data.step_code || id"
        :title="saveErrors.map((row) => row.message).join(' · ') || undefined"
    >
        <div
            class="relative rounded-[10px] border border-solid bg-white pb-4 shadow-sm transition-shadow hover:shadow-md"
            :class="cardClass"
            data-testid="node-card"
        >
            <div class="flex items-start gap-2.5 px-3 pb-1 pt-3">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center" aria-hidden="true">
                    <span class="h-4 w-4 rotate-45 rounded-[3px] border-2 border-solid border-yellow-600 bg-yellow-100" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="mb-0 line-clamp-2 break-words text-[15px] font-semibold leading-snug text-slate-900" data-testid="node-name">
                        {{ data.name || t('text.workflow.node.decision.label') }}
                    </p>
                    <p v-if="data.name" class="mb-0 font-normal leading-snug text-slate-600">
                        {{ t('text.workflow.node.decision.label') }}
                    </p>
                </div>
            </div>

            <span
                aria-hidden="true"
                class="pointer-events-none absolute left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 rounded-full border-solid transition-all"
                :class="isDropTarget ? 'h-5 w-5 border-[3px] border-white bg-teal-700 ring-2 ring-teal-700' : 'h-3.5 w-3.5 border-2 border-slate-500 bg-white'"
            />
            <Handle
                id="input"
                type="target"
                :position="Position.Top"
                :connectable="true"
                class="!absolute !inset-0 !h-auto !w-auto !transform-none !rounded-[10px] !border-0 !bg-transparent"
                :class="linking ? '' : '!pointer-events-none'"
            />
        </div>

        <p
            v-if="isDropTarget"
            class="pointer-events-none absolute left-1/2 top-full z-20 mb-0 mt-1 -translate-x-1/2 whitespace-nowrap rounded-md bg-teal-700 px-2 py-0.5 font-semibold text-white"
        >
            {{ t('text.workflow.canvas.drop_to_link') }}
        </p>

        <ul class="relative z-10 m-0 -mt-[13px] flex list-none items-start justify-center gap-2 p-0 px-2" data-testid="node-output-labels">
            <li v-for="output in outputs" :key="output.id" class="flex flex-col items-center gap-1" :data-output="output.id">
                <Handle
                    :id="output.id"
                    type="source"
                    :position="Position.Bottom"
                    :connectable="!ui.readOnly"
                    class="!static !inline-flex !h-[26px] !w-auto !min-w-0 !transform-none items-center whitespace-nowrap !rounded-full !border-[1.5px] !border-solid px-2.5 font-bold leading-none"
                    :class="PILL_CLASSES[output.tone][isLinked(output) ? 1 : 0]"
                    :title="ui.readOnly ? undefined : t('text.workflow.canvas.output_hint')"
                >
                    <span class="pointer-events-none" :data-testid="output.testId">{{ OUTPUT_GLYPHS[output.tone] }} {{ t(output.labelKey) }}</span>
                </Handle>
                <button
                    v-if="!ui.readOnly && !isLinked(output)"
                    type="button"
                    class="nodrag nopan cursor-pointer whitespace-nowrap rounded-md border border-dashed border-teal-700/60 bg-white/80 px-1.5 py-px font-medium text-teal-800 opacity-70 hover:opacity-100 focus-visible:opacity-100"
                    data-testid="node-add-step"
                    @click.stop="requestStepPicker(id, output.id, $event)"
                >
                    + {{ t('text.workflow.canvas.add_step') }}
                </button>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { Handle, Position, useVueFlow } from '@vue-flow/core'
import { isLegacyDecision } from './graphRules'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNodeIssues } from './nodeCatalog'
import { OUTPUT_GLYPHS } from './graphRules'
import { PILL_CLASSES, canvasUi as ui, requestStepPicker } from './canvasUi'

defineOptions({
    name: 'DecisionNode',
})

const props = defineProps({
    id: { type: String, default: '' },
    type: { type: String, default: 'decision' },
    data: { type: Object, default: () => ({}) },
    selected: { type: Boolean, default: false },
})

const { t } = useI18n()
const { errorsFor } = useNodeIssues()
const { getEdges, connectionStartHandle, connectionEndHandle } = useVueFlow()

// Problems the server reported for this step on the last refused save.
const saveErrors = computed(() => errorsFor({ id: props.id, data: props.data }))

// "Yes" first, whichever of the two stored handles carries it. A decision written by a template installer has no
// yes_output/no_output and links on the node's named outputs `true` / `false`, which the runtime also follows.
const outputs = computed(() => {
    const outgoing = (getEdges.value || []).filter((edge) => edge.source === props.id)
    if (!isLegacyDecision(props.data?.step_config, outgoing)) {
        return [
            { id: 'true', tone: 'green', labelKey: 'text.workflow.output.true', testId: 'decision-output-a' },
            { id: 'false', tone: 'red', labelKey: 'text.workflow.output.false', testId: 'decision-output-b' },
        ]
    }
    const yes = props.data?.step_config?.yes_output === 'target-b' ? 'target-b' : 'target-a'
    return [
        { id: yes, tone: 'green', labelKey: 'text.workflow.output.true', testId: yes === 'target-a' ? 'decision-output-a' : 'decision-output-b' },
        {
            id: yes === 'target-a' ? 'target-b' : 'target-a',
            tone: 'red',
            labelKey: 'text.workflow.output.false',
            testId: yes === 'target-a' ? 'decision-output-b' : 'decision-output-a',
        },
    ]
})
const isLinked = (output) => (getEdges.value || []).some((edge) => edge.source === props.id && edge.sourceHandle === output.id)

const linking = computed(() => !!connectionStartHandle.value && connectionStartHandle.value.nodeId !== props.id)
const isDropTarget = computed(() => linking.value && connectionEndHandle.value?.nodeId === props.id)

const cardClass = computed(() => {
    if (isDropTarget.value) return 'border-teal-700 bg-teal-50 ring-4 ring-teal-700/20'
    if (props.data?.__error || saveErrors.value.length) return 'border-red-400 ring-2 ring-red-400'
    if (props.selected) return 'border-sky-600 ring-2 ring-sky-200'
    return 'border-slate-300'
})
</script>
