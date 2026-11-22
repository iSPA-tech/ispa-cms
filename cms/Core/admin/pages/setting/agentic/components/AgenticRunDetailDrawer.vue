<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <a-drawer
        v-model:open="drawerOpen"
        :title="t('text.agentic.run.detail_title')"
        :width="$isMobile() ? '100%' : 720"
        destroy-on-close
    >
        <a-skeleton v-if="isFetching && !record" :rows="8" active />
        <template v-else-if="record">
            <div class="space-y-4">
                <div class="agentic-panel">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-ink">
                                {{ record.input_goal || t('text.agentic.run.no_goal') }}
                            </div>
                            <div class="mt-1 text-xs text-ink-muted">
                                {{ t('text.agentic.approval_detail.run_id', { id: record.id }) }} ·
                                {{
                                    record.profile?.name ||
                                    record.profile?.slug ||
                                    t('text.agentic.run.unknown_profile')
                                }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <span :class="runtimeStatusClass(record.status)" class="agentic-pill">
                                {{ sl(record.status) }}
                            </span>
                            <span class="agentic-pill">{{ record.module_scope || t('text.agentic.schedules.scope_global') }}</span>
                            <span class="agentic-pill">{{ record.environment || t('text.agentic.section.env_local') }}</span>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 text-xs text-ink-muted sm:grid-cols-2">
                        <div>
                            {{ t('text.agentic.run.workflow') }}: #{{
                                record.workflow?.id || t('text.agentic.na')
                            }}
                            · {{ sl(record.workflow?.status) }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.resume_step') }}:
                            {{
                                record.workflow?.resume_step_id ||
                                t('text.agentic.none')
                            }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.target') }}:
                            {{ shortModelName(record.target_type) }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.target_id') }}:
                            {{ record.target_id || t('text.agentic.na') }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.terminal_reason') }}:
                            {{ sl(record.terminal_reason) }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.post_approval_outcome') }}:
                            {{ sl(record.post_approval_outcome) }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.operator_request') }}:
                            {{ sl(record.operator_request_state) }}
                        </div>
                        <div>
                            {{ t('text.agentic.run.stop_requested_at') }}:
                            {{ record.stop_requested_at || t('text.agentic.na') }}
                        </div>
                    </div>
                </div>

                <div class="agentic-panel">
                    <div class="agentic-panel-title">{{ t('text.agentic.run.steps') }}</div>
                    <div class="space-y-3">
                        <div
                            v-for="step in record.steps || []"
                            :key="step.id"
                            class="rounded-xl border border-border p-4"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">
                                        #{{ step.step_index }} ·
                                        {{ step.action_name || t('text.agentic.run.unnamed_step') }}
                                    </div>
                                    <div class="mt-1 text-xs text-ink-muted">
                                        {{ sl(step.action_type) }} · {{ sl(step.state) }}
                                    </div>
                                </div>
                                <span :class="runtimeStatusClass(step.status)" class="agentic-pill">
                                    {{ sl(step.status) }}
                                </span>
                            </div>
                            <div class="mt-3 space-y-1 text-xs text-ink-muted">
                                <div>
                                    <span class="font-medium"
                                        >{{ t('text.agentic.run.reasoning') }}:</span
                                    >
                                    {{ step.reasoning_summary || t('text.agentic.na') }}
                                </div>
                                <div>
                                    <span class="font-medium"
                                        >{{ t('text.agentic.run.observation') }}:</span
                                    >
                                    {{ step.observation_summary || t('text.agentic.na') }}
                                </div>
                                <div>
                                    {{ t('text.agentic.run.terminal') }}:
                                    {{ sl(step.terminal_reason) }}
                                </div>
                                <div>
                                    {{ t('text.agentic.run.created') }}:
                                    {{ formatDate(step.created_at) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="agentic-panel">
                        <div class="agentic-panel-title">
                            {{ t('text.agentic.run.tool_calls') }}
                        </div>
                        <div class="space-y-3">
                            <div
                                v-for="toolCall in record.tool_calls || []"
                                :key="toolCall.id"
                                class="rounded-xl border border-border p-4 text-xs text-ink-muted"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>{{ t('text.agentic.run.call') }} #{{ toolCall.id }}</div>
                                    <span
                                        :class="runtimeStatusClass(toolCall.status)"
                                        class="agentic-pill"
                                    >
                                        {{ sl(toolCall.status) }}
                                    </span>
                                </div>
                                <div class="mt-2 space-y-1">
                                    <div>
                                        {{ t('text.agentic.run.retry_scope') }}:
                                        {{ sl(toolCall.retry_scope) }}
                                    </div>
                                    <div>
                                        {{ t('text.agentic.run.retry_count') }}:
                                        {{ toolCall.retry_count ?? 0 }}
                                    </div>
                                    <div>
                                        {{ t('text.agentic.run.error') }}:
                                        {{
                                            toolCall.error_message ||
                                            t('text.agentic.none')
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="agentic-panel">
                        <div class="agentic-panel-title">
                            {{ t('text.agentic.run.artifacts_approvals') }}
                        </div>
                        <div class="space-y-3">
                            <div
                                v-for="artifact in record.artifacts || []"
                                :key="`artifact-${artifact.id}`"
                                class="rounded-xl border border-border p-4 text-xs text-ink-muted"
                            >
                                <div class="font-medium text-ink">
                                    {{ sl(artifact.artifact_type) }}
                                </div>
                                <div class="mt-2 space-y-1">
                                    <div>
                                        {{ t('text.agentic.run.artifact') }} #{{ artifact.id }}
                                    </div>
                                    <div>
                                        {{ t('text.agentic.run.subject') }}:
                                        {{ shortModelName(artifact.subject_type) }} ·
                                        {{ artifact.subject_id || t('text.agentic.na') }}
                                    </div>
                                    <div>
                                        {{ t('text.agentic.run.render') }}:
                                        {{ sl(artifact.render_hint) }}
                                    </div>
                                </div>
                            </div>
                            <div
                                v-for="approval in record.approvals || []"
                                :key="`approval-${approval.id}`"
                                class="rounded-xl border border-border p-4 text-xs text-ink-muted"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="font-medium text-ink">
                                        {{ t('text.agentic.run.approval') }} #{{ approval.id }}
                                    </div>
                                    <span
                                        :class="runtimeStatusClass(approval.status)"
                                        class="agentic-pill"
                                    >
                                        {{ sl(approval.status) }}
                                    </span>
                                </div>
                                <div class="mt-2 space-y-1">
                                    <div>
                                        {{ t('text.agentic.run.apply_outcome') }}:
                                        {{ sl(approval.apply_outcome) }}
                                    </div>
                                    <div>
                                        {{ t('text.agentic.run.artifact_id') }}:
                                        {{ approval.artifact_id || t('text.agentic.na') }}
                                    </div>
                                    <div>
                                        {{ t('text.agentic.run.reviewer_note') }}:
                                        {{ approval.reviewer_note || t('text.agentic.none') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import { useAgenticMeta } from '../composables/useAgenticMeta'

const { t } = useI18n()
const { sl } = useAgenticMeta()

const props = defineProps({
    open: { type: Boolean, default: false },
    record: { type: Object, default: null },
    isFetching: { type: Boolean, default: false },
    runtimeStatusClass: { type: Function, required: true },
})

const emit = defineEmits(['update:open'])

const drawerOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
})

function shortModelName(fqcn) {
    if (!fqcn) return t('text.agentic.na')
    return fqcn.split('\\').pop()
}

function formatDate(dateStr) {
    if (!dateStr) return t('text.agentic.na')
    return dayjs(dateStr).format('DD/MM/YYYY HH:mm')
}
</script>
