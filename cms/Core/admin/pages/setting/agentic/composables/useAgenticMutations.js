import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import {
    useAgenticSyncTools,
    useAgenticSeedProfileTemplates,
    useAgenticCreateProfile,
    useAgenticUpdateProfile,
    useAgenticGrantProfileTools,
    useAgenticRevokeProfileToolGrant,
    useAgenticUpdateProfileToolGrant,
    useAgenticUpdateToolBinding,
    useApproveAgenticApproval,
    useRejectAgenticApproval,
    useApplyAgenticApproval,
    useApproveApplyAgenticApproval,
    useRequestChangesAgenticApproval,
    useCancelAgenticRun,
    useStopAgenticRun,
    useRerunAgenticRun,
    useCreateAgenticSchedule,
    useDispatchAgenticSchedule,
    useRunOnceAgenticSchedule,
    useResumeDispatchAgenticSchedule,
    useUpdateAgenticSchedule,
    useLaunchAgenticTask,
} from '@/hooks'

/**
 * All mutation handlers for the Agentic CMS UI.
 * Shared between Dashboard, Inbox, AdminConfig, InlineAssist.
 *
 * Each handler: guard → validate → mutate → message.success/error
 * Per-item loading state tracked via activeId + actionMode refs.
 */
export function useAgenticMutations() {
    const { t } = useI18n()

    // ── Per-item loading state ─────────────────────────────────────
    const activeApprovalActionId = ref(null)
    const approvalActionMode = ref(null)
    const activeGrantToolId = ref(null)
    const grantActionMode = ref(null)
    const activeBindingId = ref(null)
    const bindingActionMode = ref(null)
    const activeRunId = ref(null)
    const runActionMode = ref(null)
    const activeScheduleId = ref(null)
    const scheduleActionMode = ref(null)

    // ── TanStack mutations ─────────────────────────────────────────
    const syncToolsMutation = useAgenticSyncTools()
    const seedTemplatesMutation = useAgenticSeedProfileTemplates()
    const createProfileMutation = useAgenticCreateProfile()
    const updateProfileMutation = useAgenticUpdateProfile()
    const grantProfileToolsMutation = useAgenticGrantProfileTools()
    const revokeGrantMutation = useAgenticRevokeProfileToolGrant()
    const updateGrantMutation = useAgenticUpdateProfileToolGrant()
    const updateBindingMutation = useAgenticUpdateToolBinding()
    const approveMutation = useApproveAgenticApproval()
    const rejectMutation = useRejectAgenticApproval()
    const applyMutation = useApplyAgenticApproval()
    const approveApplyMutation = useApproveApplyAgenticApproval()
    const requestChangesMutation = useRequestChangesAgenticApproval()
    const cancelRunMutation = useCancelAgenticRun()
    const stopRunMutation = useStopAgenticRun()
    const rerunRunMutation = useRerunAgenticRun()
    const createScheduleMutation = useCreateAgenticSchedule()
    const dispatchScheduleMutation = useDispatchAgenticSchedule()
    const runOnceScheduleMutation = useRunOnceAgenticSchedule()
    const resumeDispatchMutation = useResumeDispatchAgenticSchedule()
    const updateScheduleMutation = useUpdateAgenticSchedule()
    const launchTaskMutation = useLaunchAgenticTask()

    // ── Pending state shortcuts ────────────────────────────────────
    const isSyncPending = syncToolsMutation.isPending
    const isSeedTemplatesPending = seedTemplatesMutation.isPending
    const isSaveProfilePending = createProfileMutation.isPending
    const isCreateSchedulePending = createScheduleMutation.isPending
    const isLaunchTaskPending = launchTaskMutation.isPending

    // ── Helpers ────────────────────────────────────────────────────
    function resolveErrorMessage(error, fallback) {
        return error?.response?.data?.message || error?.message || fallback
    }

    async function withAction(activeIdRef, actionModeRef, mode, id, callback) {
        activeIdRef.value = id
        actionModeRef.value = mode
        try {
            await callback()
        } finally {
            activeIdRef.value = null
            actionModeRef.value = null
        }
    }

    // ── Provisioning handlers ──────────────────────────────────────
    async function handleSyncTools() {
        try {
            const response = await syncToolsMutation.mutateAsync({})
            message.success(response?.message || t('text.agentic.msg.sync_completed'))
            return response
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.sync_failed')))
        }
    }

    async function handleSeedTemplates() {
        if (isSeedTemplatesPending.value) return
        try {
            const response = await seedTemplatesMutation.mutateAsync()
            message.success(response?.message || t('text.agentic.msg.seed_completed'))
            return response
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.seed_failed')))
        }
    }

    // ── Profile handlers ───────────────────────────────────────────
    async function handleCreateProfile(payload) {
        try {
            const response = await createProfileMutation.mutateAsync(payload)
            message.success(response?.message || t('text.agentic.msg.profile_created'))
            return response
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.profile_create_failed')))
        }
    }

    async function handleUpdateProfile(profileKey, payload) {
        try {
            const response = await updateProfileMutation.mutateAsync({ profileKey, payload })
            message.success(response?.message || t('text.agentic.msg.profile_updated'))
            return response
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.profile_update_failed')))
        }
    }

    // ── Grant handlers ─────────────────────────────────────────────
    async function handleGrantTool(profileKey, toolNames) {
        return withAction(activeGrantToolId, grantActionMode, 'grant', toolNames[0], async () => {
            try {
                const response = await grantProfileToolsMutation.mutateAsync({ profileKey, toolNames })
                message.success(response?.message || t('text.agentic.msg.grant_completed'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.grant_failed')))
            }
        })
    }

    async function handleRevokeTool(profileKey, grantId) {
        return withAction(activeGrantToolId, grantActionMode, 'revoke', grantId, async () => {
            try {
                const response = await revokeGrantMutation.mutateAsync({ profileKey, grantId })
                message.success(response?.message || t('text.agentic.msg.revoke_completed'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.revoke_failed')))
            }
        })
    }

    async function handleUpdateGrant(profileKey, toolDefinitionKey, payload) {
        return withAction(activeGrantToolId, grantActionMode, 'update', toolDefinitionKey, async () => {
            try {
                const response = await updateGrantMutation.mutateAsync({ profileKey, toolDefinitionKey, payload })
                message.success(response?.message || t('text.agentic.msg.grant_updated'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.grant_update_failed')))
            }
        })
    }

    // ── Binding handlers ───────────────────────────────────────────
    async function handleToggleBinding(bindingId, enabled) {
        return withAction(activeBindingId, bindingActionMode, 'toggle', bindingId, async () => {
            try {
                const response = await updateBindingMutation.mutateAsync({ bindingId, payload: { enabled } })
                message.success(response?.message || t('text.agentic.msg.binding_updated'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.binding_update_failed')))
            }
        })
    }

    async function handleUpdateBindingPriority(bindingId, priority) {
        return withAction(activeBindingId, bindingActionMode, 'priority', bindingId, async () => {
            try {
                const response = await updateBindingMutation.mutateAsync({ bindingId, payload: { priority } })
                message.success(response?.message || t('text.agentic.msg.binding_priority_updated'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.binding_priority_failed')))
            }
        })
    }

    // ── Approval handlers ──────────────────────────────────────────
    async function handleApprove(approvalId, reviewerNote) {
        return withAction(activeApprovalActionId, approvalActionMode, 'approve', approvalId, async () => {
            try {
                const response = await approveMutation.mutateAsync({ approvalId, reviewerNote })
                message.success(response?.message || t('text.agentic.msg.proposal_approved'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.proposal_approve_failed')))
            }
        })
    }

    /** `reason` is the declared reject reason the reviewer picked; left out when the step declares none. */
    async function handleReject(approvalId, reviewerNote, reason = null) {
        return withAction(activeApprovalActionId, approvalActionMode, 'reject', approvalId, async () => {
            try {
                const response = await rejectMutation.mutateAsync(reason ? { approvalId, reviewerNote, reason } : { approvalId, reviewerNote })
                message.success(response?.message || t('text.agentic.msg.proposal_rejected'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.proposal_reject_failed')))
            }
        })
    }

    async function handleApply(approvalId) {
        return withAction(activeApprovalActionId, approvalActionMode, 'apply', approvalId, async () => {
            try {
                const response = await applyMutation.mutateAsync({ approvalId })
                message.success(response?.message || t('text.agentic.msg.changes_applied'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.changes_apply_failed')))
            }
        })
    }

    /**
     * Approve and apply in one step. Resolves with the outcome (applied, approval_stale, apply_failed),
     * or undefined when the request itself failed. Each outcome gets its own plain message.
     */
    async function handleApproveApply(approvalId, reviewerNote) {
        let outcome
        await withAction(activeApprovalActionId, approvalActionMode, 'approve_apply', approvalId, async () => {
            try {
                const response = await approveApplyMutation.mutateAsync({ approvalId, reviewerNote })
                outcome = response?.data?.outcome || 'apply_failed'
                if (outcome === 'applied') {
                    message.success(t('text.agentic.msg.approve_apply_done'))
                } else if (outcome === 'approval_stale') {
                    message.warning(t('text.agentic.inbox.stale_warning'), 8)
                } else {
                    message.error(t('text.agentic.inbox.apply_failed_warning'), 8)
                }
            } catch (error) {
                // The API client already shows the server message for a failed request; add ours only when it did not.
                if (!error?.reported) {
                    message.error(resolveErrorMessage(error, t('text.agentic.msg.approve_apply_failed')))
                }
            }
        })
        return outcome
    }

    /**
     * Ask the AI to rewrite a pending proposal. `request` = { feedback, addedInfo, options }.
     * Resolves with the response, or undefined when the request failed.
     */
    async function handleRequestChanges(approvalId, request = {}) {
        let result
        await withAction(activeApprovalActionId, approvalActionMode, 'request_changes', approvalId, async () => {
            try {
                result = await requestChangesMutation.mutateAsync({
                    approvalId,
                    feedback: request.feedback,
                    addedInfo: request.addedInfo || null,
                    options: request.options || {},
                })
                message.success(t('text.agentic.msg.rewrite_requested'))
            } catch (error) {
                // The API client already shows the server message for a failed request; add ours only when it did not.
                if (!error?.reported) {
                    message.error(resolveErrorMessage(error, t('text.agentic.msg.rewrite_request_failed')))
                }
            }
        })
        return result
    }

    // ── Run handlers ───────────────────────────────────────────────
    async function handleCancelRun(runId, reason) {
        return withAction(activeRunId, runActionMode, 'cancel', runId, async () => {
            try {
                const response = await cancelRunMutation.mutateAsync({ runId, reason })
                message.success(response?.message || t('text.agentic.msg.run_cancelled'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.run_cancel_failed')))
            }
        })
    }

    async function handleStopRun(runId, reason) {
        return withAction(activeRunId, runActionMode, 'stop', runId, async () => {
            try {
                const response = await stopRunMutation.mutateAsync({ runId, reason })
                message.success(response?.message || t('text.agentic.msg.stop_requested'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.stop_failed')))
            }
        })
    }

    async function handleRerunRun(runId) {
        return withAction(activeRunId, runActionMode, 'rerun', runId, async () => {
            try {
                const response = await rerunRunMutation.mutateAsync({ runId })
                message.success(response?.message || t('text.agentic.msg.run_redispatched'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.rerun_failed')))
            }
        })
    }

    // ── Schedule handlers ──────────────────────────────────────────
    async function handleCreateSchedule(payload) {
        try {
            const response = await createScheduleMutation.mutateAsync(payload)
            message.success(response?.message || t('text.agentic.msg.schedule_created'))
            return response
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.schedule_create_failed')))
        }
    }

    async function handleDispatchSchedule(scheduleId) {
        return withAction(activeScheduleId, scheduleActionMode, 'dispatch', scheduleId, async () => {
            try {
                const response = await dispatchScheduleMutation.mutateAsync({ scheduleId })
                message.success(response?.message || t('text.agentic.msg.schedule_dispatched'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.schedule_dispatch_failed')))
            }
        })
    }

    async function handlePauseSchedule(scheduleId, paused) {
        return withAction(activeScheduleId, scheduleActionMode, 'pause', scheduleId, async () => {
            try {
                const response = await updateScheduleMutation.mutateAsync({ scheduleId, payload: { status: paused ? 'paused' : 'active' } })
                message.success(response?.message || (paused ? t('text.agentic.msg.schedule_paused') : t('text.agentic.msg.schedule_resumed')))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.schedule_update_failed')))
            }
        })
    }

    async function handleRunOnceSchedule(scheduleId) {
        return withAction(activeScheduleId, scheduleActionMode, 'run_once', scheduleId, async () => {
            try {
                const response = await runOnceScheduleMutation.mutateAsync({ scheduleId })
                message.success(response?.message || t('text.agentic.msg.run_once_dispatched'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.run_once_failed')))
            }
        })
    }

    async function handleResumeDispatch(scheduleId) {
        return withAction(activeScheduleId, scheduleActionMode, 'resume', scheduleId, async () => {
            try {
                const response = await resumeDispatchMutation.mutateAsync({ scheduleId })
                message.success(response?.message || t('text.agentic.msg.schedule_resume_dispatched'))
                return response
            } catch (error) {
                message.error(resolveErrorMessage(error, t('text.agentic.msg.schedule_resume_failed')))
            }
        })
    }

    // ── Task launcher (NEW — for Dashboard + Inline Assist) ───────
    async function handleLaunchTask(toolName, targetType, targetId, goal, note) {
        try {
            const response = await launchTaskMutation.mutateAsync({
                tool_name: toolName,
                target_type: targetType,
                target_id: targetId,
                goal: goal || `Execute ${toolName}`,
                note: note || null,
            })
            message.success(response?.message || t('text.agentic.msg.task_launched'))
            return response
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.task_launch_failed')))
        }
    }

    return {
        // Loading state
        activeApprovalActionId, approvalActionMode,
        activeGrantToolId, grantActionMode,
        activeBindingId, bindingActionMode,
        activeRunId, runActionMode,
        activeScheduleId, scheduleActionMode,
        isSyncPending, isSeedTemplatesPending, isSaveProfilePending,
        isCreateSchedulePending, isLaunchTaskPending,

        // Helpers
        resolveErrorMessage,

        // Provisioning
        handleSyncTools, handleSeedTemplates,

        // Profiles
        handleCreateProfile, handleUpdateProfile,

        // Grants
        handleGrantTool, handleRevokeTool, handleUpdateGrant,

        // Bindings
        handleToggleBinding, handleUpdateBindingPriority,

        // Approvals
        handleApprove, handleReject, handleApply, handleApproveApply, handleRequestChanges,

        // Runs
        handleCancelRun, handleStopRun, handleRerunRun,

        // Schedules
        handleCreateSchedule, handleDispatchSchedule,
        handlePauseSchedule, handleRunOnceSchedule, handleResumeDispatch,

        // Task launcher
        handleLaunchTask,
    }
}
