<!--
  Copyright (c) 2025-2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <!-- The settings shell already renders breadcrumbs; this page only adds its title and reload action. -->
    <div class="mb-3 flex items-center justify-between gap-3">
        <h1 class="m-0 text-lg font-semibold text-ink">{{ $t('text.server_dashboard.title') }}</h1>
        <i-button variant="default" size="sm" data-testid="server-reload" :loading="loading" @click="fetchServerInfo">
            <template #icon>
                <i-icon class="size-3.5" icon="reload" />
            </template>
            <span>{{ t('nav.b.reload') }}</span>
        </i-button>
    </div>

    <div class="space-y-4">
        <!-- Health strip: only failing checks the payload exposes -->
        <div
            v-if="serverData && warnings.length"
            class="flex flex-wrap gap-2"
            data-testid="server-health-strip"
        >
            <span
                v-for="w in warnings"
                :key="w.key"
                class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium"
                :class="w.tone === 'danger' ? 'bg-danger-soft text-danger-ink' : 'bg-warning-soft text-warning-ink'"
                data-testid="server-health-item"
            >
                <i-icon class="size-3.5 shrink-0" :icon="w.tone === 'danger' ? 'close-circle' : 'alert-outline'" />
                <span class="break-words">{{ w.label }}<template v-if="w.detail">: {{ w.detail }}</template></span>
            </span>
        </div>

        <div
            v-if="loading && !serverData"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
            data-testid="server-kpi-grid"
        >
            <i-stat-tile v-for="n in 6" :key="n" loading />
        </div>

        <a-result
            v-if="errorMessage"
            status="error"
            :title="loadFailedTitle"
            :sub-title="errorMessage"
            class="i-box"
        >
            <template #extra>
                <i-button variant="primary" size="md" :loading="loading" @click="fetchServerInfo">
                    {{ t('nav.b.reload') }}
                </i-button>
            </template>
        </a-result>

        <template v-else-if="serverData">
            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
                data-testid="server-kpi-grid"
            >
                <i-stat-tile
                    v-for="tile in tiles"
                    :key="tile.key"
                    :label="tile.label"
                    :value="tile.value"
                    :tone="tile.tone"
                />
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <i-box v-for="box in boxes" :key="box.key" :data-testid="`server-box-${box.key}`">
                    <div class="mb-3 text-sm font-semibold text-ink">{{ box.title }}</div>
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-0.5 text-sm sm:grid-cols-[auto_1fr] sm:gap-y-1">
                        <template v-for="row in box.rows" :key="row.label">
                            <dt class="text-ink-muted">{{ row.label }}</dt>
                            <dd
                                class="mb-1.5 min-w-0 font-medium [overflow-wrap:anywhere] sm:mb-0 sm:text-right"
                                :class="row.cls"
                            >
                                {{ row.text }}
                            </dd>
                        </template>
                    </dl>
                </i-box>
            </div>

            <i-box v-if="packages.length" data-testid="server-box-packages">
                <a-collapse ghost>
                    <a-collapse-panel key="packages" :header="t('text.server_dashboard.installed_packages')">
                        <div class="grid max-h-96 grid-cols-1 gap-2 overflow-y-auto md:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="[name, version] in packages"
                                :key="name"
                                class="flex items-center justify-between gap-2 rounded bg-surface-sunken p-2 text-sm"
                            >
                                <span class="min-w-0 break-words font-medium text-ink">{{ name }}</span>
                                <span class="text-ink-muted">{{ version }}</span>
                            </div>
                        </div>
                    </a-collapse-panel>
                </a-collapse>
            </i-box>

            <i-box v-if="moduleList.length" data-testid="server-box-modules-list">
                <a-collapse ghost>
                    <a-collapse-panel key="modules" :header="t('text.server_dashboard.modules_list')">
                        <div class="space-y-2">
                            <div
                                v-for="mod in moduleList"
                                :key="mod.key"
                                class="rounded-md border border-border bg-surface p-3"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <a-badge :status="mod.enabled ? 'success' : 'error'" :text="mod.name" />
                                        <span class="text-xs text-ink-faint">v{{ mod.version }}</span>
                                    </div>
                                    <div class="text-xs text-ink-faint">
                                        {{ t('text.server_dashboard.files_count', { count: mod.files_count }) }}
                                        &bull; {{ mod.size }}
                                    </div>
                                </div>
                                <p v-if="mod.description" class="mt-1 text-sm text-ink-muted">{{ mod.description }}</p>
                                <div class="mt-1 break-all text-xs text-ink-faint">
                                    <span class="font-medium">{{ t('text.path') }}:</span> {{ mod.path }}
                                </div>
                                <div v-if="mod.requirements?.length" class="mt-1 text-xs text-ink-faint">
                                    <span class="font-medium">{{ t('text.requirements') }}:</span>
                                    {{ mod.requirements.join(', ') }}
                                </div>
                            </div>
                        </div>
                    </a-collapse-panel>
                </a-collapse>
            </i-box>

            <i-box v-if="projects.length" data-testid="server-box-projects">
                <div class="mb-2 text-sm font-semibold text-ink">
                    {{ t('text.server_dashboard.project_structure') }}
                    <span class="font-normal text-ink-muted">({{ projects.length }})</span>
                </div>
                <a-collapse ghost>
                    <a-collapse-panel v-for="[name, project] in projects" :key="name" :header="name">
                        <div class="space-y-1">
                            <div
                                v-for="(item, itemName) in project"
                                :key="itemName"
                                class="flex flex-wrap items-center justify-between gap-2 rounded bg-surface-sunken px-3 py-2"
                            >
                                <div class="flex items-center gap-2">
                                    <i-icon
                                        class="size-4 text-ink-muted"
                                        :icon="item.type === 'directory' ? 'folder' : 'file'"
                                    />
                                    <span class="text-sm font-medium text-ink">{{ itemName }}</span>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-ink-faint">
                                    <span v-if="item.size">{{ item.size }}</span>
                                    <span v-if="item.extension" class="rounded bg-surface px-2 py-0.5">{{ item.extension }}</span>
                                    <span v-if="item.modified">{{ formatDate(item.modified) }}</span>
                                </div>
                            </div>
                        </div>
                    </a-collapse-panel>
                </a-collapse>
            </i-box>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'

const { t } = useI18n()
const loading = ref(false)
const serverData = ref(null)
const errorMessage = ref('')
const loadFailedTitle = computed(() => t('text.setting.dashboard.load_failed'))

const SECTION_ERRORS = [
    ['database_error', 'database_information'],
    ['directories_error', 'directories_information'],
    ['composer_error', 'composer_information'],
    ['htaccess_error', 'htaccess_information'],
    ['modules_error', 'modules_information'],
    ['project_structure_error', 'project_structure'],
]

/** Read a string like Yes/No/enabled into true, false or null. */
const toBool = (v) => {
    const s = String(v).toLowerCase()
    if (['yes', 'enabled', 'true', '1'].includes(s)) return true
    if (['no', 'disabled', 'false', '0'].includes(s)) return false
    return null
}

/** Build one definition-list row; status rows get a health colour. */
const row = (labelKey, value, { status = false, suffix = '' } = {}) => {
    const b = status ? toBool(value) : null
    let text
    if (b === true) text = t('text.yes')
    else if (b === false && status) text = t('text.no')
    else if (value === null || value === undefined || value === '') text = t('text.not_applicable')
    else text = `${value}${suffix}`
    return {
        label: t(`text.server_dashboard.${labelKey}`),
        text,
        cls: status && b !== null ? (b ? 'text-success-ink' : 'text-danger-ink') : 'text-ink',
    }
}

const info = computed(() => serverData.value?.server_info || {})
const db = computed(() => serverData.value?.database_info || {})
const dirs = computed(() => serverData.value?.directories_info || {})
const composer = computed(() => serverData.value?.composer_info || {})
const htaccess = computed(() => serverData.value?.htaccess_info || {})
const modulesInfo = computed(() => serverData.value?.modules_info || {})

/** Failing checks, built only from fields the payload exposes. */
const warnings = computed(() => {
    const out = []
    const d = serverData.value || {}
    for (const [errKey, titleKey] of SECTION_ERRORS) {
        if (d[errKey]) {
            out.push({
                key: errKey,
                tone: 'danger',
                label: t(`text.server_dashboard.${titleKey}`),
                detail: String(d[errKey]),
            })
        }
    }
    const checks = [
        ['imagick', info.value.imagick_available, 'image_magick', 'warning'],
        ['composer-lock', composer.value.composer_lock_exists, null, 'warning'],
        ['htaccess', htaccess.value.htaccess_exists, 'htaccess_exists', 'warning'],
    ]
    for (const [key, val, labelKey, tone] of checks) {
        if (toBool(val) === false) {
            out.push({
                key,
                tone,
                label: labelKey ? t(`text.server_dashboard.${labelKey}`) : 'composer.lock',
                detail: t('text.no'),
            })
        }
    }
    return out
})

/** Short PHP version, e.g. "8.3.4" from the verbose payload string. */
const phpVersion = computed(() => {
    const raw = info.value.php_version
    return raw ? String(raw).split(' ')[0] : t('text.not_applicable')
})

const tiles = computed(() => {
    const disabled = Number(modulesInfo.value.modules_disabled_count ?? 0)
    return [
        { key: 'php', label: t('text.server_dashboard.php_version'), value: phpVersion.value, tone: 'info' },
        {
            key: 'memory',
            label: t('text.server_dashboard.memory_limit'),
            value: info.value.php_memory_limit ?? t('text.not_applicable'),
            tone: 'default',
        },
        {
            key: 'db-size',
            label: t('text.server_dashboard.database_size'),
            value: dirs.value.database_size ?? t('text.not_applicable'),
            tone: serverData.value?.database_error ? 'danger' : 'default',
        },
        {
            key: 'storage',
            label: t('text.server_dashboard.storage_directory'),
            value: dirs.value.storage_directory_size ?? t('text.not_applicable'),
            tone: 'default',
        },
        {
            key: 'modules-on',
            label: t('text.server_dashboard.enabled_modules'),
            value: modulesInfo.value.modules_enabled_count ?? 0,
            tone: 'success',
        },
        {
            key: 'modules-off',
            label: t('text.server_dashboard.disabled_modules'),
            value: disabled,
            tone: 'default',
        },
    ]
})

const boxes = computed(() => [
    {
        key: 'php',
        title: t('text.server_dashboard.server_information'),
        rows: [
            row('server_architecture', info.value.server_architecture),
            row('web_server', info.value.web_server),
            row('php_version', info.value.php_version),
            row('php_sapi', info.value.php_sapi),
            row('memory_limit', info.value.php_memory_limit),
            row('time_limit', info.value.php_time_limit, { suffix: 's' }),
            row('max_input_variables', info.value.php_max_input_variables),
            row('max_input_time', info.value.max_input_time, { suffix: 's' }),
            row('max_upload_size', info.value.upload_max_filesize),
            row('post_max_size', info.value.php_post_max_size),
            row('curl_version', info.value.curl_version),
            row('image_magick', info.value.imagick_available, { status: true }),
            row('suhosin', info.value.suhosin_installed, { status: true }),
            row('pretty_permalinks', info.value.pretty_permalinks_supported),
            row('current_time', info.value.current_time),
            row('current_utc_time', info.value.current_utc_time),
            row('current_server_time', info.value.current_server_time),
        ],
    },
    {
        key: 'database',
        title: t('text.server_dashboard.database_information'),
        rows: [
            row('database_extension', db.value.database_extension),
            row('server_version', db.value.server_version),
            row('client_version', db.value.client_version),
            row('database_username', db.value.database_username),
            row('database_host', db.value.database_host),
            row('database_name', db.value.database_name),
            row('table_prefix', db.value.table_prefix || t('text.server_dashboard.none')),
            row('charset', db.value.database_charset),
            row('collation', db.value.database_collation),
            row('max_packet_size', db.value.max_allowed_packet_size),
            row('max_connections', db.value.max_connections_number),
            row('database_size', dirs.value.database_size),
        ],
    },
    {
        key: 'storage',
        title: t('text.server_dashboard.directories_information'),
        rows: [
            ['laravel', 'laravel_directory'],
            ['public', 'public_directory'],
            ['storage', 'storage_directory'],
            ['uploads', 'uploads_directory'],
            ['themes', 'themes_directory'],
            ['plugins', 'plugins_directory'],
            ['fonts', 'fonts_directory'],
        ].map(([k, labelKey]) =>
            row(
                labelKey,
                [dirs.value[`${k}_directory_size`], dirs.value[`${k}_directory_location`]]
                    .filter(Boolean)
                    .join(' - ')
            )
        ),
    },
    {
        key: 'composer',
        title: t('text.server_dashboard.composer_information'),
        rows: [
            row('composer_version', composer.value.composer_version),
            { ...row('composer_version', composer.value.composer_lock_exists, { status: true }), label: 'composer.lock' },
            { ...row('composer_version', composer.value.composer_json_exists, { status: true }), label: 'composer.json' },
        ],
    },
    {
        key: 'htaccess',
        title: t('text.server_dashboard.htaccess_information'),
        rows: [
            row('htaccess_exists', htaccess.value.htaccess_exists, { status: true }),
            row('htaccess_writable', htaccess.value.htaccess_writable, { status: true }),
            row('robots_txt_exists', htaccess.value.robots_txt_exists, { status: true }),
            row('htaccess_location', htaccess.value.htaccess_location),
        ],
    },
])

const packages = computed(() => Object.entries(composer.value.installed_packages || {}))
const moduleList = computed(() =>
    Object.entries(modulesInfo.value.modules_list || {}).map(([key, m]) => ({ key, ...m }))
)
const projects = computed(() => Object.entries(serverData.value?.project_structure || {}))

const resolveErrorMessage = (error) => {
    return error?.response?.data?.message || error?.message || t('mgs.error.unknown')
}

const fetchServerInfo = async () => {
    loading.value = true
    errorMessage.value = ''
    try {
        const response = await ajax.get('dashboard/server')
        if (response?.data?.data) {
            serverData.value = response.data.data
        }
    } catch (error) {
        console.error('Failed to load server information', error)
        errorMessage.value = resolveErrorMessage(error)
    } finally {
        loading.value = false
    }
}

const formatDate = (dateString) => {
    if (!dateString) return t('text.not_applicable')
    try {
        const date = new Date(dateString)
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
    } catch (error) {
        return dateString
    }
}

onMounted(() => {
    fetchServerInfo()
})
</script>
