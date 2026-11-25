<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <template v-if="canViewAgentic">
        <div class="space-y-4">
            <breadcrumbs :items="[{ text: t('nav.a.approvals'), icon: 'clipboard-check-outline' }]">
                <template v-if="pendingCount > 0" #badge>
                    <a-tag color="orange" class="m-0" data-testid="inbox-pending-count">{{ pendingCount }}</a-tag>
                </template>
                <template #description>{{ t('text.agentic.inbox.description') }}</template>
                <router-link :to="{ name: 'approvals-sla' }" data-testid="inbox-open-sla">
                    <a-button>{{ t('text.agentic.sla.summary_title') }}</a-button>
                </router-link>
            </breadcrumbs>

            <!-- Filters: the status tabs scroll sideways on a phone instead of cutting their labels -->
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="max-w-full overflow-x-auto">
                    <a-segmented v-model:value="statusFilter" :options="statusTabs" data-testid="inbox-status" />
                </div>
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <!-- Only what is still waiting can be overdue -->
                    <a-checkbox
                        v-if="waitingTab"
                        :checked="approvalFilters.overdue === true"
                        data-testid="inbox-overdue-only"
                        @update:checked="setOverdueOnly"
                    >
                        {{ t('text.agentic.sla.overdue_only') }}
                    </a-checkbox>
                    <a-select
                        v-model:value="approvalFilters.artifactType"
                        :options="moduleFilterOptions"
                        :placeholder="t('text.agentic.inbox.all_modules')"
                        :aria-label="t('text.agentic.inbox.all_modules')"
                        allow-clear
                        class="w-full sm:w-56"
                    />
                </div>
            </div>

            <div v-if="isLoading" class="py-8 text-center"><a-spin /></div>

            <div
                v-else-if="approvals.length === 0"
                class="rounded-xl border border-dashed border-border bg-surface px-4 py-10 text-center text-sm text-ink-muted"
                data-testid="inbox-empty"
            >
                {{ emptyText }}
            </div>

            <ul v-else class="m-0 list-none divide-y divide-border overflow-hidden rounded-xl border border-border bg-surface p-0" data-testid="inbox-list">
                <li
                    v-for="approval in approvals"
                    :key="approval.id"
                    class="flex min-w-0 flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center"
                    data-testid="inbox-row"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <router-link
                            :to="detailRoute(approval)"
                            class="block break-words text-sm font-semibold leading-6 text-ink hover:underline"
                            data-testid="inbox-row-title"
                        >
                            {{ rowTitle(approval) }}
                        </router-link>
                        <div v-if="rowSubject(approval)" class="break-words text-xs text-ink-muted" data-testid="inbox-row-subject">
                            {{ rowSubject(approval) }}
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 text-xs text-ink-faint">
                            <a-tag :color="statusTagColor(approval.status)" class="m-0" data-testid="inbox-row-status">{{ rowStatus(approval) }}</a-tag>
                            <a-tag v-if="rowRound(approval)" class="m-0" data-testid="inbox-row-round">{{ rowRound(approval) }}</a-tag>
                            <a-tag v-if="rowType(approval)" class="m-0" data-testid="inbox-row-type">{{ rowType(approval) }}</a-tag>
                            <!-- Workflow step: approval mode, and who decided when it was not a person -->
                            <!-- An AI proposal (`kind: proposal`) has no approval mode of its own, so it gets no mode tag -->
                            <template v-if="approval.diff_data?.workflow_node && approval.diff_data.workflow_node.kind !== 'proposal'">
                                <a-tag class="m-0" data-testid="row-workflow-node">{{ workflowModeLabel(approval.diff_data.workflow_node, approval) }}</a-tag>
                                <a-tag v-if="automaticDecision(approval.diff_data.workflow_node)" color="purple" class="m-0" data-testid="inbox-row-decided-by">
                                    {{ decidedByJev(approval) && rowJev(approval) ? t('text.agentic.auto_publish.decided_by_jev') : decidedByLabel(approval.diff_data.workflow_node.decided_by) }}
                                </a-tag>
                            </template>
                            <!-- What JEV concluded, and how sure it was -->
                            <a-tag v-if="rowJev(approval)" :color="rowJev(approval).color" class="m-0" :data-verdict="rowJev(approval).verdict" data-testid="inbox-row-jev">
                                {{ rowJevText(approval) }}
                            </a-tag>
                            <span>{{ formatApprovalDate(approval.created_at) }}</span>
                        </div>
                        <!-- Why the item waits for a person instead of being published on its own -->
                        <div v-if="rowHeld(approval)" class="break-words text-xs text-ink-muted" data-testid="inbox-row-held">
                            {{ rowHeld(approval) }}
                        </div>
                        <!-- JEV approved it on its own: where to take it back -->
                        <div v-if="rowTakeBack(approval)" class="text-xs" data-testid="inbox-row-take-back">
                            <router-link :to="rowTakeBack(approval)" class="break-words font-semibold text-ink underline">
                                {{ t('text.agentic.auto_publish.take_back') }}
                            </router-link>
                        </div>
                        <!-- Who was asked to review, and the time left (the chip always says it in words) -->
                        <div v-if="approval.assignment" class="flex flex-wrap items-center gap-1.5 text-xs text-ink-muted" data-testid="inbox-row-assignment">
                            <a-tag v-if="rowDue(approval)" :color="rowDue(approval).color" class="m-0" :data-tone="rowDue(approval).tone" data-testid="inbox-row-due">
                                {{ rowDue(approval).text }}
                            </a-tag>
                            <a-tag v-if="approval.assignment.assigned_to_me" color="blue" class="m-0" data-testid="inbox-row-mine">
                                {{ t('text.agentic.sla.assigned_to_you') }}
                            </a-tag>
                            <span v-if="assigneeNames(approval)" class="min-w-0 break-words" data-testid="inbox-row-assignees">
                                {{ t('text.agentic.sla.assigned_to') }}: {{ assigneeNames(approval) }}
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <a-popconfirm
                            v-if="approval.status === 'pending' && canApproveAgentic && approval.diff_data?.workflow_node?.kind !== 'question'"
                            :title="t('text.agentic.inbox.approve_apply_confirm')"
                            :ok-text="t('text.agentic.inbox.approve_apply')"
                            :cancel-text="t('text.agentic.inbox.cancel')"
                            @confirm="approveApplyRow(approval)"
                        >
                            <a-button
                                data-testid="row-approve-apply"
                                :loading="activeApprovalActionId === approval.id && approvalActionMode === 'approve_apply'"
                                :disabled="Boolean(approvalActionMode)"
                            >
                                {{ t('text.agentic.inbox.approve_apply') }}
                            </a-button>
                        </a-popconfirm>
                        <router-link :to="detailRoute(approval)" data-testid="inbox-row-open">
                            <a-button :type="approval.status === 'pending' ? 'primary' : 'default'">
                                {{ approval.status === 'pending' && canApproveAgentic ? t('text.agentic.inbox.review') : t('text.agentic.inbox.view') }}
                            </a-button>
                        </router-link>
                    </div>
                </li>
            </ul>

            <div v-if="meta.total > meta.per_page" class="flex justify-center">
                <a-pagination v-model:current="approvalFilters.page" :total="meta.total" :page-size="meta.per_page" size="small" simple />
            </div>
        </div>
    </template>
    <no-permission-error v-else />
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticMeta } from './composables/useAgenticMeta'
import { agenticAutoApprovedLink, agenticModules } from './registry'
import { useAgenticFilters } from './composables/useAgenticFilters'
import { useAgenticMutations } from './composables/useAgenticMutations'
import { useAgenticApprovals } from '@/hooks'
import { assigneeNames, buildApprovalSentence, dueChip, formatApprovalDate } from './approval-sentence'
import { approvalSubjectName } from './readable-diff'
import { decidedByJev, heldReasonLine, jevChip } from '@/components/workflow/widgets/autoPublishRules.js'

defineOptions({ name: 'AgenticApprovalInbox' })

const { t, te, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { canViewAgentic, canApproveAgentic } = useAgenticPermissions()
const { handleApproveApply, activeApprovalActionId, approvalActionMode } = useAgenticMutations()
const { artifactTypeLabel, sl } = useAgenticMeta()
const { approvalFilters } = useAgenticFilters()

/** List statuses a link may ask for (`?status=`); anything else opens the waiting list. */
const STATUSES = ['pending', 'assigned', 'approved', 'rejected', 'changes_requested', 'all']
const statusFilter = ref(STATUSES.includes(route.query?.status) ? route.query.status : 'pending')
approvalFilters.perPage = 20

/** "Giao cho tôi" is the waiting list narrowed to the signed-in reviewer; every other tab is a status. */
function applyTab(tab) {
    approvalFilters.status = tab === 'all' ? undefined : tab === 'assigned' ? 'pending' : tab
    approvalFilters.assigned = tab === 'assigned' ? 'me' : undefined
    if (!['pending', 'assigned'].includes(tab)) approvalFilters.overdue = undefined
}
applyTab(statusFilter.value)
if (route.query?.overdue === '1' && ['pending', 'assigned'].includes(statusFilter.value)) approvalFilters.overdue = true

const waitingTab = computed(() => ['pending', 'assigned'].includes(statusFilter.value))

function setOverdueOnly(checked) {
    approvalFilters.overdue = checked ? true : undefined
    approvalFilters.page = 1
}

const emptyText = computed(() => {
    if (approvalFilters.overdue) return t('text.agentic.sla.empty_overdue')
    if (statusFilter.value === 'assigned') return t('text.agentic.sla.empty_assigned')
    return statusFilter.value === 'pending' ? t('text.agentic.inbox.empty_pending') : t('text.agentic.inbox.empty')
})

const statusTabs = computed(() => [
    { label: pendingCount.value > 0 ? `${t('text.agentic.inbox.pending')} (${pendingCount.value})` : t('text.agentic.inbox.pending'), value: 'pending' },
    { label: t('text.agentic.sla.tab_assigned'), value: 'assigned' },
    { label: t('text.agentic.inbox.approved'), value: 'approved' },
    { label: t('text.agentic.inbox.rejected'), value: 'rejected' },
    { label: t('text.agentic.inbox.changes_requested'), value: 'changes_requested' },
    { label: t('text.agentic.inbox.all'), value: 'all' },
])

/** A module that is not on this site carries no label, so its filter entry is left out (never a raw key). */
const moduleFilterOptions = computed(() =>
    [
        { key: 'text.agentic.module.content', value: 'content_post' },
        { key: 'text.agentic.module.product', value: 'product' },
        { key: 'text.agentic.module.education', value: 'education' },
        // Modules that registered an approval filter value.
        ...agenticModules().filter((module) => module.inboxFilter).map((module) => ({ key: module.labelKey, value: module.inboxFilter })),
    ]
        .filter((option) => te(option.key))
        .map((option) => ({ label: t(option.key), value: option.value })),
)

watch(statusFilter, (val) => {
    applyTab(val)
    approvalFilters.page = 1
})

const { data: approvalQuery, isLoading } = useAgenticApprovals(approvalFilters, { refetchOnWindowFocus: true, refetchInterval: 30000 })
const approvals = computed(() => approvalQuery.value?.data || [])
const meta = computed(() => approvalQuery.value?.meta || {})

const pendingCount = ref(0)
watch(() => approvalQuery.value, (val) => {
    if (statusFilter.value === 'pending' && !approvalFilters.artifactType && !approvalFilters.overdue && val?.meta?.total !== undefined) {
        pendingCount.value = val.meta.total
    }
}, { immediate: true })

/** a-tag colour per status, as on the detail page. */
const STATUS_TAG_COLORS = { pending: 'orange', approved: 'blue', applied: 'green', rejected: 'red', changes_requested: 'purple', expired: 'default' }
const statusTagColor = (status) => STATUS_TAG_COLORS[status] || 'default'

// The time left is counted from the clock, so it is refreshed every minute while the list stays open.
const now = ref(Date.now())
const clock = setInterval(() => { now.value = Date.now() }, 60000)
onBeforeUnmount(() => clearInterval(clock))
const rowDue = (approval) => dueChip(approval, t, now.value)

const detailRoute = (approval) => ({ name: 'approval-detail', params: { id: String(approval.id) } })

/** What the row asks for: the workflow step's own title, else one sentence built from the change. */
function rowTitle(approval) {
    const title = approval.diff_data?.workflow_node?.title
    return typeof title === 'string' && title.trim() !== '' ? title.trim() : buildApprovalSentence(approval, t, { artifactTypeLabel })
}

/** Name of the record the row is about; left out when the title already says it. */
function rowSubject(approval) {
    const name = approvalSubjectName(approval)
    return name && !rowTitle(approval).includes(name) ? name : ''
}

/** Kind of proposal, left out when it is unknown or the title already says it. */
function rowType(approval) {
    const label = artifactTypeLabel(approval.artifact?.artifact_type)
    return label && label !== t('text.agentic.artifact.unknown') && !rowTitle(approval).includes(label) ? label : ''
}

/** A draft sent back reads "being rewritten" until its rewrite exists, then "rewritten". */
function rowStatus(approval) {
    const rewritten = approval.status === 'changes_requested' && approval.diff_data?.workflow_node?.next_approval_id
    return rewritten ? t('text.agentic.rewrite.rewritten') : sl(approval.status)
}

/** "Round 2/3" on a rewritten draft, so the reviewer sees it is not the first one. */
function rowRound(approval) {
    const node = approval.diff_data?.workflow_node
    const round = Number(node?.round) || 1
    if (round < 2) return ''
    const max = Number(node?.max_rounds) || 0
    return max ? t('text.agentic.rewrite.round', { round, max }) : t('text.agentic.rewrite.round_n', { round })
}

/** One-click approve and apply from the list; the row is then opened so the result is in view. */
async function approveApplyRow(approval) {
    if (approvalActionMode.value) return
    const outcome = await handleApproveApply(approval.id, null)
    if (outcome) router.push(detailRoute(approval))
}

/** Approval mode of a workflow node item (a question is always answered by a person). */
function workflowModeLabel(node, approval = null) {
    if (node?.kind === 'question') return t('text.agentic.workflow_node.kind_question')
    // JEV handed this item to a person: say so, rather than "JEV decides" next to an item that waits for you.
    if (node?.mode === 'jev' && (approval?.held_reason || node?.held_reason)) return t('text.agentic.workflow_node.mode_jev_handed')
    return t(`text.agentic.workflow_node.mode_${['auto', 'jev'].includes(node?.mode) ? node.mode : 'human'}`)
}

/** Whether a rule, not a person, decided the item (auto, JEV, reversible fields, expiry). */
function automaticDecision(node) {
    return Boolean(node?.decided_by) && node.decided_by !== 'human'
}

/** JEV's conclusion on the row ("JEV: Pass · sure 0.74"), when the step recorded one. */
const rowJev = (approval) => jevChip(approval, locale?.value)
function rowJevText(approval) {
    const chip = rowJev(approval)
    const verdict = t(chip.verdictKey)
    return chip.level ? t('text.agentic.auto_publish.chip', { verdict, level: chip.level }) : t('text.agentic.auto_publish.chip_plain', { verdict })
}

/** Why a waiting row was not published on its own, in plain words. */
function rowHeld(approval) {
    const line = heldReasonLine(approval, locale?.value)
    return line ? t(line.key, line.params) : ''
}

/** Where a row JEV approved on its own can be taken back: the owning module's review screen. */
const rowTakeBack = (approval) => (decidedByJev(approval) ? agenticAutoApprovedLink(approval) : null)

function decidedByLabel(decidedBy) {
    const known = ['human', 'auto', 'jev', 'reversible_fields', 'expiry']
    return t(`text.agentic.workflow_node.decided_by_${known.includes(decidedBy) ? decidedBy : 'human'}`)
}
</script>
