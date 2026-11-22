<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="allowed" class="max-w-5xl mx-auto space-y-6" data-testid="backups-page">
        <!-- OAuth return message (?oauth=ok|failed) -->
        <a-alert
            v-if="oauthNotice"
            :message="oauthNotice.text"
            :type="oauthNotice.type"
            show-icon
            closable
            data-testid="backup-oauth-notice"
            @close="oauthNotice = null"
        />

        <!-- Page actions -->
        <div class="flex flex-wrap items-center justify-end gap-2">
            <i-button
                variant="primary"
                size="sm"
                data-testid="backup-run"
                :loading="running"
                :disabled="running"
                @click="runNow"
            >
                {{ $t('text.backup.run_now') }}
            </i-button>
            <i-button size="sm" data-testid="backup-add" @click="openCreate">
                <template #icon><i-icon icon="plus" class="size-4" /></template>
                {{ $t('text.backup.add_destination') }}
            </i-button>
        </div>

        <!-- Status -->
        <section class="i-box space-y-3" data-testid="backup-status">
            <h3 class="m-0 text-sm font-semibold">{{ $t('text.backup.section.status') }}</h3>
            <div v-if="status.loading && !status.loaded" data-testid="backup-status-loading" aria-busy="true">
                <a-skeleton active :paragraph="{ rows: 2 }" />
            </div>
            <div v-else-if="status.error" role="alert" class="space-y-2" data-testid="backup-status-error">
                <p class="m-0 text-sm text-danger">{{ status.error }}</p>
                <i-button size="sm" data-testid="backup-status-retry" @click="loadStatus">{{ $t('text.backup.retry') }}</i-button>
            </div>
            <template v-else-if="status.data">
                <a-alert
                    v-if="nightly.state === 'stale'"
                    type="warning"
                    show-icon
                    :message="$t('text.backup.nightly.stale_warning')"
                    data-testid="backup-nightly-stale"
                />
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <template v-for="tile in statusTiles" :key="tile.key">
                        <!-- A value that is not a result (empty, never run) is muted text, not a big figure. -->
                        <div
                            v-if="tile.muted"
                            class="flex min-w-0 flex-col gap-1.5 rounded-lg border border-border bg-surface p-3.5"
                            data-testid="backup-status-tile"
                        >
                            <span class="line-clamp-2 text-[13px] leading-5 text-ink-muted">{{ tile.label }}</span>
                            <span class="text-sm text-ink-muted" data-testid="backup-status-muted">{{ tile.value }}</span>
                            <span v-if="tile.description" class="text-xs leading-5 text-ink-faint">{{ tile.description }}</span>
                        </div>
                        <i-stat-tile
                            v-else
                            :label="tile.label"
                            :value="tile.value"
                            :tone="tile.tone"
                            :description="tile.description"
                        />
                    </template>
                </div>
                <ul v-if="nightly.objects.length" class="m-0 list-none space-y-1 p-0 text-xs text-ink-muted" data-testid="backup-nightly-objects">
                    <li v-for="object in nightly.objects" :key="object.key">
                        {{ object.database }}:
                        {{ $t('text.backup.tables_dumped', { done: object.tables_dumped ?? 0, total: object.tables_expected ?? 0 }) }}
                        <a-tag v-if="object.failed" color="error">{{ $t('text.backup.status.failed') }}</a-tag>
                    </li>
                </ul>
            </template>
        </section>

        <!-- Destinations -->
        <section class="i-box space-y-3" data-testid="backup-destinations">
            <h3 class="m-0 text-sm font-semibold">{{ $t('text.backup.section.destinations') }}</h3>
            <div v-if="destinations.loading && !destinations.loaded" data-testid="backup-destinations-loading" aria-busy="true">
                <a-skeleton active :paragraph="{ rows: 3 }" />
            </div>
            <div v-else-if="destinations.error" role="alert" class="space-y-2" data-testid="backup-destinations-error">
                <p class="m-0 text-sm text-danger">{{ destinations.error }}</p>
                <i-button size="sm" data-testid="backup-destinations-retry" @click="loadDestinations">{{ $t('text.backup.retry') }}</i-button>
            </div>
            <p v-else-if="!destinations.data.length" class="m-0 text-sm text-ink-muted" data-testid="backup-destinations-empty">
                {{ $t('text.backup.destinations_empty') }}
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-border text-xs text-ink-muted">
                            <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.name') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.driver') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.retention') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.lock') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.schedule') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.last_result') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ $t('text.backup.col.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in destinations.data"
                            :key="item.uuid"
                            class="border-b border-border align-top"
                            data-testid="backup-destination-row"
                        >
                            <td class="py-2 pr-3">
                                <div class="font-medium">{{ item.name }}</div>
                                <a-tag v-if="!item.enabled" class="mt-1">{{ $t('text.backup.disabled') }}</a-tag>
                                <a-tag v-if="item.use_for_safety" color="blue" class="mt-1">{{ $t('text.backup.safety_short') }}</a-tag>
                            </td>
                            <td class="py-2 pr-3">
                                {{ driverLabel(item.driver) }}
                                <div v-if="isOauthDriver(item.driver)" class="text-xs text-ink-muted">
                                    {{ item.credentials_set && item.credentials_set.refresh_token ? $t('text.backup.connected') : $t('text.backup.not_connected') }}
                                </div>
                            </td>
                            <td class="py-2 pr-3">{{ $t('text.backup.days', { count: item.retention_days }) }}</td>
                            <td class="py-2 pr-3">
                                {{ item.lock_days > 0 ? $t('text.backup.days', { count: item.lock_days }) : $t('text.backup.lock_none') }}
                            </td>
                            <td class="py-2 pr-3">{{ scheduleText(item.schedule) }}</td>
                            <td class="py-2 pr-3" data-testid="backup-destination-result">
                                <template v-if="lastFailed(item)">
                                    <a-tag color="error">{{ $t('text.backup.status.failed') }}</a-tag>
                                    <div class="text-xs">{{ formatDate(item.last_failure_at) }}</div>
                                    <div v-if="item.last_error_code" class="text-xs text-danger">{{ errorLabel(item.last_error_code) }}</div>
                                </template>
                                <template v-else-if="item.last_success_at">
                                    <a-tag color="success">{{ $t('text.backup.status.succeeded') }}</a-tag>
                                    <div class="text-xs">{{ formatDate(item.last_success_at) }}</div>
                                </template>
                                <span v-else class="text-xs text-ink-muted">{{ $t('text.backup.never') }}</span>
                                <div v-if="testState[item.uuid]" class="mt-1 text-xs" data-testid="backup-test-result" role="status">
                                    <span v-if="testState[item.uuid].pending">{{ $t('text.backup.test.running') }}</span>
                                    <template v-else-if="testState[item.uuid].result">
                                        <span :class="testState[item.uuid].result.ok ? 'text-success' : 'text-danger'">
                                            {{ testState[item.uuid].result.ok ? $t('text.backup.test.ok') : $t('text.backup.test.failed') }}
                                        </span>
                                        <span v-if="testState[item.uuid].result.error_code">: {{ errorLabel(testState[item.uuid].result.error_code) }}</span>
                                        <div v-if="testState[item.uuid].result.ok" data-testid="backup-test-lock">
                                            {{ testState[item.uuid].result.lock_active ? $t('text.backup.test.lock_active') : $t('text.backup.test.lock_inactive') }}
                                        </div>
                                    </template>
                                    <span v-else class="text-danger">{{ testState[item.uuid].error }}</span>
                                </div>
                            </td>
                            <td class="py-2">
                                <div class="flex flex-wrap justify-end gap-1">
                                    <i-button
                                        size="sm"
                                        data-testid="backup-test"
                                        :loading="Boolean(testState[item.uuid] && testState[item.uuid].pending)"
                                        :disabled="Boolean(testState[item.uuid] && testState[item.uuid].pending)"
                                        @click="testDestination(item)"
                                    >
                                        {{ $t('text.backup.test.action') }}
                                    </i-button>
                                    <i-button
                                        v-if="isOauthDriver(item.driver)"
                                        size="sm"
                                        data-testid="backup-connect"
                                        :loading="connecting === item.uuid"
                                        :disabled="Boolean(connecting)"
                                        @click="connect(item)"
                                    >
                                        {{ item.credentials_set && item.credentials_set.refresh_token ? $t('text.backup.reconnect') : $t('text.backup.connect') }}
                                    </i-button>
                                    <i-button size="sm" data-testid="backup-edit" @click="openEdit(item)">{{ $t('nav.b.update') }}</i-button>
                                    <a-popconfirm
                                        :title="$t('text.backup.delete_confirm', { name: item.name })"
                                        :ok-text="$t('nav.b.delete')"
                                        :cancel-text="$t('nav.b.cancel')"
                                        @confirm="removeDestination(item)"
                                    >
                                        <i-button size="sm" variant="danger" data-testid="backup-delete">{{ $t('nav.b.delete') }}</i-button>
                                    </a-popconfirm>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- History -->
        <section class="i-box space-y-3" data-testid="backup-history">
            <h3 class="m-0 text-sm font-semibold">{{ $t('text.backup.section.history') }}</h3>
            <div v-if="history.loading && !history.loaded" data-testid="backup-history-loading" aria-busy="true">
                <a-skeleton active :paragraph="{ rows: 3 }" />
            </div>
            <div v-else-if="history.error" role="alert" class="space-y-2" data-testid="backup-history-error">
                <p class="m-0 text-sm text-danger">{{ history.error }}</p>
                <i-button size="sm" data-testid="backup-history-retry" @click="loadHistory">{{ $t('text.backup.retry') }}</i-button>
            </div>
            <p v-else-if="!historyRows.length" class="m-0 text-sm text-ink-muted" data-testid="backup-history-empty">
                {{ $t('text.backup.history_empty') }}
            </p>
            <template v-else>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-border text-xs text-ink-muted">
                                <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.started') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.reason') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.status') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.tables') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ $t('text.backup.col.size') }}</th>
                                <th class="py-2 font-medium">{{ $t('text.backup.col.copies') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in historyRows" :key="row.uuid" class="border-b border-border align-top" data-testid="backup-history-row">
                                <td class="py-2 pr-3">{{ formatDate(row.started_at || row.created_at) }}</td>
                                <td class="py-2 pr-3">{{ $t('text.backup.reason.' + row.reason) }}</td>
                                <td class="py-2 pr-3">
                                    <a-tag :color="statusColor(row.status)">{{ $t('text.backup.status.' + row.status) }}</a-tag>
                                    <div v-if="row.error_code" class="text-xs text-danger">{{ errorLabel(row.error_code) }}</div>
                                </td>
                                <td class="py-2 pr-3">
                                    {{ row.tables_expected ? $t('text.backup.tables_dumped', { done: row.tables_dumped ?? 0, total: row.tables_expected }) : '-' }}
                                </td>
                                <td class="py-2 pr-3">{{ formatBytes(row.size_bytes) }}</td>
                                <td class="py-2">
                                    <ul class="m-0 list-none space-y-1 p-0">
                                        <li v-for="(copy, index) in row.copies" :key="index" class="text-xs">
                                            {{ copy.destination ? copy.destination.name : '-' }}
                                            <a-tag :color="copyColor(copy.status)">{{ $t('text.backup.copy.' + copy.status) }}</a-tag>
                                            <span v-if="copy.error_code" class="text-danger">{{ errorLabel(copy.error_code) }}</span>
                                        </li>
                                        <li v-if="!row.copies || !row.copies.length" class="text-xs text-ink-muted">-</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="historyLastPage > 1" class="flex items-center justify-end gap-2">
                    <i-button size="sm" :disabled="historyPage <= 1 || history.loading" @click="goHistory(historyPage - 1)">{{ $t('text.backup.prev_page') }}</i-button>
                    <span class="text-xs text-ink-muted">{{ historyPage }} / {{ historyLastPage }}</span>
                    <i-button size="sm" :disabled="historyPage >= historyLastPage || history.loading" @click="goHistory(historyPage + 1)">{{ $t('text.backup.next_page') }}</i-button>
                </div>
            </template>
        </section>

        <!-- Add / edit drawer -->
        <a-drawer
            :open="drawer.open"
            width="min(480px, 100vw)"
            :title="drawer.editing ? $t('text.backup.edit_destination') : $t('text.backup.add_destination')"
            @close="closeDrawer"
        >
            <form ref="formEl" class="space-y-4" data-testid="backup-form" novalidate @submit.prevent="saveDestination">
                <!-- Sticky so a failed save is never scrolled out of sight. -->
                <div v-if="drawer.error" class="sticky -top-6 z-10 -mt-2 bg-surface pb-2">
                    <a-alert
                        type="error"
                        show-icon
                        :message="drawer.error"
                        :description="drawer.errorDetails.join(' ')"
                        data-testid="backup-form-error"
                    />
                </div>

                <label class="block space-y-1" :data-invalid="hasError('name') ? 'true' : null">
                    <span class="text-sm font-medium">
                        {{ $t('text.backup.field.name') }}<span class="text-danger" aria-hidden="true" data-testid="backup-required"> *</span>
                    </span>
                    <a-input
                        v-model:value="form.name"
                        :maxlength="120"
                        :status="hasError('name') ? 'error' : undefined"
                        data-testid="backup-field-name"
                    />
                    <p v-if="hasError('name')" class="m-0 text-xs text-danger" data-testid="backup-error-name">{{ fieldErrors.name }}</p>
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ $t('text.backup.col.driver') }}</span>
                    <a-select
                        v-model:value="form.driver"
                        class="w-full"
                        :disabled="drawer.editing"
                        :options="driverOptions"
                        data-testid="backup-field-driver"
                    />
                </label>

                <!-- S3-compatible presets: a UI helper only, nothing about the preset is sent to the API. -->
                <div v-if="showProviders" class="space-y-1">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">{{ $t('text.backup.form.provider') }}</span>
                        <a-select v-model:value="provider" class="w-full" :options="providerOptions" data-testid="backup-field-provider" />
                    </label>
                    <p class="m-0 text-xs text-ink-muted" data-testid="backup-provider-help">{{ $t('text.backup.provider.' + provider + '_help') }}</p>
                </div>

                <template v-if="currentDriver">
                    <template v-for="entry in driverEntries" :key="entry.id">
                        <button
                            v-if="entry.toggle"
                            type="button"
                            class="flex w-full items-center gap-1 border-0 bg-transparent p-0 text-left text-sm font-medium text-ink"
                            :aria-expanded="advancedOpen ? 'true' : 'false'"
                            data-testid="backup-advanced"
                            @click="advancedOpen = !advancedOpen"
                        >
                            <i-icon :icon="advancedOpen ? 'chevron-up' : 'chevron-down'" class="size-4" />
                            {{ $t('text.backup.form.advanced') }}
                            <span class="text-xs font-normal text-ink-muted">{{ $t('text.backup.form.advanced_hint') }}</span>
                        </button>
                        <label
                            v-else-if="entry.kind === 'config'"
                            :class="entry.def.type === 'bool' ? 'flex flex-wrap items-center gap-2' : 'block space-y-1'"
                            :data-invalid="hasError(entry.path) ? 'true' : null"
                        >
                            <span class="text-sm font-medium">
                                {{ $t('text.backup.field.' + entry.key) }}<span
                                    v-if="entry.def.required"
                                    class="text-danger"
                                    aria-hidden="true"
                                    data-testid="backup-required"
                                > *</span>
                            </span>
                            <a-switch v-if="entry.def.type === 'bool'" v-model:checked="form.config[entry.key]" />
                            <a-input-number
                                v-else-if="entry.def.type === 'int'"
                                v-model:value="form.config[entry.key]"
                                :min="1"
                                :max="65535"
                                class="w-full"
                                :status="hasError(entry.path) ? 'error' : undefined"
                            />
                            <a-input
                                v-else
                                v-model:value="form.config[entry.key]"
                                :status="hasError(entry.path) ? 'error' : undefined"
                                :data-testid="'backup-field-' + entry.key"
                            />
                            <p v-if="entry.key === 'root' && form.driver === 'local'" class="m-0 text-xs text-ink-muted">
                                {{ $t('text.backup.form.root_help') }}
                            </p>
                            <p v-if="hasError(entry.path)" class="m-0 text-xs text-danger" :data-testid="'backup-error-' + entry.key">
                                {{ fieldErrors[entry.path] }}
                            </p>
                        </label>
                        <div v-else class="space-y-1" :data-invalid="hasError(entry.path) ? 'true' : null">
                            <label class="block space-y-1">
                                <span class="text-sm font-medium">
                                    {{ $t('text.backup.field.' + entry.key) }}<span
                                        v-if="credentialRequired"
                                        class="text-danger"
                                        aria-hidden="true"
                                        data-testid="backup-required"
                                    > *</span>
                                </span>
                                <a-input-password
                                    v-model:value="form.credentials[entry.key]"
                                    autocomplete="new-password"
                                    :status="hasError(entry.path) ? 'error' : undefined"
                                    :data-testid="'backup-secret-' + entry.key"
                                />
                            </label>
                            <p v-if="drawer.credentialsSet[entry.key]" class="m-0 text-xs text-ink-muted" data-testid="backup-secret-saved">
                                {{ $t('text.backup.secret_saved') }}
                            </p>
                            <p v-if="hasError(entry.path)" class="m-0 text-xs text-danger" :data-testid="'backup-error-' + entry.key">
                                {{ fieldErrors[entry.path] }}
                            </p>
                        </div>
                    </template>
                </template>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block space-y-1" :data-invalid="hasError('retention_days') ? 'true' : null">
                        <span class="text-sm font-medium">
                            {{ $t('text.backup.field.retention_days') }}<span class="text-danger" aria-hidden="true" data-testid="backup-required"> *</span>
                        </span>
                        <a-input-number
                            v-model:value="form.retention_days"
                            :min="1"
                            :max="3650"
                            class="w-full"
                            :status="hasError('retention_days') ? 'error' : undefined"
                        />
                        <p v-if="hasError('retention_days')" class="m-0 text-xs text-danger" data-testid="backup-error-retention_days">
                            {{ fieldErrors.retention_days }}
                        </p>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">{{ $t('text.backup.field.lock_days') }}</span>
                        <a-input-number v-model:value="form.lock_days" :min="0" :max="3650" class="w-full" />
                    </label>
                </div>
                <p class="m-0 text-xs text-ink-muted">{{ $t('text.backup.field.lock_help') }}</p>

                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ $t('text.backup.field.schedule') }}</span>
                    <a-time-picker
                        v-model:value="form.time"
                        format="HH:mm"
                        value-format="HH:mm"
                        :allow-clear="true"
                        :placeholder="$t('text.backup.field.schedule_placeholder')"
                        class="w-full"
                    />
                    <span class="block text-xs text-ink-muted">{{ $t('text.backup.field.schedule_help') }}</span>
                </label>

                <div class="flex items-center gap-2">
                    <a-switch v-model:checked="form.use_for_safety" />
                    <span class="text-sm">{{ $t('text.backup.field.use_for_safety') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <a-switch v-model:checked="form.enabled" />
                    <span class="text-sm">{{ $t('text.backup.field.enabled') }}</span>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <i-button size="md" @click="closeDrawer">{{ $t('nav.b.cancel') }}</i-button>
                    <i-button
                        size="md"
                        variant="primary"
                        native-type="submit"
                        data-testid="backup-save"
                        :loading="drawer.saving"
                        :disabled="drawer.saving"
                    >
                        {{ $t('nav.b.save') }}
                    </i-button>
                </div>
            </form>
        </a-drawer>
    </div>
</template>

<script setup>
import { computed, defineComponent, nextTick, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { iAlert } from '@/mixins'
import { hasAccountData, isWebmaster } from '@/hooks/useCan'
import {
    OAUTH_DRIVERS,
    backupErrorCodeOf,
    backupErrorKey,
    buildDestinationPayload,
    createBackupDestination,
    deleteBackupDestination,
    getBackupDestinations,
    getBackups,
    getBackupStatus,
    runBackup,
    scheduleToTime,
    startBackupOAuth,
    testBackupDestination,
    updateBackupDestination,
} from '@/libs/backup'

defineComponent({ name: 'SettingBackups' })

const HISTORY_PER_PAGE = 10
const DEFAULT_RETENTION_DAYS = 3

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

/** Webmaster only: the API refuses everyone else, and the route guard sends them away; this keeps the page empty. */
const allowed = computed(() => isWebmaster())

const makeBlock = () => reactive({ loading: true, loaded: false, error: '', data: null })
const status = makeBlock()
const destinations = makeBlock()
destinations.data = []
const history = makeBlock()
const historyPage = ref(1)

const errorLabel = (code) => {
    const key = backupErrorKey(code)
    return key ? t(key) : ''
}

const errorText = (error, fallbackKey) => {
    const key = backupErrorKey(backupErrorCodeOf(error))
    return key ? t(key) : t(fallbackKey)
}

/** Load one data block: first load shows a skeleton, a failure shows the message with a retry. */
const loadBlock = async (block, fetcher, failedKey) => {
    block.loading = true
    block.error = ''
    try {
        block.data = await fetcher()
        block.loaded = true
    } catch (error) {
        block.error = errorText(error, failedKey)
    } finally {
        block.loading = false
    }
}

const loadStatus = () => loadBlock(status, getBackupStatus, 'text.backup.load_failed.status')
const loadDestinations = () => loadBlock(destinations, getBackupDestinations, 'text.backup.load_failed.destinations')
const loadHistory = () =>
    loadBlock(history, () => getBackups({ page: historyPage.value, per_page: HISTORY_PER_PAGE }), 'text.backup.load_failed.history')
const loadAll = () => Promise.all([loadStatus(), loadDestinations(), loadHistory()])

const goHistory = (page) => {
    historyPage.value = page
    loadHistory()
}

const historyRows = computed(() => history.data?.data || [])
const historyLastPage = computed(() => Number(history.data?.meta?.last_page || 1))

// ---- Status ------------------------------------------------------------------------------------------------------
const nightly = computed(() => {
    const raw = status.data?.server_nightly || {}
    return {
        state: raw.state || 'unknown',
        date: raw.date || '',
        objects: Array.isArray(raw.objects) ? raw.objects : [],
    }
})
const nightlyTone = computed(
    () => ({ ok: 'success', failed: 'danger', stale: 'warning', invalid: 'warning' })[nightly.value.state] || 'default'
)
const nightlyTables = computed(() => {
    const objects = nightly.value.objects
    if (!objects.length) return '-'
    const done = objects.reduce((sum, o) => sum + (o.tables_dumped || 0), 0)
    const total = objects.reduce((sum, o) => sum + (o.tables_expected || 0), 0)
    return `${done} / ${total}`
})
/** Most recent app backup across destinations. */
const lastAppBackup = computed(() => {
    const backups = (status.data?.destinations || []).map((d) => d.last_backup).filter((b) => b && b.finished_at)
    backups.sort((a, b) => String(b.finished_at).localeCompare(String(a.finished_at)))
    return backups[0] || null
})
/** True when a tile value is a real figure or date, false for empty placeholders ("-", "never", unknown state). */
const isResultValue = (value) => /^\d/.test(String(value ?? '').trim())
/** The three status tiles; a non-numeric value is flagged `muted` so it does not look like a result. */
const statusTiles = computed(() => {
    const nightlyValue = t('text.backup.nightly.state.' + nightly.value.state)
    const tablesValue = nightlyTables.value
    const lastValue = lastAppBackup.value ? formatDate(lastAppBackup.value.finished_at) : t('text.backup.never')
    return [
        {
            key: 'nightly',
            label: t('text.backup.nightly.title'),
            value: nightlyValue,
            tone: nightlyTone.value,
            description: nightly.value.date ? t('text.backup.nightly.date', { date: nightly.value.date }) : '',
            muted: nightly.value.state === 'unknown',
        },
        {
            key: 'tables',
            label: t('text.backup.nightly.tables'),
            value: tablesValue,
            tone: nightly.value.state === 'failed' ? 'danger' : 'default',
            description: '',
            muted: !isResultValue(tablesValue),
        },
        {
            key: 'last',
            label: t('text.backup.last_app_backup'),
            value: lastValue,
            tone: lastAppBackup.value && lastAppBackup.value.status === 'failed' ? 'danger' : 'default',
            description: lastAppBackup.value ? t('text.backup.status.' + lastAppBackup.value.status) : '',
            muted: !isResultValue(lastValue),
        },
    ]
})
const drivers = computed(() => status.data?.drivers || [])
const driverByKey = (key) => drivers.value.find((d) => d.key === key) || null
const driverOptions = computed(() => drivers.value.map((d) => ({ value: d.key, label: driverLabel(d.key) })))
const isOauthDriver = (key) => OAUTH_DRIVERS.includes(key) && Boolean(driverByKey(key))

// ---- Formatting --------------------------------------------------------------------------------------------------
const driverLabel = (key) => t('text.backup.driver.' + key)
const intlLocale = computed(() => (locale.value === 'vi' ? 'vi-VN' : 'en-GB'))
const formatDate = (iso) => {
    if (!iso) return '-'
    const date = new Date(iso)
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString(intlLocale.value, { dateStyle: 'short', timeStyle: 'short' })
}
const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) return '-'
    const mb = Number(bytes) / (1024 * 1024)
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb.toFixed(1)} MB`
}
const scheduleText = (schedule) => {
    const time = scheduleToTime(schedule)
    return time ? t('text.backup.daily_at', { time }) : t('text.backup.no_schedule')
}
const lastFailed = (item) =>
    Boolean(item.last_failure_at) && (!item.last_success_at || String(item.last_failure_at) > String(item.last_success_at))
const statusColor = (value) => ({ succeeded: 'success', failed: 'error', partial: 'warning', running: 'processing' })[value] || 'default'
const copyColor = (value) => ({ stored: 'success', failed: 'error', uploading: 'processing', prune_refused: 'warning' })[value] || 'default'

// ---- Run a backup ------------------------------------------------------------------------------------------------
const running = ref(false)
const runNow = async () => {
    if (running.value) return
    running.value = true
    try {
        await runBackup()
        iAlert({ message: t('text.backup.run_started'), type: 'success' })
        loadHistory()
    } catch (error) {
        iAlert({ message: errorText(error, 'text.backup.run_failed'), type: 'error' })
    } finally {
        running.value = false
    }
}

// ---- Probe -------------------------------------------------------------------------------------------------------
const testState = reactive({})
const testDestination = async (item) => {
    if (testState[item.uuid]?.pending) return
    testState[item.uuid] = { pending: true, result: null, error: '' }
    try {
        testState[item.uuid] = { pending: false, result: await testBackupDestination(item.uuid), error: '' }
    } catch (error) {
        testState[item.uuid] = { pending: false, result: null, error: errorText(error, 'text.backup.test.request_failed') }
    }
}

// ---- Connect (OAuth) ---------------------------------------------------------------------------------------------
const connecting = ref('')
const connect = async (item) => {
    if (connecting.value) return
    connecting.value = item.uuid
    try {
        const url = await startBackupOAuth(item.uuid)
        if (!url) throw new Error('no-url')
        window.location.assign(url)
    } catch (error) {
        iAlert({ message: errorText(error, 'text.backup.connect_failed'), type: 'error' })
        connecting.value = ''
    }
}

/** `?oauth=ok|failed` is where the provider redirect lands back on this page. */
const oauthNotice = ref(null)
const readOauthReturn = () => {
    const value = route?.query?.oauth
    if (value === 'ok') oauthNotice.value = { type: 'success', text: t('text.backup.oauth.ok') }
    else if (value === 'failed') oauthNotice.value = { type: 'error', text: t('text.backup.oauth.failed') }
}

// ---- Delete ------------------------------------------------------------------------------------------------------
const removeDestination = async (item) => {
    try {
        await deleteBackupDestination(item.uuid)
        iAlert({ message: t('text.backup.deleted'), type: 'success' })
        await Promise.all([loadDestinations(), loadStatus()])
    } catch (error) {
        const code = backupErrorCodeOf(error)
        const message =
            code === 'BACKUP_DESTINATION_IN_USE' ? t('text.backup.delete_in_use') : errorText(error, 'text.backup.delete_failed')
        iAlert({ message, type: 'error' })
    }
}

// ---- Add / edit drawer -------------------------------------------------------------------------------------------
const drawer = reactive({ open: false, editing: false, uuid: '', saving: false, error: '', errorDetails: [], credentialsSet: {} })
const formEl = ref(null)
/** Field errors keyed by path: `name`, `config.<key>`, `credentials.<key>`, `retention_days`, `lock_days`. */
const fieldErrors = reactive({})
const hasError = (path) => Boolean(fieldErrors[path])
const advancedOpen = ref(false)
/** S3-compatible provider preset: a UI helper only, never part of the payload. */
const provider = ref('other')
const PROVIDERS = ['aws', 'r2', 'other']
const providerOptions = computed(() => PROVIDERS.map((value) => ({ value, label: t('text.backup.provider.' + value) })))
const form = reactive({
    name: '',
    driver: '',
    config: {},
    credentials: {},
    retention_days: DEFAULT_RETENTION_DAYS,
    lock_days: 0,
    time: null,
    use_for_safety: false,
    enabled: true,
})
const currentDriver = computed(() => driverByKey(form.driver))

/** Config defaults for a driver (declared by the API), so a new destination starts with sensible values. */
const defaultConfig = (driver) => {
    const config = {}
    for (const [key, def] of Object.entries(driver?.fields || {})) {
        config[key] = def.type === 'bool' ? Boolean(def.default) : (def.default ?? '')
    }
    return config
}

// Changing the driver on a new destination swaps the field set.
watch(
    () => form.driver,
    (key) => {
        if (drawer.open && !drawer.editing) {
            form.config = defaultConfig(driverByKey(key))
            form.credentials = {}
            provider.value = 'other'
            clearErrors()
        }
    }
)

/** Config keys that are tucked under "Advanced", per driver (technical S3 settings most people never change). */
const ADVANCED_FIELDS = { s3: ['region', 'path_style', 'prefix'] }
/** How the server wants credentials on create: every field, or at least one of them. Updates keep stored secrets. */
const CREDENTIAL_RULE = { s3: 'all', webdav: 'all', sftp: 'any' }
/** Which form field(s) a machine error code belongs to; the first one the current driver has is used. */
const CODE_FIELDS = {
    BACKUP_BUCKET_REQUIRED: ['config.bucket'],
    BACKUP_ENDPOINT_NOT_HTTPS: ['config.endpoint', 'config.base_uri'],
    BACKUP_HOST_REQUIRED: ['config.host'],
    BACKUP_USERNAME_REQUIRED: ['config.username'],
    BACKUP_PORT_INVALID: ['config.port'],
    BACKUP_SFTP_FINGERPRINT_REQUIRED: ['config.host_fingerprint'],
    BACKUP_LOCAL_ROOT_REQUIRED: ['config.root'],
    BACKUP_LOCAL_ROOT_INVALID: ['config.root'],
    BACKUP_LOCAL_ROOT_FORBIDDEN: ['config.root'],
    BACKUP_OAUTH_CLIENT_MISSING: ['config.client_id'],
    BACKUP_CREDENTIALS_REQUIRED: ['credentials.*'],
    BACKUP_RETENTION_NOT_ABOVE_LOCK: ['retention_days'],
    BACKUP_RETENTION_TOO_LONG: ['retention_days'],
}

const showProviders = computed(() => !drawer.editing && form.driver === 's3')
const credentialRequired = computed(() => !drawer.editing && CREDENTIAL_RULE[form.driver] === 'all')

/** Fields of the open drawer in render order: plain config, credentials, then the Advanced toggle and its fields. */
const driverEntries = computed(() => {
    const driver = currentDriver.value
    if (!driver) return []
    const advancedKeys = ADVANCED_FIELDS[driver.key] || []
    const config = Object.entries(driver.fields || {}).map(([key, def]) => ({
        id: 'config-' + key,
        kind: 'config',
        key,
        def,
        path: 'config.' + key,
        advanced: advancedKeys.includes(key),
    }))
    const credentials = (driver.credential_fields || []).map((key) => ({ id: 'cred-' + key, kind: 'credential', key, path: 'credentials.' + key }))
    const entries = [...config.filter((e) => !e.advanced), ...credentials]
    const advanced = config.filter((e) => e.advanced)
    if (advanced.length) {
        entries.push({ id: 'advanced-toggle', toggle: true })
        if (advancedOpen.value) entries.push(...advanced)
    }
    return entries
})
/** Config paths that sit inside the collapsed Advanced section for the current driver. */
const advancedPaths = () => (ADVANCED_FIELDS[form.driver] || []).map((key) => 'config.' + key)

const clearErrors = () => {
    for (const key of Object.keys(fieldErrors)) delete fieldErrors[key]
    drawer.error = ''
    drawer.errorDetails = []
}

/**
 * Client-side check before the request: required fields per driver, so an empty save is explained on screen.
 *
 * @returns {Record<string, string>} Message per invalid field path (empty when valid).
 */
const validateForm = () => {
    const required = t('text.backup.form.field_required')
    const errors = {}
    const blank = (value) => value === null || value === undefined || String(value).trim() === ''
    if (blank(form.name)) errors.name = required
    for (const [key, def] of Object.entries(currentDriver.value?.fields || {})) {
        if (def.required && def.type !== 'bool' && blank(form.config[key])) errors['config.' + key] = required
    }
    if (!drawer.editing) {
        const keys = currentDriver.value?.credential_fields || []
        const rule = CREDENTIAL_RULE[form.driver]
        if (rule === 'all') {
            for (const key of keys) if (blank(form.credentials[key])) errors['credentials.' + key] = required
        } else if (rule === 'any' && keys.length && keys.every((key) => blank(form.credentials[key]))) {
            errors['credentials.' + keys[0]] = required
        }
    }
    if (blank(form.retention_days)) errors.retention_days = required
    return errors
}

/** Resolve a CODE_FIELDS target to a field path of the current driver (`credentials.*` = its first secret). */
const resolveCodePath = (target) => {
    if (target === 'credentials.*') {
        const first = (currentDriver.value?.credential_fields || [])[0]
        return first ? 'credentials.' + first : null
    }
    if (target.startsWith('config.')) return currentDriver.value?.fields?.[target.slice(7)] ? target : null
    return target
}

/**
 * Turn a failed save into field errors plus one summary for the sticky alert.
 *
 * The API answers a malformed body with 406 `{ message: string[], error_code: 'VALIDATION_FAILED' }` (flat messages,
 * no field names) and a rule failure with 422 `{ errorCode, data: { errors: string[] } }` (machine codes). Both are
 * shown in the alert; codes that map to a field also mark that field. A Laravel-style `errors` object
 * (`{ field: [message] }`), if present, is mapped by field name.
 *
 * @param {unknown} error Axios error.
 */
const showSaveError = (error) => {
    const body = error?.response?.data || {}
    drawer.error = errorText(error, 'text.backup.save_failed')
    if (backupErrorCodeOf(error) === 'VALIDATION_FAILED' && Array.isArray(body.message)) {
        drawer.errorDetails = body.message.filter((m) => typeof m === 'string')
    }
    const nested = body.data?.errors ?? body.errors
    if (Array.isArray(nested)) {
        for (const code of nested) {
            for (const target of CODE_FIELDS[code] || []) {
                const path = resolveCodePath(target)
                if (path) {
                    fieldErrors[path] = errorLabel(code)
                    break
                }
            }
        }
    } else if (nested && typeof nested === 'object') {
        for (const [path, messages] of Object.entries(nested)) {
            const message = Array.isArray(messages) ? messages[0] : messages
            if (typeof message === 'string') fieldErrors[path] = message
        }
    }
    const first = backupErrorCodeOf(error)
    if (!Object.keys(fieldErrors).length && CODE_FIELDS[first]) {
        const path = (CODE_FIELDS[first] || []).map(resolveCodePath).find(Boolean)
        if (path) fieldErrors[path] = errorLabel(first)
    }
}

/** Expand Advanced when an invalid field sits inside it, then scroll the first invalid field into view and focus it. */
const revealFirstInvalid = async () => {
    if (advancedPaths().some((path) => fieldErrors[path])) advancedOpen.value = true
    await nextTick()
    const target = formEl.value?.querySelector?.('[data-invalid="true"]')
    if (!target) return
    target.scrollIntoView?.({ block: 'center', behavior: 'smooth' })
    target.querySelector?.('input')?.focus?.({ preventScroll: true })
}

/** Apply a provider preset to the S3 config (only keys the driver declares); the endpoint is only filled when empty. */
const applyProvider = (value) => {
    if (!drawer.open || drawer.editing || form.driver !== 's3') return
    const fields = currentDriver.value?.fields || {}
    const set = (key, next) => {
        if (fields[key]) form.config[key] = next
    }
    if (value === 'aws') {
        set('region', 'us-east-1')
        set('path_style', false)
        if (fields.endpoint && !String(form.config.endpoint || '').trim()) form.config.endpoint = 'https://s3.amazonaws.com'
    } else if (value === 'r2') {
        set('region', 'auto')
        set('path_style', true)
    }
}
watch(provider, applyProvider)

const openCreate = () => {
    // The simplest destination first: a folder on this server (the local driver), else the first driver offered.
    const first = driverByKey('local') || drivers.value[0] || null
    clearErrors()
    advancedOpen.value = false
    provider.value = 'other'
    Object.assign(form, {
        name: '',
        driver: first?.key || '',
        config: defaultConfig(first),
        credentials: {},
        retention_days: DEFAULT_RETENTION_DAYS,
        lock_days: 0,
        time: null,
        use_for_safety: false,
        enabled: true,
    })
    Object.assign(drawer, { open: true, editing: false, uuid: '', saving: false, error: '', errorDetails: [], credentialsSet: {} })
}

/** Secrets are never returned by the API, so an edit starts with empty secret inputs and a "saved" hint. */
const openEdit = (item) => {
    const driver = driverByKey(item.driver)
    clearErrors()
    advancedOpen.value = false
    Object.assign(form, {
        name: item.name,
        driver: item.driver,
        config: { ...defaultConfig(driver), ...(item.config || {}) },
        credentials: {},
        retention_days: item.retention_days,
        lock_days: item.lock_days,
        time: scheduleToTime(item.schedule),
        use_for_safety: Boolean(item.use_for_safety),
        enabled: Boolean(item.enabled),
    })
    Object.assign(drawer, {
        open: true,
        editing: true,
        uuid: item.uuid,
        saving: false,
        error: '',
        errorDetails: [],
        credentialsSet: { ...(item.credentials_set || {}) },
    })
}

const closeDrawer = () => {
    drawer.open = false
}

const saveDestination = async () => {
    if (drawer.saving) return
    clearErrors()
    const invalid = validateForm()
    if (Object.keys(invalid).length) {
        Object.assign(fieldErrors, invalid)
        drawer.error = t('text.backup.form.fix_errors')
        await revealFirstInvalid()
        return
    }
    drawer.saving = true
    const payload = buildDestinationPayload({ ...form }, currentDriver.value, drawer.editing)
    try {
        if (drawer.editing) await updateBackupDestination(drawer.uuid, payload)
        else await createBackupDestination(payload)
    } catch (error) {
        showSaveError(error)
        drawer.saving = false
        await revealFirstInvalid()
        return
    }
    drawer.saving = false
    drawer.open = false
    iAlert({ message: t('text.backup.saved'), type: 'success' })
    loadDestinations()
    loadStatus()
}

// ---- Lifecycle ---------------------------------------------------------------------------------------------------
let started = false
const start = () => {
    if (started) return
    started = true
    readOauthReturn()
    loadAll()
}

watch(
    allowed,
    (ok) => {
        if (ok) start()
        else if (hasAccountData()) router.replace({ name: 'dashboard' })
    },
    { immediate: true }
)
</script>
