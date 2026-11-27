<!--
  "Changes waiting for review": sensitive workflow changes that are not live yet. A change waits for a second person
  (who approves it after confirming their account, or rejects it with an optional reason) or, on a site with one
  publisher, for the end of its waiting time, which a publisher can skip with "go live now" after confirming their
  account. Its author or a Webmaster can cancel it. The author never approves.
-->
<template>
    <a-drawer
        :open="open"
        :title="t('text.workflow.guard.pending.title')"
        :width="drawerWidth"
        destroy-on-close
        data-testid="pending-drawer"
        @close="$emit('close')"
    >
        <div class="space-y-4">
            <p class="m-0 text-sm text-gray-600">{{ t('text.workflow.guard.pending.intro') }}</p>

            <a-alert v-if="notice" :type="notice.tone" show-icon closable :message="notice.text" data-testid="pending-notice" @close="notice = null" />

            <div v-if="loading && !rows.length" role="status" data-testid="pending-loading">
                <a-skeleton active :paragraph="{ rows: 3 }" />
            </div>
            <a-alert v-else-if="loadError" type="error" show-icon :message="t('text.workflow.guard.pending.load_failed')" data-testid="pending-load-error">
                <template #action>
                    <a-button size="small" @click="$emit('reload')">{{ t('text.workflow.guard.retry') }}</a-button>
                </template>
            </a-alert>
            <a-empty v-else-if="!rows.length" :description="t('text.workflow.guard.pending.empty')" data-testid="pending-empty" />

            <ul v-else class="mb-0 list-none space-y-3 p-0">
                <li
                    v-for="row in rows"
                    :key="row.uuid"
                    class="space-y-2 rounded-lg border border-solid border-slate-200 px-3 py-3"
                    data-testid="pending-row"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="min-w-0 break-words font-semibold text-gray-800">{{ row.project_name }}</span>
                        <a-tag color="orange" class="!m-0">{{ t('text.workflow.guard.version_n', { number: row.number }) }}</a-tag>
                    </div>
                    <p class="m-0 text-sm text-gray-600">
                        {{ row.created_by_name ? t('text.workflow.guard.history.by', { name: row.created_by_name }) : t('text.workflow.guard.history.by_system') }}
                        · {{ formatDate(row.created_at) }}
                    </p>
                    <p class="m-0 text-sm font-medium text-amber-700" data-testid="pending-when">{{ whenText(row) }}</p>

                    <ul v-if="changesOf(row).length" class="m-0 list-disc space-y-1 pl-5 text-sm text-gray-800">
                        <li v-for="(change, index) in changesOf(row)" :key="index" class="break-words" data-testid="pending-change">
                            {{ change.text }}
                            <span v-if="change.detail" class="font-medium">{{ change.detail }}</span>
                        </li>
                    </ul>

                    <p v-if="!row.can_approve && row.status === 'pending_review'" class="m-0 text-xs text-gray-500" data-testid="pending-own">
                        {{ t(row.can_cancel ? 'text.workflow.guard.pending.own_change' : 'text.workflow.guard.pending.cannot_approve') }}
                    </p>

                    <!-- Reason of a rejection: optional, shown to the author. -->
                    <div v-if="rejecting === row.uuid" class="space-y-2" data-testid="pending-reject-form">
                        <label class="block space-y-1">
                            <span class="text-sm font-medium text-gray-700">{{ t('text.workflow.guard.pending.reason_label') }}</span>
                            <a-textarea v-model:value="reason" :auto-size="{ minRows: 2, maxRows: 4 }" :maxlength="300" :disabled="busy === row.uuid" data-testid="pending-reason" />
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <a-button danger :loading="busy === row.uuid" data-testid="pending-reject-confirm" @click="reject(row)">
                                {{ t('text.workflow.guard.pending.reject_confirm') }}
                            </a-button>
                            <a-button :disabled="busy === row.uuid" @click="rejecting = ''">{{ $t('nav.b.cancel') }}</a-button>
                        </div>
                    </div>

                    <div v-else-if="row.can_approve || row.can_cancel || row.can_release_now" class="flex flex-wrap gap-2">
                        <a-button
                            v-if="row.can_release_now"
                            type="primary"
                            :loading="busy === row.uuid"
                            :disabled="Boolean(busy) || stepUp.open.value"
                            data-testid="pending-release-now"
                            @click="releaseNow(row)"
                        >
                            {{ t('text.workflow.guard.pending.release_now') }}
                        </a-button>
                        <a-button
                            v-if="row.can_approve"
                            type="primary"
                            :loading="busy === row.uuid"
                            :disabled="Boolean(busy) || stepUp.open.value"
                            data-testid="pending-approve"
                            @click="approve(row)"
                        >
                            {{ t('text.workflow.guard.pending.approve') }}
                        </a-button>
                        <a-button v-if="row.can_approve" danger :disabled="Boolean(busy)" data-testid="pending-reject" @click="startReject(row)">
                            {{ t('text.workflow.guard.pending.reject') }}
                        </a-button>
                        <a-button v-if="row.can_cancel" :loading="busy === row.uuid" :disabled="Boolean(busy)" data-testid="pending-cancel" @click="askCancel(row)">
                            {{ t('text.workflow.guard.pending.cancel') }}
                        </a-button>
                    </div>
                </li>
            </ul>
        </div>

        <StepUpDialog
            :open="stepUp.open.value"
            :purpose="PUBLISH_PURPOSE"
            :intro="t(stepUpIntro)"
            @confirmed="stepUp.confirmed"
            @cancel="stepUp.cancelled"
        />
    </a-drawer>
</template>

<script setup>
import dayjs from 'dayjs'
import { Modal } from 'ant-design-vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStepUpRunner } from '@/libs/securityTokens'
import StepUpDialog from '../../../../components/security/step-up-dialog.vue'
import { PUBLISH_PURPOSE, approveChange, cancelChange, guardErrorCode, refusalProblems, rejectChange, releaseChange } from '../../../../workflow-guard-api'
import { describeChanges, pendingWhen, publishProblems } from '../../../../workflow-guard-report'

defineOptions({ name: 'WorkflowPendingChanges' })

const props = defineProps({
    open: { type: Boolean, default: false },
    /** Rows of `workflow/versions/pending`; the page owns the list so its count shows on the toolbar. */
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    loadError: { type: Boolean, default: false },
})

/** `reload` asks the page to read the list again; `changed` says a change went live, was rejected or cancelled. */
const emit = defineEmits(['close', 'reload', 'changed'])

const { t } = useI18n()
const stepUp = useStepUpRunner({ purpose: PUBLISH_PURPOSE, lazy: true })

const busy = ref('')
const rejecting = ref('')
const reason = ref('')
const notice = ref(null)
/** What the account confirmation says: approving a change or skipping its waiting time. */
const stepUpIntro = ref('text.workflow.guard.stepup.approve_intro')
/** Ticks every half minute so "minutes left" stays true while the drawer is open. */
const now = ref(new Date())
let timer = null

const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 560 ? '100%' : 520))
const formatDate = (value) => (value ? dayjs(value).format('DD/MM/YYYY HH:mm') : '—')
const changesOf = (row) => describeChanges(row?.changes, { t })
const whenText = (row) => {
    const when = pendingWhen(row, now.value)
    return t(when.key, when.params)
}

const failText = (error, fallbackKey) => {
    const code = guardErrorCode(error)
    const rows = publishProblems(refusalProblems(error), { t })
    if (rows.length) return `${t('text.workflow.guard.publish.refused_title')} ${rows.map((row) => row.text).join(' ')}`
    const known = {
        forbidden: 'text.workflow.guard.error.forbidden',
        not_allowed: 'text.workflow.guard.error.forbidden',
        step_up_required: 'text.workflow.guard.error.stepup_needed',
        author_cannot_approve: 'text.workflow.guard.pending.own_change',
        not_pending: 'text.workflow.guard.pending.not_pending',
        integrity_mismatch: 'text.workflow.guard.history.rollback_tampered',
    }
    return t(known[code] || fallbackKey)
}

/** Run one action on a row with the busy guard, then say what happened and ask for a fresh list. */
async function act(row, action, doneKey, failKey) {
    if (busy.value) return
    busy.value = row.uuid
    notice.value = null
    try {
        const answer = await action()
        if (answer === undefined) return
        notice.value = { tone: 'success', text: t(doneKey, { name: row.project_name }) }
        rejecting.value = ''
        emit('changed', row)
        emit('reload')
    } catch (error) {
        notice.value = { tone: 'error', text: failText(error, failKey) }
        // Someone else decided it meanwhile: show the list as it is now.
        if (guardErrorCode(error) === 'not_pending') emit('reload')
    } finally {
        busy.value = ''
    }
}

const approve = (row) => {
    stepUpIntro.value = 'text.workflow.guard.stepup.approve_intro'
    return act(row, () => stepUp.run((grant) => approveChange(row.project_uuid, row.uuid, grant), { askFirst: true }), 'text.workflow.guard.pending.approved', 'text.workflow.guard.pending.approve_failed')
}

const releaseNow = (row) => {
    stepUpIntro.value = 'text.workflow.guard.stepup.release_intro'
    return act(row, () => stepUp.run((grant) => releaseChange(row.project_uuid, row.uuid, grant), { askFirst: true }), 'text.workflow.guard.pending.released', 'text.workflow.guard.pending.release_failed')
}

const reject = (row) =>
    act(row, () => rejectChange(row.project_uuid, row.uuid, reason.value.trim()), 'text.workflow.guard.pending.rejected', 'text.workflow.guard.pending.reject_failed')

function startReject(row) {
    reason.value = ''
    rejecting.value = row.uuid
}

function askCancel(row) {
    Modal.confirm({
        title: t('text.workflow.guard.pending.cancel_title'),
        content: t('text.workflow.guard.pending.cancel_body', { name: row.project_name }),
        okText: t('text.workflow.guard.pending.cancel'),
        okButtonProps: { danger: true },
        cancelText: t('text.workflow.guard.pending.keep'),
        onOk: () => act(row, () => cancelChange(row.project_uuid, row.uuid), 'text.workflow.guard.pending.cancelled', 'text.workflow.guard.pending.cancel_failed'),
    })
}

watch(
    () => props.open,
    (value) => {
        clearInterval(timer)
        timer = null
        if (!value) return
        notice.value = null
        rejecting.value = ''
        now.value = new Date()
        timer = setInterval(() => (now.value = new Date()), 30000)
        emit('reload')
    },
    { immediate: true }
)

onBeforeUnmount(() => clearInterval(timer))
</script>
