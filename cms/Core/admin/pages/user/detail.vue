<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup>
import { computed, defineComponent, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import 'dayjs/locale/vi'
import { useUserDetail } from '@/hooks'
import { rule } from '@/mixins'
import UserDataScopeCard from '@/components/data-scope/user-data-scope-card.vue'
import ProfileTabs from '@/components/profile/profile-tabs.vue'

dayjs.extend(relativeTime)

defineComponent({
    name: 'UserDetailPage',
})

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const userId = computed(() => route.params.uuid || '')

const includeParams = computed(() => ({
    include: 'roles,settings,meta,audit_logs,avatar,status_enum',
}))

const {
    data: queryResponse,
    isLoading,
    isFetching,
    error,
    refetch,
} = useUserDetail(userId, includeParams, {
    keepPreviousData: true,
    enabled: computed(() => Boolean(userId.value)),
})

const user = computed(() => queryResponse.value?.data || {})
const roles = computed(() => (Array.isArray(user.value.roles) ? user.value.roles : []))
const permissions = computed(() =>
    Array.isArray(user.value.permissions) ? user.value.permissions : []
)
const settings = computed(() => (Array.isArray(user.value.settings) ? user.value.settings : []))
const metaEntries = computed(() => buildMetaEntries(user.value.meta))
const auditLogs = computed(() =>
    Array.isArray(user.value.audit_logs) ? user.value.audit_logs : []
)
const statistics = computed(() => user.value.statistics || {})

/** Node names the card has loaded, keyed by "dimension|node key"; never rendered, only used to look up a name. */
const scopeLabels = ref({})

/**
 * Remember the node names of the user's current scope (emitted by the data-scope card after each load).
 *
 * @param {Array<{ dimension: string, node: { key: string, label?: string } }>} rows Current assignments.
 */
function onScopeLoaded(rows) {
    const map = {}
    for (const row of Array.isArray(rows) ? rows : []) {
        if (row?.node?.key && row.node.label) map[`${row.dimension}|${row.node.key}`] = row.node.label
    }
    scopeLabels.value = map
}

/** True for audit entries written by the data-scope module (for example `data_scope.user_updated`). */
const isDataScopeLog = (log) => String(log?.action || '').startsWith('data_scope.')

/**
 * Plain sentences for one data-scope audit entry, built from the before/after assignment lists.
 *
 * Node names come from the card's current scope; a node whose name is unknown (for example one that was removed)
 * gets a generic phrase. Keys, ids and raw payloads are never shown.
 *
 * @param {{ changed?: { before?: { assignments?: Array<object> }, after?: { assignments?: Array<object> } } }} log Audit entry.
 * @returns {string[]}
 */
function scopeHistorySentences(log) {
    const asMap = (rows) => {
        const map = new Map()
        for (const row of Array.isArray(rows) ? rows : []) {
            if (row?.node_key) map.set(`${row.dimension}|${row.node_key}`, row)
        }
        return map
    }
    const before = asMap(log?.changed?.before?.assignments)
    const after = asMap(log?.changed?.after?.assignments)
    const nameOf = (id, row) => scopeLabels.value[id] || row?.label || t('text.data_scope.history_unknown_node')
    const reachOf = (expansion) => t(`text.data_scope.history_reach_${expansion === 'branch' || expansion === 'self' ? expansion : 'descendants'}`)
    const sentences = []

    for (const [id, row] of after) {
        if (!before.has(id)) {
            sentences.push(
                row.expansion === 'self'
                    ? t('text.data_scope.history_added', { name: nameOf(id, row) })
                    : t('text.data_scope.history_added_reach', { name: nameOf(id, row), reach: reachOf(row.expansion) })
            )
        } else if (before.get(id).expansion !== row.expansion) {
            sentences.push(t('text.data_scope.history_changed', { name: nameOf(id, row), reach: reachOf(row.expansion) }))
        }
    }
    for (const id of before.keys()) {
        if (!after.has(id)) sentences.push(t('text.data_scope.history_removed', { name: nameOf(id, before.get(id)) }))
    }

    return sentences.length ? sentences : [t('text.data_scope.history_no_change')]
}

const statusTag = computed(() => {
    const key = user.value.status || ''
    // status_label from the API is the enum name (a code), so the translated label wins.
    const label = userStatusLabel(key) || user.value.status_label || key || t('text.unknown.title')

    return {
        key,
        label,
        color: userStatusColor(key),
    }
})

const typeTag = computed(() => {
    const key = user.value.type || ''
    const label = user.value.type_label || key || '—'

    return {
        key,
        label,
    }
})

const avatarUrl = computed(() => user.value.avatar?.url || user.value.avatar?.path || null)

const summaryCards = computed(() => [
    {
        key: 'roles',
        label: t('text.user.total_roles'),
        value: formatNumber(statistics.value.role_count ?? roles.value.length),
    },
    {
        key: 'permissions',
        label: t('text.user.total_permissions'),
        value: formatNumber(statistics.value.permission_count ?? permissions.value.length),
    },
    {
        key: 'created_at',
        label: t('text.created_at'),
        value: formatDate(user.value.created_at),
        helper: formatRelative(user.value.created_at),
    },
    {
        key: 'updated_at',
        label: t('text.updated_at'),
        value: formatDate(user.value.updated_at),
        helper: formatRelative(user.value.updated_at),
    },
])

const accountInformation = computed(() => [
    {
        key: 'email',
        label: t('validation.attributes.email'),
        value: user.value.email || '—',
    },
    {
        key: 'username',
        label: t('validation.attributes.username'),
        value: user.value.username || '—',
    },
    {
        key: 'phone',
        label: t('validation.attributes.mobile'),
        value: user.value.mobile || '—',
    },
    {
        key: 'type',
        label: t('text.user.type'),
        value: typeTag.value.label,
    },
    {
        key: 'status',
        label: t('validation.attributes.status'),
        value: statusTag.value.label,
    },
    {
        key: 'level',
        label: t('text.user.level'),
        value: user.value.level ?? '—',
    },
    {
        key: 'email_verified_at',
        label: t('text.user.email_verified_at'),
        value: formatDate(user.value.email_verified_at),
    },
])

const permissionsPreview = computed(() => {
    const list = permissions.value
    if (list.length <= 20) {
        return {
            list,
            remaining: 0,
        }
    }

    return {
        list: list.slice(0, 20),
        remaining: list.length - 20,
    }
})

const isRefreshing = computed(() => isFetching.value && Boolean(user.value.uuid))

const errorMessage = computed(() => {
    if (!error.value) {
        return ''
    }

    return error.value.message || t('mgs.error.unknown')
})

const handleReload = () => {
    refetch()
}

const goToEdit = () => {
    if (!user.value.uuid) {
        return
    }

    router.push({
        name: 'user-update',
        params: { uuid: user.value.uuid },
    })
}

function formatDate(value) {
    if (!value) {
        return '—'
    }
    const parsed = dayjs(value)
    return parsed.isValid() ? parsed.format('DD/MM/YYYY HH:mm') : '—'
}

function formatRelative(value) {
    if (!value) {
        return ''
    }
    const parsed = dayjs(value)
    // dayjs keeps one global locale that is not tied to the user's language, so pass it on every call.
    return parsed.isValid() ? parsed.locale(locale.value === 'vi' ? 'vi' : 'en').fromNow() : ''
}

function formatNumber(value) {
    const numeric = Number(value)
    if (!Number.isFinite(numeric)) {
        return '0'
    }

    return new Intl.NumberFormat('en-US').format(numeric)
}

function normalizeKey(value) {
    if (!value) {
        return ''
    }

    return value
        .toString()
        .replace(/[_\-]+/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase())
}

function buildMetaEntries(meta) {
    if (!meta || typeof meta !== 'object') {
        return []
    }

    return Object.entries(meta).map(([key, payload]) => {
        if (payload && typeof payload === 'object') {
            const label = payload.label || normalizeKey(key)
            const value = payload.value ?? payload.display ?? payload

            return {
                key,
                label,
                value,
            }
        }

        return {
            key,
            label: normalizeKey(key),
            value: payload,
        }
    })
}

/** Same labels as the users list: reuse the existing active/inactive/banned keys instead of the raw status code. */
function userStatusLabel(status) {
    if (['user_banned', 'user_blocked', 'banned', 'blocked'].includes(status)) return t('text.user.banned')
    if (['user_inactive', 'inactive'].includes(status)) return t('text.user.inactive')
    if (['user_active', 'active'].includes(status)) return t('text.user.active')
    return ''
}

function userStatusColor(status) {
    switch (status) {
        case 'user_active':
        case 'active':
            return 'green'
        case 'user_inactive':
        case 'inactive':
            return 'default'
        case 'user_banned':
        case 'user_blocked':
        case 'banned':
        case 'blocked':
            return 'red'
        default:
            return 'default'
    }
}
</script>

<template>
    <div class="max-w-7xl mx-auto space-y-4">
        <breadcrumbs
            :items="[
                {
                    icon: 'account-group',
                    title: $t('nav.a.users'),
                    name: 'user-manage',
                },
                {
                    icon: 'account',
                    text: user?.name || user?.email || `#${route.params.uuid}`,
                },
            ]"
        >
            <div class="flex items-center gap-2 ml-auto">
                <router-link
                    v-if="user?.uuid"
                    :to="{ name: 'user-update', params: { uuid: user?.uuid } }"
                >
                    <a-button size="small" type="default">
                        <i-icon class="mr-1 size-3" icon="pencil-outline" />
                        <span>{{ $t('nav.b.update') }}</span>
                    </a-button>
                </router-link>
                <a-button :loading="isRefreshing" size="small" type="default" @click="handleReload">
                    <i-icon class="mr-1 size-3" icon="reload-alert" />
                    <span>{{ $t('nav.b.reload') }}</span>
                </a-button>
            </div>
        </breadcrumbs>

        <a-skeleton v-if="isLoading" :paragraph="{ rows: 12 }" active />

        <a-result
            v-else-if="errorMessage"
            :sub-title="errorMessage"
            :title="$t('mgs.failed.load', { name: $t('nav.a.user') })"
            status="error"
        >
            <template #extra>
                <a-button type="primary" @click="handleReload">
                    <i-icon class="mr-1 size-3" icon="reload" />
                    {{ $t('nav.b.retry') }}
                </a-button>
            </template>
        </a-result>

        <template v-else>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-4">
                    <div class="i-box space-y-4">
                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="flex items-start gap-3">
                                <a-avatar v-if="avatarUrl" :src="avatarUrl" size="large" />
                                <div>
                                    <h1 class="text-xl font-semibold text-slate-900">
                                        {{ user.name || user.email || user.username }}
                                    </h1>
                                    <div class="text-sm text-slate-500 space-y-0.5">
                                        <p>
                                            <span class="font-medium"
                                                >{{ $t('validation.attributes.status') }}:</span
                                            >
                                            {{ statusTag.label }}
                                        </p>
                                        <p>
                                            <span class="font-medium"
                                                >{{ $t('text.user.type') }}:</span
                                            >
                                            {{ typeTag.label }}
                                        </p>
                                        <p v-if="user.email">
                                            <span class="font-medium"
                                                >{{ $t('validation.attributes.email') }}:</span
                                            >
                                            {{ user.email }}
                                        </p>
                                        <p v-if="user.username">
                                            <span class="font-medium"
                                                >{{ $t('validation.attributes.username') }}:</span
                                            >
                                            {{ user.username }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <dl
                                class="flex flex-col gap-2 text-right text-xs uppercase text-slate-500"
                            >
                                <div class="flex items-center justify-end gap-2">
                                    <dt>{{ $t('validation.attributes.status') }}</dt>
                                    <dd>
                                        <a-tag
                                            :color="statusTag.color"
                                            class="m-0 text-[11px] uppercase"
                                        >
                                            {{ statusTag.label }}
                                        </a-tag>
                                    </dd>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <dt>{{ $t('text.user.type') }}</dt>
                                    <dd>
                                        <a-tag class="m-0 text-[11px] uppercase">
                                            {{ typeTag.label }}
                                        </a-tag>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <div
                                v-for="card in summaryCards"
                                :key="card.key"
                                class="rounded-lg border border-slate-100 bg-white p-4 shadow-sm"
                            >
                                <p class="text-xs uppercase tracking-wide text-slate-500">
                                    {{ card.label }}
                                </p>
                                <p class="mt-1 text-lg font-semibold text-slate-800">
                                    {{ card.value }}
                                </p>
                                <p v-if="card.helper" class="mt-1 text-xs text-slate-500">
                                    {{ card.helper }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="i-box space-y-3">
                        <h2 class="text-sm font-semibold uppercase text-slate-700">
                            {{ $t('text.user.account_information') }}
                        </h2>
                        <dl class="divide-y divide-slate-100">
                            <div
                                v-for="item in accountInformation"
                                :key="item.key"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <dt class="text-xs font-medium uppercase text-slate-500">
                                    {{ item.label }}
                                </dt>
                                <dd class="text-sm text-slate-700 text-right break-all">
                                    {{ item.value || '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <user-data-scope-card
                        v-if="rule('data_scope.manage') && (user.uuid || userId)"
                        :user-uuid="user.uuid || userId"
                        @loaded="onScopeLoaded"
                    />

                    <div class="i-box space-y-3">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold uppercase text-slate-700">
                                {{ $t('text.user.roles') }}
                            </h2>
                            <span class="text-xs text-slate-500">
                                {{ t('text.user.total_roles') }}:
                                {{ formatNumber(roles.length) }}
                            </span>
                        </div>
                        <div v-if="roles.length" class="space-y-3">
                            <div
                                v-for="role in roles"
                                :key="role.id"
                                class="rounded-lg border border-slate-100 p-3"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-slate-800">
                                            {{ role.name }}
                                        </p>
                                        <p
                                            v-if="role.custom?.level !== undefined"
                                            class="text-xs text-slate-500"
                                        >
                                            {{ $t('text.user.level') }}:
                                            {{ role.custom.level }}
                                        </p>
                                    </div>
                                    <a-tag
                                        :color="role.custom?.badge || 'default'"
                                        class="m-0 text-[11px] uppercase"
                                    >
                                        {{ role.custom?.title || role.name }}
                                    </a-tag>
                                </div>
                                <div
                                    v-if="role.permissions?.length"
                                    class="mt-2 flex flex-wrap gap-1"
                                >
                                    <a-tag
                                        v-for="permission in role.permissions"
                                        :key="`${role.id}-${permission.id}`"
                                        class="m-0 text-[11px]"
                                    >
                                        {{ permission.group }}.{{ permission.name }}
                                    </a-tag>
                                </div>
                            </div>
                        </div>
                        <a-empty
                            v-else
                            :description="$t('text.user.no_roles')"
                        />
                    </div>

                    <div class="i-box space-y-3">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold uppercase text-slate-700">
                                {{ $t('text.user.permissions') }}
                            </h2>
                            <span class="text-xs text-slate-500">
                                {{ formatNumber(permissions.length) }}
                            </span>
                        </div>
                        <div v-if="permissionsPreview.list.length" class="flex flex-wrap gap-1">
                            <a-tag
                                v-for="permission in permissionsPreview.list"
                                :key="permission"
                                class="m-0 text-[11px]"
                            >
                                {{ permission }}
                            </a-tag>
                            <a-tag v-if="permissionsPreview.remaining > 0" class="m-0 text-[11px]">
                                +{{ permissionsPreview.remaining }}
                            </a-tag>
                        </div>
                        <a-empty
                            v-else
                            :description="$t('text.user.no_permissions')"
                        />
                    </div>

                    <div class="i-box space-y-3">
                        <h2 class="text-sm font-semibold uppercase text-slate-700">
                            {{ $t('text.user.settings') }}
                        </h2>
                        <a-table
                            v-if="settings.length"
                            :columns="[
                                { key: 'key', title: t('validation.attributes.key') },
                                { key: 'value', title: t('validation.attributes.value') },
                            ]"
                            :data-source="settings"
                            :pagination="false"
                            :row-key="(row) => row.id || row.key"
                            size="small"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'key'">
                                    <span class="text-xs font-medium uppercase text-slate-500">
                                        {{ normalizeKey(record.key) }}
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="text-sm text-slate-700">
                                        {{
                                            record.value === null ||
                                            record.value === undefined ||
                                            record.value === ''
                                                ? '—'
                                                : record.value
                                        }}
                                    </span>
                                </template>
                            </template>
                        </a-table>
                        <a-empty
                            v-else
                            :description="$t('text.user.no_settings')"
                        />
                    </div>

                    <div class="i-box space-y-3">
                        <h2 class="text-sm font-semibold uppercase text-slate-700">
                            {{ $t('text.user.meta') }}
                        </h2>
                        <dl v-if="metaEntries.length" class="divide-y divide-slate-100">
                            <div
                                v-for="meta in metaEntries"
                                :key="meta.key"
                                class="py-2 text-sm text-slate-700"
                            >
                                <dt class="text-xs font-medium uppercase text-slate-500">
                                    {{ meta.label }}
                                </dt>
                                <dd class="mt-1 whitespace-pre-wrap text-slate-700">
                                    {{
                                        meta.value === null ||
                                        meta.value === undefined ||
                                        meta.value === ''
                                            ? '—'
                                            : meta.value
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <a-empty
                            v-else
                            :description="$t('text.user.no_meta')"
                        />
                    </div>

                    <div class="i-box space-y-3">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold uppercase text-slate-700">
                                {{ $t('text.user.activity') }}
                            </h2>
                        </div>
                        <a-timeline v-if="auditLogs.length" mode="left">
                            <a-timeline-item
                                v-for="log in auditLogs"
                                :key="log.id || log.created_at"
                            >
                                <div class="text-sm font-medium text-slate-800">
                                    {{
                                        isDataScopeLog(log)
                                            ? t('text.data_scope.history_event')
                                            : normalizeKey(log.action)
                                    }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ formatDate(log.created_at) }}
                                    <span v-if="formatRelative(log.created_at)">
                                        ({{ formatRelative(log.created_at) }})
                                    </span>
                                </div>
                                <div v-if="log.user" class="text-xs text-slate-500">
                                    {{ $t('text.user.performed_by') }}:
                                    {{ log.user.name || log.user.email }}
                                </div>
                                <ul
                                    v-if="isDataScopeLog(log)"
                                    class="m-0 mt-1 list-none space-y-0.5 p-0 text-xs text-slate-600"
                                    data-testid="scope-history"
                                >
                                    <li
                                        v-for="(sentence, index) in scopeHistorySentences(log)"
                                        :key="index"
                                    >
                                        {{ sentence }}
                                    </li>
                                </ul>
                                <pre
                                    v-else-if="log.changed"
                                    class="mt-1 rounded bg-slate-50 p-2 text-[11px] text-slate-600"
                                    >{{ JSON.stringify(log.changed, null, 2) }}</pre
                                >
                            </a-timeline-item>
                        </a-timeline>
                        <a-empty
                            v-else
                            :description="$t('text.user.no_activity')"
                        />
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="i-box space-y-3">
                        <h2 class="text-sm font-semibold uppercase text-slate-700">
                            {{ $t('text.user.summary') }}
                        </h2>
                        <dl class="divide-y divide-slate-100">
                            <div class="flex items-center justify-between gap-3 py-2">
                                <dt class="text-xs font-medium uppercase text-slate-500">
                                    {{ $t('text.created_at') }}
                                </dt>
                                <dd class="text-sm text-slate-700 text-right">
                                    {{ formatDate(user.created_at) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 py-2">
                                <dt class="text-xs font-medium uppercase text-slate-500">
                                    {{ $t('text.updated_at') }}
                                </dt>
                                <dd class="text-sm text-slate-700 text-right">
                                    {{ formatDate(user.updated_at) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 py-2">
                                <dt class="text-xs font-medium uppercase text-slate-500">
                                    {{ $t('text.user.permissions') }}
                                </dt>
                                <dd class="text-sm text-slate-700 text-right">
                                    {{ formatNumber(permissions.length) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 py-2">
                                <dt class="text-xs font-medium uppercase text-slate-500">
                                    {{ $t('text.user.settings') }}
                                </dt>
                                <dd class="text-sm text-slate-700 text-right">
                                    {{ formatNumber(settings.length) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="i-box space-y-3">
                        <h2 class="text-sm font-semibold uppercase text-slate-700">
                            {{ $t('text.user.actions') }}
                        </h2>
                        <div class="space-y-2">
                            <a-button block size="small" type="primary" @click="goToEdit">
                                <i-icon class="mr-1 size-3" icon="pencil-outline" />
                                <span>{{ $t('nav.b.update') }}</span>
                            </a-button>
                            <a-button
                                :loading="isRefreshing"
                                block
                                size="small"
                                type="default"
                                @click="handleReload"
                            >
                                <i-icon class="mr-1 size-3" icon="reload" />
                                <span>{{ $t('nav.b.reload') }}</span>
                            </a-button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs other modules contribute to a user profile; renders nothing when none applies. -->
            <profile-tabs v-if="user.uuid || userId" target="user" :uuid="user.uuid || userId" />
        </template>
    </div>
</template>
