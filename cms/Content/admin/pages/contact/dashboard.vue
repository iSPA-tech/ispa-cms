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
                text: $t('nav.a.contacts'),
                title: $t('nav.a.contacts'),
                icon: 'email-outline',
            },
        ]"
    >
        <div v-if="canViewShowroomInquiryScope" class="max-w-full overflow-x-auto">
            <a-segmented
                :value="isShowroomInquiryScope ? 'showroom' : 'all'"
                :options="scopeOptions"
                size="small"
                data-testid="contact-scope"
                @change="onScopeChange"
            />
        </div>
        <i-button
            variant="default"
            size="sm"
            data-testid="contact-reload"
            :loading="isReloading"
            @click="reloadDashboard"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="reload" />
            </template>
            <span>{{ $t('text.contact_dashboard.reload') }}</span>
        </i-button>
        <i-button
            variant="primary"
            size="sm"
            data-testid="contact-open-manage"
            :to="{ name: 'contact-manage', query: manageQuery }"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="email-multiple-outline" />
            </template>
            <span>{{ $t('nav.a.contact-manage') }}</span>
        </i-button>
        <template #description>
            {{ dashboardSubtitle }}
            <template v-if="lastUpdatedLabel"> · {{ $t('text.last_updated') }}: {{ lastUpdatedLabel }}</template>
        </template>
    </breadcrumbs>

    <div class="space-y-4">
        <a-alert v-if="hasError" type="warning" show-icon :message="errorMessage" />

        <i-attention-strip v-if="!isLoading && hasData && attentionItems.length" :items="attentionItems" />

        <div
            v-if="isLoading"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
            data-testid="contact-kpi-grid"
        >
            <i-stat-tile v-for="n in 6" :key="n" loading />
        </div>

        <i-box v-else-if="!hasData" data-testid="contact-empty">
            <a-empty :description="$t('text.contact_dashboard.empty_state')" class="my-6" />
        </i-box>

        <template v-else>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" data-testid="contact-kpi-grid">
                <i-stat-tile
                    v-for="metric in summaryMetrics"
                    :key="metric.key"
                    :label="metric.label"
                    :value="metric.value"
                    :tone="metric.tone"
                    :to="metric.to"
                />
            </div>

            <i-box data-testid="contact-recent-box">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-ink">
                        {{ $t('text.contact_dashboard.recent_contacts') }}
                    </h2>
                    <router-link
                        :to="{ name: 'contact-manage', query: manageQuery }"
                        class="text-xs text-primary-ink hover:underline"
                    >
                        {{ $t('web.a.show-all') }}
                    </router-link>
                </div>
                <div v-if="recentContacts.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border text-sm" data-testid="contact-recent-rows">
                        <thead class="bg-surface-sunken text-xs text-ink-muted">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ $t('validation.attributes.name') }}</th>
                                <th class="px-3 py-2 text-left">{{ $t('text.s.contact') }}</th>
                                <th class="px-3 py-2 text-left">{{ $t('validation.attributes.type') }}</th>
                                <th class="px-3 py-2 text-left">{{ $t('text.contact_dashboard.source') }}</th>
                                <th class="px-3 py-2 text-left">{{ $t('validation.attributes.status') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('text.created_at') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-ink">
                            <tr v-for="contact in recentContacts" :key="contact.id">
                                <td class="px-3 py-2">
                                    <p class="m-0 font-medium">
                                        {{ contact.name || $t('text.contact_dashboard.unknown') }}
                                    </p>
                                    <p class="m-0 text-xs text-ink-muted">{{ contact.email || '—' }}</p>
                                </td>
                                <td class="px-3 py-2 text-xs">{{ contact.mobile || '—' }}</td>
                                <td class="px-3 py-2">
                                    <a-tag v-if="contact.type" color="blue">{{ contact.type }}</a-tag>
                                    <span v-else class="text-ink-faint">—</span>
                                </td>
                                <td class="px-3 py-2">
                                    <div v-if="contact.showroom_context" class="space-y-1">
                                        <a-tag color="cyan">{{ $t('text.contact.showroom_inquiry') }}</a-tag>
                                        <p class="m-0 text-xs text-ink-muted">{{ contact.showroom_context.name }}</p>
                                    </div>
                                    <a-tag v-else-if="contact.source_label" color="green">
                                        {{ contact.source_label }}
                                    </a-tag>
                                    <span v-else class="text-ink-faint">
                                        {{ $t('text.contact_dashboard.unknown') }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <span :class="statusBadge(contact.status).class">
                                        {{ statusBadge(contact.status).label }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right text-xs text-ink-muted">
                                    {{ formatDate(contact.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <a-empty v-else :description="$t('text.contact_dashboard.empty_recent')" class="my-6" />
            </i-box>

            <i-box data-testid="contact-activity-box">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-ink">{{ $t('text.contact_dashboard.activity') }}</h2>
                </div>
                <i-chart
                    v-if="hasSeriesValues(activityChart.series)"
                    type="trend"
                    :series="activityChart.series"
                    :categories="activityChart.categories"
                />
                <a-empty v-else :description="$t('text.contact_dashboard.empty_activity')" class="my-6" />
            </i-box>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <i-box data-testid="contact-sources-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $t('text.contact_dashboard.top_sources') }}</h2>
                    </div>
                    <i-chart
                        v-if="hasSeriesValues(sourceChart.series)"
                        type="bars"
                        horizontal
                        :series="sourceChart.series"
                        :categories="sourceChart.categories"
                    />
                    <a-empty v-else :description="$t('text.contact_dashboard.empty_source')" class="my-6" />
                </i-box>

                <i-box data-testid="contact-types-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $t('text.contact_dashboard.top_types') }}</h2>
                    </div>
                    <i-chart
                        v-if="hasSeriesValues(typeChart.series)"
                        type="bars"
                        horizontal
                        :series="typeChart.series"
                        :categories="typeChart.categories"
                    />
                    <a-empty v-else :description="$t('text.contact_dashboard.empty_type')" class="my-6" />
                </i-box>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, reactive, watch } from 'vue'
import { getUiExtension } from '@/runtime/uiExtensions'
import { useActiveModules, useContactDashboard } from '@/hooks'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import dayjs from 'dayjs'

defineComponent({
    name: 'ContactDashboard',
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { isModuleActive } = useActiveModules()
// The inquiry scope (a source prefix and the module that owns it) is registered by a module as the `contact.inquiry-scope`
// UI extension; without one the contact screens have no inquiry scope.
const inquiryScope = computed(() => getUiExtension('contact.inquiry-scope'))
const showroomInquirySourcePrefix = computed(() => inquiryScope.value?.sourcePrefix || '')
const isInquiryQuery = (query) =>
    query.type === 'showroom_inquiry' || (showroomInquirySourcePrefix.value !== '' && query.sourcePrefix === showroomInquirySourcePrefix.value)

const buildDashboardParams = (query = {}) => ({
    activity_days: 14,
    recent_limit: 8,
    source_limit: 6,
    type_limit: 6,
    type: typeof query.type === 'string' ? query.type : '',
    sourcePrefix: typeof (query.sourcePrefix || query.source_prefix) === 'string'
        ? (query.sourcePrefix || query.source_prefix)
        : '',
})

const queryParams = reactive(buildDashboardParams(route.query))

watch(
    () => route.query,
    (value) => {
        Object.assign(queryParams, buildDashboardParams(value))
    }
)

const {
    data,
    refetch,
    isLoading,
    isFetching,
    isError,
    error,
} = useContactDashboard(queryParams, {
    refetchOnMount: 'always',
    refetchOnWindowFocus: false,
    staleTime: 1000 * 60 * 3,
})

const dashboard = computed(() => data?.value || {})
const canViewShowroomInquiryScope = computed(() => Boolean(inquiryScope.value) && isModuleActive(inquiryScope.value.moduleName))
const isShowroomInquiryScope = computed(() => {
    return canViewShowroomInquiryScope.value && isInquiryQuery(queryParams)
})
const manageQuery = computed(() => {
    if (!isShowroomInquiryScope.value) {
        return {}
    }

    return {
        type: 'showroom_inquiry',
        sourcePrefix: showroomInquirySourcePrefix.value,
    }
})
const totals = computed(() => dashboard.value.totals || {})
const statusTotals = computed(() => dashboard.value.status || {})
const dashboardSubtitle = computed(() => {
    if (isShowroomInquiryScope.value) {
        return t('text.contact.showroom_dashboard_subtitle')
    }

    return t('text.contact_dashboard.subtitle')
})

/** Unfiltered contact list query for the active scope, optionally narrowed by status. */
const manageLink = (extra = {}) => ({ name: 'contact-manage', query: { ...manageQuery.value, ...extra } })

/** KPI tiles: what waits on the operator first, then volume and context. */
const summaryMetrics = computed(() => [
    {
        key: 'pending',
        label: t('text.contact_dashboard.pending'),
        value: statusTotals.value.pending ?? 0,
        tone: 'warning',
        to: manageLink(),
    },
    {
        key: 'unreadEmails',
        label: t('text.contact_dashboard.unread_emails'),
        value: statusTotals.value.unreadEmails ?? 0,
        tone: 'warning',
    },
    {
        key: 'today',
        label: t('text.contact_dashboard.new_today'),
        value: totals.value.today ?? 0,
        tone: 'info',
        to: manageLink(),
    },
    {
        key: 'replied',
        label: t('text.contact_dashboard.replied'),
        value: statusTotals.value.replied ?? 0,
        tone: 'success',
    },
    {
        key: 'total',
        label: t('text.contact_dashboard.total_contacts'),
        value: totals.value.total ?? 0,
        tone: 'default',
        to: manageLink(),
    },
    {
        key: 'blocked',
        label: t('text.status-off'),
        value: statusTotals.value.blocked ?? 0,
        tone: 'danger',
        to: manageLink({ status: 'blocked' }),
    },
])

/** Attention chips: only work that needs an operator action. */
const attentionItems = computed(() => [
    {
        key: 'contact_pending',
        label: t('text.contact_dashboard.pending'),
        count: Number(statusTotals.value.pending) || 0,
        route: 'contact-manage',
        query: manageQuery.value,
    },
])

/** True when at least one value in the series set is above zero (all-zero charts show an empty state). */
const hasSeriesValues = (series) => (series || []).some((s) => (s?.data || []).some((v) => Number(v) > 0))

/**
 * Builds a single-series chart payload from `{ label, total }` rows.
 * @param {Array} items Breakdown rows.
 * @param {string} name Series name.
 * @returns {{series: Array, categories: Array}}
 */
const buildBreakdownChart = (items, name) => ({
    series: [{ name, data: (items || []).map((item) => Number(item.total) || 0) }],
    categories: (items || []).map((item) => item.label || t('text.contact_dashboard.unknown')),
})

const sourceChart = computed(() =>
    buildBreakdownChart(dashboard.value.sources, t('text.contact_dashboard.top_sources'))
)
const typeChart = computed(() => buildBreakdownChart(dashboard.value.types, t('text.contact_dashboard.top_types')))
const sourceBreakdown = computed(() => dashboard.value.sources || [])
const typeBreakdown = computed(() => dashboard.value.types || [])
const RECENT_ROWS = 5
const recentContacts = computed(() => (dashboard.value.recentContacts || []).slice(0, RECENT_ROWS))

const activityChart = computed(() => ({
    series: [
        {
            name: t('text.contact_dashboard.activity'),
            data: (dashboard.value.activity || []).map((item) => Number(item.total) || 0),
        },
    ],
    categories: (dashboard.value.activity || []).map((item) => dayjs(item.date).format('DD MMM')),
}))

const hasData = computed(() => {
    return (
        (totals.value.total ?? 0) > 0 ||
        (statusTotals.value.blocked ?? 0) > 0 ||
        sourceBreakdown.value.length > 0 ||
        typeBreakdown.value.length > 0 ||
        (dashboard.value.recentContacts || []).length > 0
    )
})

const lastUpdatedLabel = computed(() => {
    if (!dashboard.value.refreshedAt) {
        return ''
    }

    return dayjs(dashboard.value.refreshedAt).format('YYYY-MM-DD HH:mm')
})

const isReloading = computed(() => isFetching.value && !isLoading.value)
const hasError = computed(() => isError.value)
const errorMessage = computed(() => {
    return error.value?.message || t('text.contact_dashboard.load_failed')
})

const scopeOptions = computed(() => [
    { label: t('text.contact.scope_all'), value: 'all' },
    { label: t('text.contact.scope_showroom_inquiries'), value: 'showroom' },
])

/** Switches the dashboard scope from the segmented control. */
const onScopeChange = (value) => applyDashboardScope(value === 'showroom')

const applyDashboardScope = async (showroomOnly = false) => {
    await router.push({
        name: 'contact-dashboard',
        query: showroomOnly && canViewShowroomInquiryScope.value
            ? {
                type: 'showroom_inquiry',
                sourcePrefix: showroomInquirySourcePrefix.value,
            }
            : {},
    })
}

watch(canViewShowroomInquiryScope, async (canView) => {
    const hasShowroomQuery = isInquiryQuery(queryParams)

    if (!canView && hasShowroomQuery) {
        await applyDashboardScope(false)
    }
}, { immediate: true })

const reloadDashboard = async () => {
    await refetch({ meta: { reload: true } })
}

const formatDate = (value) => {
    if (!value) {
        return '—'
    }

    return dayjs(value).format('DD MMM YYYY HH:mm')
}

const statusBadge = (status) => {
    const normalized = (status || '').toLowerCase()
    const map = {
        replied: {
            label: t('text.contact_dashboard.status_replied'),
            class: 'text-xs px-2 py-0.5 rounded-full bg-success-soft text-success-ink',
        },
        assigned: {
            label: t('text.contact_dashboard.status_assigned'),
            class: 'text-xs px-2 py-0.5 rounded-full bg-info-soft text-info-ink',
        },
        new: {
            label: t('text.contact_dashboard.status_new'),
            class: 'text-xs px-2 py-0.5 rounded-full bg-surface-sunken text-ink-muted',
        },
        blocked: {
            label: t('text.status-off'),
            class: 'text-xs px-2 py-0.5 rounded-full bg-danger-soft text-danger-ink',
        },
    }

    return map[normalized] || map.new
}
</script>
