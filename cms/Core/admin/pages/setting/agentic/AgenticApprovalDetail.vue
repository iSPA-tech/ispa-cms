<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <div v-if="isLoading" class="py-8 text-center"><a-spin /></div>

    <div v-else-if="!approval" class="py-8 text-center text-sm text-ink-faint">
        {{ t('text.agentic.inbox.not_found') }}
    </div>

    <div v-else class="space-y-4">
        <!-- Header: what is asked, in one sentence, with its state -->
        <header class="min-w-0 space-y-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <a-tag :color="statusTagColor(approval.status)" class="m-0" data-testid="approval-status">
                    {{ statusText(approval.status, Boolean(nextApprovalId)) }}
                </a-tag>
                <a-tag v-if="approval.apply_outcome && approval.apply_outcome !== approval.status" :color="statusTagColor(approval.apply_outcome)" class="m-0" data-testid="approval-outcome">
                    {{ sl(approval.apply_outcome) }}
                </a-tag>
                <a-tag v-if="moduleName" class="m-0">{{ moduleName }}</a-tag>
                <a-tag v-if="round > 1 || canRequestRewrite" class="m-0" data-testid="approval-round">{{ roundText }}</a-tag>
            </div>
            <h2 class="m-0 break-words text-lg font-semibold leading-7 text-ink" data-testid="approval-sentence">
                {{ heading }}
            </h2>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
                <span v-if="subjectName" class="min-w-0 break-words font-medium text-ink" data-testid="approval-subject">{{ subjectName }}</span>
                <span data-testid="approval-created-at">{{ createdAtText }}</span>
            </div>
        </header>

        <!-- Apply did not go through: say what happened in plain words -->
        <a-alert
            v-if="approval.apply_outcome === 'approval_stale'"
            type="warning"
            show-icon
            data-testid="approval-stale"
            :message="t('text.agentic.inbox.stale_warning')"
        />
        <a-alert
            v-if="approval.apply_outcome === 'apply_failed'"
            type="error"
            show-icon
            data-testid="approval-apply-failed"
            :message="t('text.agentic.inbox.apply_failed_warning')"
        />

        <!-- A rewrite was asked for: say where the new draft will be, and link to it once it exists -->
        <div
            v-if="approval.status === 'changes_requested'"
            class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border border-border bg-info-soft p-4 text-sm text-ink"
            role="status"
            data-testid="approval-rewriting"
        >
            <span class="min-w-0 break-words">
                {{ nextApprovalId ? t('text.agentic.rewrite.ready_notice') : t('text.agentic.rewrite.pending_notice') }}
            </span>
            <router-link v-if="nextApprovalId" :to="roundRoute(nextApprovalId)" data-testid="open-rewrite">
                <a-button type="primary">{{ t('text.agentic.rewrite.open_next') }}</a-button>
            </router-link>
        </div>

        <!-- Who was asked to review, by when, and how the decision compares to the target time -->
        <section v-if="assignment" class="min-w-0 space-y-2 rounded-xl border border-border bg-surface p-4" data-testid="approval-assignment">
            <h3 class="m-0 border-b border-border pb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('text.agentic.sla.box_title') }}</h3>
            <dl class="m-0 grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[minmax(0,11rem)_minmax(0,1fr)]">
                <template v-if="assignedNames || assignment.assigned_to_me">
                    <dt class="text-ink-muted">{{ t('text.agentic.sla.assigned_to') }}</dt>
                    <dd class="m-0 flex min-w-0 flex-wrap items-center gap-1.5 break-words text-ink" data-testid="assignment-assignees">
                        <span v-if="assignedNames" class="min-w-0 break-words">{{ assignedNames }}</span>
                        <a-tag v-if="assignment.assigned_to_me" color="blue" class="m-0" data-testid="assignment-mine">{{ t('text.agentic.sla.assigned_to_you') }}</a-tag>
                    </dd>
                </template>
                <template v-if="assignment.due_at">
                    <dt class="text-ink-muted">{{ t('text.agentic.sla.due_at') }}</dt>
                    <dd class="m-0 flex min-w-0 flex-wrap items-center gap-1.5 text-ink" data-testid="assignment-due">
                        <span>{{ formatApprovalDate(assignment.due_at) }}</span>
                        <span v-if="assignment.sla_seconds" class="text-xs text-ink-muted">
                            ({{ t('text.agentic.sla.target', { time: formatDuration(assignment.sla_seconds, t) }) }})
                        </span>
                        <a-tag v-if="approval.status === 'pending' && dueState" :color="dueState.color" class="m-0" :data-tone="dueState.tone" data-testid="assignment-due-chip">
                            {{ dueState.text }}
                        </a-tag>
                    </dd>
                </template>
                <template v-if="assignment.decided_by?.name">
                    <dt class="text-ink-muted">{{ t('text.agentic.sla.decided_by') }}</dt>
                    <dd class="m-0 min-w-0 break-words text-ink" data-testid="assignment-decided-by">{{ assignment.decided_by.name }}</dd>
                </template>
                <template v-if="assignment.decided_at">
                    <dt class="text-ink-muted">{{ t('text.agentic.sla.response_time') }}</dt>
                    <dd class="m-0 flex min-w-0 flex-wrap items-center gap-1.5 text-ink" data-testid="assignment-response">
                        <span>{{ formatDuration(assignment.response_seconds, t) }}</span>
                        <span class="text-xs text-ink-muted">({{ formatApprovalDate(assignment.decided_at) }})</span>
                    </dd>
                </template>
                <template v-if="approval.status !== 'pending' && dueState">
                    <dt class="text-ink-muted">{{ t('text.agentic.sla.result') }}</dt>
                    <dd class="m-0 text-ink">
                        <a-tag :color="dueState.color" class="m-0" :data-tone="dueState.tone" data-testid="assignment-sla-result">{{ dueState.text }}</a-tag>
                    </dd>
                </template>
            </dl>
            <p v-if="assignment.fallback" class="m-0 break-words text-xs text-ink-muted" data-testid="assignment-fallback">
                {{ t('text.agentic.sla.fallback_note') }}
            </p>
        </section>

        <!-- How it is approved, who decided, and the automatic review: one compact block -->
        <section v-if="showWorkflowNode" class="min-w-0 space-y-2 rounded-xl border border-border bg-surface p-4" data-testid="approval-workflow-node">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <!-- An AI proposal has no approval mode of its own: a person always decides it -->
                <template v-if="!isProposal">
                    <span class="text-ink-muted">{{ t('text.agentic.workflow_node.mode_label') }}:</span>
                    <a-tag class="m-0" data-testid="workflow-node-mode">{{ modeLabel }}</a-tag>
                </template>
                <template v-if="workflowNode.decided_by">
                    <span class="text-ink-muted">{{ t('text.agentic.workflow_node.decided_by_label') }}:</span>
                    <a-tag :color="workflowNode.decided_by === 'human' ? 'default' : 'purple'" class="m-0" data-testid="workflow-node-decided-by">
                        {{ decidedByLabel }}
                    </a-tag>
                </template>
                <span v-if="workflowNode.expires_at && approval.status === 'pending'" class="text-xs text-ink-faint">
                    {{ t('text.agentic.workflow_node.expires_at', { time: formatApprovalDate(workflowNode.expires_at) }) }}
                </span>
                <span v-if="workflowNode.undone_at" class="text-xs text-ink-faint">
                    {{ t('text.agentic.workflow_node.undone', { time: formatApprovalDate(workflowNode.undone_at) }) }}
                </span>
            </div>

            <!-- JEV verdict -->
            <div v-if="workflowNode.jev" class="min-w-0 border-t border-border pt-2 text-sm" data-testid="workflow-node-jev">
                <div class="font-medium text-ink">
                    {{ t('text.agentic.workflow_node.jev_title') }}:
                    {{ t(`text.agentic.workflow_node.jev_verdict_${['pass', 'fail'].includes(workflowNode.jev.verdict) ? workflowNode.jev.verdict : 'unsure'}`) }}
                    <span v-if="workflowNode.jev.confidence !== null && workflowNode.jev.confidence !== undefined" class="font-normal text-ink-muted">
                        · {{ t('text.agentic.workflow_node.jev_confidence', { value: Math.round(workflowNode.jev.confidence * 100) }) }}
                    </span>
                </div>
                <ul v-if="workflowNode.jev.reasons?.length" class="mb-0 mt-1 list-disc space-y-0.5 pl-5 text-xs leading-5 text-ink-muted">
                    <li v-for="(reason, index) in workflowNode.jev.reasons" :key="index" class="break-words">{{ reason }}</li>
                </ul>
                <div v-if="workflowNode.handed_to === 'human'" class="mt-1 text-xs text-ink-faint">
                    {{ t('text.agentic.workflow_node.jev_handed_to_human') }}
                </div>
            </div>
        </section>

        <!-- Question (ask_and_wait) -->
        <section v-if="isQuestion" class="min-w-0 space-y-3 rounded-xl border border-border bg-surface p-4" data-testid="workflow-node-question">
            <h3 class="m-0 text-sm font-semibold text-ink">{{ t('text.agentic.workflow_node.question_title') }}</h3>
            <p class="m-0 whitespace-pre-line break-words text-sm leading-6 text-ink">{{ approval.artifact?.payload?.question }}</p>
            <template v-if="canEdit">
                <a-radio-group v-if="questionOptions.length" v-model:value="edits.answer" class="flex flex-col gap-1">
                    <a-radio v-for="option in questionOptions" :key="option" :value="option">{{ option }}</a-radio>
                </a-radio-group>
                <label v-else class="block">
                    <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('text.agentic.workflow_node.answer_label') }}</span>
                    <a-textarea
                        v-model:value="edits.answer"
                        :auto-size="{ minRows: 3, maxRows: 12 }"
                        :placeholder="t('text.agentic.workflow_node.answer_placeholder')"
                    />
                </label>
            </template>
            <p v-else-if="approval.artifact?.payload?.answer" class="m-0 break-words text-sm text-ink-muted">
                {{ t('text.agentic.workflow_node.answer_label') }}: {{ approval.artifact.payload.answer }}
            </p>
        </section>

        <!-- What is proposed, in readable form -->
        <section v-else-if="approval.artifact" class="min-w-0 space-y-3 rounded-xl border border-border bg-surface p-4" data-testid="approval-proposal">
            <h3 class="m-0 border-b border-border pb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('text.agentic.approval.proposal_title') }}</h3>
            <AgenticArtifactDiff :record="approval" :format-json="formatJson" :format-scorecard-value="formatScorecardValue" />
        </section>

        <!-- Editable fields before approving: opened on request, so long text is not shown twice -->
        <section
            v-if="!isQuestion && canEdit && editableFields.length"
            ref="editSection"
            class="min-w-0 scroll-mt-4 space-y-3 rounded-xl border border-border bg-surface p-4"
            data-testid="workflow-node-edit"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="m-0 text-sm font-semibold text-ink">{{ t('text.agentic.workflow_node.edit_title') }}</h3>
                <a-button size="small" data-testid="workflow-node-edit-toggle" @click="editing = !editing">
                    {{ editing ? t('text.agentic.approval.edit_close') : t('text.agentic.approval.edit_open') }}
                </a-button>
            </div>
            <p v-if="!editing" class="m-0 text-xs text-ink-muted">{{ editableFields.map((field) => readableFieldLabel(field)).join(', ') }}</p>
            <template v-else>
                <label v-for="field in editableFields" :key="field" class="block">
                    <span class="mb-1 block text-xs font-medium text-ink-muted">{{ readableFieldLabel(field) }}</span>
                    <a-textarea v-model:value="edits[field]" :auto-size="{ minRows: 1, maxRows: 24 }" />
                </label>
            </template>
        </section>

        <!-- Changes the reviewer made -->
        <section v-if="editHistory.length" class="min-w-0 rounded-xl border border-border bg-surface p-4 text-sm" data-testid="workflow-node-edits">
            <h3 class="m-0 mb-2 text-sm font-semibold text-ink">{{ t('text.agentic.workflow_node.edits_title') }}</h3>
            <div v-for="item in editHistory" :key="item.field" class="break-words text-ink-muted">
                <span class="font-medium text-ink">{{ readableFieldLabel(item.field) }}:</span>
                <s>{{ item.before ?? '—' }}</s> → {{ item.after ?? '—' }}
            </div>
        </section>

        <!-- Rounds of this proposal: what was asked for each time, oldest first -->
        <section v-if="showHistory" class="min-w-0 space-y-3 rounded-xl border border-border bg-surface p-4" data-testid="revision-history">
            <h3 class="m-0 text-sm font-semibold text-ink">{{ t('text.agentic.rewrite.history_title') }}</h3>
            <ol class="m-0 list-none space-y-3 p-0">
                <li
                    v-for="entry in revisionHistory"
                    :key="entry.approval_id"
                    class="min-w-0 space-y-1 border-0 border-l-2 border-solid pl-3 text-sm"
                    :class="isCurrentRound(entry) ? 'border-border-strong' : 'border-border'"
                    data-testid="revision-round"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium text-ink">{{ t('text.agentic.rewrite.round_n', { round: entry.round }) }}</span>
                        <a-tag :color="statusTagColor(entry.status)" class="m-0">{{ statusText(entry.status, hasLaterRound(entry)) }}</a-tag>
                        <span v-if="isCurrentRound(entry)" class="text-xs text-ink-faint">{{ t('text.agentic.rewrite.current_round') }}</span>
                        <router-link v-else :to="roundRoute(entry.approval_id)" class="text-xs" data-testid="revision-round-link">
                            {{ t('text.agentic.rewrite.open_round') }}
                        </router-link>
                    </div>
                    <template v-if="entry.feedback">
                        <p class="m-0 whitespace-pre-line break-words text-ink">
                            <span class="text-ink-muted">{{ t('text.agentic.rewrite.history_feedback') }}:</span> {{ entry.feedback }}
                        </p>
                        <p v-if="entry.added_info" class="m-0 whitespace-pre-line break-words text-ink">
                            <span class="text-ink-muted">{{ t('text.agentic.rewrite.history_added_info') }}:</span> {{ entry.added_info }}
                        </p>
                        <p v-for="option in chosenOptions(entry)" :key="option.key" class="m-0 break-words text-ink">
                            <span class="text-ink-muted">{{ option.label }}:</span> {{ option.value }}
                        </p>
                    </template>
                    <p v-if="rejectReasonOf(entry)" class="m-0 break-words text-ink" data-testid="revision-reject-reason">
                        <span class="text-ink-muted">{{ t('text.agentic.reject.reason_history') }}:</span> {{ rejectReasonOf(entry) }}
                    </p>
                    <div v-if="entry.reviewer_name || entry.reviewed_at" class="break-words text-xs text-ink-faint">
                        {{ [entry.reviewer_name, entry.reviewed_at ? formatApprovalDate(entry.reviewed_at) : ''].filter(Boolean).join(' · ') }}
                    </div>
                </li>
            </ol>
        </section>

        <!-- Who the agent acted for and their data scope -->
        <section class="min-w-0 space-y-3" :class="delegatedBy || scopeStart || scopeNow ? 'rounded-xl border border-border bg-surface p-4' : ''" data-testid="approval-acting-for">
            <div :class="delegatedBy ? 'text-sm font-medium text-ink' : 'text-xs text-ink-faint'">
                <template v-if="delegatedBy">{{ t('text.data_scope.acting_for', { name: delegatedBy.name }) }}</template>
                <template v-else>{{ t('text.data_scope.no_delegator') }}</template>
            </div>
            <div v-if="scopeStart || scopeNow" class="grid gap-3 sm:grid-cols-2">
                <div v-if="scopeStart" class="min-w-0">
                    <div class="mb-1 text-xs text-ink-faint">{{ t('text.data_scope.at_start') }}</div>
                    <ScopeSummary :summary="scopeStart" />
                </div>
                <div v-if="scopeNow" class="min-w-0">
                    <div class="mb-1 text-xs text-ink-faint">{{ t('text.data_scope.now') }}</div>
                    <ScopeSummary :summary="scopeNow" :compare-to="scopeStart" />
                </div>
            </div>
            <a-alert v-if="scopeChanged" type="warning" show-icon :message="t('text.data_scope.changed_warning')" />
        </section>

        <!-- Technical details: collapsed by default, for support only -->
        <a-collapse :bordered="false" class="bg-surface" data-testid="approval-technical">
            <a-collapse-panel key="technical" :header="t('text.agentic.approval.technical_title')">
                <dl class="mb-3 grid grid-cols-1 gap-x-4 gap-y-1 text-xs text-ink-muted sm:grid-cols-[auto_1fr]">
                    <dt>{{ t('text.agentic.approval.technical_action') }}</dt>
                    <dd class="m-0 break-all">{{ approval.artifact?.artifact_type || '—' }}</dd>
                    <dt>{{ t('text.agentic.approval.technical_subject') }}</dt>
                    <dd class="m-0 break-all">{{ approval.artifact?.subject_type || '—' }} #{{ approval.artifact?.subject_id ?? '—' }}</dd>
                </dl>
                <div class="max-w-full overflow-x-auto rounded-lg border border-border bg-surface-sunken p-3">
                    <pre class="m-0 text-xs leading-5 text-ink-muted">{{ formatJson(approval.diff_data || approval.artifact?.payload || {}) }}</pre>
                </div>
            </a-collapse-panel>
        </a-collapse>

        <!-- Action bar: stays in view while the proposal scrolls -->
        <div
            v-if="hasActions || approval.reviewer_note || rejectedReasonText"
            class="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-xl border border-border-strong bg-surface p-3 shadow-lg"
            data-testid="approval-actions"
        >
            <!-- Pending: Approve and apply (one click), then the separate steps -->
            <template v-if="approval.status === 'pending' && canApproveAgentic">
                <a-button
                    type="primary"
                    data-testid="approve-apply-button"
                    :loading="approvalActionMode === 'approve_apply'"
                    :disabled="Boolean(approvalActionMode)"
                    @click="showApproveApplyModal = true"
                >
                    {{ isQuestion ? t('text.agentic.workflow_node.send_answer') : t('text.agentic.inbox.approve_apply') }}
                </a-button>
                <a-button
                    v-if="!isQuestion"
                    data-testid="approve-button"
                    :loading="approvalActionMode === 'approve'"
                    @click="showApproveModal = true"
                >
                    {{ t('text.agentic.inbox.approve') }}
                </a-button>
                <!-- Edit the text yourself, then approve -->
                <a-button
                    v-if="!isQuestion && editableFields.length"
                    data-testid="edit-content-button"
                    :disabled="Boolean(approvalActionMode)"
                    @click="openEditing"
                >
                    {{ t('text.agentic.rewrite.edit_content') }}
                </a-button>
                <!-- Send it back to the AI with feedback -->
                <a-button
                    v-if="canRequestRewrite"
                    data-testid="request-rewrite-button"
                    :loading="approvalActionMode === 'request_changes'"
                    :disabled="Boolean(approvalActionMode)"
                    @click="showRewriteModal = true"
                >
                    {{ t('text.agentic.rewrite.request') }}
                </a-button>
                <a-button
                    danger
                    data-testid="reject-button"
                    :loading="approvalActionMode === 'reject'"
                    :disabled="Boolean(approvalActionMode)"
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

            <!-- Applied by the reversible-change rule without a person: it can be undone once -->
            <a-button
                v-if="canUndo"
                data-testid="undo-button"
                :loading="undoing"
                :disabled="undoing"
                @click="doUndo"
            >
                {{ t('text.agentic.workflow_node.undo') }}
            </a-button>

            <!-- Applied badge -->
            <span v-if="approval.apply_outcome === 'applied'" class="text-sm text-success-ink">
                ✓ {{ t('text.agentic.inbox.applied') }}
            </span>

            <!-- The reviewer changed the text: say which button keeps it -->
            <div v-if="canEdit && hasEdits" class="w-full break-words text-xs text-info-ink" data-testid="edits-pending">
                {{ t('text.agentic.rewrite.edits_pending') }}
            </div>
            <!-- No rewrite left: say so instead of silently dropping the button -->
            <div v-if="roundsUsedUp" class="w-full break-words text-xs text-ink-muted" data-testid="rewrite-rounds-used">
                {{ t('text.agentic.rewrite.rounds_used', { max: maxRounds }) }}
            </div>

            <!-- Why it was rejected, in the words the reviewer picked -->
            <div v-if="rejectedReasonText" class="w-full break-words text-xs text-ink-muted" data-testid="reject-reason-shown">
                {{ t('text.agentic.reject.reason_history') }}: {{ rejectedReasonText }}
            </div>

            <!-- Reviewer note -->
            <div v-if="approval.reviewer_note" class="w-full break-words text-xs text-ink-muted">
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
            data-testid="reject-modal"
            @ok="doReject"
        >
            <!-- The step declared reasons: one must be picked, the note becomes optional -->
            <form v-if="rejectReasons.length" class="space-y-4" data-testid="reject-form" @submit.prevent="doReject">
                <fieldset class="m-0 min-w-0 border-0 p-0">
                    <legend class="mb-1 block text-sm font-medium text-ink">
                        {{ t('text.agentic.reject.reason_label') }} <span class="text-danger-ink" aria-hidden="true">*</span>
                    </legend>
                    <a-radio-group
                        v-model:value="rejectReasonKey"
                        class="flex flex-col gap-2"
                        :disabled="approvalActionMode === 'reject'"
                        data-testid="reject-reasons"
                    >
                        <a-radio v-for="reason in rejectReasons" :key="reason.key" :value="reason.key" class="whitespace-normal break-words">
                            {{ reason.label }}
                        </a-radio>
                    </a-radio-group>
                    <span v-if="rejectReasonError" class="mt-1 block text-xs text-danger-ink" role="alert" data-testid="reject-reason-error">
                        {{ t('text.agentic.reject.reason_required') }}
                    </span>
                </fieldset>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-ink">{{ t('text.agentic.reject.note_label') }}</span>
                    <a-textarea
                        v-model:value="rejectNote"
                        :placeholder="t('text.agentic.reject.note_placeholder')"
                        :disabled="approvalActionMode === 'reject'"
                        :auto-size="{ minRows: 2, maxRows: 8 }"
                        data-testid="reject-note"
                    />
                </label>
            </form>
            <template v-else>
                <a-textarea
                    v-model:value="rejectNote"
                    :placeholder="t('text.agentic.inbox.reject_reason_placeholder')"
                    :rows="3"
                    data-testid="reject-note"
                />
                <p class="mt-2 text-xs text-ink-faint">
                    {{ t('text.agentic.inbox.reject_tip') }}
                </p>
            </template>
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

        <!-- Approve and apply modal -->
        <a-modal
            v-model:open="showApproveApplyModal"
            :title="t('text.agentic.inbox.approve_apply_title')"
            :ok-text="t('text.agentic.inbox.approve_apply')"
            :cancel-text="t('text.agentic.inbox.cancel')"
            :confirm-loading="approvalActionMode === 'approve_apply'"
            :mask-closable="approvalActionMode !== 'approve_apply'"
            :closable="approvalActionMode !== 'approve_apply'"
            @ok="doApproveApply"
        >
            <p class="mb-3 text-sm text-ink-muted">
                {{ t('text.agentic.inbox.approve_apply_confirm') }}
            </p>
            <a-textarea
                v-model:value="reviewerNote"
                :placeholder="t('text.agentic.inbox.note_placeholder')"
                :rows="3"
            />
        </a-modal>

        <!-- Ask the AI to rewrite -->
        <a-modal
            v-model:open="showRewriteModal"
            :title="t('text.agentic.rewrite.title')"
            :ok-text="t('text.agentic.rewrite.submit')"
            :cancel-text="t('text.agentic.inbox.cancel')"
            :confirm-loading="approvalActionMode === 'request_changes'"
            :mask-closable="approvalActionMode !== 'request_changes'"
            :closable="approvalActionMode !== 'request_changes'"
            data-testid="rewrite-modal"
            @ok="doRequestChanges"
        >
            <form class="space-y-4" data-testid="rewrite-form" @submit.prevent="doRequestChanges">
                <p class="m-0 text-sm text-ink-muted">{{ t('text.agentic.rewrite.intro') }}</p>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-ink">
                        {{ t('text.agentic.rewrite.feedback_label') }} <span class="text-danger-ink" aria-hidden="true">*</span>
                    </span>
                    <a-textarea
                        v-model:value="rewrite.feedback"
                        :auto-size="{ minRows: 3, maxRows: 10 }"
                        :maxlength="5000"
                        :placeholder="t('text.agentic.rewrite.feedback_placeholder')"
                        :disabled="approvalActionMode === 'request_changes'"
                        :status="rewriteError ? 'error' : undefined"
                        data-testid="rewrite-feedback"
                    />
                    <span v-if="rewriteError" class="mt-1 block text-xs text-danger-ink" role="alert" data-testid="rewrite-feedback-error">
                        {{ t('text.agentic.rewrite.feedback_required') }}
                    </span>
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-ink">{{ t('text.agentic.rewrite.added_info_label') }}</span>
                    <a-textarea
                        v-model:value="rewrite.addedInfo"
                        :auto-size="{ minRows: 2, maxRows: 10 }"
                        :maxlength="10000"
                        :placeholder="t('text.agentic.rewrite.added_info_placeholder')"
                        :disabled="approvalActionMode === 'request_changes'"
                        data-testid="rewrite-added-info"
                    />
                    <span class="mt-1 block text-xs text-ink-faint">{{ t('text.agentic.rewrite.added_info_hint') }}</span>
                </label>
                <!-- One choice per option the step declared; leaving it empty lets the AI decide -->
                <div v-for="option in revisionOptions" :key="option.key" class="min-w-0" data-testid="rewrite-option">
                    <span class="mb-1 block text-sm font-medium text-ink">{{ optionLabel(option) }}</span>
                    <!-- Which AI model writes the next draft: the first entry keeps the one in use -->
                    <a-select
                        v-if="option.type === 'model'"
                        :value="rewrite.options[option.key] ?? ''"
                        :options="[{ value: '', label: t('text.agentic.rewrite.model_keep') }, ...option.choices.map((choice) => ({ value: choice.value, label: choiceLabel(option, choice) }))]"
                        :aria-label="optionLabel(option)"
                        :disabled="approvalActionMode === 'request_changes'"
                        class="w-full"
                        data-testid="rewrite-model"
                        @update:value="(value) => (rewrite.options[option.key] = value || '')"
                    />
                    <a-select
                        v-else
                        v-model:value="rewrite.options[option.key]"
                        :options="option.choices.map((choice) => ({ value: choice.value, label: choiceLabel(option, choice) }))"
                        :placeholder="t('text.agentic.rewrite.option_any')"
                        :aria-label="optionLabel(option)"
                        :disabled="approvalActionMode === 'request_changes'"
                        allow-clear
                        class="w-full"
                    />
                </div>
                <p v-if="maxRounds" class="m-0 text-xs text-ink-faint" data-testid="rewrite-round">{{ roundText }}</p>
            </form>
        </a-modal>
    </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticMeta } from './composables/useAgenticMeta'
import { useAgenticMutations } from './composables/useAgenticMutations'
import { useAgenticApproval } from '@/hooks'
import AgenticArtifactDiff from './components/AgenticArtifactDiff.vue'
import { assigneeNames, buildApprovalSentence, dueChip, formatApprovalDate, formatDuration } from './approval-sentence'
import { approvalSubjectName, fieldLabel as translatedFieldLabel } from './readable-diff'
import ScopeSummary from '@/components/data-scope/scope-summary.vue'

const props = defineProps({
    approvalId: { type: [Number, String], default: null },
})

defineOptions({ name: 'AgenticApprovalDetail' })

const { t, te } = useI18n()
const { canApproveAgentic } = useAgenticPermissions()
const { artifactTypeLabel, moduleLabel, sl } = useAgenticMeta()
const { handleApprove, handleReject, handleApply, handleApproveApply, handleRequestChanges, approvalActionMode } = useAgenticMutations()

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
    changes_requested: 'purple',
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
const { data: approvalQuery, isLoading, refetch: refetchApproval } = useAgenticApproval(resolvedId, { refetchOnWindowFocus: false })
const approval = computed(() => approvalQuery.value || null)

// Assignment and target time; absent on approvals opened before the feature (the box is then left out).
const assignment = computed(() => approval.value?.assignment || null)
const assignedNames = computed(() => assigneeNames(approval.value))
const dueState = computed(() => dueChip(approval.value, t))

const sentence = computed(() => (approval.value ? buildApprovalSentence(approval.value, t, { artifactTypeLabel }) : ''))
const createdAtText = computed(() => formatApprovalDate(approval.value?.created_at))
// The workflow step's own title says what is asked; without one the sentence built from the change does.
const heading = computed(() => {
    // A question is shown in full in its own block; the heading only says that someone is being asked.
    if (approval.value?.diff_data?.workflow_node?.kind === 'question') return t('text.workflow.notifications.question_asked')
    const title = approval.value?.diff_data?.workflow_node?.title
    return typeof title === 'string' && title.trim() !== '' ? title.trim() : sentence.value
})
// Left out when the heading already names the record.
const subjectName = computed(() => {
    const name = approvalSubjectName(approval.value)
    return name && !heading.value.includes(name) ? name : ''
})
const moduleName = computed(() => (approval.value?.run?.module_scope ? moduleLabel(approval.value.run.module_scope) : ''))

const delegatedBy = computed(() => approval.value?.run?.delegated_by || null)
const scopeStart = computed(() => approval.value?.scope?.at_start || null)
const scopeNow = computed(() => approval.value?.scope?.now || null)
// Warn only when both summaries exist and differ.
const scopeChanged = computed(() =>
    Boolean(scopeStart.value && scopeNow.value) && JSON.stringify(scopeStart.value) !== JSON.stringify(scopeNow.value)
)

// ── Workflow node items (approval / ask_and_wait nodes) ──────────────────────────────
const workflowNode = computed(() => approval.value?.diff_data?.workflow_node || null)
const isQuestion = computed(() => workflowNode.value?.kind === 'question')
// An AI tool proposal (`kind: proposal`) carries the review options but no approval mode.
const isProposal = computed(() => workflowNode.value?.kind === 'proposal')
const showWorkflowNode = computed(() =>
    Boolean(workflowNode.value)
    && (!isProposal.value || Boolean(workflowNode.value.decided_by || workflowNode.value.jev || workflowNode.value.undone_at
        || (workflowNode.value.expires_at && approval.value?.status === 'pending')))
)
const canEdit = computed(() => approval.value?.status === 'pending' && canApproveAgentic.value)
const editableFields = computed(() => (Array.isArray(workflowNode.value?.editable_fields) ? workflowNode.value.editable_fields : []))
const questionOptions = computed(() => (Array.isArray(approval.value?.artifact?.payload?.options) ? approval.value.artifact.payload.options : []))
const modeLabel = computed(() => {
    if (isQuestion.value) return t('text.agentic.workflow_node.kind_question')
    const mode = ['auto', 'jev'].includes(workflowNode.value?.mode) ? workflowNode.value.mode : 'human'
    return t(`text.agentic.workflow_node.mode_${mode}`)
})
const decidedByLabel = computed(() => {
    const known = ['human', 'auto', 'jev', 'reversible_fields', 'expiry']
    const by = known.includes(workflowNode.value?.decided_by) ? workflowNode.value.decided_by : 'human'
    return t(`text.agentic.workflow_node.decided_by_${by}`)
})
const editHistory = computed(() =>
    Object.entries(workflowNode.value?.edits || {}).map(([field, change]) => ({ field, before: change?.before, after: change?.after })),
)

/** Reviewer edits, keyed by payload path; starts from the proposed values. */
const edits = reactive({})
const payloadValue = (path) => path.split('.').reduce((value, key) => (value && typeof value === 'object' ? value[key] : undefined), approval.value?.artifact?.payload)
watch(
    () => [approval.value?.id, editableFields.value.join('|')],
    () => {
        Object.keys(edits).forEach((key) => delete edits[key])
        editableFields.value.forEach((field) => {
            const value = payloadValue(field)
            edits[field] = value === null || value === undefined ? '' : String(value)
        })
    },
    { immediate: true },
)

/** Readable label of a payload path: the authored wording of its last segment, else the segment in plain words. */
const readableFieldLabel = (path) => translatedFieldLabel(path, t, te)
/** The edit boxes open on request. */
const editing = ref(false)
const editSection = ref(null)

/** "Edit the content" in the action bar: open the boxes and bring them into view. */
async function openEditing() {
    editing.value = true
    await nextTick()
    const element = editSection.value?.$el || editSection.value
    if (typeof element?.scrollIntoView === 'function') element.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// ── Rewrite on request (rounds, options, history) ────────────────────────────────────
const has = (key) => typeof te === 'function' && Boolean(key) && te(key)
/**
 * Wording of an option or a choice: the authored row of its label key; a label typed in the workflow builder as it
 * is; else the code in plain words (never a raw translation key).
 */
const labelText = (labelKey, fallback) => {
    if (has(labelKey)) return t(labelKey)
    if (typeof labelKey === 'string' && labelKey.trim() !== '' && !/^[a-z]+(\.[A-Za-z0-9_-]+)+$/.test(labelKey.trim())) return labelKey.trim()
    const words = String(fallback ?? '').replace(/[_-]+/g, ' ').trim()
    return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/** A plain `label` (model names, labels typed in the builder) wins over a label key. */
const plainLabel = (item, fallback) =>
    typeof item?.label === 'string' && item.label.trim() !== '' ? item.label.trim() : labelText(item?.label_key, fallback)
/** "openai:gpt-4o" in readable form: "Openai · gpt-4o". The stored value itself is never shown. */
const modelText = (value) => {
    const [provider, ...rest] = String(value ?? '').split(':')
    const model = rest.join(':')
    if (!provider || !model) return String(value ?? '')
    return `${provider.charAt(0).toUpperCase()}${provider.slice(1)} · ${model}`
}
const optionLabel = (option) => (option?.type === 'model' && !has(option?.label_key) && !option?.label ? t('text.agentic.rewrite.model_option') : plainLabel(option, option?.key))
const choiceLabel = (option, choice) =>
    option?.type === 'model' && !choice?.label && !has(choice?.label_key) ? modelText(choice?.value) : plainLabel(choice, choice?.value)

const round = computed(() => Math.max(1, Number(workflowNode.value?.round) || 1))
const maxRounds = computed(() => Math.max(0, Number(workflowNode.value?.max_rounds) || 0))
const roundText = computed(() =>
    maxRounds.value ? t('text.agentic.rewrite.round', { round: round.value, max: maxRounds.value }) : t('text.agentic.rewrite.round_n', { round: round.value })
)
const nextApprovalId = computed(() => workflowNode.value?.next_approval_id || null)
const roundRoute = (id) => ({ name: 'approval-detail', params: { id: String(id) } })

/** The reviewer may send the draft back: the step allows it and a round is left. */
const canRequestRewrite = computed(() =>
    canEdit.value && !isQuestion.value && workflowNode.value?.revisable === true && (!maxRounds.value || round.value < maxRounds.value)
)
/** Every allowed rewrite was used: the reviewer edits, approves or rejects. */
const roundsUsedUp = computed(() => canEdit.value && !isQuestion.value && round.value > 1 && maxRounds.value > 0 && round.value >= maxRounds.value)

const revisionOptions = computed(() =>
    (Array.isArray(workflowNode.value?.revision_options) ? workflowNode.value.revision_options : [])
        .filter((option) => option && typeof option.key === 'string' && option.key !== '' && Array.isArray(option.choices) && option.choices.length)
)

const revisionHistory = computed(() => (Array.isArray(approval.value?.revision_history) ? approval.value.revision_history : []))
const showHistory = computed(() => revisionHistory.value.length > 1 || revisionHistory.value.some((entry) => entry?.feedback))
const hasLaterRound = (entry) => revisionHistory.value.some((other) => Number(other?.round) > Number(entry?.round))
/** A draft sent back reads "being rewritten" until its rewrite exists, then "rewritten". */
const statusText = (status, rewritten) => (status === 'changes_requested' && rewritten ? t('text.agentic.rewrite.rewritten') : sl(status))
const isCurrentRound = (entry) => String(entry?.approval_id) === String(approval.value?.id)
/** Options chosen in a round, in readable words. */
const chosenOptions = (entry) =>
    Object.entries(entry?.options && typeof entry.options === 'object' ? entry.options : {}).map(([key, value]) => {
        // A model chosen in an earlier round may no longer be offered: it still reads as "Provider · model".
        const option = revisionOptions.value.find((item) => item.key === key) || (key === 'model' ? { key, type: 'model' } : undefined)
        const choice = option?.choices?.find((item) => String(item.value) === String(value))
        return { key, label: optionLabel(option || { key }), value: choiceLabel(option, choice || { value }) }
    })

// ── Reject with a declared reason ────────────────────────────────────────────────────
/** Reasons the step declared, in plain words; none on approvals created before reasons existed. */
const rejectReasons = computed(() =>
    (Array.isArray(workflowNode.value?.reject_reasons) ? workflowNode.value.reject_reasons : [])
        .filter((reason) => reason && typeof reason.key === 'string' && reason.key !== '')
        .map((reason) => ({ key: reason.key, label: plainLabel(reason, reason.key) }))
)
const reasonText = (key) => {
    if (typeof key !== 'string' || key === '') return ''
    return rejectReasons.value.find((reason) => reason.key === key)?.label || labelText('', key)
}
/** The reason of a rejected round; the round on screen also reads it from its own review data. */
const rejectReasonOf = (entry) => {
    if (entry?.status !== 'rejected') return ''
    return reasonText(entry.reject_reason || entry.reason || (isCurrentRound(entry) ? workflowNode.value?.reject_reason : ''))
}
const rejectedReasonText = computed(() => (approval.value?.status === 'rejected' ? reasonText(workflowNode.value?.reject_reason) : ''))

const showRewriteModal = ref(false)
const rewrite = reactive({ feedback: '', addedInfo: '', options: {} })
const rewriteError = ref(false)
watch(() => rewrite.feedback, () => { rewriteError.value = false })

async function doRequestChanges() {
    if (approvalActionMode.value) return
    const feedback = rewrite.feedback.trim()
    if (feedback.length < 3) {
        rewriteError.value = true
        return
    }
    const options = Object.fromEntries(Object.entries(rewrite.options).filter(([, value]) => value !== undefined && value !== null && value !== ''))
    const result = await handleRequestChanges(approval.value.id, { feedback, addedInfo: rewrite.addedInfo.trim() || null, options })
    if (result) {
        showRewriteModal.value = false
        rewrite.feedback = ''
        rewrite.addedInfo = ''
        rewrite.options = {}
        if (typeof refetchApproval === 'function') await refetchApproval()
    }
}

/** Only the fields the reviewer actually changed are sent. */
const changedEdits = computed(() => {
    const changed = {}
    editableFields.value.forEach((field) => {
        const before = payloadValue(field)
        const now = edits[field]
        if (now !== undefined && String(now) !== (before === null || before === undefined ? '' : String(before))) changed[field] = now
    })
    return changed
})

const hasEdits = computed(() => Object.keys(changedEdits.value).length > 0)

/**
 * Approve (`apply` false) or approve and apply with the reviewer's edits (the shared mutations send no edits).
 * Resolves with the outcome (`approved` when nothing is applied yet), or null when the request failed.
 */
async function decideWithEdits(apply) {
    approvalActionMode.value = apply ? 'approve_apply' : 'approve'
    try {
        const response = await ajax.post(`agentic/approvals/${approval.value.id}/${apply ? 'approve-apply' : 'approve'}`, {
            reviewer_note: reviewerNote.value || null,
            edited_fields: changedEdits.value,
        })
        const outcome = apply ? response?.data?.data?.outcome || response?.data?.outcome || 'applied' : 'approved'
        if (outcome === 'approved') message.success(t('text.agentic.msg.proposal_approved'))
        else if (outcome === 'applied') message.success(t('text.agentic.msg.approve_apply_done'))
        else message.warning(t('text.agentic.inbox.apply_failed_warning'), 8)
        if (typeof refetchApproval === 'function') await refetchApproval()
        return outcome
    } catch (error) {
        const fallback = apply ? t('text.agentic.msg.approve_apply_failed') : t('text.agentic.msg.proposal_approve_failed')
        if (!error?.reported) message.error(error?.response?.data?.message || fallback)
        return null
    } finally {
        approvalActionMode.value = null
    }
}

const shouldShowApply = computed(() =>
    approval.value?.status === 'approved'
    && [null, 'awaiting_apply', 'apply_failed', 'approval_stale'].includes(approval.value?.apply_outcome ?? null)
)

/** Whether the action bar has anything to offer. */
const hasActions = computed(() =>
    (approval.value?.status === 'pending' && canApproveAgentic.value)
    || shouldShowApply.value
    || canUndo.value
    || approval.value?.apply_outcome === 'applied'
)

// Modal state
const showApproveModal = ref(false)
const showApproveApplyModal = ref(false)
const showRejectModal = ref(false)
const showApplyModal = ref(false)
const reviewerNote = ref('')
const rejectNote = ref('')
const rejectReasonKey = ref(null)
const rejectReasonError = ref(false)
watch(rejectReasonKey, () => { rejectReasonError.value = false })

async function doApprove() {
    if (approvalActionMode.value) return
    // Edits made in the boxes are kept on a plain approve too, not only on approve and apply.
    const result = hasEdits.value ? await decideWithEdits(false) : await handleApprove(approval.value.id, reviewerNote.value || null)
    if (result) {
        showApproveModal.value = false
        reviewerNote.value = ''
    }
}

async function doApproveApply() {
    if (approvalActionMode.value) return
    // Any outcome closes the dialog: the page then shows the new state (applied, stale or failed).
    const outcome = hasEdits.value ? await decideWithEdits(true) : await handleApproveApply(approval.value.id, reviewerNote.value || null)
    if (outcome) {
        showApproveApplyModal.value = false
        reviewerNote.value = ''
    }
}

async function doReject() {
    if (approvalActionMode.value) return
    const note = rejectNote.value.trim()
    let result
    if (rejectReasons.value.length) {
        if (!rejectReasonKey.value) {
            rejectReasonError.value = true
            return
        }
        result = await handleReject(approval.value.id, note || null, rejectReasonKey.value)
    } else {
        // No declared reasons: the note is the reason, as before.
        if (!note) return
        result = await handleReject(approval.value.id, rejectNote.value)
    }
    if (result) {
        showRejectModal.value = false
        rejectNote.value = ''
        rejectReasonKey.value = null
    }
}

/** A reversible change the workflow applied by itself, not yet undone. */
const canUndo = computed(() =>
    canApproveAgentic.value
    && workflowNode.value?.decided_by === 'reversible_fields'
    && approval.value?.apply_outcome === 'applied'
    && !workflowNode.value?.undone_at
)
const undoing = ref(false)

async function doUndo() {
    if (undoing.value) return
    undoing.value = true
    try {
        await ajax.post(`agentic/approvals/${approval.value.id}/undo`)
        message.success(t('text.agentic.workflow_node.undo_done'))
        if (typeof refetchApproval === 'function') await refetchApproval()
    } catch (error) {
        if (!error?.reported) message.error(error?.response?.data?.message || t('text.agentic.workflow_node.undo_failed'))
    } finally {
        undoing.value = false
    }
}

async function doApply() {
    const result = await handleApply(approval.value.id)
    if (result) {
        showApplyModal.value = false
    }
}
</script>
