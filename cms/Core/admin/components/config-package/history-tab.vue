<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4" data-testid="config-package-history">
        <section class="i-box space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0 space-y-1">
                    <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.history_title') }}</h4>
                    <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.history_hint') }}</p>
                </div>
                <a-button :disabled="loading" data-testid="history-reload" @click="load">
                    {{ t('text.config_package.history_reload') }}
                </a-button>
            </div>

            <div v-if="loading" class="space-y-2 py-2" role="status" data-testid="history-loading">
                <a-skeleton active :paragraph="{ rows: 4 }" />
                <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.history_loading') }}</p>
            </div>
            <a-alert
                v-else-if="loadError"
                type="error"
                show-icon
                :message="t('text.config_package.history_error')"
                :description="t('text.config_package.history_error_hint')"
                data-testid="history-error"
            >
                <template #action>
                    <a-button size="small" data-testid="history-retry" @click="load">
                        {{ t('text.config_package.export_retry') }}
                    </a-button>
                </template>
            </a-alert>
            <a-empty v-else-if="!rows.length" :description="t('text.config_package.history_empty')" data-testid="history-empty" />
            <div v-else class="max-w-full overflow-x-auto" data-testid="history-table-wrap">
                <table class="w-full border-collapse text-left text-sm" data-testid="history-table">
                    <thead>
                        <tr>
                            <th class="whitespace-nowrap p-2 font-semibold">{{ t('text.config_package.history_col_kind') }}</th>
                            <th class="whitespace-nowrap p-2 font-semibold">{{ t('text.config_package.history_col_when') }}</th>
                            <th class="whitespace-nowrap p-2 font-semibold">{{ t('text.config_package.history_col_status') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.import_col_section') }}</th>
                            <th class="p-2 font-semibold">{{ t('text.config_package.import_col_result') }}</th>
                            <th class="p-2"><span class="sr-only">{{ t('text.config_package.history_col_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.uuid" class="align-top" :data-testid="`history-row-${row.uuid}`">
                            <td class="whitespace-nowrap p-2">{{ t(`text.config_package.kind_${row.kind}`) }}</td>
                            <td class="whitespace-nowrap p-2">{{ row.when }}</td>
                            <td class="p-2"><a-tag class="m-0">{{ t(`text.config_package.history_status_${row.status}`) }}</a-tag></td>
                            <td class="max-w-[14rem] break-words p-2">{{ row.sections }}</td>
                            <td class="max-w-[14rem] break-words p-2 text-ink-muted">{{ row.result }}</td>
                            <td class="p-2">
                                <div class="flex flex-wrap gap-2">
                                    <a-button
                                        v-if="row.canDownload"
                                        size="small"
                                        :loading="downloading === row.uuid"
                                        :disabled="!!downloading"
                                        :data-testid="`history-download-${row.uuid}`"
                                        @click="download(row.uuid)"
                                    >
                                        {{ t('text.config_package.export_download') }}
                                    </a-button>
                                    <a-button v-if="row.canView" size="small" :data-testid="`history-view-${row.uuid}`" @click="view(row)">
                                        {{ t('text.config_package.history_view') }}
                                    </a-button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <a-alert v-if="downloadError" type="error" show-icon :message="t('text.config_package.export_error_download')" :description="t('text.config_package.history_download_error_hint')" data-testid="history-download-error" />

        <section v-if="viewed" class="i-box space-y-3" data-testid="history-detail">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="m-0 text-sm font-semibold">{{ t('text.config_package.history_detail_title', { kind: t(`text.config_package.kind_${viewed.kind}`), when: viewed.when }) }}</h4>
                <a-button size="small" data-testid="history-detail-close" @click="viewed = null">{{ t('text.config_package.history_close') }}</a-button>
            </div>
            <a-empty v-if="!viewed.detail.rows.length" :description="t('text.config_package.history_detail_none')" />
            <div v-else class="max-w-full overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm" data-testid="history-detail-table">
                    <thead>
                        <tr>
                            <th class="p-2 font-semibold">{{ t('text.config_package.import_col_section') }}</th>
                            <template v-if="viewed.detail.layout === 'apply'">
                                <th class="p-2 font-semibold">{{ t('text.config_package.import_col_result') }}</th>
                                <th class="p-2 font-semibold">{{ t('text.config_package.apply_col_applied') }}</th>
                                <th class="p-2 font-semibold">{{ t('text.config_package.action_unchanged') }}</th>
                                <th class="p-2 font-semibold">{{ t('text.config_package.action_skipped') }}</th>
                                <th class="p-2 font-semibold">{{ t('text.config_package.action_blocked') }}</th>
                            </template>
                            <template v-else-if="viewed.detail.layout === 'check'">
                                <th v-for="action in ACTIONS" :key="action" class="p-2 font-semibold">{{ t(`text.config_package.action_${action}`) }}</th>
                            </template>
                            <th v-else class="p-2 font-semibold">{{ t('text.config_package.history_col_items') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in viewed.detail.rows" :key="item.section" :data-testid="`history-detail-row-${item.section}`">
                            <td class="p-2">{{ labelOf(item.section) }}</td>
                            <template v-if="viewed.detail.layout === 'apply'">
                                <td class="p-2">{{ t(`text.config_package.apply_section_${item.status}`) }}</td>
                                <td class="p-2">{{ item.applied }}</td>
                                <td class="p-2">{{ item.unchanged }}</td>
                                <td class="p-2">{{ item.skipped }}</td>
                                <td class="p-2">{{ item.blocked }}</td>
                            </template>
                            <template v-else-if="viewed.detail.layout === 'check'">
                                <td v-for="action in ACTIONS" :key="action" class="p-2">{{ item.counts[action] }}</td>
                            </template>
                            <td v-else class="p-2">{{ item.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<script setup>
import { computed, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchRuns } from '@/libs/configPackageApi'
import { ACTIONS, describeRun } from '../../config-package-diff.js'
import { downloadPackage } from '../../config-package-download.js'

defineComponent({ name: 'ConfigPackageHistoryTab' })

// History tab: the signed-in Webmaster's last runs (exports, checks, imports), newest first. Exports that finished can
// be downloaded again; checks and imports open a small result table. Nothing here changes data.
const PAGE_SIZE = 50
const KINDS = ['export', 'dry_run', 'apply']
const STATUSES = ['queued', 'running', 'succeeded', 'failed']

const { t, te, locale } = useI18n()

const runs = ref([])
const loading = ref(true)
const loadError = ref(false)
/** Uuid of the export being downloaded, or null. */
const downloading = ref(null)
const downloadError = ref(false)
/** Run whose result table is open: { kind, when, detail } or null. */
const viewed = ref(null)

let disposed = false

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
 * Date and time of a run in the admin language.
 *
 * @param {string|null|undefined} iso ISO 8601 time.
 * @returns {string}
 */
function whenOf(iso) {
    const date = iso ? new Date(iso) : null
    if (!date || Number.isNaN(date.getTime())) return ''
    return date.toLocaleString(locale?.value || undefined)
}

/**
 * One-line result of a run for the list.
 *
 * @param {object} run Run record.
 * @returns {string}
 */
function resultOf(run) {
    if (run.status !== 'succeeded') return ''
    const { layout, headline } = describeRun(run)
    if (layout === 'apply') return t('text.config_package.history_result_apply', headline)
    if (layout === 'check') return t('text.config_package.history_result_check', headline)
    return t('text.config_package.history_result_export', { count: headline.count }, headline.count)
}

const rows = computed(() =>
    runs.value.map((run) => {
        const kind = KINDS.includes(run.kind) ? run.kind : 'export'
        const status = STATUSES.includes(run.status) ? run.status : 'failed'
        return {
            uuid: String(run.uuid),
            kind,
            status,
            when: whenOf(run.created_at),
            sections: (Array.isArray(run.sections) ? run.sections : []).map(labelOf).join(', '),
            result: resultOf({ ...run, kind, status }),
            // A download needs the stored package; it is gone once the run expired.
            canDownload: kind === 'export' && status === 'succeeded' && run.has_package !== false,
            canView: kind !== 'export' && status === 'succeeded',
            run,
        }
    }),
)

/** Load the last runs; a failure shows a retry state. */
async function load() {
    loading.value = true
    loadError.value = false
    try {
        const body = await fetchRuns({ per_page: PAGE_SIZE })
        if (disposed) return
        // The paginated admin response wraps the list twice: { data: { data: [...], meta } }.
        const list = Array.isArray(body?.data) ? body.data : body?.data?.data
        runs.value = Array.isArray(list) ? list.slice(0, PAGE_SIZE) : []
    } catch (e) {
        if (!disposed) loadError.value = true
    } finally {
        if (!disposed) loading.value = false
    }
}

/**
 * Download the package of a finished export again.
 *
 * @param {string} uuid Run uuid.
 */
async function download(uuid) {
    if (downloading.value) return
    downloading.value = uuid
    downloadError.value = false
    try {
        await downloadPackage(uuid)
    } catch (e) {
        if (!disposed) downloadError.value = true
    } finally {
        if (!disposed) downloading.value = null
    }
}

/**
 * Open the result table of a check or an import.
 *
 * @param {{kind: string, when: string, run: object}} row List row.
 */
function view(row) {
    viewed.value = { kind: row.kind, when: row.when, detail: describeRun(row.run) }
}

onMounted(load)
onBeforeUnmount(() => {
    disposed = true
})
</script>
