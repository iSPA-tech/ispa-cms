<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4" data-testid="account-dashboard">
        <!-- "Cần xử lý" first (1c): what is still open on this account, each with where to fix it. -->
        <i-attention-strip :items="attentionItems" />

        <i-stat-strip :cols="4" data-testid="account-kpi-grid">
            <i-stat-tile
                :label="t('text.acc.profile-completion')"
                :value="profileCompletion"
                format="percent"
                :tone="profileCompletion >= 100 ? 'success' : 'warning'"
                :to="{ name: 'account-update' }"
                :description="`${completedChecklist}/${checklistItems.length}`"
                :loading="loadingSettings"
            />
            <i-stat-tile
                :label="t('text.acc.two-step-verification')"
                :value="twoFaEnabled ? t('text.acc.enabled') : t('text.acc.disabled')"
                :tone="twoFaEnabled ? 'success' : 'warning'"
                :to="{ name: 'account-setting' }"
                :loading="loadingSettings"
            />
            <i-stat-tile
                :label="t('text.acc.needs-attention')"
                :value="openChecklistCount"
                :tone="openChecklistCount > 0 ? 'warning' : 'success'"
                :loading="loadingSettings"
            />
            <i-stat-tile
                :label="t('text.acc.recent-activity')"
                :value="activities?.total || 0"
                :to="{ name: 'account-activity' }"
                :loading="loadingTable"
            />
        </i-stat-strip>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                <i-box data-testid="account-activity-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ t('text.acc.recent-activity') }}
                        </h2>
                        <router-link :to="{ name: 'account-activity' }" class="text-xs text-primary-ink hover:underline">
                            {{ t('text.acc.view-full-history') }}
                        </router-link>
                    </div>
                    <a-spin :spinning="loadingTable" class="block">
                        <a-table
                            size="small"
                            :columns="columns"
                            :data-source="(activities.data || []).slice(0, 5)"
                            :locale="{ emptyText: t('text.acc.no-recent-activity') }"
                            :pagination="false"
                        >
                            <template #bodyCell="{ column: col, record: row }">
                                <template v-if="col.key === 'method'">
                                    <a-tag :color="methodColor(row.options?.method)">
                                        {{ row.options?.method || t('text.acc.not-applicable') }}
                                    </a-tag>
                                </template>
                                <template v-else-if="col.key === 'action'">
                                    {{ $t(row.action?.replace('action.', 'a.')) }}
                                </template>
                                <template v-else-if="col.key === 'target'">
                                    <div class="space-y-1">
                                        <p class="m-0 text-xs text-ink-faint">
                                            {{ formatTargetType(row.targetType) }}
                                        </p>
                                        <span class="text-sm text-ink">
                                            {{ row.target?.name || t('text.acc.no-linked-target') }}
                                        </span>
                                    </div>
                                </template>
                                <template v-else-if="col.key === 'created_at'">
                                    {{ formatTimestamp(row.createdAt, t('text.acc.unknown-time')) }}
                                </template>
                            </template>
                        </a-table>
                    </a-spin>
                </i-box>

                <i-box data-testid="account-profile-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ t('text.acc.profile-overview') }}
                        </h2>
                        <router-link :to="{ name: 'account-update' }" class="text-xs text-primary-ink hover:underline">
                            {{ t('text.acc.edit-profile') }}
                        </router-link>
                    </div>
                    <dl class="m-0 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div v-for="item in accountSummaryItems" :key="item.label" class="min-w-0">
                            <dt class="text-xs text-ink-faint">{{ item.label }}</dt>
                            <dd class="m-0 mt-0.5 break-words text-sm font-medium text-ink">{{ item.value }}</dd>
                        </div>
                    </dl>
                </i-box>

                <i-box v-if="filteredAttributes.length > 0" data-testid="account-attributes-box">
                    <h2 class="mb-3 text-sm font-semibold text-ink">
                        {{ t('text.acc.additional-profile-details') }}
                    </h2>
                    <div class="space-y-4">
                        <div v-for="meta in filteredAttributes" :key="meta.id">
                            <h3 class="mb-2 text-sm font-semibold text-ink">
                                {{ meta.name }}
                            </h3>
                            <dl class="m-0 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div v-for="attribute in meta.options" :key="attribute.id" class="min-w-0">
                                    <dt class="text-xs text-ink-faint">
                                        {{ attribute.translated_label || attribute.name }}
                                    </dt>
                                    <dd class="m-0 mt-0.5 break-words text-sm text-ink">
                                        {{ metaValue(attribute) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </i-box>
            </div>

            <div class="space-y-4">
                <i-box data-testid="account-security-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ t('text.acc.security-snapshot') }}
                        </h2>
                        <router-link :to="{ name: 'account-password' }" class="text-xs text-primary-ink hover:underline">
                            {{ t('text.acc.action.change-password') }}
                        </router-link>
                    </div>
                    <dl class="m-0 space-y-3">
                        <div v-for="item in securityHighlights" :key="item.label" class="flex items-center justify-between gap-3">
                            <dt class="text-sm text-ink-muted">{{ item.label }}</dt>
                            <dd class="m-0">
                                <span
                                    class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="item.done ? 'bg-success-soft text-success-ink' : 'bg-warning-soft text-warning-ink'"
                                >
                                    {{ item.value }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-faint">{{ t('text.acc.password-history') }}</dt>
                            <dd class="m-0 mt-0.5 text-sm text-ink">{{ lastPasswordChangeLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-faint">{{ t('text.acc.sign-in-policy') }}</dt>
                            <dd class="m-0 mt-0.5 text-sm text-ink">
                                {{
                                    twoFaEnabled
                                        ? t('text.acc.verification-required-sign-in')
                                        : t('text.acc.verification-disabled-sign-in')
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-faint">{{ t('text.acc.last-sign-in') }}</dt>
                            <dd class="m-0 mt-0.5 text-sm text-ink">{{ lastLoginLabel }}</dd>
                        </div>
                    </dl>
                </i-box>

                <i-box data-testid="account-preferences-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ t('text.acc.preferences-snapshot') }}
                        </h2>
                        <router-link :to="{ name: 'account-setting' }" class="text-xs text-primary-ink hover:underline">
                            {{ t('text.acc.action.open-preferences') }}
                        </router-link>
                    </div>
                    <dl class="m-0 space-y-3">
                        <div>
                            <dt class="text-xs text-ink-faint">{{ t('text.acc.localization-language') }}</dt>
                            <dd class="m-0 mt-0.5 text-sm font-medium text-ink">{{ languageLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-faint">{{ t('text.acc.localization-timezone') }}</dt>
                            <dd class="m-0 mt-0.5 text-sm font-medium text-ink">{{ timezoneLabel }}</dd>
                        </div>
                    </dl>
                </i-box>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isEmpty } from 'lodash-es'
import { useStorage } from '@vueuse/core'
import ajax from '@/ajax'
import { can } from '@/hooks/useCan'
import { useAccount } from '@/hooks'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { dateFormat, iAlert } from '@/mixins'
import {
    buildAccountChecklist,
    countCompletedItems,
    formatAccountValue,
    getCurrentMetaMap,
    getSettingOptionLabel,
    getSettingValue,
} from './account-helpers'

defineComponent({
    name: 'AccountDashboard',
})

const { t } = useI18n()
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const settings = ref({})
const activities = ref({})
const loadingTable = ref(false)
const loadingSettings = ref(false)
const attributes = inject('meta_attributes', ref([]))

useAccount({
    enabled: isEmpty(accountData.value),
    onSuccess: (data) => {
        accountData.value = data.data || {}
    },
})

const columns = computed(() => [
    {
        key: 'method',
        dataIndex: 'method',
        title: t('text.acc.activity-method'),
        width: 110,
    },
    {
        key: 'action',
        dataIndex: 'action',
        title: t('text.action'),
    },
    {
        key: 'target',
        dataIndex: 'target',
        title: t('text.acc.activity-target'),
    },
    {
        key: 'created_at',
        dataIndex: 'created_at',
        title: t('text.date'),
        align: 'right',
        width: 180,
    },
])

const activitySnapshotParams = {
    page: 1,
    pageSize: 5,
}

const checklistItems = computed(() => buildAccountChecklist(accountData.value, settings.value, t))
const completedChecklist = computed(() => countCompletedItems(checklistItems.value))
const profileCompletion = computed(() => {
    return checklistItems.value.length > 0
        ? Math.round((completedChecklist.value / checklistItems.value.length) * 100)
        : 0
})
const completedChecklistLabel = computed(() => {
    return completedChecklist.value === checklistItems.value.length
        ? t('text.acc.completed-basics')
        : t('text.acc.finish-remaining-checks')
})
const twoFaEnabled = computed(() => Boolean(getSettingValue(settings.value, 'security', 'two_fa', false)))
const openChecklist = computed(() => checklistItems.value.filter((item) => !item.done))
/** Open checklist rows as "Cần xử lý" panel items (yes/no conditions with their own action label). */
const attentionItems = computed(() =>
    openChecklist.value.map((item) => ({
        key: item.key,
        route: item.routeName,
        count: true,
        label: item.label,
        description: item.description,
        action: item.actionLabel,
    }))
)
const openChecklistCount = computed(() => openChecklist.value.length)

/**
 * Map an HTTP method to an a-tag colour preset.
 * @param {string} method HTTP method from the activity log.
 * @returns {string} Ant Design tag preset.
 */
function methodColor(method) {
    return { DELETE: 'error', PUT: 'processing', POST: 'success' }[method] || 'default'
}

const securityHighlights = computed(() => {
    return [
        {
            label: t('text.acc.email-verification'),
            value: accountData.value?.emailVerifiedAt ? t('text.acc.verified') : t('text.acc.pending'),
            done: Boolean(accountData.value?.emailVerifiedAt),
        },
        {
            label: t('text.acc.two-step-verification'),
            value: getSettingValue(settings.value, 'security', 'two_fa', false)
                ? t('text.acc.enabled')
                : t('text.acc.disabled'),
            done: Boolean(getSettingValue(settings.value, 'security', 'two_fa', false)),
        },
        {
            label: t('text.acc.password-email-alerts'),
            value: getSettingValue(settings.value, 'security', 'email_change_password', false)
                ? t('text.acc.enabled')
                : t('text.acc.disabled'),
            done: Boolean(getSettingValue(settings.value, 'security', 'email_change_password', false)),
        },
    ]
})
const languageLabel = computed(() =>
    getSettingOptionLabel(settings.value, 'localization', 'language', t('text.acc.not-configured'))
)
const timezoneLabel = computed(() =>
    getSettingOptionLabel(settings.value, 'localization', 'timezone', t('text.acc.not-configured'))
)
const lastLoginLabel = computed(() =>
    formatTimestamp(accountData.value?.lastLogin?.createdAt, t('text.acc.no-recorded-login'))
)
const lastPasswordChangeLabel = computed(() => {
    if (accountData.value?.lastChangePassword?.createdAt) {
        const createdAt = formatTimestamp(accountData.value.lastChangePassword.createdAt)
        const ipAddress = accountData.value.lastChangePassword.ipAddress || t('text.acc.unknown-ip')

        // Stored messages use {d}; {date} is kept for older packs.
        return t('text.acc.password-last-changed-ip', { date: createdAt, d: createdAt, ip: ipAddress })
    }

    // Only use the "since {date}" sentence when a real date is available, so no dangling "kể từ ." shows.
    const since = accountData.value?.createdAt
        ? dateFormat(accountData.value.createdAt, 'YYYY-MM-DD')
        : ''
    if (since && since !== 'Invalid Date') {
        return t('text.acc.password-not-updated-since', { date: since, d: since })
    }

    return t('text.acc.password-not-updated-yet')
})
const accountSummaryItems = computed(() => {
    return [
        {
            label: t('validation.attributes.fullname'),
            value: formatAccountValue(accountData.value?.name, t('text.acc.not-provided')),
        },
        {
            label: t('validation.attributes.username'),
            value: formatAccountValue(accountData.value?.username, t('text.acc.not-provided')),
        },
        {
            label: t('text.auth.email'),
            value: formatAccountValue(accountData.value?.email, t('text.acc.not-provided')),
        },
        {
            label: t('validation.attributes.mobile'),
            value: formatAccountValue(accountData.value?.mobile, t('text.acc.not-provided')),
        },
        {
            label: t('text.roles'),
            value: formatAccountValue(
                (accountData.value?.roles || []).map((role) => role.name),
                t('text.acc.not-provided')
            ),
        },
        {
            label: t('text.created_at'),
            value: formatTimestamp(accountData.value?.createdAt, t('text.acc.unknown-date')),
        },
    ]
})
const filteredAttributes = computed(() => {
    return (attributes.value || [])
        .map((meta) => ({
            ...meta,
            options: (meta.options || []).filter((attribute) => attribute.is_filter),
        }))
        .filter((meta) => meta.options.length > 0)
})
function formatTimestamp(value, fallback = t('text.acc.not-available')) {
    return value ? dateFormat(value, 'YYYY-MM-DD HH:mm') : fallback
}

function formatTargetType(value = '') {
    return (value || '')
        .replace('App\\Models\\', '')
        .replace('Cms\\Core\\Models\\', '')
}

function metaValue(attribute) {
    const currentMeta = getCurrentMetaMap(accountData.value)
    const key = `${attribute.name}_${attribute.id}`

    return formatAccountValue(currentMeta[key]?.value, t('text.acc.not-provided'))
}

function getActivities() {
    loadingTable.value = true
    ajax.get('account/activities', { params: activitySnapshotParams })
        .then((res) => {
            if (res.data.status === 'success') {
                activities.value = res.data.data
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingTable.value = false
        })
}

function loadSettings(uuid) {
    // The settings endpoint needs the user update permission; a read-only role skips the request instead of failing it.
    if (!uuid || !can('user', 'update')) {
        return
    }

    loadingSettings.value = true
    ajax.get(`user/${uuid}/settings`)
        .then((res) => {
            if (res.data.status === 'success') {
                settings.value = res.data.data || {}
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingSettings.value = false
        })
}

watch(
    () => accountData.value?.uuid,
    (uuid) => {
        if (uuid) {
            loadSettings(uuid)
        }
    },
    { immediate: true }
)

onMounted(() => {
    getActivities()
})
</script>
