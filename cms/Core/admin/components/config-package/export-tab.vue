<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4" data-testid="config-package-export">
        <section class="i-box space-y-3">
            <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.export_sections_title') }}</h4>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.export_sections_hint') }}</p>

            <div v-if="loadingSections" class="py-4 text-center" data-testid="export-loading">
                <a-spin />
                <p class="m-0 mt-2 text-sm text-ink-muted">{{ t('text.config_package.export_loading') }}</p>
            </div>
            <a-alert
                v-else-if="loadError"
                type="error"
                show-icon
                :message="t('text.config_package.export_load_error')"
                :description="t('text.config_package.export_load_error_hint')"
                data-testid="export-load-error"
            >
                <template #action>
                    <a-button size="small" data-testid="export-retry" @click="loadSections">
                        {{ t('text.config_package.export_retry') }}
                    </a-button>
                </template>
            </a-alert>
            <a-empty v-else-if="!sections.length" :description="t('text.config_package.export_no_sections')" />
            <ul v-else class="m-0 list-none space-y-2 p-0" data-testid="export-sections">
                <li v-for="section in sections" :key="section.key" class="min-w-0">
                    <a-checkbox
                        :checked="selected.includes(section.key)"
                        :disabled="loading"
                        @update:checked="(on) => toggle(section.key, on)"
                    >
                        <span class="break-words">{{ sectionLabel(section.key) }}</span>
                        <span class="text-ink-muted"> · {{ t('text.config_package.export_count', { count: section.count ?? 0 }, section.count ?? 0) }}</span>
                    </a-checkbox>
                </li>
            </ul>

            <div v-if="selected.includes('translations') && scopes.length" class="space-y-1">
                <label class="block text-sm font-medium">{{ t('text.config_package.export_scope_label') }}</label>
                <a-select v-model:value="scope" class="w-full max-w-xs" :disabled="loading">
                    <a-select-option v-for="item in scopes" :key="item" :value="item">
                        {{ scopeLabel(item) }}
                    </a-select-option>
                </a-select>
            </div>

            <p v-if="!loadingSections && !loadError && sections.length && !selected.length" class="m-0 text-sm text-ink-muted" data-testid="export-empty">
                {{ t('text.config_package.export_select_none') }}
            </p>

            <a-button type="primary" :disabled="!canStart" :loading="loading && !run" data-testid="export-start" @click="start">
                {{ t('text.config_package.export_start') }}
            </a-button>
        </section>

        <a-alert
            v-if="error"
            type="error"
            show-icon
            :message="t(error.title)"
            :description="t(error.hint)"
            data-testid="export-error"
        />

        <section v-if="run" class="i-box flex flex-wrap items-center justify-between gap-3" data-testid="export-run">
            <div class="min-w-0 space-y-1">
                <p class="m-0 text-sm font-semibold">{{ t('text.config_package.export_run_title') }}</p>
                <a-tag class="m-0">{{ t(`text.config_package.status_${run.status}`) }}</a-tag>
                <p v-if="active" class="m-0 text-sm text-ink-muted">{{ t('text.config_package.export_run_wait') }}</p>
            </div>
            <a-button v-if="run.status === 'succeeded'" :loading="downloading" :disabled="downloading" data-testid="export-download" @click="download">
                {{ t('text.config_package.export_download') }}
            </a-button>
        </section>

        <a-alert
            type="info"
            show-icon
            :message="t('text.config_package.left_out_title')"
            :description="t('text.config_package.left_out_text')"
            data-testid="export-left-out"
        />
    </div>
</template>

<script setup>
import { computed, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchRun, fetchSections, startExport } from '@/libs/configPackageApi'
import { getBackgroundTasks, isTaskActive } from '@/libs/background-tasks'
import { downloadPackage } from '../../config-package-download.js'

defineComponent({ name: 'ConfigPackageExportTab' })

// Export tab of the configuration packages page. The Webmaster picks sections, starts an export and watches it:
// the run is followed through the background task list (same source as the header task bar) every POLL_MS until it
// finishes. Polling stops when the run finishes and on unmount. Download is an authenticated blob request.
const POLL_MS = 3000
const KNOWN_STATUSES = ['queued', 'running', 'succeeded', 'failed']

const { t, te } = useI18n()

/** Sections from the API: [{ key, count }]. */
const sections = ref([])
const scopes = ref([])
const loadingSections = ref(true)
const loadError = ref(false)
const selected = ref([])
const scope = ref('web')
/** True from the click on Export until the run finished or the request failed (reset only then). */
const loading = ref(false)
/** Current run: { uuid, task, status } or null. */
const run = ref(null)
const error = ref(null)
const downloading = ref(false)

let timer = null
let disposed = false

const active = computed(() => !!run.value && isTaskActive(run.value))
const canStart = computed(() => !loading.value && !loadingSections.value && !loadError.value && selected.value.length > 0)

/**
 * Translated label of a section; an untranslated key falls back to a humanised name.
 *
 * @param {string} key Section key.
 * @returns {string}
 */
function sectionLabel(key) {
    const path = `text.config_package.section_${key}`
    if (te(path)) return t(path)
    const words = String(key).replace(/[_-]+/g, ' ').trim()
    return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * Translated label of a translation scope; an untranslated one falls back to the raw value.
 *
 * @param {string} item Scope value.
 * @returns {string}
 */
function scopeLabel(item) {
    const path = `text.config_package.scope_${item}`
    return te(path) ? t(path) : item
}

/**
 * Add or remove a section from the selection.
 *
 * @param {string} key Section key.
 * @param {boolean} on Whether the section is now checked.
 */
function toggle(key, on) {
    selected.value = on ? [...new Set([...selected.value, key])] : selected.value.filter((item) => item !== key)
}

/** Load the section list; failures show a retry state. */
async function loadSections() {
    loadingSections.value = true
    loadError.value = false
    try {
        // The API client returns the response body; the admin API wraps the payload in `data`.
        const body = (await fetchSections())?.data || {}
        if (disposed) return
        sections.value = Array.isArray(body.sections) ? body.sections : []
        scopes.value = Array.isArray(body.translation_scopes) ? body.translation_scopes : []
        if (scopes.value.length && !scopes.value.includes(scope.value)) scope.value = scopes.value[0]
    } catch (e) {
        if (!disposed) loadError.value = true
    } finally {
        if (!disposed) loadingSections.value = false
    }
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
 * Read the run status: from the background task list when the run has a task, from the run itself otherwise or
 * when the task is not listed. Returns null when it cannot be read (the next tick tries again).
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

/** One poll tick: update the status, finish the run when it is no longer active. */
async function poll() {
    timer = null
    if (disposed || !run.value) return
    const status = await readStatus()
    if (disposed || !run.value) return
    if (KNOWN_STATUSES.includes(status)) run.value = { ...run.value, status }
    if (active.value || status === null) return schedulePoll()
    loading.value = false
    if (status === 'failed') error.value = { title: 'text.config_package.export_error_failed', hint: 'text.config_package.export_error_failed_hint' }
}

/** Start an export of the selected sections. */
async function start() {
    if (loading.value || !selected.value.length) return
    loading.value = true
    error.value = null
    run.value = null
    try {
        const body =
            (
                await startExport({
                    sections: [...selected.value],
                    translation_scope: scope.value,
                })
            )?.data || {}
        if (disposed) return
        run.value = { uuid: body.run, task: body.task || null, status: KNOWN_STATUSES.includes(body.status) ? body.status : 'queued' }
        schedulePoll()
    } catch (e) {
        if (disposed) return
        loading.value = false
        error.value = { title: 'text.config_package.export_error_start', hint: 'text.config_package.export_error_start_hint' }
    }
}

/** Download the finished package as an authenticated blob and save it. */
async function download() {
    if (downloading.value || !run.value) return
    downloading.value = true
    error.value = null
    try {
        await downloadPackage(run.value.uuid)
    } catch (e) {
        error.value = { title: 'text.config_package.export_error_download', hint: 'text.config_package.export_error_download_hint' }
    } finally {
        downloading.value = false
    }
}

onMounted(loadSections)
onBeforeUnmount(() => {
    disposed = true
    stopPolling()
})
</script>
