<template>
    <section class="i-box space-y-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="text-lg font-semibold text-ink">{{ $t('text.agentic.inbox.title') }}</div>
                <div class="text-sm text-ink-muted">
                    {{ $t('text.agentic.section.approvals_intro') }}
                </div>
            </div>

            <div class="grid gap-2 sm:grid-cols-3">
                <a-select
                    v-model:value="filters.status"
                    :options="statusOptions"
                    allow-clear
                    :placeholder="$t('text.agentic.col.status')"
                />
                <a-select
                    v-model:value="filters.applyOutcome"
                    :options="applyOutcomeOptions"
                    allow-clear
                    :placeholder="$t('text.agentic.run.apply_outcome')"
                />
                <a-input
                    v-model:value="filters.artifactType"
                    allow-clear
                    :placeholder="$t('text.agentic.section.artifact_type')"
                />
            </div>
        </div>

        <a-table
            :columns="columns"
            :data-source="approvals"
            :loading="isLoading || isFetching"
            :pagination="false"
            row-key="id"
            size="small"
        >
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'summary'">
                    <div class="space-y-1">
                        <div class="font-medium text-ink">
                            {{ record.artifact?.artifact_type }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ $t('text.agentic.approval_detail.run_id', { id: record.run?.id }) }} · {{ record.profile?.slug || $t('text.agentic.schedules.unknown_profile') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ $t('text.agentic.section.workflow_number', { id: record.workflow?.id || $t('text.agentic.na') }) }} · {{ record.workflow?.status || $t('text.agentic.run.detached') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ record.artifact?.payload?.summary || $t('text.agentic.section.no_proposal_summary') }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'status'">
                    <div class="space-y-2">
                        <span class="agentic-pill" :class="runtimeStatusClass(record.status)">
                            {{ sl(record.status) }}
                        </span>
                        <div class="text-xs text-ink-muted">
                            {{ record.apply_outcome ? sl(record.apply_outcome) : sl('not_applied') }}
                        </div>
                        <div v-if="record.workflow?.status" class="text-xs text-ink-muted">
                            {{ $t('text.agentic.section.workflow_status', { status: sl(record.workflow.status) }) }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'diff'">
                    <AgenticArtifactDiff
                        :record="record"
                        :format-json="formatJson"
                        :format-scorecard-value="formatScorecardValue"
                    />
                </template>
                <template v-else-if="column.key === 'actions'">
                    <div class="flex flex-wrap gap-2">
                        <a-button
                            size="small"
                            type="default"
                            @click="onOpenApprovalDetail(record)"
                        >
                            {{ $t('text.agentic.page.inspect') }}
                        </a-button>
                        <a-button
                            v-if="record.status === 'pending'"
                            size="small"
                            type="primary"
                            :disabled="!canApprove"
                            :loading="activeApprovalActionId === record.id && approvalActionMode === 'approve' && isApprovePending"
                            @click="onApproveApproval(record)"
                        >
                            {{ $t('text.agentic.inbox.approve') }}
                        </a-button>
                        <a-button
                            v-if="record.status === 'pending'"
                            size="small"
                            danger
                            :disabled="!canApprove"
                            :loading="activeApprovalActionId === record.id && approvalActionMode === 'reject' && isRejectPending"
                            @click="onRejectApproval(record)"
                        >
                            {{ $t('text.agentic.inbox.reject') }}
                        </a-button>
                        <a-button
                            v-if="shouldShowApply(record)"
                            size="small"
                            type="default"
                            :disabled="!canApprove"
                            :loading="activeApprovalActionId === record.id && approvalActionMode === 'apply' && isApplyPending"
                            @click="onApplyApproval(record)"
                        >
                            {{ $t('nav.b.apply') }}
                        </a-button>
                    </div>
                </template>
            </template>
        </a-table>

        <div class="flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-xs text-ink-muted">
                {{ formatMeta(meta) }}
            </div>
            <a-pagination
                v-model:current="filters.page"
                :page-size="filters.perPage"
                :total="meta.total || 0"
                show-less-items
                size="small"
            />
        </div>
    </section>
</template>

<script setup>
import AgenticArtifactDiff from './AgenticArtifactDiff.vue'
import { useAgenticMeta } from '../composables/useAgenticMeta'

const { sl } = useAgenticMeta()

defineProps({
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    applyOutcomeOptions: { type: Array, required: true },
    columns: { type: Array, required: true },
    approvals: { type: Array, required: true },
    meta: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
    isLoading: { type: Boolean, default: false },
    isFetching: { type: Boolean, default: false },
    isApprovePending: { type: Boolean, default: false },
    isRejectPending: { type: Boolean, default: false },
    isApplyPending: { type: Boolean, default: false },
    activeApprovalActionId: { type: [Number, String], default: null },
    approvalActionMode: { type: String, default: null },
    runtimeStatusClass: { type: Function, required: true },
    formatMeta: { type: Function, required: true },
    formatJson: { type: Function, required: true },
    formatScorecardValue: { type: Function, required: true },
    shouldShowApply: { type: Function, required: true },
    onOpenApprovalDetail: { type: Function, required: true },
    onApproveApproval: { type: Function, required: true },
    onRejectApproval: { type: Function, required: true },
    onApplyApproval: { type: Function, required: true },
})
</script>
