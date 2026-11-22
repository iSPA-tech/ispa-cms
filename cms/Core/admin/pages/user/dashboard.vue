<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                text: $t('nav.a.users'),
                title: $t('nav.a.users'),
                icon: 'account-group',
            },
        ]"
    >
        <i-button
            size="sm"
            variant="default"
            data-testid="user-reload"
            :loading="isLoading"
            @click="reloadDashboard"
        >
            <template #icon>
                <i-icon icon="reload" class="size-3.5" />
            </template>
            <span>{{ $t('nav.b.reload') }}</span>
        </i-button>
        <i-button as="router-link" :to="{ name: 'user-create' }" variant="primary">
            <template #icon>
                <i-icon class="size-3.5" icon="plus-thick" />
            </template>
            <span>{{ $t('nav.b.create') }}</span>
        </i-button>
        <template v-if="lastLoadedLabel" #description>
            {{ $t('text.last_updated') }}: {{ lastLoadedLabel }}
        </template>
    </breadcrumbs>

    <div class="space-y-4">
        <a-alert v-if="hasError" type="warning" show-icon :message="errorMessage" />

        <i-box v-if="!isLoading && !hasDashboardData" class="flex items-center justify-center p-6">
            <a-empty :description="$t('text.user.no_dashboard_data')" />
        </i-box>
        <template v-else>
            <i-attention-strip v-if="!isLoading" :items="attentionItems" />

            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
                data-testid="user-kpi-grid"
            >
                <template v-if="isLoading">
                    <i-stat-tile v-for="n in 6" :key="n" loading />
                </template>
                <template v-else>
                    <i-stat-tile
                        v-for="metric in metrics"
                        :key="metric.key"
                        :label="metric.label"
                        :value="metric.value"
                        :to="metric.to"
                        :tone="metric.tone"
                    />
                </template>
            </div>

            <div v-if="!isLoading" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <i-box data-testid="user-roles-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ $t('text.user.role_distribution') }}
                        </h2>
                        <router-link :to="{ name: 'user-roles' }" class="text-xs text-primary-ink hover:underline">
                            {{ $t('text.user.manage_roles') }}
                        </router-link>
                    </div>
                    <i-chart
                        v-if="hasSeriesValues(roleSeries)"
                        type="bars"
                        horizontal
                        :series="roleSeries"
                        :categories="roleCategories"
                    />
                    <a-empty v-else :description="$t('text.user.no_role_data')" />
                </i-box>

                <i-box data-testid="user-latest-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ $t('text.user.latest_users') }}
                        </h2>
                        <router-link :to="{ name: 'user-manage' }" class="text-xs text-primary-ink hover:underline">
                            {{ $t('web.a.show-all') }}
                        </router-link>
                    </div>
                    <div v-if="latestUsers.length > 0" class="divide-y divide-border" data-testid="user-latest-list">
                        <div
                            v-for="user in latestUsers"
                            :key="user.id || user.uuid || user.email || user.name"
                            class="flex items-start justify-between gap-4 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">
                                    {{ user.name || user.full_name || user.username || '—' }}
                                </p>
                                <p v-if="user.email" class="truncate text-xs text-ink-muted">
                                    {{ user.email }}
                                </p>
                            </div>
                            <div class="shrink-0 space-y-1 text-right text-xs text-ink-muted">
                                <div>{{ formatUserRole(user) }}</div>
                                <div>{{ formatDate(user.created_at || user.updated_at) }}</div>
                            </div>
                        </div>
                    </div>
                    <a-empty v-else :description="$t('text.user.no_recent_users')" />
                </i-box>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, onActivated, onMounted, ref } from 'vue'
import { getUserDashboard } from '@/libs'
import { iAlert } from '@/mixins'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'

defineComponent({
    name: 'UserDashboard',
})

const { t } = useI18n()
const isLoading = ref(false)
const hasError = ref(false)
const errorMessage = ref('')
const dashboardData = ref(null)
const lastLoadedAt = ref(null)
const defaultErrorMessage = computed(() => t('text.user.load_failed'))

const stats = computed(() => {
    const payload = dashboardData.value || {}
    const totals = payload.stats || payload.kpis || {}

    return {
        totalUsers: totals.total_users ?? totals.total ?? payload.total_users ?? payload.total ?? 0,
        activeUsers:
            totals.active_users ?? totals.active ?? payload.active_users ?? payload.active ?? 0,
        inactiveUsers:
            totals.inactive_users ??
            totals.inactive ??
            payload.inactive_users ??
            payload.inactive ??
            0,
        newUsers: totals.new_users ?? payload.new_users ?? payload.recent ?? 0,
        students: totals.students ?? payload.students ?? totals.student ?? payload.student ?? 0,
        staffs: totals.staffs ?? totals.staff ?? payload.staffs ?? payload.staff ?? 0,
    }
})

const roleDistribution = computed(() => {
    const roles =
        dashboardData.value?.roles ||
        dashboardData.value?.role_distribution ||
        dashboardData.value?.stats?.roles

    if (Array.isArray(roles)) {
        return roles
    }

    if (roles && typeof roles === 'object') {
        return Object.entries(roles).map(([key, value]) => ({
            name: key,
            total: value,
        }))
    }

    return []
})

const latestUsers = computed(() => {
    const list =
        dashboardData.value?.latest_users ||
        dashboardData.value?.recent_users ||
        dashboardData.value?.recent ||
        []

    return Array.isArray(list) ? list.slice(0, 5) : []
})

const hasDashboardData = computed(() => {
    const data = dashboardData.value
    return data ? Object.keys(data).length > 0 : false
})

const summaryMetrics = computed(() => [
    {
        key: 'totalUsers',
        label: t('text.user.metrics.total_users'),
        value: stats.value.totalUsers,
        tone: 'default',
        to: { name: 'user-manage' },
    },
    {
        key: 'activeUsers',
        label: t('text.user.metrics.active_users'),
        value: stats.value.activeUsers,
        tone: 'success',
        to: { name: 'user-manage', query: { status: 'status_active' } },
    },
    {
        key: 'inactiveUsers',
        label: t('text.user.metrics.inactive_users'),
        value: stats.value.inactiveUsers,
        tone: 'warning',
        to: { name: 'user-manage', query: { status: 'status_inactive' } },
    },
    {
        key: 'newUsers',
        label: t('text.user.metrics.new_users'),
        value: stats.value.newUsers,
        tone: 'info',
    },
])

/** Six KPI tiles in display order: plain counts, then volume, then waiting-on-operator. */
const metrics = computed(() => [...summaryMetrics.value, ...teamMetrics.value])

/** Top roles by user count, capped so the horizontal bars stay readable. */
const topRoles = computed(() =>
    roleDistribution.value
        .map((role) => ({ name: formatRoleName(role), total: Number(role.total ?? role.count ?? 0) || 0 }))
        .sort((a, b) => b.total - a.total)
        .slice(0, 8)
)
const roleCategories = computed(() => topRoles.value.map((role) => role.name))
const roleSeries = computed(() => [{ name: t('nav.a.users'), data: topRoles.value.map((role) => role.total) }])

/**
 * Whether any series carries a positive value (all-zero charts show an empty state instead).
 *
 * @param {Array<{data: number[]}>} series Chart series.
 * @returns {boolean}
 */
const hasSeriesValues = (series) => (series || []).some((s) => (s?.data || []).some((v) => Number(v) > 0))

const attentionItems = computed(() => [
    {
        key: 'inactive_users',
        count: Number(stats.value.inactiveUsers) || 0,
        route: 'user-manage',
        query: { status: 'status_inactive' },
        label: t('text.user.metrics.inactive_users'),
    },
])

const teamMetrics = computed(() => [
    {
        key: 'students',
        label: t('text.user.metrics.student_accounts'),
        value: stats.value.students,
        tone: 'default',
    },
    {
        key: 'staffs',
        label: t('text.user.metrics.team_members'),
        value: stats.value.staffs,
        tone: 'default',
    },
])

const formatDate = (value) => {
    if (!value) {
        return '—'
    }

    const date = dayjs(value)
    return date.isValid() ? date.format('DD/MM/YYYY HH:mm') : value
}

const formatUserRole = (user) => {
    if (Array.isArray(user?.roles) && user.roles.length > 0) {
        return user.roles.map((role) => role.name || role.label || role.title).join(', ')
    }

    return user?.role_name || user?.role || user?.role_label || '—'
}

const formatRoleName = (role) => {
    return role?.name || role?.label || role?.title || t('text.unknown.title')
}

const loadDashboard = async () => {
    isLoading.value = true
    hasError.value = false
    errorMessage.value = ''

    try {
        const response = await getUserDashboard()
        const payload = response?.data || response || {}

        if (response?.status && response?.status !== 'success') {
            hasError.value = true
            errorMessage.value = response?.message || defaultErrorMessage.value
            iAlert(response)
        }

        dashboardData.value = payload || {}
        lastLoadedAt.value = new Date()
    } catch (error) {
        hasError.value = true
        errorMessage.value = error?.message || defaultErrorMessage.value
        iAlert(error, true)
    } finally {
        isLoading.value = false
    }
}

const reloadDashboard = () => {
    if (!isLoading.value) {
        loadDashboard()
    }
}

const lastLoadedLabel = computed(() => {
    if (!lastLoadedAt.value) {
        return ''
    }

    return dayjs(lastLoadedAt.value).format('DD/MM/YYYY HH:mm')
})

onMounted(() => {
    loadDashboard()
})

onActivated(() => {
    if (!hasDashboardData.value && !isLoading.value) {
        loadDashboard()
    }
})
</script>
