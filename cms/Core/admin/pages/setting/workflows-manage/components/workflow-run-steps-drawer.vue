<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-drawer
        :open="open"
        :title="t('text.workflow.runs.steps_title')"
        width="min(480px, 100vw)"
        destroy-on-close
        @close="$emit('close')"
    >
        <div v-if="run" class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-600" data-testid="steps-run-summary">
            <a-tag :color="runStatusColor(run.status)">{{ runStatusLabel(run.status) }}</a-tag>
            <span>{{ runSourceLabel(run.source) }}</span>
            <span>{{ formatRunDate(run.started_at || run.created_at) }}</span>
        </div>

        <div v-if="loading" class="py-8 text-center"><a-spin /></div>

        <a-alert v-else-if="error" type="error" show-icon :message="error" data-testid="steps-error" />

        <a-empty v-else-if="!steps.length" :description="t('text.workflow.runs.steps_empty')" data-testid="steps-empty" />

        <ol v-else class="m-0 list-none space-y-3 p-0" data-testid="steps-list">
            <li v-for="(step, index) in steps" :key="index" class="rounded-lg border border-slate-200 px-3 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="min-w-0 break-words text-sm font-medium text-gray-700">{{ stepName(step, index) }}</span>
                    <a-tag :color="runStatusColor(step.status)">{{ runStatusLabel(step.status) }}</a-tag>
                </div>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                    <span>{{ t('text.workflow.runs.col.started') }}: {{ formatRunDate(step.started_at) }}</span>
                    <span>{{ t('text.workflow.runs.col.duration') }}: {{ formatRunDuration(step.duration_ms, t) }}</span>
                </div>
                <!-- A step that sent nothing on purpose (a business mail switched off, the same address written to recently, ...). -->
                <a-alert
                    v-if="skipReason(step)"
                    class="mt-2"
                    type="warning"
                    show-icon
                    :message="t('text.workflow.runs.step_skipped', { reason: skipReason(step) })"
                    data-testid="step-skipped"
                />
                <a-alert
                    v-if="step.error_message"
                    class="mt-2"
                    type="error"
                    :message="t('text.workflow.runs.step_error')"
                    :description="step.error_message"
                />
            </li>
        </ol>
    </a-drawer>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { getWorkflowRunSteps, workflowErrorCode } from '../workflow-run-api'
import { formatRunDate, formatRunDuration, runStatusColor, useRunLabels } from '../workflow-run-labels'

defineOptions({ name: 'WorkflowRunStepsDrawer' })

const props = defineProps({
    open: { type: Boolean, default: false },
    run: { type: Object, default: null },
    workflow: { type: Object, default: null },
})

defineEmits(['close'])

const { t, te } = useI18n()
const { runStatusLabel, runSourceLabel } = useRunLabels()

const steps = ref([])
const loading = ref(false)
const error = ref('')

/** Why a step sent nothing, in words (`result_data.skip_reason` of an `email_send` step), or ''. */
const skipReason = (step) => {
    const result = step?.result_data
    if (!result || typeof result !== 'object' || result.skipped !== true) return ''
    const key = `text.workflow.value.${result.skip_reason}`
    return result.skip_reason && te(key) ? t(key) : t('text.workflow.runs.skip_unknown')
}

/** The name the builder shows for the step; a step the graph no longer has is numbered instead of showing its id. */
const stepName = (step, index) => {
    const id = String(step.step_id ?? '')
    const node = (props.workflow?.nodes || []).find((item) =>
        [item?.id, item?.data?.id, item?.data?.step_id].some((value) => value !== undefined && value !== null && String(value) === id)
    )
    return node?.data?.label || node?.data?.name || node?.label || t('text.workflow.runs.step_number', { number: index + 1 })
}

watch(
    () => [props.open, props.run?.id],
    async ([open, runId]) => {
        if (!open || !runId) return
        loading.value = true
        error.value = ''
        steps.value = []
        try {
            const loaded = await getWorkflowRunSteps(runId)
            if (runId === props.run?.id) steps.value = loaded
        } catch (err) {
            error.value = t(workflowErrorCode(err) === 'forbidden' ? 'text.workflow.runs.error.forbidden' : 'text.workflow.runs.error.steps_failed')
        } finally {
            loading.value = false
        }
    },
    { immediate: true }
)
</script>
