<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-card v-if="workflow" size="small" :title="t('text.workflow.runs.title')" data-testid="workflow-run-panel">
        <div class="space-y-5">
            <!-- Run now -->
            <div v-if="canRun" class="space-y-2">
                <a-button
                    type="primary"
                    data-testid="run-now"
                    :disabled="Boolean(runBlockedReason)"
                    :loading="running"
                    @click="runNow"
                >
                    {{ t('text.workflow.runs.run_now') }}
                </a-button>
                <p v-if="runBlockedReason" class="m-0 text-sm text-gray-500" data-testid="run-blocked-reason">
                    {{ runBlockedReason }}
                </p>
            </div>

            <!-- Schedule -->
            <div v-if="!workflow.system_managed" class="space-y-3" data-testid="schedule-control">
                <div class="text-sm font-medium text-gray-700">{{ t('text.workflow.schedule.title') }}</div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-xs text-gray-500">{{ t('text.workflow.schedule.repeat') }}</span>
                        <a-select
                            v-model:value="form.mode"
                            class="w-full"
                            data-testid="schedule-mode"
                            :disabled="!canUpdate || saving"
                            :options="modeOptions"
                        />
                    </label>

                    <label v-if="form.mode === 'weekly'" class="block space-y-1">
                        <span class="text-xs text-gray-500">{{ t('text.workflow.schedule.day') }}</span>
                        <a-select
                            v-model:value="form.day"
                            class="w-full"
                            data-testid="schedule-day"
                            :disabled="!canUpdate || saving"
                            :options="dayOptions"
                        />
                    </label>

                    <label v-if="form.mode === 'daily' || form.mode === 'weekly'" class="block space-y-1">
                        <span class="text-xs text-gray-500">{{ t('text.workflow.schedule.time') }}</span>
                        <a-time-picker
                            v-model:value="form.time"
                            class="w-full"
                            format="HH:mm"
                            value-format="HH:mm"
                            data-testid="schedule-time"
                            :allow-clear="false"
                            :disabled="!canUpdate || saving"
                        />
                    </label>

                    <label v-if="form.mode === 'custom'" class="block space-y-1 sm:col-span-2">
                        <span class="text-xs text-gray-500">{{ t('text.workflow.schedule.custom_label') }}</span>
                        <a-input
                            v-model:value="form.custom"
                            data-testid="schedule-custom"
                            placeholder="0 8 * * 1-5"
                            :disabled="!canUpdate || saving"
                        />
                        <span class="block text-xs text-gray-500">{{ t('text.workflow.schedule.custom_help') }}</span>
                    </label>

                    <label v-if="form.mode !== 'off'" class="block space-y-1 sm:col-span-2">
                        <span class="text-xs text-gray-500">{{ t('text.workflow.schedule.timezone') }}</span>
                        <a-select
                            v-model:value="form.timezone"
                            class="w-full"
                            show-search
                            data-testid="schedule-timezone"
                            :disabled="!canUpdate || saving"
                            :options="timezoneOptions"
                        />
                    </label>
                </div>

                <a-alert v-if="scheduleError" type="error" show-icon :message="scheduleError" data-testid="schedule-error" />

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <a-button
                        v-if="canUpdate"
                        data-testid="schedule-save"
                        :disabled="!scheduleDirty"
                        :loading="saving"
                        @click="saveSchedule"
                    >
                        {{ t('text.workflow.schedule.save') }}
                    </a-button>
                    <span class="text-xs text-gray-500" data-testid="schedule-last-run">
                        {{ lastScheduledRunText }}
                    </span>
                </div>

                <p v-if="scheduleNote" class="m-0 text-xs text-gray-500" data-testid="schedule-note">{{ scheduleNote }}</p>
                <p v-if="canUpdate" class="m-0 text-xs text-gray-500" data-testid="schedule-publish-note">{{ t('text.workflow.guard.draft.schedule_note') }}</p>
            </div>

            <!-- Recent runs -->
            <div class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-sm font-medium text-gray-700">{{ t('text.workflow.runs.recent') }}</div>
                    <a-button size="small" :loading="loadingRuns" data-testid="runs-refresh" @click="loadRuns()">
                        {{ t('text.workflow.runs.refresh') }}
                    </a-button>
                </div>

                <a-alert v-if="runsError" type="error" show-icon :message="runsError" data-testid="runs-error" />

                <a-table
                    v-else
                    size="small"
                    row-key="id"
                    data-testid="runs-table"
                    :columns="columns"
                    :data-source="runs"
                    :loading="loadingRuns"
                    :scroll="{ x: 'max-content' }"
                    :locale="{ emptyText: t('text.workflow.runs.empty') }"
                    :pagination="pagination"
                    @change="onTableChange"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'status'">
                            <a-tag :color="runStatusColor(record.status)">{{ runStatusLabel(record.status) }}</a-tag>
                        </template>
                        <template v-else-if="column.key === 'source'">{{ runSourceLabel(record.source) }}</template>
                        <template v-else-if="column.key === 'started'">{{ formatDate(record.started_at || record.created_at) }}</template>
                        <template v-else-if="column.key === 'duration'">{{ formatDuration(record.duration_ms) }}</template>
                        <template v-else-if="column.key === 'steps'">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <!-- The run waits for a person: go straight to what has to be approved. -->
                                <router-link
                                    v-if="pendingApprovals[record.id]"
                                    :to="{ name: 'approval-detail', params: { id: String(pendingApprovals[record.id]) } }"
                                    class="font-medium"
                                    data-testid="run-open-approval"
                                >
                                    {{ t('text.workflow.runs.open_approval') }}
                                </router-link>
                                <a-button type="link" size="small" class="px-0" @click="openSteps(record)">
                                    {{ t('text.workflow.runs.view_steps') }}
                                </a-button>
                            </div>
                        </template>
                    </template>
                </a-table>
            </div>
        </div>

        <WorkflowRunStepsDrawer :open="stepsOpen" :run="stepsRun" :workflow="workflow" @close="stepsOpen = false" />
    </a-card>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import { getPendingApprovalsByRun, getWorkflowRuns, runWorkflowProject, saveWorkflowSchedule, workflowErrorCode } from '../workflow-run-api'
import { SCHEDULE_MODES, cronFromSchedule, isRunInProgress, scheduleFromCron } from '../workflow-schedule'
import { formatRunDate, formatRunDuration, runStatusColor, useRunLabels } from '../workflow-run-labels'
import WorkflowRunStepsDrawer from './workflow-run-steps-drawer.vue'

defineOptions({ name: 'WorkflowRunPanel' })

const props = defineProps({
    workflow: { type: Object, default: null },
    // Live on/off state of the workflow (the list switch may have changed it).
    isActive: { type: Boolean, default: false },
    canUpdate: { type: Boolean, default: false },
    // Starting a run by hand is its own right (`workflow_project.run`), apart from editing the draft.
    canRun: { type: Boolean, default: false },
    // No version was ever published: there is nothing to run yet.
    neverPublished: { type: Boolean, default: false },
    // All workflows are paused by a Webmaster.
    pausedAll: { type: Boolean, default: false },
})

/** `changed` after the schedule was saved: the draft may now differ from the version that runs. */
const emit = defineEmits(['changed'])

const { t } = useI18n()
const { runStatusLabel, runSourceLabel } = useRunLabels()

const PER_PAGE = 10
const POLL_MS = 5000
// An empty value means "use the website's time zone" (stored as null).
const SITE_TIMEZONE = ''

const runs = ref([])
const meta = ref({ page: 1, per_page: PER_PAGE, total: 0 })
const schedule = ref(null)
const loadingRuns = ref(false)
const runsError = ref('')
const running = ref(false)
const saving = ref(false)
const scheduleError = ref('')
const stepsOpen = ref(false)
const stepsRun = ref(null)

const form = reactive({ ...scheduleFromCron(null), timezone: SITE_TIMEZONE })

const formatDate = formatRunDate
const formatDuration = (durationMs) => formatRunDuration(durationMs, t)

/** Plain messages for the error codes the run and schedule endpoints answer with. */
const errorText = (error, fallbackKey) => {
    const keys = {
        workflow_already_running: 'text.workflow.runs.error.already_running',
        project_inactive: 'text.workflow.runs.blocked_inactive',
        not_published: 'text.workflow.guard.run.blocked_unpublished',
        project_not_published: 'text.workflow.guard.run.blocked_unpublished',
        workflows_paused: 'text.workflow.guard.run.blocked_paused_all',
        breaker_tripped: 'text.workflow.guard.run.blocked_breaker',
        integrity_mismatch: 'text.workflow.guard.paused.reason.integrity',
        workflow_not_runnable: 'text.workflow.runs.blocked_system',
        forbidden: 'text.workflow.runs.error.forbidden',
        validation_failed: 'text.workflow.schedule.error.invalid',
    }
    return t(keys[workflowErrorCode(error)] || fallbackKey)
}

const runBlockedReason = computed(() => {
    if (props.workflow?.system_managed) return t('text.workflow.runs.blocked_system')
    if (props.neverPublished) return t('text.workflow.guard.run.blocked_unpublished')
    if (props.pausedAll) return t('text.workflow.guard.run.blocked_paused_all')
    if (props.workflow?.paused_reason) return t('text.workflow.guard.run.blocked_paused')
    if (!props.isActive) return t('text.workflow.runs.blocked_inactive')
    return ''
})

const modeOptions = computed(() => SCHEDULE_MODES.map((mode) => ({ value: mode, label: t(`text.workflow.schedule.mode.${mode}`) })))
const dayOptions = computed(() => [1, 2, 3, 4, 5, 6, 0].map((day) => ({ value: day, label: t(`text.workflow.schedule.weekday.${day}`) })))

const timezoneOptions = computed(() => {
    let zones = []
    try {
        zones = Intl.supportedValuesOf('timeZone')
    } catch {
        zones = []
    }
    // Keep a stored zone selectable even when this browser does not list it.
    if (form.timezone && !zones.includes(form.timezone)) zones = [form.timezone, ...zones]
    return [{ value: SITE_TIMEZONE, label: t('text.workflow.schedule.timezone_site') }, ...zones.map((zone) => ({ value: zone, label: zone }))]
})

const columns = computed(() => [
    { key: 'status', title: t('text.workflow.runs.col.status') },
    { key: 'source', title: t('text.workflow.runs.col.source') },
    { key: 'started', title: t('text.workflow.runs.col.started') },
    { key: 'duration', title: t('text.workflow.runs.col.duration') },
    { key: 'steps', title: '' },
])

const pagination = computed(() =>
    meta.value.total > meta.value.per_page
        ? { current: meta.value.page, pageSize: meta.value.per_page, total: meta.value.total, simple: true, showSizeChanger: false }
        : false
)

const storedForm = computed(() => ({ ...scheduleFromCron(schedule.value?.cron), timezone: schedule.value?.timezone || SITE_TIMEZONE }))

const scheduleDirty = computed(() => {
    const next = cronFromSchedule(form)
    const current = schedule.value?.cron || null
    if (next === undefined) return true
    if (next !== current) return true
    return next !== null && (form.timezone || SITE_TIMEZONE) !== (schedule.value?.timezone || SITE_TIMEZONE)
})

const lastScheduledRunText = computed(() =>
    schedule.value?.last_scheduled_run_at
        ? t('text.workflow.schedule.last_run', { time: formatDate(schedule.value.last_scheduled_run_at) })
        : t('text.workflow.schedule.never_run')
)

/** Who the scheduled runs act for, and a reminder when the workflow is off. */
const scheduleNote = computed(() => {
    if (!schedule.value?.cron) return ''
    if (!props.isActive) return t('text.workflow.schedule.paused_inactive')
    return schedule.value.scheduled_by?.name ? t('text.workflow.schedule.set_by', { name: schedule.value.scheduled_by.name }) : ''
})

const applySchedule = (value) => {
    schedule.value = value
    Object.assign(form, storedForm.value)
    scheduleError.value = ''
}

/** Approval waiting for a person, per run id; lets a waiting run link to its approval. */
const pendingApprovals = ref({})
const FINISHED_STATUSES = ['completed', 'failed', 'cancelled', 'skipped']

/** Look the waiting approvals up only while a run on this page has not finished. A failure leaves the links out. */
async function loadPendingApprovals() {
    if (!runs.value.some((run) => !FINISHED_STATUSES.includes(run.status))) {
        pendingApprovals.value = {}
        return
    }
    try {
        pendingApprovals.value = await getPendingApprovalsByRun()
    } catch (error) {
        pendingApprovals.value = {}
    }
}

let pollTimer = null
const stopPolling = () => {
    if (pollTimer) clearTimeout(pollTimer)
    pollTimer = null
}

/** Load one page of runs. A quiet reload (polling) keeps the table steady and leaves the schedule form alone. */
async function loadRuns(page = meta.value.page, { quiet = false } = {}) {
    const projectId = props.workflow?.id
    if (!projectId) return
    stopPolling()
    if (!quiet) loadingRuns.value = true
    try {
        const result = await getWorkflowRuns(projectId, { page, perPage: PER_PAGE })
        if (projectId !== props.workflow?.id) return
        runs.value = result.runs
        meta.value = { ...meta.value, ...result.meta }
        runsError.value = ''
        if (quiet) {
            schedule.value = result.schedule
        } else {
            applySchedule(result.schedule)
        }
        loadPendingApprovals()
        if (runs.value.some(isRunInProgress)) {
            pollTimer = setTimeout(() => loadRuns(meta.value.page, { quiet: true }), POLL_MS)
        }
    } catch (error) {
        if (!quiet) runsError.value = errorText(error, 'text.workflow.runs.error.load_failed')
    } finally {
        if (!quiet) loadingRuns.value = false
    }
}

async function runNow() {
    if (running.value || runBlockedReason.value) return
    running.value = true
    try {
        await runWorkflowProject(props.workflow.id)
        message.success(t('text.workflow.runs.started'))
        await loadRuns(1, { quiet: true })
    } catch (error) {
        message.error(errorText(error, 'text.workflow.runs.error.start_failed'))
    } finally {
        running.value = false
    }
}

async function saveSchedule() {
    if (saving.value) return
    const cron = cronFromSchedule(form)
    if (cron === undefined) {
        scheduleError.value = t(form.mode === 'custom' ? 'text.workflow.schedule.error.custom_required' : 'text.workflow.schedule.error.time_required')
        return
    }

    saving.value = true
    scheduleError.value = ''
    try {
        const saved = await saveWorkflowSchedule(props.workflow.id, { cron, timezone: cron ? form.timezone : null })
        applySchedule(saved)
        message.success(t(cron ? 'text.workflow.schedule.saved' : 'text.workflow.schedule.turned_off'))
        emit('changed')
    } catch (error) {
        scheduleError.value = errorText(error, 'text.workflow.schedule.error.save_failed')
    } finally {
        saving.value = false
    }
}

const onTableChange = (page) => loadRuns(page?.current || 1)

const openSteps = (run) => {
    stepsRun.value = run
    stepsOpen.value = true
}

watch(
    () => props.workflow?.id,
    () => {
        stopPolling()
        runs.value = []
        meta.value = { page: 1, per_page: PER_PAGE, total: 0 }
        stepsOpen.value = false
        applySchedule(null)
        loadRuns(1)
    },
    { immediate: true }
)

onBeforeUnmount(stopPolling)
</script>
