<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-modal
        v-model:open="visible"
        :footer="null"
        :title="t('text.import.title', { entity: entityLabel })"
        destroy-on-close
        width="900px"
        @cancel="handleClose"
    >
        <div class="space-y-6">
            <!-- Download Template Section -->
            <div class="border rounded-lg p-4 bg-slate-50">
                <h3 class="text-sm font-semibold text-slate-700 mb-3 flex items-center gap-2">
                    <i-icon class="size-4 text-blue-600" icon="download" />
                    {{ t('text.import.download_template') }}
                </h3>
                <p class="text-xs text-slate-600 mb-3">
                    {{ t('text.import.choose_format') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-for="format in formats"
                        :key="format"
                        :download="`${entity}_template.${format}`"
                        :href="getTemplateUrl(format)"
                        class="inline-flex items-center gap-2 px-3 py-2 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-100 transition-colors"
                        target="_blank"
                    >
                        <i-icon :icon="getFormatIcon(format)" class="size-4" />
                        {{ t('text.import.template_format', { format: format.toUpperCase() }) }}
                    </a>
                </div>
            </div>

            <!-- Import Notes Section -->
            <div v-if="metadata" class="border rounded-lg p-4 bg-blue-50">
                <h3 class="text-sm font-semibold text-slate-700 mb-2 flex items-center gap-2">
                    <i-icon class="size-4 text-blue-600" icon="information" />
                    {{ t('text.import.notes_title') }}
                </h3>
                <p class="text-xs text-slate-600 mb-3">
                    {{ t('text.import.notes_hint') }}
                </p>
                <div v-if="metadataLoading" class="text-xs text-slate-500">
                    {{ t('text.import.loading_metadata') }}
                </div>
                <div v-else-if="metadataError" class="text-xs text-red-600">
                    {{ metadataError }}
                </div>
                <div v-else class="max-h-64 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-100 sticky top-0">
                            <tr>
                                <th class="text-left p-2 font-semibold">
                                    {{ t('text.import.col_column') }}
                                </th>
                                <th class="text-left p-2 font-semibold">{{ t('text.label') }}</th>
                                <th class="text-left p-2 font-semibold">
                                    {{ t('text.import.col_notes') }}
                                </th>
                                <th class="text-left p-2 font-semibold">
                                    {{ t('text.import.col_mapping') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="header in metadata.headers"
                                :key="header"
                                class="border-b border-slate-200 hover:bg-white"
                            >
                                <td class="p-2 font-mono text-slate-800">{{ header }}</td>
                                <td class="p-2 text-slate-700">{{ metadata.labels?.[header] || '—' }}</td>
                                <td class="p-2 text-slate-600">{{ metadata.notes[header] || '—' }}</td>
                                <td class="p-2 text-slate-500">
                                    {{ getMappingHint(header) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Upload Section -->
            <div class="border rounded-lg p-4">
                <h3 class="text-sm font-semibold text-slate-700 mb-3 flex items-center gap-2">
                    <i-icon class="size-4 text-green-600" icon="upload" />
                    {{ t('text.import.upload_file') }}
                </h3>
                <a-upload
                    v-model:file-list="fileList"
                    :before-upload="beforeUpload"
                    :max-count="1"
                    accept=".csv,.txt,.xls,.xlsx"
                    @remove="handleRemove"
                >
                    <a-button>
                        <i-icon class="size-4 mr-1" icon="file-upload" />
                        {{ t('text.import.select_file') }}
                    </a-button>
                </a-upload>
                <p v-if="uploadError" class="text-xs text-red-600 mt-2">{{ uploadError }}</p>
            </div>

            <!-- Error Alert -->
            <div v-if="importError" class="border border-red-300 rounded-lg p-4 bg-red-50">
                <div class="flex items-start gap-3">
                    <i-icon class="size-5 text-red-600 mt-0.5 shrink-0" icon="alert-circle" />
                    <div class="flex-1">
                        <h4 class="text-sm font-semibold text-red-800 mb-1">
                            {{ t('text.import.error_title') }}
                        </h4>
                        <p class="text-xs text-red-700 whitespace-pre-wrap">{{ importError }}</p>
                    </div>
                    <a-button size="small" type="text" @click="importError = null">
                        <i-icon class="size-4 text-red-600" icon="close" />
                    </a-button>
                </div>
            </div>

            <!-- Import Actions -->
            <div class="flex items-center gap-3">
                <a-button
                    :disabled="!selectedFile || importing"
                    :loading="importing && isDryRun"
                    type="default"
                    @click="handleImport(true)"
                >
                    <i-icon class="size-4 mr-1" icon="eye-outline" />
                    {{ t('text.import.dry_run_preview') }}
                </a-button>
                <a-button
                    :disabled="
                        !selectedFile ||
                        importing ||
                        (!hasPreview && requirePreview) ||
                        summary.created === 0
                    "
                    :loading="importing && !isDryRun"
                    type="primary"
                    @click="handleImport(false)"
                >
                    <i-icon class="size-4 mr-1" icon="database-import" />
                    {{ t('text.import.commit') }}
                </a-button>
                <span v-if="!hasPreview && requirePreview" class="text-xs text-amber-600">
                    {{ t('text.import.run_dry_run_first') }}
                </span>
            </div>

            <!-- Results Section -->
            <div v-if="importResult" class="border rounded-lg p-4 bg-slate-50">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-slate-700 flex items-center gap-2">
                        <i-icon class="size-4 text-purple-600" icon="chart-box" />
                        {{ t('text.import.results_title') }}
                        <a-tag v-if="importResult.dry_run" class="ml-2" color="orange">
                            <i-icon class="size-3 mr-1" icon="eye-outline" />
                            {{ t('text.import.dry_run_tag') }}
                        </a-tag>
                        <a-tag v-else class="ml-2" color="green">
                            <i-icon class="size-3 mr-1" icon="check-circle" />
                            {{ t('text.import.committed_tag') }}
                        </a-tag>
                    </h3>
                    <a-button v-if="hasErrors" size="small" type="link" @click="downloadErrors">
                        <i-icon class="size-3 mr-1" icon="download" />
                        {{ t('text.import.download_errors') }}
                    </a-button>
                </div>

                <!-- File & Timing Info -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-white rounded-md p-3 border">
                        <div class="flex items-center gap-2 mb-2">
                            <i-icon class="size-4 text-blue-600" icon="file-document" />
                            <span class="text-xs font-semibold text-slate-700">
                                {{ t('text.import.file_info') }}
                            </span>
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ t('text.import.file_name') }}</span>
                                <span class="font-medium text-slate-800">{{
                                    importResult.fileName || t('text.import.not_available')
                                }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ t('text.import.file_size') }}</span>
                                <span class="font-medium text-slate-800">{{
                                    formatFileSize(importResult.fileSize)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ t('text.import.file_type') }}</span>
                                <span class="font-medium text-slate-800 uppercase">{{
                                    importResult.fileExtension || t('text.import.not_available')
                                }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-md p-3 border">
                        <div class="flex items-center gap-2 mb-2">
                            <i-icon class="size-4 text-purple-600" icon="clock-outline" />
                            <span class="text-xs font-semibold text-slate-700">
                                {{ t('text.import.processing_time') }}
                            </span>
                        </div>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ t('text.import.duration') }}</span>
                                <span class="font-medium text-slate-800">{{
                                    formatDuration(importResult.duration_ms)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ t('text.import.started') }}</span>
                                <span class="font-medium text-slate-800">{{
                                    formatTime(importResult.start_time)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ t('text.import.completed') }}</span>
                                <span class="font-medium text-slate-800">{{
                                    formatTime(importResult.end_time)
                                }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Counters -->
                <div class="grid grid-cols-5 gap-3 mb-4">
                    <div
                        class="bg-white rounded-md p-3 text-center border hover:shadow-sm transition-shadow"
                    >
                        <div class="text-xs text-slate-600 mb-1">{{ t('text.import.stat_total') }}</div>
                        <div class="text-2xl font-bold text-slate-800">{{ summary.total }}</div>
                    </div>
                    <div
                        class="bg-white rounded-md p-3 text-center border hover:shadow-sm transition-shadow"
                    >
                        <div class="text-xs text-green-600 mb-1">{{ t('text.import.stat_created') }}</div>
                        <div class="text-2xl font-bold text-green-700">{{ summary.created }}</div>
                        <div v-if="summary.total > 0" class="text-xs text-green-600 mt-1">
                            {{ Math.round((summary.created / summary.total) * 100) }}%
                        </div>
                    </div>
                    <div
                        class="bg-white rounded-md p-3 text-center border hover:shadow-sm transition-shadow"
                    >
                        <div class="text-xs text-blue-600 mb-1">{{ t('text.import.stat_updated') }}</div>
                        <div class="text-2xl font-bold text-blue-700">{{ summary.updated }}</div>
                        <div v-if="summary.total > 0" class="text-xs text-blue-600 mt-1">
                            {{ Math.round((summary.updated / summary.total) * 100) }}%
                        </div>
                    </div>
                    <div
                        class="bg-white rounded-md p-3 text-center border hover:shadow-sm transition-shadow"
                    >
                        <div class="text-xs text-amber-600 mb-1">{{ t('text.import.stat_skipped') }}</div>
                        <div class="text-2xl font-bold text-amber-700">{{ summary.skipped }}</div>
                        <div v-if="summary.total > 0" class="text-xs text-amber-600 mt-1">
                            {{ Math.round((summary.skipped / summary.total) * 100) }}%
                        </div>
                    </div>
                    <div
                        class="bg-white rounded-md p-3 text-center border hover:shadow-sm transition-shadow"
                    >
                        <div class="text-xs text-red-600 mb-1">{{ t('text.import.stat_failed') }}</div>
                        <div class="text-2xl font-bold text-red-700">{{ summary.failed }}</div>
                        <div v-if="summary.total > 0" class="text-xs text-red-600 mt-1">
                            {{ Math.round((summary.failed / summary.total) * 100) }}%
                        </div>
                    </div>
                </div>

                <!-- Success/Warning Message -->
                <div
                    v-if="summary.total === 0"
                    class="bg-blue-50 border border-blue-200 rounded-md p-3 mb-4"
                >
                    <div class="flex items-start gap-2">
                        <i-icon class="size-4 text-blue-600 mt-0.5" icon="information" />
                        <div class="text-xs text-blue-800">
                            <strong>{{ t('text.import.no_rows_title') }}</strong>
                            {{ t('text.import.no_rows_body') }}
                        </div>
                    </div>
                </div>
                <div
                    v-else-if="summary.failed === 0 && !importResult.dry_run"
                    class="bg-green-50 border border-green-200 rounded-md p-3 mb-4"
                >
                    <div class="flex items-start gap-2">
                        <i-icon class="size-4 text-green-600 mt-0.5" icon="check-circle" />
                        <div class="text-xs text-green-800">
                            <strong>{{ t('text.import.success_title') }}</strong>
                            {{ t('text.import.success_body', { count: summary.total }) }}
                        </div>
                    </div>
                </div>
                <div
                    v-else-if="summary.failed > 0"
                    class="bg-red-50 border border-red-200 rounded-md p-3 mb-4"
                >
                    <div class="flex items-start gap-2">
                        <i-icon class="size-4 text-red-600 mt-0.5" icon="alert-circle" />
                        <div class="text-xs text-red-800">
                            <strong>
                                {{ t('text.import.failed_title', { count: summary.failed }) }}
                            </strong>
                            {{ t('text.import.failed_body') }}
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="flex items-center gap-2 mb-3">
                    <a-radio-group
                        v-model:value="resultFilter"
                        button-style="solid"
                        class="text-xs"
                        size="small"
                    >
                        <a-radio-button value="all">{{ t('text.filter.all') }}</a-radio-button>
                        <a-radio-button value="fail">
                            {{ t('text.import.filter_errors') }}
                        </a-radio-button>
                        <a-radio-button value="create">
                            {{ t('text.import.filter_creates') }}
                        </a-radio-button>
                        <a-radio-button value="update">
                            {{ t('text.import.filter_updates') }}
                        </a-radio-button>
                        <a-radio-button value="skip">{{ t('text.import.filter_skips') }}</a-radio-button>
                    </a-radio-group>
                </div>

                <!-- Results Table -->
                <div v-if="filteredResults.length > 0" class="max-h-96 overflow-y-auto">
                    <a-table
                        :columns="resultColumns"
                        :data-source="filteredResults"
                        :pagination="false"
                        row-key="rowIndex"
                        size="small"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'rowIndex'">
                                <span class="font-mono text-xs">{{ record.rowIndex }}</span>
                            </template>
                            <template v-else-if="column.key === 'action'">
                                <a-tag :color="getActionColor(record.action)">
                                    {{ getActionLabel(record.action) }}
                                </a-tag>
                            </template>
                            <template v-else-if="column.key === 'identifiers'">
                                <div class="text-xs">
                                    <span
                                        v-if="
                                            record.identifiers &&
                                            Object.keys(record.identifiers).length > 0
                                        "
                                    >
                                        {{ formatIdentifiers(record.identifiers) }}
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                </div>
                            </template>
                            <template v-else-if="column.key === 'errors'">
                                <span
                                    v-if="hasRowErrors(record)"
                                    class="text-red-600 font-semibold"
                                >
                                    {{ getErrorCount(record) }}
                                </span>
                                <span v-else class="text-green-600">✓</span>
                            </template>
                            <template v-else-if="column.key === 'message'">
                                <div class="text-xs">
                                    <span
                                        v-if="hasRowErrors(record)"
                                        class="text-red-600"
                                    >
                                        {{ getFirstError(record) }}
                                    </span>
                                    <span v-else-if="record.message" class="text-slate-600">
                                        {{ record.message }}
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                </div>
                            </template>
                            <template v-else-if="column.key === 'expand'">
                                <a-button
                                    v-if="getErrorCount(record) > 1"
                                    size="small"
                                    type="link"
                                    @click="toggleExpand(record.rowIndex)"
                                >
                                    <i-icon
                                        :icon="
                                            expandedRows.includes(record.rowIndex)
                                                ? 'chevron-up'
                                                : 'chevron-down'
                                        "
                                        class="size-3"
                                    />
                                </a-button>
                            </template>
                        </template>
                        <template #expandedRowRender="{ record }">
                            <div
                                v-if="hasRowErrors(record)"
                                class="pl-4 py-2 bg-red-50"
                            >
                                <p class="text-xs font-semibold text-red-700 mb-2">
                                    {{ t('text.import.all_errors') }}
                                </p>
                                <div class="space-y-2">
                                    <div
                                        v-for="(messages, field) in record.errors"
                                        :key="field"
                                        class="space-y-1"
                                    >
                                        <p class="text-xs font-medium text-red-800">{{ field }}:</p>
                                        <ul class="list-disc list-inside pl-2 space-y-0.5">
                                            <li
                                                v-for="(error, idx) in messages"
                                                :key="idx"
                                                class="text-xs text-red-600"
                                            >
                                                {{ error }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </a-table>
                </div>
            </div>
        </div>
    </a-modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { message } from 'ant-design-vue'
import dayjs from 'dayjs'
import { useI18n } from 'vue-i18n'
import { url } from '@/config'
import { getImportDefinitionMetadata } from '@/libs/import-definitions'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    module: {
        type: String,
        required: true,
    },
    entity: {
        type: String,
        required: true,
    },
    entityLabel: {
        type: String,
        required: true,
    },
    importFunction: {
        type: Function,
        required: true,
    },
    requirePreview: {
        type: Boolean,
        default: true,
    },
})

const emit = defineEmits(['update:open', 'success'])

const { t } = useI18n()

const visible = ref(false)
const fileList = ref([])
const selectedFile = ref(null)
const uploadError = ref('')
const importing = ref(false)
const isDryRun = ref(false)
const importResult = ref(null)
const importError = ref(null)
const hasPreview = ref(false)
const resultFilter = ref('all')
const expandedRows = ref([])
const metadata = ref(null)
const metadataLoading = ref(false)
const metadataError = ref(null)

const formats = ['csv', 'xlsx', 'xls']

watch(
    () => props.open,
    (val) => {
        visible.value = val
    }
)

watch(visible, (val) => {
    emit('update:open', val)
    if (!val) {
        resetState()
    } else {
        // Fetch metadata when modal opens
        fetchMetadata()
    }
})

const getTemplateUrl = (format) => {
    return `${url}template/${props.module}/${props.entity}/template.${format}`
}

const getFormatIcon = (format) => {
    const icons = {
        csv: 'file-delimited',
        txt: 'file-document-outline',
        xlsx: 'file-excel',
        xls: 'file-excel',
    }
    return icons[format] || 'file'
}

const beforeUpload = (file) => {
    const validExtensions = ['.csv', '.txt', '.xls', '.xlsx']
    const fileName = file.name.toLowerCase()
    const isValid = validExtensions.some((ext) => fileName.endsWith(ext))

    if (!isValid) {
        uploadError.value = t('text.import.invalid_file_type')
        return false
    }

    uploadError.value = ''
    selectedFile.value = file
    return false // Prevent auto upload
}

const handleRemove = () => {
    selectedFile.value = null
    uploadError.value = ''
}

const handleImport = async (dryRun) => {
    if (!selectedFile.value) {
        message.warning(t('text.import.select_file_first'))
        return
    }

    importing.value = true
    isDryRun.value = dryRun
    importError.value = null // Clear previous errors

    try {
        const formData = new FormData()
        formData.append('file', selectedFile.value, selectedFile.value.name)
        formData.append('dry_run', dryRun ? '1' : '0')
        formData.append('transaction_mode', 'single')
        formData.append('limit_preview_rows', '100')
        formData.append('strict_headers', '1')

        const response = await props.importFunction(formData)

        importResult.value = response?.data || response

        if (dryRun) {
            hasPreview.value = true
            message.success(t('text.import.dry_run_done'))
        } else {
            message.success(t('text.import.success_title'))
            emit('success', importResult.value)
        }
    } catch (error) {
        const errorMsg = error?.response?.data?.message || error?.message || t('text.import.failed')

        // Show error in modal instead of toast for validation errors
        if (errorMsg.includes('Strict header validation') || errorMsg.includes('validation')) {
            importError.value = errorMsg
        } else {
            message.error(errorMsg)
        }

        importResult.value = null
    } finally {
        importing.value = false
    }
}

const summary = computed(() => {
    if (!importResult.value) {
        return { total: 0, created: 0, updated: 0, skipped: 0, failed: 0 }
    }

    // Use counts from API response if available
    if (importResult.value.total_rows !== undefined) {
        return {
            total: importResult.value.total_rows || 0,
            created: importResult.value.created_count || 0,
            updated: importResult.value.updated_count || 0,
            skipped: importResult.value.skipped_count || 0,
            failed: importResult.value.failed_count || 0,
        }
    }

    // Fallback to counting rows array
    const rows = importResult.value.rows || []
    return {
        total: rows.length,
        created: rows.filter((r) => r.action === 'create').length,
        updated: rows.filter((r) => r.action === 'update').length,
        skipped: rows.filter((r) => r.action === 'skip').length,
        failed: rows.filter((r) => r.action === 'fail').length,
    }
})

const hasErrors = computed(() => summary.value.failed > 0)

const filteredResults = computed(() => {
    if (!importResult.value?.row_results) return []

    const rows = importResult.value.row_results
    if (resultFilter.value === 'all') return rows

    return rows.filter((r) => r.action === resultFilter.value)
})

const resultColumns = computed(() => [
    { key: 'rowIndex', title: t('text.import.col_row'), width: 60 },
    { key: 'action', title: t('text.action'), width: 100 },
    { key: 'identifiers', title: t('text.import.col_identifiers'), ellipsis: true },
    { key: 'errors', title: t('text.import.col_status'), width: 80, align: 'center' },
    { key: 'message', title: t('text.import.col_message'), ellipsis: true },
    { key: 'expand', title: '', width: 50, align: 'center' },
])

const getActionColor = (action) => {
    const colors = {
        create: 'green',
        update: 'blue',
        skip: 'orange',
        fail: 'red',
    }
    return colors[action] || 'default'
}

// Translated label for a row action (falls back to the raw action name for unknown actions).
const getActionLabel = (action) => {
    const keys = {
        create: 'nav.b.create',
        update: 'action.update',
        skip: 'text.import.action_skip',
        fail: 'text.import.action_fail',
    }
    return keys[action] ? t(keys[action]) : action
}

const toggleExpand = (rowIndex) => {
    const idx = expandedRows.value.indexOf(rowIndex)
    if (idx > -1) {
        expandedRows.value.splice(idx, 1)
    } else {
        expandedRows.value.push(rowIndex)
    }
}

const downloadErrors = () => {
    if (!importResult.value?.row_results) return

    const errorRows = importResult.value.row_results.filter((r) => r.action === 'fail')
    if (errorRows.length === 0) {
        message.info(t('text.import.no_errors_to_download'))
        return
    }

    // Generate CSV
    const headers = [
        t('text.import.col_row'),
        t('text.import.col_identifiers'),
        t('validation.attributes.field'),
        t('text.import.col_errors'),
    ]
    const csvRows = [headers.join(',')]
    
    errorRows.forEach((row) => {
        const identifiers = row.identifiers ? formatIdentifiers(row.identifiers) : ''
        
        if (row.errors && typeof row.errors === 'object') {
            // Errors is an object with field names as keys
            Object.entries(row.errors).forEach(([field, messages]) => {
                const errorText = messages.join('; ').replace(/"/g, '""')
                csvRows.push(`${row.rowIndex},"${identifiers}","${field}","${errorText}"`)
            })
        } else {
            // Fallback for old format or no errors
            csvRows.push(`${row.rowIndex},"${identifiers}","","${row.message || ''}"`)
        }
    })

    const csvContent = csvRows.join('\n')
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    const url = URL.createObjectURL(blob)
    const filename = `${props.entity}_import_errors_${dayjs().format('YYYY-MM-DD')}.csv`

    link.setAttribute('href', url)
    link.setAttribute('download', filename)
    link.style.visibility = 'hidden'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)

    message.success(t('text.import.error_file_downloaded'))
}

const resetState = () => {
    fileList.value = []
    selectedFile.value = null
    uploadError.value = ''
    importing.value = false
    isDryRun.value = false
    importResult.value = null
    importError.value = null
    hasPreview.value = false
    resultFilter.value = 'all'
    expandedRows.value = []
}

const handleClose = () => {
    visible.value = false
}

const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDuration = (ms) => {
    if (!ms) return '0ms'
    if (ms < 1000) return Math.round(ms) + 'ms'
    return (ms / 1000).toFixed(2) + 's'
}

const formatTime = (timestamp) => {
    if (!timestamp) return t('text.import.not_available')
    return dayjs(timestamp).format('HH:mm:ss')
}

const formatIdentifiers = (identifiers) => {
    if (!identifiers || typeof identifiers !== 'object') return ''
    return Object.entries(identifiers)
        .map(([key, value]) => `${key}: ${value}`)
        .join(', ')
}

const hasRowErrors = (record) => {
    return record.errors && typeof record.errors === 'object' && Object.keys(record.errors).length > 0
}

const getErrorCount = (record) => {
    if (!hasRowErrors(record)) return 0
    return Object.values(record.errors).reduce((count, messages) => count + messages.length, 0)
}

const getFirstError = (record) => {
    if (!hasRowErrors(record)) return ''
    const firstField = Object.keys(record.errors)[0]
    const firstMessage = record.errors[firstField][0]
    return `${firstField}: ${firstMessage}`
}

const fetchMetadata = async () => {
    if (!props.module || !props.entity) {
        return
    }

    metadataLoading.value = true
    metadataError.value = null

    try {
        const data = await getImportDefinitionMetadata(props.module, props.entity)
        metadata.value = data
    } catch (error) {
        metadataError.value = error?.response?.data?.message || error?.message || t('text.import.metadata_failed')
        console.error('Failed to fetch import metadata:', error)
    } finally {
        metadataLoading.value = false
    }
}

const getMappingHint = (header) => {
    if (!metadata.value?.mappings) return '—'
    
    const mapping = metadata.value.mappings.find(m => m.input === header)
    if (!mapping) return '—'
    
    if (mapping.type === 'fk_lookup' && mapping.lookup_label) {
        return t('text.import.mapping_lookup', { label: mapping.lookup_label })
    }
    
    if (mapping.type === 'transform') {
        return t('text.import.mapping_transform')
    }
    
    return t('text.import.mapping_direct')
}
</script>
