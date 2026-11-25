<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
  Setup of an installed sample workflow: only the few things this site must decide (who reviews, who is told, when it
  runs, which connected account a step uses). Everything else comes with the sample and is only listed.
  Choosing an answer writes nothing. Two buttons act: "Safe test run" and "Save and turn on" (answers into the draft,
  then the usual publish flow, then the workflow is switched on).
-->
<template>
    <no-permission-error v-if="!allowed" data-testid="workflow-setup-not-allowed" />
    <div v-else class="mx-auto max-w-3xl space-y-4" data-testid="workflow-setup-page">
        <section class="i-box space-y-2">
            <router-link :to="{ name: 'setting-workflows-manage' }" class="text-sm" data-testid="setup-back">{{ t('text.workflow.package.back') }}</router-link>

            <div v-if="stage === 'loading'" class="flex items-center gap-3" role="status" data-testid="setup-loading">
                <a-spin />
                <p class="m-0 text-sm">{{ t('text.workflow.setup.loading') }}</p>
            </div>
            <a-alert
                v-else-if="stage === 'error' || stage === 'missing'"
                type="error"
                show-icon
                :message="t(stage === 'missing' ? 'text.workflow.setup.missing_title' : 'text.workflow.setup.load_failed_title')"
                :description="t(stage === 'missing' ? 'text.workflow.setup.missing_hint' : 'text.workflow.setup.load_failed_hint')"
                data-testid="setup-load-error"
            >
                <template v-if="stage === 'error'" #action>
                    <a-button size="small" data-testid="setup-retry" @click="load">{{ t('text.workflow.package.retry') }}</a-button>
                </template>
            </a-alert>
            <template v-else>
                <p class="m-0 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('text.workflow.setup.eyebrow') }}</p>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h1 class="m-0 min-w-0 break-words text-lg font-semibold" data-testid="setup-title">
                        {{ t(progress.total && progress.done < progress.total ? 'text.workflow.setup.title' : 'text.workflow.setup.title_done', { name: workflow.name }) }}
                    </h1>
                    <a-tag v-if="progress.total" :color="progress.done === progress.total ? 'green' : 'gold'" class="!m-0" data-testid="setup-progress">
                        {{ t('text.workflow.setup.progress', progress) }}
                    </a-tag>
                </div>
                <p class="m-0 text-sm text-ink-muted" data-testid="setup-intro">
                    {{ progress.total ? t('text.workflow.setup.intro', { count: progress.total }) : t('text.workflow.setup.intro_none') }}
                </p>
                <a-alert v-if="!canUpdate" type="info" show-icon :message="t('text.workflow.setup.read_only')" data-testid="setup-read-only" />
            </template>
        </section>

        <template v-if="stage === 'ready'">
            <!-- Turned on (or waiting for the second person): nothing more to do here. -->
            <section v-if="outcome" class="i-box space-y-3" role="status" data-testid="setup-outcome" :data-outcome="outcome">
                <h2 class="m-0 text-base font-semibold" :class="outcome === 'enabled' ? 'text-green-700' : 'text-amber-700'">
                    {{ t(`text.workflow.setup.outcome_${outcome}_title`, { name: workflow.name }) }}
                </h2>
                <p class="m-0 text-sm">{{ t(`text.workflow.setup.outcome_${outcome}_hint`) }}</p>
                <div class="flex flex-wrap gap-2">
                    <a-button type="primary" data-testid="setup-to-list" @click="router.push({ name: 'setting-workflows-manage' })">{{ t('text.workflow.setup.to_list') }}</a-button>
                    <a-button data-testid="setup-to-designer" @click="openDesigner">{{ t('text.workflow.setup.view_designer') }}</a-button>
                </div>
            </section>

            <!-- The questions. -->
            <section
                v-for="(question, index) in questions"
                :key="question.key"
                class="i-box space-y-3"
                :class="openKey === question.key ? 'ring-2 ring-cyan-600/30' : ''"
                data-testid="setup-question"
                :data-key="question.key"
                :data-kind="question.kind"
                :data-answered="answered(question) ? 'yes' : 'no'"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                        :class="answered(question) ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'"
                        aria-hidden="true"
                    >
                        {{ answered(question) ? '✓' : index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="m-0 min-w-0 break-words text-base font-semibold">{{ question.label }}</h2>
                            <a-tag v-if="answered(question)" color="green" class="!m-0">{{ t('text.workflow.setup.chosen') }}</a-tag>
                            <a-tag v-else-if="question.required" color="gold" class="!m-0">{{ t('text.workflow.setup.step_badge') }}</a-tag>
                        </div>
                        <p v-if="openKey !== question.key && answered(question)" class="m-0 break-words text-sm text-ink-muted" data-testid="setup-answer-summary">
                            {{ summaryOf(question) }}
                        </p>
                        <p v-else-if="question.help" class="m-0 break-words text-sm text-ink-muted">{{ question.help }}</p>
                    </div>
                    <a-button v-if="openKey !== question.key && canUpdate && !outcome" size="small" data-testid="setup-question-open" @click="openKey = question.key">
                        {{ t(answered(question) ? 'text.workflow.setup.change' : 'text.workflow.setup.choose') }}
                    </a-button>
                </div>

                <div v-if="openKey === question.key" class="space-y-3 sm:pl-10" data-testid="setup-question-body">
                    <!-- Who reviews -->
                    <template v-if="question.kind === 'reviewers'">
                        <div class="space-y-1">
                            <p class="m-0 text-sm font-medium">{{ t('text.workflow.setup.reviewers_group') }}</p>
                            <PermissionPicker
                                :model-value="answers[question.key].permission"
                                :read-only="!canUpdate"
                                :label="t('text.workflow.setup.reviewers_group')"
                                @update:model-value="(value) => (answers[question.key].permission = value || '')"
                            />
                        </div>
                        <div v-if="question.assignees_allowed !== false" class="space-y-1">
                            <p class="m-0 text-sm font-medium">{{ t('text.workflow.setup.reviewers_people') }}</p>
                            <ReviewerPicker
                                :model-value="answers[question.key].assignees"
                                :read-only="!canUpdate"
                                :label="t('text.workflow.setup.reviewers_people')"
                                @update:model-value="(value) => (answers[question.key].assignees = value)"
                            />
                            <p class="m-0 text-xs text-ink-muted">{{ t('text.workflow.setup.reviewers_people_hint') }}</p>
                        </div>
                    </template>

                    <!-- Who is told -->
                    <template v-else-if="question.kind === 'notify_channel'">
                        <template v-if="question.field === 'permission'">
                            <PermissionPicker
                                :model-value="answers[question.key]"
                                :read-only="!canUpdate"
                                required
                                :label="question.label"
                                @update:model-value="(value) => (answers[question.key] = value || '')"
                            />
                            <p class="m-0 text-xs text-ink-muted">{{ t('text.workflow.setup.notify_in_app') }}</p>
                        </template>
                        <a-input v-else v-model:value="answers[question.key]" :disabled="!canUpdate" :aria-label="question.label" data-testid="setup-text-answer" />
                    </template>

                    <!-- Connected account -->
                    <template v-else-if="question.kind === 'credential'">
                        <a-select
                            class="w-full"
                            :value="answers[question.key] || undefined"
                            :options="credentialOptions(question)"
                            :loading="credentials.loading"
                            :disabled="!canUpdate"
                            :placeholder="t('text.workflow.setup.credential_placeholder')"
                            :aria-label="question.label"
                            :not-found-content="t('text.workflow.setup.credential_none')"
                            data-testid="setup-credential"
                            @update:value="(value) => (answers[question.key] = value || '')"
                        />
                        <p class="m-0 text-xs text-ink-muted">
                            {{ t(credentials.failed ? 'text.workflow.setup.credential_failed' : 'text.workflow.setup.credential_hint') }}
                            <router-link v-if="canManageCredentials" :to="{ name: 'setting-workflow-credentials' }" data-testid="setup-credential-manage">
                                {{ t('text.workflow.setup.credential_manage') }}
                            </router-link>
                        </p>
                    </template>

                    <!-- When it runs -->
                    <template v-else-if="question.kind === 'schedule'">
                        <a-radio-group
                            :value="scheduleChoice(question)"
                            :disabled="!canUpdate"
                            class="!flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                            data-testid="setup-schedule"
                            @update:value="(key) => chooseSchedule(question, key)"
                        >
                            <a-radio v-for="choice in scheduleChoices" :key="choice.key" :value="choice.key" :data-choice="choice.key">
                                {{ t(`text.workflow.setup.schedule_${choice.key}`) }}
                            </a-radio>
                        </a-radio-group>
                        <div v-if="scheduleChoice(question) === 'other'" class="flex flex-wrap items-center gap-2" data-testid="setup-schedule-other">
                            <a-select class="w-40" :value="otherSchedule.mode" :options="otherModes" :disabled="!canUpdate" :aria-label="t('text.workflow.setup.schedule_repeat')" @update:value="(mode) => setOther(question, { mode })" />
                            <a-select
                                v-if="otherSchedule.mode === 'weekly'"
                                class="w-36"
                                :value="otherSchedule.day"
                                :options="weekdays"
                                :disabled="!canUpdate"
                                :aria-label="t('text.workflow.setup.schedule_day')"
                                @update:value="(day) => setOther(question, { day })"
                            />
                            <a-input
                                v-if="otherSchedule.mode !== 'hourly'"
                                type="time"
                                class="w-32"
                                :value="otherSchedule.time"
                                :disabled="!canUpdate"
                                :aria-label="t('text.workflow.setup.schedule_time')"
                                data-testid="setup-schedule-time"
                                @update:value="(time) => setOther(question, { time })"
                            />
                        </div>
                    </template>

                    <!-- A yes/no the site picks (an optional branch of the sample) -->
                    <template v-else-if="question.kind === 'choice'">
                        <a-radio-group v-model:value="answers[question.key]" :disabled="!canUpdate" :aria-label="question.label" data-testid="setup-choice-answer">
                            <a-radio :value="true">{{ t('text.workflow.setup.choice_on') }}</a-radio>
                            <a-radio :value="false">{{ t('text.workflow.setup.choice_off') }}</a-radio>
                        </a-radio-group>
                    </template>

                    <!-- Any other required setting -->
                    <template v-else>
                        <a-input
                            v-if="typeof answers[question.key] === 'string'"
                            v-model:value="answers[question.key]"
                            :disabled="!canUpdate"
                            :aria-label="question.label"
                            data-testid="setup-text-answer"
                        />
                        <p v-else class="m-0 text-sm" data-testid="setup-in-designer">
                            {{ t('text.workflow.setup.in_designer') }}
                            <a-button type="link" class="!h-auto !p-0" @click="openDesigner">{{ t('text.workflow.setup.view_designer') }}</a-button>
                        </p>
                    </template>

                    <p v-if="problems[question.key]" class="m-0 break-words text-sm text-red-600" role="alert" data-testid="setup-question-problem">
                        {{ t('text.workflow.setup.answer_refused') }}
                    </p>
                    <a-button v-if="canUpdate" type="primary" ghost :disabled="!answered(question)" data-testid="setup-question-next" @click="next(question)">
                        {{ t('text.workflow.setup.continue') }}
                    </a-button>
                </div>
            </section>

            <!-- What the sample already sets. -->
            <section v-if="setup.preset.length" class="i-box space-y-2" data-testid="setup-preset">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="m-0 text-sm font-semibold">{{ t('text.workflow.setup.preset_title') }}</h2>
                    <a-button type="link" size="small" class="!h-auto !p-0" data-testid="setup-view-designer" @click="openDesigner">{{ t('text.workflow.setup.view_designer') }}</a-button>
                </div>
                <ul class="m-0 list-disc space-y-1 pl-5 text-sm text-ink-muted">
                    <li v-for="(row, index) in setup.preset" :key="index" class="break-words">{{ row.label }}</li>
                </ul>
            </section>

            <!-- Safe test run: result. -->
            <section v-if="run.state !== 'idle'" class="i-box space-y-2" data-testid="setup-run" :data-state="run.state">
                <h2 class="m-0 text-sm font-semibold">{{ t('text.workflow.package.dry_run_title') }}</h2>
                <div v-if="run.state === 'running'" class="flex items-center gap-3" role="status">
                    <a-spin />
                    <p class="m-0 text-sm">{{ t('text.workflow.setup.run_running') }}</p>
                </div>
                <p v-else-if="run.state === 'failed'" class="m-0 text-sm text-red-600" role="alert">{{ t('text.workflow.setup.run_failed') }}</p>
                <p v-else-if="run.state === 'none'" class="m-0 text-sm">{{ t('text.workflow.setup.run_none') }}</p>
                <p v-else-if="run.state === 'incomplete'" class="m-0 text-sm text-amber-700" role="status">{{ t('text.workflow.setup.run_incomplete') }}</p>
                <template v-else>
                    <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow.package.dry_run_safe') }}</p>
                    <p class="m-0 text-sm font-medium" :class="run.state === 'completed' ? 'text-green-700' : 'text-amber-700'" data-testid="setup-run-state">
                        {{ run.state === 'stopped' ? t('text.workflow.setup.run_stopped') : t(`text.workflow.package.dry_run_${run.state}`, { count: run.rows.length }) }}
                    </p>
                    <details class="text-sm" :open="run.rows.length <= 8">
                        <summary class="cursor-pointer py-1">{{ t('text.workflow.package.dry_run_steps', { count: run.rows.length }) }}</summary>
                        <ol class="m-0 mt-1 list-decimal space-y-2 pl-5">
                            <li v-for="row in run.rows" :key="row.code" class="break-words" :data-mode="row.mode">
                                <span class="font-medium">{{ row.name }}</span>
                                <span class="block text-ink-muted">
                                    <template v-if="row.failed">{{ t('text.workflow.package.dry_run_row_failed') }}</template>
                                    <template v-else-if="row.mode === 'ran'">{{ t('text.workflow.package.dry_run_row_ran') }}</template>
                                    <template v-else-if="row.mode === 'approval'">{{ t('text.workflow.package.dry_run_row_approval') }}</template>
                                    <template v-else>{{ t('text.workflow.setup.run_row_skipped') }}</template>
                                </span>
                            </li>
                        </ol>
                    </details>
                </template>
            </section>

            <!-- The two actions. -->
            <section v-if="!outcome" class="i-box space-y-2" data-testid="setup-actions">
                <a-alert v-if="saveError" type="error" show-icon :message="t(saveError)" data-testid="setup-save-error" />
                <div class="flex flex-wrap gap-2">
                    <a-button :loading="run.state === 'running'" :disabled="saving" data-testid="setup-test-run" @click="testRun">{{ t('text.workflow.package.dry_run_title') }}</a-button>
                    <a-button v-if="canUpdate" type="primary" :loading="saving" :disabled="!ready || run.state === 'running'" data-testid="setup-save-enable" @click="saveAndEnable">
                        {{ t('text.workflow.setup.save_enable') }}
                    </a-button>
                </div>
                <p class="m-0 text-xs text-ink-muted" data-testid="setup-actions-note">
                    {{ ready ? t('text.workflow.setup.actions_ready') : t('text.workflow.setup.actions_wait', { count: progress.total - progress.done }) }}
                </p>
            </section>
        </template>

        <WorkflowPublishDialog :names="stepNameMap" :open="publishOpen" :workflow="workflow" @close="afterPublishDialog" @done="(answer) => (publishAnswer = answer)" @refused="saveError = 'text.workflow.setup.publish_refused'" />
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { rule } from '@/mixins'
import { updateWorkflow } from '@/libs'
import { fetchPermissionOptions, resolveReviewers } from '@/services/workflowApi'
import PermissionPicker from '@/components/workflow/widgets/PermissionPicker.vue'
import ReviewerPicker from '@/components/workflow/inputs/ReviewerPicker.vue'
import WorkflowPublishDialog from './workflows-manage/components/workflow-publish-dialog.vue'
import { cronFromSchedule, scheduleFromCron } from './workflows-manage/workflow-schedule'
import { previewPublish } from '../../workflow-guard-api'
import { fetchCredentials } from '../../workflow-package-api'
import { dryRunRows, dryRunState } from '../../workflow-package-report'
import {
    SCHEDULE_CHOICES,
    changedAnswers,
    fetchWorkflowSetup,
    findWorkflow,
    initialAnswer,
    isAnswered,
    safeTestRun,
    setupIncomplete,
    saveWorkflowSetup,
    setupProblems,
    setupProgress,
} from '../../workflow-setup-api'

defineOptions({ name: 'WorkflowSetup' })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const allowed = computed(() => rule('workflow_project.viewAny'))
const canUpdate = computed(() => rule('workflow_project.update'))
const canManageCredentials = computed(() => rule('workflow_credential.manage'))

/** loading | error | missing | ready */
const stage = ref('loading')
const workflow = ref(null)
const setup = ref({ complete: false, questions: [], preset: [] })
const answers = reactive({})
const openKey = ref('')
const problems = ref({})

const questions = computed(() => setup.value.questions)
const answered = (question) => isAnswered(question, answers[question.key])
const progress = computed(() => setupProgress(questions.value, answers))
const ready = computed(() => progress.value.done === progress.value.total)

const takeSetup = (body) => {
    setup.value = body
    Object.keys(answers).forEach((key) => delete answers[key])
    body.questions.forEach((question) => (answers[question.key] = initialAnswer(question)))
    openKey.value = body.questions.find((question) => question.required && !isAnswered(question, answers[question.key]))?.key || ''
    problems.value = {}
}

let token = 0
async function load() {
    const mine = ++token
    stage.value = 'loading'
    try {
        const found = await findWorkflow(route.params.project)
        if (mine !== token) return
        if (!found) {
            stage.value = 'missing'
            return
        }
        const body = await fetchWorkflowSetup(found.id)
        if (mine !== token) return
        workflow.value = found
        takeSetup(body)
        stage.value = 'ready'
    } catch (error) {
        if (mine === token) stage.value = error?.response?.status === 404 ? 'missing' : 'error'
    }
}
watch(() => route.params.project, load, { immediate: true })

const next = (question) => {
    const list = questions.value
    const after = [...list.slice(list.indexOf(question) + 1), ...list].find((row) => row.key !== question.key && row.required && !answered(row))
    openKey.value = after ? after.key : ''
}

/** step_code => step name, so the test run and the publish sentences name steps the way the designer does. */
const stepNameMap = computed(() =>
    Object.fromEntries(
        (workflow.value?.nodes || [])
            .map((node) => [node.step_code || node.data?.step_code, node.name || node.data?.name || node.data?.label])
            .filter(([code, name]) => code && name)
    )
)

const openDesigner = () => router.push({ name: 'setting-workflows-manage', query: { open: workflow.value?.slug } })

// ---------------------------------------------------------------------------------------------------------------------
// Names for the answers
// ---------------------------------------------------------------------------------------------------------------------

const permissionNames = ref({})
fetchPermissionOptions()
    .then((rows) => (permissionNames.value = Object.fromEntries(rows.map((row) => [row.key, row.label || row.key]))))
    .catch(() => {})

const peopleNames = ref({})
watch(
    () => questions.value.filter((question) => question.kind === 'reviewers').flatMap((question) => answers[question.key]?.assignees || []).join('|'),
    async (uuids) => {
        const missing = uuids.split('|').filter((uuid) => uuid && !(uuid in peopleNames.value))
        if (!missing.length) return
        try {
            const rows = await resolveReviewers(missing)
            peopleNames.value = { ...peopleNames.value, ...Object.fromEntries(rows.map((row) => [row.uuid, row.name])) }
        } catch (error) {
            // The summary then counts the people instead of naming them.
        }
    },
    { immediate: true }
)

const credentials = reactive({ rows: [], loading: false, failed: false })
watch(
    () => questions.value.some((question) => question.kind === 'credential'),
    async (needed) => {
        if (!needed || credentials.rows.length || credentials.loading) return
        credentials.loading = true
        try {
            credentials.rows = await fetchCredentials()
        } catch (error) {
            credentials.failed = true
        } finally {
            credentials.loading = false
        }
    },
    { immediate: true }
)
const credentialOptions = (question) => {
    const options = credentials.rows.map((row) => ({ value: row.name, label: row.description ? `${row.name} · ${row.description}` : row.name }))
    const current = answers[question.key]
    if (current && !options.some((option) => option.value === current)) options.push({ value: current, label: current })
    return options
}

// ---------------------------------------------------------------------------------------------------------------------
// Schedule
// ---------------------------------------------------------------------------------------------------------------------

const scheduleChoices = [...SCHEDULE_CHOICES, { key: 'other' }]
const otherSchedule = reactive({ mode: 'daily', time: '08:00', day: 1 })
const otherChosen = ref(false)
const otherModes = computed(() => ['hourly', 'daily', 'weekly'].map((value) => ({ value, label: t(`text.workflow.setup.schedule_mode_${value}`) })))
const weekdays = computed(() => [1, 2, 3, 4, 5, 6, 0].map((value) => ({ value, label: t(`text.workflow.setup.weekday_${value}`) })))

const scheduleChoice = (question) => {
    const cron = answers[question.key]?.cron
    if (otherChosen.value) return 'other'
    if (typeof cron !== 'string') return undefined
    return SCHEDULE_CHOICES.find((choice) => choice.cron === cron)?.key || 'other'
}
const chooseSchedule = (question, key) => {
    otherChosen.value = key === 'other'
    const choice = SCHEDULE_CHOICES.find((row) => row.key === key)
    answers[question.key] = { ...answers[question.key], cron: choice ? choice.cron : cronFromSchedule(otherSchedule) ?? null }
}
const setOther = (question, patch) => {
    Object.assign(otherSchedule, patch)
    answers[question.key] = { ...answers[question.key], cron: cronFromSchedule(otherSchedule) ?? null }
}
// A stored time that is none of the ready choices opens as "another time".
watch(
    () => questions.value.find((question) => question.kind === 'schedule'),
    (question) => {
        const cron = question ? answers[question.key]?.cron : null
        otherChosen.value = false
        if (typeof cron !== 'string' || cron === '' || SCHEDULE_CHOICES.some((choice) => choice.cron === cron)) return
        const stored = scheduleFromCron(cron)
        if (['hourly', 'daily', 'weekly'].includes(stored.mode)) Object.assign(otherSchedule, { mode: stored.mode, time: stored.time, day: stored.day })
        otherChosen.value = true
    },
    { immediate: true }
)

const scheduleText = (cron) => {
    const choice = SCHEDULE_CHOICES.find((row) => row.cron === cron)
    if (choice) return t(`text.workflow.setup.schedule_${choice.key}`)
    const stored = scheduleFromCron(cron)
    if (stored.mode === 'hourly') return t('text.workflow.setup.schedule_mode_hourly')
    if (stored.mode === 'daily') return t('text.workflow.setup.schedule_daily_at', { time: stored.time })
    if (stored.mode === 'weekly') return t('text.workflow.setup.schedule_weekly_at', { day: t(`text.workflow.setup.weekday_${stored.day}`), time: stored.time })
    return t('text.workflow.setup.schedule_other')
}

/** The chosen answer in one line, in the operator's words (never a key or an id). */
const summaryOf = (question) => {
    const answer = answers[question.key]
    if (question.kind === 'reviewers') {
        const parts = []
        if (answer.permission) parts.push(t('text.workflow.setup.summary_group', { name: permissionNames.value[answer.permission] || answer.permission }))
        if (answer.assignees?.length) {
            const names = answer.assignees.map((uuid) => peopleNames.value[uuid]).filter(Boolean)
            parts.push(names.length === answer.assignees.length ? names.join(', ') : t('text.workflow.setup.summary_people', { count: answer.assignees.length }))
        }
        return parts.join(' · ')
    }
    if (question.kind === 'schedule') return scheduleText(answer.cron)
    if (question.kind === 'choice') return t(answer ? 'text.workflow.setup.choice_on' : 'text.workflow.setup.choice_off')
    if (question.kind === 'notify_channel' && question.field === 'permission') return t('text.workflow.setup.summary_group', { name: permissionNames.value[answer] || answer })
    return typeof answer === 'string' ? answer : t('text.workflow.setup.chosen')
}

// ---------------------------------------------------------------------------------------------------------------------
// Safe test run
// ---------------------------------------------------------------------------------------------------------------------

/** idle | running | failed | incomplete | none | completed | stopped */
const run = reactive({ state: 'idle', rows: [] })
async function testRun() {
    if (run.state === 'running' || !workflow.value?.id) return
    run.state = 'running'
    try {
        const report = await safeTestRun(workflow.value.id)
        run.rows = dryRunRows(report, stepNameMap.value)
        run.state = dryRunState(report)
    } catch (error) {
        run.state = setupIncomplete(error) ? 'incomplete' : 'failed'
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Save and turn on
// ---------------------------------------------------------------------------------------------------------------------

const saving = ref(false)
const saveError = ref('')
const publishOpen = ref(false)
const publishAnswer = ref(null)
/** '' | enabled | waiting */
const outcome = ref('')

async function enable() {
    try {
        const res = await updateWorkflow(workflow.value.id, { is_active: true })
        if (res?.status !== 'success') throw res
        outcome.value = 'enabled'
    } catch (error) {
        saveError.value = 'text.workflow.setup.enable_failed'
    }
}

async function saveAndEnable() {
    if (saving.value || !ready.value || !canUpdate.value) return
    saving.value = true
    saveError.value = ''
    problems.value = {}
    try {
        const changed = changedAnswers(questions.value, answers)
        if (Object.keys(changed).length) {
            const open = openKey.value
            takeSetup(await saveWorkflowSetup(workflow.value.id, changed))
            openKey.value = questions.value.some((question) => question.key === open && !answered(question)) ? open : openKey.value
        }
    } catch (error) {
        const rows = setupProblems(error)
        problems.value = Object.fromEntries(rows.filter((row) => row.key).map((row) => [row.key, row]))
        const first = rows.find((row) => row.key && questions.value.some((question) => question.key === row.key))
        if (first) openKey.value = first.key
        saveError.value = error?.response?.status === 403 ? 'text.workflow.guard.error.forbidden' : first ? 'text.workflow.setup.save_refused' : 'text.workflow.setup.save_failed'
        saving.value = false
        return
    }

    // Publishing goes through the usual dialog (what changes, a confirmation or a second person when it is sensitive).
    // A draft that is already what runs has nothing to publish: the workflow is switched on straight away.
    try {
        const preview = await previewPublish(workflow.value.id)
        if (preview?.unchanged) await enable()
        else {
            publishAnswer.value = null
            publishOpen.value = true
        }
    } catch (error) {
        saveError.value = error?.response?.status === 403 ? 'text.workflow.setup.publish_forbidden' : 'text.workflow.setup.save_failed'
    } finally {
        saving.value = false
    }
}

async function afterPublishDialog() {
    publishOpen.value = false
    const answer = publishAnswer.value
    if (!answer) return
    publishAnswer.value = null
    if (answer.path === 'published' || answer.path === 'unchanged') {
        saving.value = true
        await enable()
        saving.value = false
    } else {
        outcome.value = 'waiting'
    }
}
</script>
