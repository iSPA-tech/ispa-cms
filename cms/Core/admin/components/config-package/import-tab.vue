<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4" data-testid="config-package-import">
        <section class="i-box space-y-3">
            <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.import_title') }}</h4>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.import_hint') }}</p>

            <div v-if="!run" class="space-y-2">
                <!-- The native input is visually hidden: its own button text follows the browser language, not the admin's. -->
                <input
                    ref="fileInput"
                    type="file"
                    accept=".json,application/json"
                    class="sr-only"
                    :aria-label="t('text.config_package.import_choose_file')"
                    :disabled="loading"
                    data-testid="import-file"
                    @change="onFileChange"
                />
                <p v-if="file" class="m-0 break-words text-sm" data-testid="import-file-name">{{ file.name }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a-button v-if="!run" :disabled="loading" data-testid="import-pick" @click="fileInput && fileInput.click()">
                    {{ t('text.config_package.import_choose_file') }}
                </a-button>
                <a-button v-if="!run" type="primary" :disabled="!file || loading" :loading="loading" data-testid="import-check" @click="check">
                    {{ t('text.config_package.import_check') }}
                </a-button>
                <a-button v-if="run || error" :disabled="(loading && !finished) || (!!applyState && !applyFinished)" data-testid="import-reset" @click="reset">
                    {{ t('text.config_package.import_another') }}
                </a-button>
            </div>
        </section>

        <a-alert v-if="error" type="error" show-icon :message="t(error.title)" :description="t(error.hint)" data-testid="import-error">
            <template #action>
                <a-button v-if="file && !run" size="small" :disabled="loading" data-testid="import-retry" @click="check">
                    {{ t('text.config_package.export_retry') }}
                </a-button>
            </template>
        </a-alert>
        <section v-if="error && error.details.length" class="i-box space-y-1" data-testid="import-error-details">
            <p class="m-0 text-sm font-semibold">{{ t('text.config_package.import_error_details') }}</p>
            <ul class="m-0 space-y-1 pl-4 text-sm text-ink-muted">
                <li v-for="(detail, index) in error.details" :key="index" class="break-words">{{ detail }}</li>
            </ul>
        </section>

        <section v-if="run && !finished" class="i-box space-y-1" data-testid="import-progress">
            <a-spin />
            <p class="m-0 text-sm font-semibold">{{ t(`text.config_package.import_status_${run.status}`) }}</p>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.import_progress_wait') }}</p>
        </section>

        <template v-if="plan && run && run.status === 'succeeded'">
            <section class="i-box space-y-3" data-testid="import-summary">
                <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.import_summary_title') }}</h4>
                <a-tag class="m-0" data-testid="import-run-status">{{ t('text.config_package.import_status_succeeded') }}</a-tag>
                <div class="max-w-full overflow-x-auto">
                    <table class="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr>
                                <th class="p-2 font-semibold">{{ t('text.config_package.import_col_section') }}</th>
                                <th class="p-2 font-semibold">{{ t('text.config_package.import_col_result') }}</th>
                                <th v-for="action in ACTIONS" :key="action" class="p-2 font-semibold">{{ t(`text.config_package.action_${action}`) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.section" :data-testid="`import-summary-${row.section}`">
                                <td class="p-2">{{ labelOf(row.section) }}</td>
                                <td class="p-2">{{ t(`text.config_package.section_status_${row.status}`) }}</td>
                                <td v-for="action in ACTIONS" :key="action" class="p-2">{{ row.counts[action] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul v-if="plan.warnings.length" class="m-0 space-y-1 pl-4 text-sm text-ink-muted" data-testid="import-warnings">
                    <li v-for="(warning, index) in plan.warnings" :key="index" class="break-words">{{ describe(warning) }}</li>
                </ul>
            </section>

            <section class="i-box space-y-2" data-testid="import-secrets">
                <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.import_secrets_title') }}</h4>
                <p v-if="!secrets.alreadySet.length && !secrets.missing.length" class="m-0 text-sm text-ink-muted">
                    {{ t('text.config_package.import_secrets_none') }}
                </p>
                <div v-if="secrets.alreadySet.length" data-testid="import-secrets-set">
                    <p class="m-0 text-sm font-medium">{{ t('text.config_package.import_secrets_set') }}</p>
                    <ul class="m-0 pl-4 text-sm text-ink-muted">
                        <li v-for="key in secrets.alreadySet" :key="key" class="break-words">{{ secretLabel(secrets.labels, key) }}</li>
                    </ul>
                </div>
                <div v-if="secrets.missing.length" data-testid="import-secrets-missing">
                    <p class="m-0 text-sm font-medium">{{ t('text.config_package.import_secrets_missing') }}</p>
                    <ul class="m-0 pl-4 text-sm">
                        <li v-for="key in secrets.missing" :key="key" class="break-words">{{ secretLabel(secrets.labels, key) }}</li>
                    </ul>
                    <router-link v-if="hasEnvironmentRoute" :to="{ name: 'setting-environment' }" class="text-sm" data-testid="import-secrets-link">
                        {{ t('text.config_package.import_secrets_link') }}
                    </router-link>
                    <p v-else class="m-0 mt-1 text-sm text-ink-muted" data-testid="import-secrets-plain">{{ t('text.config_package.import_secrets_plain') }}</p>
                </div>
            </section>

            <section v-if="rows.length && !applyState" class="i-box space-y-3" data-testid="import-apply-box">
                <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.apply_choices_title') }}</h4>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.apply_choices_hint') }}</p>
                <ul class="m-0 list-none space-y-3 p-0">
                    <li v-for="row in rows" :key="row.section" class="min-w-0">
                        <fieldset class="m-0 min-w-0 border-0 p-0" :data-testid="`import-section-choice-${row.section}`">
                            <legend class="mb-1 break-words p-0 text-sm font-medium">{{ labelOf(row.section) }}</legend>
                            <p class="m-0 mb-1 break-words text-sm text-ink-muted">{{ choiceSummary(row) }}</p>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                <label v-for="option in CHOICES" :key="option" class="inline-flex items-center gap-1 text-sm">
                                    <input
                                        type="radio"
                                        :name="`import-choice-${row.section}`"
                                        :value="option"
                                        :checked="sectionChoices[row.section] === option"
                                        :disabled="row.status === 'skipped'"
                                        :data-testid="`import-section-choice-${row.section}-${option}`"
                                        @change="setSectionChoice(row.section, option)"
                                    />
                                    {{ t(`text.config_package.apply_choice_${option}`) }}
                                </label>
                            </div>
                        </fieldset>
                    </li>
                </ul>
                <p v-if="applyBlockedReason" class="m-0 text-sm text-ink-muted" role="status" data-testid="import-apply-why">
                    {{ t(`text.config_package.apply_blocked_${applyBlockedReason}`) }}
                </p>
                <a-button class="mt-1" type="primary" :disabled="!!applyBlockedReason || applying" data-testid="import-apply" @click="openConfirm">
                    {{ t('text.config_package.apply_button') }}
                </a-button>
            </section>

            <section v-if="rows.length" class="space-y-3">
                <div class="flex max-w-full gap-2 overflow-x-auto" role="group" :aria-label="t('text.config_package.import_col_section')" data-testid="import-sections">
                    <button
                        v-for="row in rows"
                        :key="row.section"
                        type="button"
                        class="whitespace-nowrap rounded border px-3 py-1 text-sm"
                        :class="row.section === plan.section ? 'font-semibold' : 'text-ink-muted'"
                        :aria-pressed="row.section === plan.section"
                        :disabled="loadingPlan"
                        :data-testid="`import-section-${row.section}`"
                        @click="loadPlan(row.section, 1)"
                    >
                        {{ labelOf(row.section) }}
                    </button>
                </div>

                <diff-section
                    v-if="plan.section"
                    :section="plan.section"
                    :overview="currentOverview"
                    :items="plan.items"
                    :more="currentMore"
                    :choices="plan.choices"
                    :selected="choices[plan.section] || {}"
                    @update:choices="(value) => (choices = { ...choices, [plan.section]: value })"
                />

                <div v-if="plan.last_page > 1" class="flex flex-wrap items-center gap-2" data-testid="import-pager">
                    <a-button size="small" :disabled="loadingPlan || plan.page <= 1" data-testid="import-prev" @click="loadPlan(plan.section, plan.page - 1)">
                        {{ t('text.config_package.import_prev') }}
                    </a-button>
                    <span class="text-sm text-ink-muted">{{ t('text.config_package.import_page', { page: plan.page, last: plan.last_page }) }}</span>
                    <a-button size="small" :disabled="loadingPlan || plan.page >= plan.last_page" data-testid="import-next" @click="loadPlan(plan.section, plan.page + 1)">
                        {{ t('text.config_package.import_next') }}
                    </a-button>
                </div>
            </section>
        </template>

        <a-modal :open="confirmOpen" :footer="false" :title="t('text.config_package.apply_confirm_title', { host })" :width="480" @cancel="closeConfirm">
            <div class="space-y-3" data-testid="import-apply-dialog">
                <p class="m-0 break-words text-sm">{{ t('text.config_package.apply_confirm_counts', counts) }}</p>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.apply_confirm_backup') }}</p>
                <label class="block space-y-1">
                    <span class="block break-words text-sm font-medium">{{ t('text.config_package.apply_confirm_host_label', { host }) }}</span>
                    <a-input v-model:value="typedHost" autocomplete="off" :maxlength="255" data-testid="import-apply-host" />
                </label>
                <div class="flex flex-wrap justify-end gap-2">
                    <a-button data-testid="import-apply-cancel" @click="closeConfirm">{{ t('text.config_package.apply_cancel') }}</a-button>
                    <a-button type="primary" :disabled="!hostMatches || applying" :loading="applying" data-testid="import-apply-confirm" @click="confirmApply">
                        {{ t('text.config_package.apply_confirm_ok') }}
                    </a-button>
                </div>
            </div>
        </a-modal>

        <a-alert v-if="applyError" type="error" show-icon :message="t(applyError.title)" :description="t(applyError.hint)" data-testid="import-apply-error" />

        <section v-if="applyState && !applyFinished" class="i-box space-y-1" data-testid="import-apply-progress">
            <a-spin />
            <p class="m-0 text-sm font-semibold">{{ applyProgressText }}</p>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.apply_progress_wait') }}</p>
        </section>

        <section v-if="applyState && applyFinished && applyResult" class="i-box space-y-3" data-testid="import-apply-result">
            <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.apply_result_title') }}</h4>
            <a-tag class="m-0" data-testid="import-apply-status">{{ t(`text.config_package.apply_status_${applyState.status}`) }}</a-tag>
            <p v-if="applyResult.backup" class="m-0 text-sm text-ink-muted">{{ t('text.config_package.apply_backup_done') }}</p>
            <div v-if="applyResult.rows.length" class="max-w-full overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm" data-testid="import-apply-table">
                    <thead>
                        <tr>
                            <th class="p-2 font-semibold">{{ t('text.config_package.import_col_section') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.import_col_result') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.apply_col_applied') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.action_unchanged') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.action_skipped') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.action_blocked') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in applyResult.rows" :key="row.section" :data-testid="`import-apply-row-${row.section}`">
                            <td class="p-2">{{ labelOf(row.section) }}</td>
                            <td class="p-2">{{ t(`text.config_package.apply_section_${row.status}`) }}</td>
                            <td class="p-2">{{ row.applied }}</td>
                            <td class="p-2">{{ row.unchanged }}</td>
                            <td class="p-2">{{ row.skipped }}</td>
                            <td class="p-2">{{ row.blocked }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="applyResult.blocked > 0" class="m-0 text-sm text-ink-muted" data-testid="import-apply-blocked-hint">
                {{ t('text.config_package.apply_blocked_hint') }}
            </p>
            <p v-if="applyResult.syncFailed.length" class="m-0 break-words text-sm text-ink-muted" data-testid="import-apply-sync-failed">
                {{ t('text.config_package.apply_sync_failed', { sections: applyResult.syncFailed.map(labelOf).join(', ') }) }}
            </p>
        </section>
    </div>
</template>

<script setup>
import { computed, defineComponent, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { applyRun, fetchRun, uploadPackage } from '@/libs/configPackageApi'
import { getBackgroundTasks, isTaskActive } from '@/libs/background-tasks'
import {
    ACTIONS,
    applyCounts,
    applyResultRows,
    buildApplyChoices,
    defaultSectionChoices,
    matchApplyError,
    matchMessage,
    missingSecretsList,
    secretLabel,
    summaryRows,
} from '../../config-package-diff.js'
import DiffSection from './diff-section.vue'

defineComponent({ name: 'ConfigPackageImportTab' })

// Import tab: upload one package file, follow the dry run through the background task list every POLL_MS, then show
// the plan per section. The operator chooses per section to apply or skip, confirms by typing the site host, and the
// apply run is followed the same way until it finishes (polling stops then and on unmount).
const POLL_MS = 3000
const MAX_BYTES = 20 * 1024 * 1024
const KNOWN_STATUSES = ['queued', 'running', 'succeeded', 'failed']
const CHOICES = ['apply', 'skip']

const { t, te } = useI18n()
/** Host of this site: the operator types it to confirm an apply. */
const host = typeof window !== 'undefined' ? window.location.host : ''
const router = useRouter()

const file = ref(null)
const fileInput = ref(null)
/** True from the click on Check until the dry run finished or failed. */
const loading = ref(false)
const run = ref(null)
const error = ref(null)
const plan = ref(null)
const loadingPlan = ref(false)
/** Operator choices: { [section]: { [itemKey]: boolean } }. */
const choices = ref({})
/** Apply or skip per section: { [section]: 'apply' | 'skip' }. */
const sectionChoices = ref({})
/** Item keys the operator has seen per section: { [section]: string[] }. */
const itemKeys = ref({})
const confirmOpen = ref(false)
const typedHost = ref('')
/** True from the confirmation until the apply request answered. */
const applying = ref(false)
/** The apply run: { uuid, task, status, message, params } or null. */
const applyState = ref(null)
const applyError = ref(null)
/** Result of the finished apply run: { rows, backup, blocked, syncFailed } or null. */
const applyResult = ref(null)

let timer = null
let applyTimer = null
let disposed = false

const finished = computed(() => !!run.value && !isTaskActive(run.value))
const rows = computed(() => summaryRows(plan.value?.sections))
const secrets = computed(() => missingSecretsList(plan.value?.required_secrets))
const applyFinished = computed(() => !!applyState.value && !isTaskActive(applyState.value))
const counts = computed(() => applyCounts(plan.value?.sections, sectionChoices.value))
const hostMatches = computed(() => typedHost.value.trim() !== '' && typedHost.value.trim() === host)
/** Why the apply button is off, as a key suffix; null when the package can be applied. */
const applyBlockedReason = computed(() => {
    if (secrets.value.missing.length) return 'secrets'
    if (counts.value.apply === 0) return 'nothing'
    return null
})
/** Progress line of the apply run: the task's own step message when it is a known key, else the status. */
const applyProgressText = computed(() => {
    const state = applyState.value
    if (state?.message && te(state.message)) return t(state.message, state.params || {})
    return t(`text.config_package.apply_status_${state?.status || 'queued'}`)
})
const hasEnvironmentRoute = computed(() => !!router && router.hasRoute('setting-environment'))
const currentOverview = computed(() => ({ ...(plan.value?.sections?.[plan.value.section] || {}), warnings: [] }))
const currentMore = computed(() => Number(plan.value?.sections?.[plan.value.section]?.more) || 0)

/**
 * Translated section label; an untranslated key falls back to a humanised name.
 *
 * @param {string} key Section key.
 * @returns {string}
 */
function labelOf(key) {
    const path = `text.config_package.section_${key}`
    if (te(path)) return t(path)
    const words = String(key).replace(/[_-]+/g, ' ').trim()
    return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * Translate a known server message (reason or warning); an unknown one is shown as sent.
 *
 * @param {string} text Server message.
 * @returns {string}
 */
function describe(text) {
    const match = matchMessage(text)
    if (!match) return text
    const params = { ...match.params }
    if (params.section) params.section = labelOf(params.section)
    const path = `text.config_package.${match.key}`
    return match.plural ? t(path, params, params.count) : t(path, params)
}

/**
 * Build an error state from translation keys.
 *
 * @param {string} name Error key suffix.
 * @param {string[]} [details] Technical detail lines.
 * @returns {{title: string, hint: string, details: string[]}}
 */
function errorState(name, details = []) {
    return { title: `text.config_package.import_error_${name}`, hint: `text.config_package.import_error_${name}_hint`, details }
}

/**
 * Detail lines of a refused package: each issue translated from its key and params, the English message when the key
 * is not known, or the plain message list sent by older servers.
 *
 * @param {{issues?: Array<{key?: string, params?: object, message?: string}>, errors?: string[]}|undefined} data Response data.
 * @returns {string[]}
 */
function issueLines(data) {
    if (Array.isArray(data?.issues) && data.issues.length) {
        return data.issues.map((issue) => (issue?.key && te(issue.key) ? t(issue.key, issue.params || {}) : String(issue?.message ?? '')))
    }
    return Array.isArray(data?.errors) ? data.errors.map(String) : []
}

/**
 * One line for the apply box: what applying this section would do.
 *
 * @param {{status: string, counts: Record<string, number>}} row Summary row of the section.
 * @returns {string}
 */
function choiceSummary(row) {
    if (row.status === 'skipped') return t('text.config_package.section_status_skipped')
    return t('text.config_package.apply_choice_summary', { changes: row.counts.create + row.counts.update, blocked: row.counts.blocked })
}

/**
 * Take the chosen file after checking type, size and count on the client.
 *
 * @param {Event} event Change event of the file input.
 */
function onFileChange(event) {
    const files = Array.from(event.target.files || [])
    error.value = null
    file.value = null
    if (!files.length) return
    if (files.length > 1) return (error.value = errorState('too_many'))
    const [picked] = files
    if (!/\.json$/i.test(picked.name || '')) return (error.value = errorState('type'))
    if (picked.size > MAX_BYTES) return (error.value = errorState('size'))
    file.value = picked
}

/**
 * Map a failed upload to an error state.
 *
 * @param {object} e Rejection from the API client.
 * @returns {{title: string, hint: string, details: string[]}}
 */
function uploadError(e) {
    const status = e?.response?.status
    const body = e?.response?.data || {}
    // The API sends the code as `error_code`; `errorCode` is accepted too.
    const code = body.error_code || body.errorCode
    if (code === 'CONFIG_PACKAGE_INVALID_JSON') return errorState('json')
    if (code === 'CONFIG_PACKAGE_INVALID') {
        return errorState('invalid', issueLines(body.data))
    }
    if (status === 413) return errorState('size')
    if (status === 422 && body.errors?.file) return errorState('file')
    return errorState('upload')
}

/** Stop the pending poll, if any. */
function stopPolling() {
    if (timer) clearTimeout(timer)
    timer = null
}

/** Schedule the next status check unless the component is gone. */
function schedulePoll() {
    stopPolling()
    if (disposed) return
    timer = setTimeout(poll, POLL_MS)
}

/**
 * Read the run status from the background task, falling back to the run itself; null when unreadable.
 *
 * @returns {Promise<string|null>}
 */
async function readStatus() {
    const current = run.value
    try {
        if (current.task) {
            const task = (await getBackgroundTasks()).find((item) => item.uuid === current.task)
            if (task) return task.status
        }
        return (await fetchRun(current.uuid))?.data?.run?.status || null
    } catch (e) {
        return null
    }
}

/** One poll tick: update the status and load the plan when the dry run succeeded. */
async function poll() {
    timer = null
    if (disposed || !run.value) return
    const status = await readStatus()
    if (disposed || !run.value) return
    if (KNOWN_STATUSES.includes(status)) run.value = { ...run.value, status }
    if (status === null || isTaskActive(run.value)) return schedulePoll()
    loading.value = false
    if (status === 'failed') {
        error.value = errorState('failed')
        return
    }
    await loadPlan(null, 1)
}

/**
 * Load one page of the plan of the current run.
 *
 * @param {string|null} section Section name; null for the first section.
 * @param {number} page Page number.
 */
async function loadPlan(section, page) {
    if (loadingPlan.value || !run.value) return
    loadingPlan.value = true
    try {
        const params = { page }
        if (section) params.section = section
        const body = (await fetchRun(run.value.uuid, params))?.data || {}
        if (disposed) return
        const next = body.plan
        if (!next) throw new Error('no plan')
        plan.value = {
            sections: next.sections || {},
            section: next.section || null,
            items: Array.isArray(next.items) ? next.items : [],
            page: next.page || 1,
            last_page: next.last_page || 1,
            warnings: Array.isArray(next.warnings) ? next.warnings.map(String) : [],
            required_secrets: next.required_secrets || null,
            choices: Array.isArray(next.choices) ? next.choices : [],
        }
        // Defaults are set once per dry run; later pages must not undo the operator's choice.
        if (!Object.keys(sectionChoices.value).length) sectionChoices.value = defaultSectionChoices(plan.value.sections)
        if (plan.value.section) {
            const known = new Set(itemKeys.value[plan.value.section] || [])
            for (const item of plan.value.items) if (item?.key) known.add(String(item.key))
            itemKeys.value = { ...itemKeys.value, [plan.value.section]: [...known] }
        }
    } catch (e) {
        if (!disposed) error.value = errorState('plan')
    } finally {
        if (!disposed) loadingPlan.value = false
    }
}

/** Upload the file and start following the dry run. */
async function check() {
    if (loading.value || !file.value) return
    loading.value = true
    error.value = null
    plan.value = null
    run.value = null
    try {
        const body = (await uploadPackage(file.value))?.data || {}
        if (disposed) return
        run.value = { uuid: body.run, task: body.task || null, status: KNOWN_STATUSES.includes(body.status) ? body.status : 'queued' }
        schedulePoll()
    } catch (e) {
        if (disposed) return
        loading.value = false
        error.value = uploadError(e)
    }
}

/**
 * Record the operator's apply/skip choice of one section.
 *
 * @param {string} section Section name.
 * @param {'apply'|'skip'} option Choice.
 */
function setSectionChoice(section, option) {
    sectionChoices.value = { ...sectionChoices.value, [section]: option }
}

/** Open the confirmation unless the package cannot be applied. */
function openConfirm() {
    if (applyBlockedReason.value || applying.value) return
    typedHost.value = ''
    confirmOpen.value = true
}

/** Close the confirmation without applying. */
function closeConfirm() {
    if (applying.value) return
    confirmOpen.value = false
    typedHost.value = ''
}

/** Stop the pending apply poll, if any. */
function stopApplyPolling() {
    if (applyTimer) clearTimeout(applyTimer)
    applyTimer = null
}

/** Schedule the next apply status check unless the component is gone. */
function scheduleApplyPoll() {
    stopApplyPolling()
    if (disposed) return
    applyTimer = setTimeout(pollApply, POLL_MS)
}

/** Fill the result table from the finished apply run; an error message maps to a translated sentence. */
async function loadApplyResult() {
    try {
        const record = (await fetchRun(applyState.value.uuid))?.data?.run || {}
        if (disposed) return
        const summary = record.summary || {}
        if (record.status === 'failed' || summary.error) {
            const name = matchApplyError(summary.error)
            applyError.value = { title: `text.config_package.${name}`, hint: `text.config_package.${name}_hint` }
        }
        applyState.value = { ...applyState.value, status: KNOWN_STATUSES.includes(record.status) ? record.status : applyState.value.status }
        const rows = applyResultRows(summary)
        applyResult.value = {
            rows,
            backup: !!(summary.backup_ref || record.backup_ref),
            blocked: rows.reduce((total, row) => total + row.blocked, 0),
            syncFailed: Array.isArray(summary.sync_failed) ? summary.sync_failed.map(String) : [],
        }
    } catch (e) {
        if (!disposed) {
            applyError.value = { title: 'text.config_package.apply_error_failed', hint: 'text.config_package.apply_error_failed_hint' }
            applyResult.value = { rows: [], backup: false, blocked: 0, syncFailed: [] }
        }
    }
}

/** One apply poll tick: update the status and progress, load the result when the run is no longer active. */
async function pollApply() {
    applyTimer = null
    if (disposed || !applyState.value) return
    let status = null
    try {
        const task = applyState.value.task ? (await getBackgroundTasks()).find((item) => item.uuid === applyState.value.task) : null
        if (task) {
            status = task.status
            applyState.value = { ...applyState.value, message: task.message || null, params: task.message_params || null }
        } else {
            status = (await fetchRun(applyState.value.uuid))?.data?.run?.status || null
        }
    } catch (e) {
        status = null
    }
    if (disposed || !applyState.value) return
    if (KNOWN_STATUSES.includes(status)) applyState.value = { ...applyState.value, status }
    if (status === null || isTaskActive(applyState.value)) return scheduleApplyPoll()
    await loadApplyResult()
}

/**
 * Translated error state of a failed apply request: a refused apply (409) names the stale check, everything else the
 * generic start failure. Server text is never shown.
 *
 * @param {object} e Rejection from the API client.
 * @returns {{title: string, hint: string}}
 */
function applyRequestError(e) {
    const body = e?.response?.data || {}
    const code = body.error_code || body.errorCode
    if (e?.response?.status === 409 || code === 'CONFIG_PACKAGE_APPLY_REFUSED') {
        return { title: 'text.config_package.apply_error_refused', hint: 'text.config_package.apply_error_refused_hint' }
    }
    return { title: 'text.config_package.apply_error_start', hint: 'text.config_package.apply_error_start_hint' }
}

/** Send the apply request after the host was typed, then follow the run. */
async function confirmApply() {
    if (applying.value || !hostMatches.value || !run.value || applyBlockedReason.value) return
    applying.value = true
    applyError.value = null
    try {
        const body = (await applyRun(run.value.uuid, buildApplyChoices(sectionChoices.value, itemKeys.value)))?.data || {}
        if (disposed) return
        applyState.value = {
            uuid: body.run,
            task: body.task || null,
            status: KNOWN_STATUSES.includes(body.status) ? body.status : 'queued',
            message: null,
            params: null,
        }
        confirmOpen.value = false
        typedHost.value = ''
        // A run that is already finished (immediate queue) shows its result at once instead of after the first tick.
        if (isTaskActive(applyState.value)) scheduleApplyPoll()
        else await loadApplyResult()
    } catch (e) {
        if (disposed) return
        confirmOpen.value = false
        applyError.value = applyRequestError(e)
    } finally {
        if (!disposed) applying.value = false
    }
}

/** Forget the file, run, plan and choices so another file can be chosen. */
function reset() {
    stopPolling()
    stopApplyPolling()
    sectionChoices.value = {}
    itemKeys.value = {}
    confirmOpen.value = false
    typedHost.value = ''
    applying.value = false
    applyState.value = null
    applyError.value = null
    applyResult.value = null
    loading.value = false
    file.value = null
    run.value = null
    plan.value = null
    error.value = null
    choices.value = {}
    if (fileInput.value) fileInput.value.value = ''
}

onBeforeUnmount(() => {
    disposed = true
    stopPolling()
    stopApplyPolling()
})
</script>
