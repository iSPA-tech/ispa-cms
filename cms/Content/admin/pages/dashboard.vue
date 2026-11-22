<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="mx-auto max-w-6xl space-y-4">
        <breadcrumbs
            :items="[
                {
                    icon: 'text-box-edit-outline',
                    title: $t('nav.a.posts'),
                    text: $t('nav.a.posts'),
                },
            ]"
        >
            <span v-if="lastUpdatedLabel" class="text-xs text-ink-muted">
                {{ $t('text.last_updated') }}: {{ lastUpdatedLabel }}
            </span>
            <i-button
                v-if="canRefresh"
                variant="default"
                size="sm"
                data-testid="post-reload"
                :loading="isRefreshing"
                @click="handleRefresh"
            >
                <template #icon>
                    <i-icon class="size-3.5" icon="reload" />
                </template>
                <span>{{ $t('nav.b.reload') }}</span>
            </i-button>
            <i-button as="router-link" :to="{ name: 'post-create' }" variant="primary" size="sm">
                <template #icon>
                    <i-icon class="size-3.5" icon="plus-thick" />
                </template>
                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
            <template #description>
                <p class="text-xs text-ink-muted">
                    {{ $t('text.posts.dashboard-desc') }}
                </p>
            </template>
        </breadcrumbs>

        <a-alert v-if="errorMessage" :message="errorMessage" show-icon type="warning" />

        <i-attention-strip v-if="!isInitialLoading && !errorMessage" :items="attentionItems" />

        <div
            class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-4"
            data-testid="post-kpi-grid"
        >
            <template v-if="isInitialLoading">
                <i-stat-tile v-for="n in 4" :key="n" loading />
            </template>
            <template v-else>
                <i-stat-tile
                    v-for="metric in summaryMetrics"
                    :key="metric.key"
                    :label="metric.label"
                    :value="metric.value"
                    :description="metric.secondary || ''"
                    :to="metric.to"
                    :tone="metric.tone"
                />
            </template>
        </div>

        <a-skeleton v-if="isInitialLoading" :paragraph="{ rows: 10 }" active />

        <template v-else>
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <i-box class="space-y-3 xl:col-span-2" data-testid="post-upcoming-box">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ upcomingTitle }}
                        </h2>
                        <span class="text-xs text-ink-muted">
                            {{ scheduledCountLabel }}
                        </span>
                    </div>
                    <ul v-if="upcomingPosts.length" class="space-y-2 text-sm">
                        <li
                            v-for="item in upcomingPosts"
                            :key="item.uuid || item.name"
                            class="flex items-start justify-between gap-3 rounded-md border border-border bg-surface-sunken px-3 py-2"
                        >
                            <div class="min-w-0 space-y-1">
                                <router-link
                                    v-if="item.uuid"
                                    :to="{ name: 'post-update', params: { uuid: item.uuid } }"
                                    class="block text-sm font-medium text-ink hover:text-primary-ink"
                                >
                                    {{ item.name || '—' }}
                                </router-link>
                                <p v-else class="text-sm font-medium text-ink">{{ item.name || '—' }}</p>
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
                                    <a-tag :color="item.typeStyle.tag" class="m-0">{{ item.typeLabel }}</a-tag>
                                    <span v-if="item.categoryName" class="truncate">{{ item.categoryName }}</span>
                                </p>
                            </div>
                            <div class="shrink-0 text-right text-xs text-ink-muted leading-5 tabular-nums">
                                <div>{{ item.publishedLabel }}</div>
                                <div v-if="item.publishedRelative">
                                    {{ item.publishedRelative }}
                                </div>
                            </div>
                        </li>
                    </ul>
                    <a-empty v-else :description="noUpcomingLabel" />
                </i-box>

                <i-box class="space-y-3" data-testid="post-type-box">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ typeOverviewTitle }}
                        </h2>
                        <span class="text-xs text-ink-muted">
                            {{ totalPostsLabel }}
                        </span>
                    </div>
                    <i-chart
                        v-if="typeBreakdown.length && totalPosts > 0"
                        type="bars"
                        horizontal
                        :series="typeSeries"
                        :categories="typeCategories"
                        :height="typeChartHeight"
                    />
                    <a-empty v-else :description="noTypeDataLabel" />
                </i-box>
            </div>

            <i-box class="space-y-3" data-testid="post-recent-box">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-ink">
                        {{ recentTitle }}
                    </h2>
                    <router-link :to="{ name: 'post-post' }" class="text-xs text-primary-ink">
                        {{ $t('web.a.show-all') }}
                    </router-link>
                </div>

                <ul v-if="recentPosts.length" class="divide-y divide-border">
                    <li
                        v-for="item in recentPosts"
                        :key="item.uuid || item.name"
                        class="flex items-start justify-between gap-4 py-3"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-surface-sunken text-ink-muted"
                                aria-hidden="true"
                            >
                                <i-icon :icon="item.typeStyle.icon" class="size-5" />
                            </span>
                            <div class="min-w-0 space-y-1">
                                <router-link
                                    v-if="item.uuid"
                                    :to="{ name: 'post-update', params: { uuid: item.uuid } }"
                                    class="block text-sm font-medium text-ink hover:text-primary-ink"
                                >
                                    {{ item.name || '—' }}
                                </router-link>
                                <p v-else class="text-sm font-medium text-ink">{{ item.name || '—' }}</p>
                                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
                                    <a-tag :color="item.typeStyle.tag" class="m-0">{{ item.typeLabel }}</a-tag>
                                    <a-badge :status="item.statusBadge" :text="item.statusLabel" class="text-xs" />
                                    <span v-if="item.categoryName" class="inline-flex min-w-0 items-center gap-1">
                                        <i-icon class="size-3.5 shrink-0 text-ink-faint" icon="folder" />
                                        <span class="truncate">{{ item.categoryName }}</span>
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div class="shrink-0 text-right text-xs text-ink-muted leading-5 tabular-nums">
                            <div :title="item.updatedLabel">{{ item.updatedRelative }}</div>
                            <div v-if="item.createdLabel">{{ item.createdLabel }}</div>
                            <i-button variant="ghost" size="xs" @click="goToEdit(item.uuid)">
                                {{ $t('nav.b.edit') }}
                            </i-button>
                        </div>
                    </li>
                </ul>
                <a-empty v-else :description="noRecentLabel" />
            </i-box>

            <i-box v-if="hasDashboardData">
                <quick-report type="Post" />
            </i-box>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, ref } from 'vue'
import { useRouter } from 'vue-router'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import 'dayjs/locale/vi'
import { useI18n } from 'vue-i18n'
import { numberFormat } from '@/mixins'
import { useEnumerates } from '@/hooks'
import { postStatusBadge, postTypeStyle } from '@/utils/post-presentation'

defineComponent({
    name: 'PostDashboard',
})

dayjs.extend(relativeTime)

const router = useRouter()
const { t, te, locale } = useI18n()

const isLoading = inject('isLoading', ref(false))
const dashboardData = inject('dashboardData', ref({}))
const dashboardError = inject('dashboardError', ref(null))
const dashboardLastFetchedAt = inject('dashboardLastFetchedAt', ref(null))
const refreshDashboard = inject('refreshPostDashboard', null)

const formatNumber = (value, fmt = '0,0') => numberFormat(value ?? 0, fmt)

const baseData = computed(() => dashboardData.value || {})
const hasDashboardData = computed(() => Object.keys(baseData.value).length > 0)

const errorMessage = computed(() => dashboardError.value || '')
const isInitialLoading = computed(() => isLoading.value && !hasDashboardData.value)
const isRefreshing = computed(() => isLoading.value && hasDashboardData.value)
const canRefresh = computed(() => typeof refreshDashboard === 'function')

const rawRecents = computed(() =>
    Array.isArray(baseData.value.recents) ? baseData.value.recents : []
)
const rawIncoming = computed(() =>
    Array.isArray(baseData.value.incoming) ? baseData.value.incoming : []
)
const commentCount = computed(() => Number(baseData.value.commentCount ?? 0))
const postTypeMap = computed(() => baseData.value.postTypes || {})

// Status names come from the `status` enumerates (vi name / en alias), the same source as the status select.
const { data: enumerates } = useEnumerates()
const statusNames = computed(() => {
    const source = enumerates?.value?.data ?? enumerates?.value ?? []
    const isVietnamese = String(locale.value || '').startsWith('vi')

    return Object.fromEntries(
        (Array.isArray(source) ? source : [])
            .filter((item) => item?.type === 'status' && item.enum_key)
            .map((item) => [item.enum_key, (isVietnamese ? item.name : item.name_alias) || item.name])
    )
})

const resolvePostTypeLabel = (type) => {
    if (!type) {
        return t('text.unknown.title')
    }
    const normalized = type.toString().toLowerCase()
    const canonicalKey = `text.type.${normalized}`
    if (te(canonicalKey)) {
        return t(canonicalKey)
    }
    const typeKey = `t.type.${normalized}`
    if (te(typeKey)) {
        return t(typeKey)
    }
    const altKey = `n.a.${normalized}`
    if (te(altKey)) {
        return t(altKey)
    }
    return normalized.charAt(0).toUpperCase() + normalized.slice(1)
}

const resolveStatusLabel = (status) => {
    if (!status) {
        return t('text.unknown.title')
    }
    const normalized = status.toString().toLowerCase().replace(/\s+/g, '_')
    if (statusNames.value[normalized]) {
        return statusNames.value[normalized]
    }
    const bare = normalized.replace(/^status_/, '')
    const key = `status.${bare}`
    return te(key) ? t(key) : bare.charAt(0).toUpperCase() + bare.slice(1).replace(/_/g, ' ')
}

const formatDateTime = (value) => {
    if (!value) {
        return ''
    }
    const date = dayjs(value)
    return date.isValid() ? date.format('DD/MM/YYYY HH:mm') : ''
}

const formatRelative = (value) => {
    if (!value) {
        return ''
    }
    const date = dayjs(value)
    // Per-instance locale so "2 days ago" follows the admin language without changing the global dayjs locale.
    return date.isValid() ? date.locale(String(locale.value || '').startsWith('vi') ? 'vi' : 'en').fromNow() : ''
}

const typeBreakdown = computed(() => {
    const entries = Object.entries(postTypeMap.value || {}).map(([key, value]) => ({
        key,
        count: Number(value ?? 0),
    }))

    if (entries.length === 0) {
        return []
    }

    const total = entries.reduce((sum, item) => sum + item.count, 0) || 0

    return entries
        .sort((a, b) => b.count - a.count)
        .map((item) => {
            const percent = total > 0 ? Math.round((item.count / total) * 1000) / 10 : 0
            return {
                ...item,
                percent,
                label: resolvePostTypeLabel(item.key),
                style: postTypeStyle(item.key),
                countLabel: formatNumber(item.count, '0,0'),
            }
        })
})

const totalPosts = computed(() => typeBreakdown.value.reduce((sum, item) => sum + item.count, 0))
const upcomingPosts = computed(() =>
    rawIncoming.value.map((item) => ({
        ...item,
        categoryName: item.category?.name ?? '',
        typeLabel: resolvePostTypeLabel(item.type),
        typeStyle: postTypeStyle(item.type),
        publishedLabel: formatDateTime(item.published_at),
        publishedRelative: formatRelative(item.published_at),
    }))
)
const recentPosts = computed(() =>
    rawRecents.value.map((item) => ({
        ...item,
        categoryName: item.category?.name ?? '',
        typeLabel: resolvePostTypeLabel(item.type),
        typeStyle: postTypeStyle(item.type),
        statusLabel: resolveStatusLabel(item.status),
        statusBadge: postStatusBadge(item.status),
        createdLabel: formatDateTime(item.created_at),
        updatedLabel: formatDateTime(item.updated_at || item.created_at),
        updatedRelative: formatRelative(item.updated_at || item.created_at),
    }))
)

const upcomingCount = computed(() => upcomingPosts.value.length)
const recentUpdatesCount = computed(() => {
    const now = dayjs()
    return rawRecents.value.filter((item) => {
        if (!item.updated_at) {
            return false
        }
        const updatedAt = dayjs(item.updated_at)
        return updatedAt.isValid() ? now.diff(updatedAt, 'day') <= 7 : false
    }).length
})

const lastUpdatedLabel = computed(() => {
    const source =
        dashboardLastFetchedAt.value ||
        rawRecents.value[0]?.updated_at ||
        rawRecents.value[0]?.created_at
    if (!source) {
        return ''
    }
    const date = dayjs(source)
    return date.isValid() ? date.format('DD/MM/YYYY HH:mm') : ''
})

const summaryMetrics = computed(() => [
    {
        key: 'total_posts',
        label: t('nav.a.posts'),
        value: totalPosts.value,
        to: { name: 'post-post' },
        tone: 'info',
    },
    {
        key: 'comments',
        label: t('nav.a.comments'),
        value: commentCount.value,
        to: { name: 'comment-manage' },
        tone: 'default',
    },
    {
        key: 'scheduled',
        label: t('text.dashboard.incoming'),
        value: upcomingCount.value,
        secondary: upcomingCount.value > 0 ? t('text.dashboard.queue') : null,
        tone: 'warning',
    },
    {
        key: 'recent_updates',
        label: t('text.dashboard.recent-updates'),
        value: recentUpdatesCount.value,
        secondary: '7d',
        tone: 'success',
    },
])

const typeOverviewTitle = computed(() => t('text.dashboard.post-type-distribution'))
const totalPostsLabel = computed(
    () => `${t('text.total')}: ${formatNumber(totalPosts.value, '0,0')}`
)
const noTypeDataLabel = computed(() => t('text.no-data'))
/** Series for the horizontal type-distribution bars (one series, one value per post type). */
const typeSeries = computed(() => [
    { name: t('text.total'), data: typeBreakdown.value.map((item) => item.count) },
])
const typeCategories = computed(() => typeBreakdown.value.map((item) => item.label))
/** Chart height grows with the number of post types so labels never collide. */
const typeChartHeight = computed(() => Math.max(160, typeBreakdown.value.length * 36 + 40))

/** Chips for the attention strip; only scheduled posts have a count in the payload. */
const attentionItems = computed(() => [
    {
        key: 'posts_scheduled',
        count: upcomingCount.value,
        route: 'post-post',
        label: t('text.dashboard.incoming'),
    },
])

const upcomingTitle = computed(() => t('text.dashboard.upcoming-posts'))
const scheduledCountLabel = computed(
    () => `${t('text.total')}: ${formatNumber(upcomingCount.value, '0,0')}`
)
const noUpcomingLabel = computed(() => t('text.dashboard.no-incoming-posts'))
const recentTitle = computed(() => t('text.dashboard.recents-posts'))
const noRecentLabel = computed(() => t('text.no-data'))

const handleRefresh = () => {
    if (typeof refreshDashboard === 'function') {
        refreshDashboard()
    }
}

const goToEdit = (uuid) => {
    if (!uuid) {
        return
    }
    router.push({
        name: 'post-update',
        params: { uuid },
    })
}
</script>
