<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <div v-if="isLoading" class="py-8 text-center"><a-spin /></div>

    <div v-else-if="!approval" class="py-8 text-center text-sm text-ink-faint">
        {{ t('text.agentic.inbox.not_found') }}
    </div>

    <div v-else class="space-y-4">
        <!-- Header -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a-tag :color="statusTagColor(approval.status)" data-testid="approval-status">
                    {{ sl(approval.status) }}
                </a-tag>
                <a-tag v-if="approval.apply_outcome" :color="statusTagColor(approval.apply_outcome)" data-testid="approval-outcome">
                    {{ sl(approval.apply_outcome) }}
                </a-tag>
                <h3 class="mt-2 text-lg font-semibold text-ink" data-testid="approval-sentence">
                    {{ sentence }}
                </h3>
                <div class="text-xs text-ink-muted">
                    {{ moduleLabel(approval.run?.module_scope) }}
                </div>
            </div>
            <div class="text-xs text-ink-faint" data-testid="approval-created-at">
                {{ createdAtText }}
            </div>
        </div>

        <!-- Technical details: collapsed by default -->
        <a-collapse :bordered="false" class="bg-surface" data-testid="approval-technical">
            <a-collapse-panel key="technical" :header="t('text.agentic.approval.technical_title')">
                <dl class="mb-3 grid grid-cols-1 gap-x-4 gap-y-1 text-xs text-ink-muted sm:grid-cols-[auto_1fr]">
                    <dt>{{ t('text.agentic.approval.technical_action') }}</dt>
                    <dd class="m-0 break-all">{{ approval.artifact?.artifact_type || '—' }}</dd>
                    <dt>{{ t('text.agentic.approval.technical_subject') }}</dt>
                    <dd class="m-0 break-all">{{ approval.artifact?.subject_type || '—' }} #{{ approval.artifact?.subject_id ?? '—' }}</dd>
                </dl>
                <AgenticArtifactDiff
                    v-if="approval.artifact"
                    :record="approval"
                    :format-json="formatJson"
                    :format-scorecard-value="formatScorecardValue"
                />
            </a-collapse-panel>
        </a-collapse>

        <!-- Who the agent acted for and their data scope -->
        <div class="space-y-3 rounded-lg border border-border bg-surface p-4" data-testid="approval-acting-for">
            <div class="text-sm font-medium text-ink">
                <template v-if="delegatedBy">{{ t('text.data_scope.acting_for', { name: delegatedBy.name }) }}</template>
                <template v-else>{{ t('text.data_scope.no_delegator') }}</template>
            </div>
            <div v-if="scopeStart || scopeNow" class="grid gap-3 sm:grid-cols-2">
                <div v-if="scopeStart">
                    <div class="mb-1 text-xs text-ink-faint">{{ t('text.data_scope.at_start') }}</div>
                    <ScopeSummary :summary="scopeStart" />
                </div>
                <div v-if="scopeNow">
                    <div class="mb-1 text-xs text-ink-faint">{{ t('text.data_scope.now') }}</div>
                    <ScopeSummary :summary="scopeNow" :compare-to="scopeStart" />
                </div>
            </div>
            <a-alert v-if="scopeChanged" type="warning" show-icon :message="t('text.data_scope.changed_warning')" />
        </div>

        <!-- Action bar (replaces all window.prompt/confirm) -->
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface-sunken p-4">
            <!-- Pending: Approve + Reject -->
            <template v-if="approval.status === 'pending' && canApproveAgentic">
                <a-button
                    type="primary"
                    :loading="approvalActionMode === 'approve'"
                    @click="showApproveModal = true"
                >
                    {{ t('text.agentic.inbox.approve') }}
                </a-button>
                <a-button
                    danger
                    :loading="approvalActionMode === 'reject'"
                    @click="showRejectModal = true"
                >
                    {{ t('text.agentic.inbox.reject') }}
                </a-button>
            </template>

            <!-- Approved: Apply -->
            <template v-if="shouldShowApply">
                <a-button
                    type="primary"
                    :loading="approvalActionMode === 'apply'"
                    @click="showApplyModal = true"
                >
                    {{ t('text.agentic.inbox.apply') }}
                </a-button>
            </template>

            <!-- Applied badge -->
            <span v-if="approval.apply_outcome === 'applied'" class="text-sm text-success-ink">
                ✓ {{ t('text.agentic.inbox.applied') }}
            </span>

            <!-- Stale warning -->
            <div v-if="approval.apply_outcome === 'approval_stale'" class="text-sm text-warning-ink">
                ⚠ {{ t('text.agentic.inbox.stale_warning') }}
            </div>

            <!-- Reviewer note -->
            <div v-if="approval.reviewer_note" class="w-full text-xs text-ink-muted">
                {{ t('text.agentic.inbox.reviewer_note') }}: {{ approval.reviewer_note }}
            </div>
        </div>

        <!-- Approve modal -->
        <a-modal
            v-model:open="showApproveModal"
            :title="t('text.agentic.inbox.approve_title')"
            :ok-text="t('text.agentic.inbox.confirm_approve')"
            :cancel-text="t('text.agentic.inbox.cancel')"
            :confirm-loading="approvalActionMode === 'approve'"
            :mask-closable="approvalActionMode !== 'approve'"
            :closable="approvalActionMode !== 'approve'"
            @ok="doApprove"
        >
            <p class="mb-3 text-sm text-ink-muted">
                {{ t('text.agentic.inbox.approve_confirm') }}
            </p>
            <a-textarea
                v-model:value="reviewerNote"
                :placeholder="t('text.agentic.inbox.note_placeholder')"
                :rows="3"
            />
        </a-modal>

        <!-- Reject modal -->
        <a-modal
            v-model:open="showRejectModal"
            :title="t('text.agentic.inbox.reject_title')"
            :ok-text="t('text.agentic.inbox.confirm_reject')"
            :cancel-text="t('text.agentic.inbox.cancel')"
            ok-type="danger"
            :confirm-loading="approvalActionMode === 'reject'"
            :mask-closable="approvalActionMode !== 'reject'"
            :closable="approvalActionMode !== 'reject'"
            @ok="doReject"
        >
            <a-textarea
                v-model:value="rejectReason"
                :placeholder="t('text.agentic.inbox.reject_reason_placeholder')"
                :rows="3"
            />
            <p class="mt-2 text-xs text-ink-faint">
                {{ t('text.agentic.inbox.reject_tip') }}
            </p>
        </a-modal>

        <!-- Apply modal -->
        <a-modal
            v-model:open="showApplyModal"
            :title="t('text.agentic.inbox.apply_title')"
            :ok-text="t('text.agentic.inbox.confirm_apply')"
            :cancel-text="t('text.agentic.inbox.cancel')"
            :confirm-loading="approvalActionMode === 'apply'"
            :mask-closable="approvalActionMode !== 'apply'"
            :closable="approvalActionMode !== 'apply'"
            @ok="doApply"
        >
            <p class="text-sm text-ink-muted">
                {{ t('text.agentic.inbox.apply_confirm') }}
            </p>
        </a-modal>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticMeta } from './composables/useAgenticMeta'
import { useAgenticMutations } from './composables/useAgenticMutations'
import { useAgenticApproval } from '@/hooks'
import AgenticArtifactDiff from './components/AgenticArtifactDiff.vue'
import { buildApprovalSentence, formatApprovalDate } from './approval-sentence'
import ScopeSummary from '@/components/data-scope/scope-summary.vue'

const props = defineProps({
    approvalId: { type: [Number, String], default: null },
})

defineOptions({ name: 'AgenticApprovalDetail' })

const { t } = useI18n()
const { canApproveAgentic } = useAgenticPermissions()
const { artifactTypeLabel, moduleLabel, sl } = useAgenticMeta()
const { handleApprove, handleReject, handleApply, approvalActionMode } = useAgenticMutations()

/** Shared a-tag colours per status, matching the inventory status badges. */
const STATUS_TAG_COLORS = {
    pending: 'orange',
    awaiting_approval: 'orange',
    awaiting_apply: 'orange',
    approved: 'blue',
    running: 'cyan',
    applied: 'green',
    completed: 'green',
    rejected: 'red',
    failed: 'red',
    apply_failed: 'red',
    approval_stale: 'red',
}

/** a-tag colour for a status code; unknown codes stay neutral. */
const statusTagColor = (status) => STATUS_TAG_COLORS[status] || 'default'

function formatJson(obj) {
    try {
        return JSON.stringify(obj, null, 2)
    } catch {
        return String(obj)
    }
}

function formatScorecardValue(val) {
    if (val === null || val === undefined) return '—'
    if (typeof val === 'object') return formatJson(val)
    return String(val)
}

// Embedded in the inbox the id comes as a prop; opened directly on `agentic/approvals/:id` it comes from the route.
const route = useRoute()
const resolvedId = computed(() => props.approvalId || route?.params?.id || null)
const { data: approvalQuery, isLoading } = useAgenticApproval(resolvedId, { refetchOnWindowFocus: false })
const approval = computed(() => approvalQuery.value || null)

const sentence = computed(() => (approval.value ? buildApprovalSentence(approval.value, t, { artifactTypeLabel }) : ''))
const createdAtText = computed(() => formatApprovalDate(approval.value?.created_at))

const delegatedBy = computed(() => approval.value?.run?.delegated_by || null)
const scopeStart = computed(() => approval.value?.scope?.at_start || null)
const scopeNow = computed(() => approval.value?.scope?.now || null)
// Warn only when both summaries exist and differ.
const scopeChanged = computed(() =>
    Boolean(scopeStart.value && scopeNow.value) && JSON.stringify(scopeStart.value) !== JSON.stringify(scopeNow.value)
)

const shouldShowApply = computed(() =>
    approval.value?.status === 'approved'
    && [null, 'awaiting_apply', 'apply_failed', 'approval_stale'].includes(approval.value?.apply_outcome ?? null)
)

// Modal state
const showApproveModal = ref(false)
const showRejectModal = ref(false)
const showApplyModal = ref(false)
const reviewerNote = ref('')
const rejectReason = ref('')

async function doApprove() {
    const result = await handleApprove(approval.value.id, reviewerNote.value || null)
    if (result) {
        showApproveModal.value = false
        reviewerNote.value = ''
    }
}

async function doReject() {
    if (!rejectReason.value.trim()) return
    const result = await handleReject(approval.value.id, rejectReason.value)
    if (result) {
        showRejectModal.value = false
        rejectReason.value = ''
    }
}

async function doApply() {
    const result = await handleApply(approval.value.id)
    if (result) {
        showApplyModal.value = false
    }
}
</script>
