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
                title: $t('nav.a.media'),
                text: $t('nav.a.media'),
                icon: 'multimedia',
            },
        ]"
    >
        <i-button
            variant="default"
            size="sm"
            data-testid="media-reload"
            :loading="isRefreshing"
            @click="refreshDashboardWithMeta"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="reload" />
            </template>
            <span>{{ $t('nav.b.reload') }}</span>
        </i-button>
        <i-button variant="primary" size="sm" data-testid="media-open-manager" :to="{ name: 'media-manage' }">
            <span>{{ $t('web.a.show-all') }}</span>
        </i-button>
        <template #description>
            {{ $t('text.media.dashboard-desc') }}
            <template v-if="lastUpdatedLabel">
                · {{ $t('text.last_updated') }}: {{ lastUpdatedLabel }}
            </template>
        </template>
    </breadcrumbs>

    <div class="space-y-4">
        <a-alert v-if="errorMessage" type="warning" show-icon :message="errorMessage" />

        <i-attention-strip v-if="!isInitialLoading && attentionItems.length" :items="attentionItems" />

        <div
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
            data-testid="media-kpi-grid"
        >
            <template v-if="isInitialLoading">
                <i-stat-tile v-for="n in 6" :key="n" loading />
            </template>
            <template v-else>
                <i-stat-tile
                    v-for="card in kpiCards"
                    :key="card.key"
                    :label="card.label"
                    :value="card.value"
                    :format="card.format"
                    :description="card.description"
                    :tone="card.tone"
                />
            </template>
        </div>

        <template v-if="!isInitialLoading">
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <i-box class="xl:col-span-2" data-testid="media-type-box">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-ink">
                            {{ $t('text.media.type-distribution') }}
                        </h2>
                        <span class="text-xs text-ink-faint">
                            {{ $t('text.all-files') }}: {{ formatNumber(stats.totalFiles, '0,0') }}
                        </span>
                    </div>
                    <template v-if="hasSeriesValues(typeChart.barSeries)">
                        <i-chart
                            v-if="typeChart.asBars"
                            type="bars"
                            horizontal
                            :series="typeChart.barSeries"
                            :categories="typeChart.labels"
                        />
                        <i-chart
                            v-else
                            type="donut"
                            :series="typeChart.series"
                            :options="typeChart.options"
                        />
                        <ul class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-ink-muted sm:grid-cols-2" data-testid="media-type-parts">
                            <li v-for="part in typeChart.parts" :key="part.key" class="flex items-center gap-2">
                                <span
                                    class="inline-block size-2.5 shrink-0 rounded-xs"
                                    :style="{ background: part.color }"
                                ></span>
                                <span class="min-w-0 flex-1 truncate">{{ part.label }}</span>
                                <span class="tabular-nums text-ink">{{ formatNumber(part.count, '0,0') }}</span>
                                <span class="min-w-10 text-right tabular-nums text-ink-muted">{{ Math.round(part.percent) }}%</span>
                            </li>
                        </ul>
                    </template>
                    <a-empty v-else :description="noDataLabel" class="my-6" />
                </i-box>

                <i-box v-if="unusedBlockVisible" data-testid="media-unused-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $t('text.media.unused') }}</h2>
                        <router-link
                            :to="{ name: 'media-manage' }"
                            class="text-xs text-primary-ink hover:underline"
                        >
                            {{ $t('web.a.show-all') }}
                        </router-link>
                    </div>
                    <ul v-if="unusedUploads.length > 0" class="space-y-2 text-sm" data-testid="media-unused-rows">
                        <li
                            v-for="item in unusedUploads"
                            :key="item.id || item.name"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="truncate text-ink">{{ item.name || '—' }}</span>
                            <span class="shrink-0 text-xs text-ink-faint">{{ item.displaySize }}</span>
                        </li>
                    </ul>
                    <a-empty v-else :description="noDataLabel" class="my-6" />
                </i-box>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <i-box v-if="recentBlockVisible" data-testid="media-recent-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $t('text.media.recent') }}</h2>
                        <router-link
                            :to="{ name: 'media-manage' }"
                            class="text-xs text-primary-ink hover:underline"
                        >
                            {{ $t('web.a.show-all') }}
                        </router-link>
                    </div>
                    <ul v-if="recentUploads.length > 0" class="divide-y divide-border" data-testid="media-recent-rows">
                        <li
                            v-for="item in recentUploads"
                            :key="item.id || item.name"
                            class="flex items-start justify-between gap-3 py-3"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface-sunken text-ink-muted"
                                >
                                    <i-icon class="size-5" :icon="item.icon" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-ink">
                                        {{ item.name || '—' }}
                                    </p>
                                    <p class="text-xs text-ink-faint">
                                        {{ item.displaySize }} • {{ item.typeLabel }}
                                    </p>
                                </div>
                            </div>
                            <div class="shrink-0 text-right text-xs leading-5 text-ink-faint">
                                <div>{{ item.createdLabel }}</div>
                                <div v-if="item.uploader">{{ item.uploader }}</div>
                            </div>
                        </li>
                    </ul>
                    <a-empty v-else :description="noDataLabel" class="my-6" />
                </i-box>

                <i-box v-if="largestBlockVisible" data-testid="media-largest-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $t('text.media.largest') }}</h2>
                        <router-link
                            :to="{ name: 'media-manage' }"
                            class="text-xs text-primary-ink hover:underline"
                        >
                            {{ $t('web.a.show-all') }}
                        </router-link>
                    </div>
                    <div v-if="largestFiles.length > 0" class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="media-largest-rows">
                            <thead class="border-b border-border text-left text-xs font-medium text-ink-muted">
                                <tr>
                                    <th class="py-2 pr-3">{{ $t('text.file-name') }}</th>
                                    <th class="whitespace-nowrap py-2 pr-3">{{ $t('text.file-size') }}</th>
                                    <th class="whitespace-nowrap py-2">{{ $t('validation.attributes.type') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="item in largestFiles" :key="item.id || item.name">
                                    <td class="py-2 pr-3">
                                        <span class="line-clamp-1 text-ink">{{ item.name }}</span>
                                    </td>
                                    <td class="py-2 pr-3 text-ink-muted">{{ item.displaySize }}</td>
                                    <td class="py-2 text-ink-muted">{{ item.typeLabel }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <a-empty v-else :description="noDataLabel" class="my-6" />
                </i-box>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import dayjs from 'dayjs'
import { useUploadDashboard } from '@/hooks'
import { numberFormat } from '@/mixins'
import { useI18n } from 'vue-i18n'
import { useQueryClient } from '@tanstack/vue-query'
import { getUploadDashboard } from '@/libs/upload'

const queryClient = useQueryClient()

defineComponent({
    name: 'MediaDashboard',
})

const { t } = useI18n()

const {
    data: dashboardResponse,
    isLoading,
    isFetching,
    error,
} = useUploadDashboard(
    {},
    {
        keepPreviousData: true,
    }
)

const dashboardData = computed(() => dashboardResponse?.value?.data || {})

const lastUpdatedAt = ref(null)

watch(
    () => dashboardData.value,
    (val) => {
        if (val && Object.keys(val).length > 0) {
            lastUpdatedAt.value = val.refreshed_at || dayjs().toISOString()
        }
    },
    { immediate: true }
)

const formatBytes = (bytes) => {
    if (!bytes || bytes <= 0) {
        return '0 B'
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
    let index = 0
    let value = bytes
    while (value >= 1024 && index < units.length - 1) {
        value /= 1024
        index += 1
    }
    const fixed = value >= 10 || index === 0 ? value.toFixed(0) : value.toFixed(1)
    return `${fixed} ${units[index]}`
}

const formatNumber = (value, fmt = '0,0') => {
    return numberFormat(value ?? 0, fmt)
}

const resolveTypeLabel = (key) => {
    if (key === 'other') {
        return t('text.type.other') || 'Other'
    }

    return t(`t.type.${key}`) || key.charAt(0).toUpperCase() + key.slice(1)
}

const stats = computed(() => {
    const payload = dashboardData.value?.stats ?? {}
    const baseCounts = payload.counts ?? {}

    const counts = {
        image: Number(baseCounts.image ?? 0),
        video: Number(baseCounts.video ?? 0),
        audio: Number(baseCounts.audio ?? 0),
        document: Number(baseCounts.document ?? 0),
        archive: Number(baseCounts.archive ?? 0),
        other: Number(baseCounts.other ?? 0),
    }

    const totalBytes = Number(payload.total_bytes ?? 0)
    const averageBytes = Number(payload.average_bytes ?? 0)
    const unusedUploads = Array.isArray(dashboardData.value?.unused_uploads)
        ? dashboardData.value.unused_uploads.length
        : 0

    const unusedCount =
        payload.unused_count !== undefined && payload.unused_count !== null
            ? Number(payload.unused_count)
            : unusedUploads

    return {
        totalFiles: Number(payload.total_files ?? 0),
        totalBytes,
        newUploads24h: Number(payload.new_uploads_24h ?? 0),
        counts,
        averageSize: averageBytes,
        averageSizeLabel: payload.average_size_label ?? formatBytes(averageBytes),
        totalSizeLabel: payload.total_size_label ?? formatBytes(totalBytes),
        unusedCount,
        capacityBytes: Number(payload.capacity_bytes ?? 0),
    }
})

/**
 * Storage usage as a percentage tile; the tone follows the capacity thresholds.
 * @returns {{percent: number|null, tone: string}} Percent of capacity used (null when capacity is unknown).
 */
const storageTile = computed(() => {
    if (!stats.value.capacityBytes || stats.value.capacityBytes <= 0) {
        return { percent: null, tone: 'default' }
    }
    const percent = storageUsage.value.percent
    return { percent, tone: percent >= 98 ? 'danger' : percent >= 90 ? 'warning' : 'default' }
})

const kpiCards = computed(() => [
    {
        key: 'total',
        label: t('text.media.total-files'),
        value: stats.value.totalFiles,
        format: 'number',
        description: stats.value.totalBytes > 0 ? stats.value.totalSizeLabel : undefined,
        tone: 'default',
    },
    {
        key: 'storage',
        label: t('text.media.storage-usage'),
        // Unknown capacity: show the used size itself (no percent, no ratio description).
        value: storageTile.value.percent ?? stats.value.totalSizeLabel,
        format: storageTile.value.percent === null ? 'number' : 'percent',
        description:
            storageTile.value.percent !== null && stats.value.totalBytes > 0 && storageUsage.value.capacityLabel
                ? `${stats.value.totalSizeLabel} / ${storageUsage.value.capacityLabel}`
                : undefined,
        tone: storageTile.value.tone,
    },
    {
        key: 'new24h',
        label: t('text.media.new-24h'),
        value: stats.value.newUploads24h,
        format: 'number',
        tone: stats.value.newUploads24h > 0 ? 'info' : 'default',
    },
    {
        key: 'images',
        label: t('validation.attributes.images'),
        value: stats.value.counts.image ?? 0,
        format: 'number',
        tone: 'default',
    },
    {
        key: 'documents',
        label: t('text.type.document'),
        value: stats.value.counts.document ?? 0,
        format: 'number',
        tone: 'default',
    },
    {
        key: 'unused',
        label: t('text.media.unused'),
        value: stats.value.unusedCount,
        format: 'number',
        tone: stats.value.unusedCount > 0 ? 'warning' : 'default',
    },
])

/** Attention chips: work that needs action, linking to the media list. */
const attentionItems = computed(() =>
    stats.value.unusedCount > 0
        ? [
              {
                  key: 'media_unused',
                  count: stats.value.unusedCount,
                  route: 'media-manage',
                  label: t('text.media.unused'),
              },
          ]
        : []
)

/**
 * True when at least one series value is positive.
 * @param {Array<{data: number[]}>} series Chart series.
 * @returns {boolean} Whether the chart has something to draw.
 */
const hasSeriesValues = (series) =>
    (series || []).some((s) => (s?.data || []).some((v) => Number(v) > 0))

const noDataLabel = computed(() => t('text.no-data'))

const typeDistribution = computed(() => {
    const distribution = Array.isArray(dashboardData.value?.type_distribution)
        ? dashboardData.value.type_distribution
        : []

    if (distribution.length > 0) {
        return distribution.map((item) => {
            const key = item.key || item.type || 'other'

            return {
                key,
                label: item.label || resolveTypeLabel(key),
                count: Number(item.count ?? item.total ?? 0),
                percent: Number(item.percent ?? 0),
            }
        })
    }

    const counts = stats.value.counts
    const total = stats.value.totalFiles || 1

    return Object.entries(counts).map(([key, value]) => ({
        key,
        label: resolveTypeLabel(key),
        count: Number(value ?? 0),
        percent: total > 0 ? Math.round((Number(value ?? 0) / total) * 100) : 0,
    }))
})

/**
 * Media type share: donut for up to five types, horizontal bars beyond that.
 * @returns {object} Series, labels, option overrides and the legend parts with their chart colours.
 */
const typeChart = computed(() => {
    const items = typeDistribution.value.filter((item) => item.count > 0)
    const labels = items.map((item) => item.label)
    const series = items.map((item) => item.count)
    const total = series.reduce((acc, curr) => acc + curr, 0)

    return {
        series,
        labels,
        asBars: items.length > 5,
        barSeries: [{ name: t('text.all-files'), data: series }],
        options: {
            labels,
            plotOptions: {
                pie: {
                    donut: {
                        size: '70%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: t('text.total'),
                                formatter: () => formatNumber(total, '0,0'),
                            },
                        },
                    },
                },
            },
        },
        parts: items.map((item, idx) => ({
            ...item,
            color: `var(--color-chart-${idx < 5 ? idx + 1 : 'other'})`,
        })),
    }
})

const largestFiles = computed(() => {
    const items = Array.isArray(dashboardData.value?.largest_uploads)
        ? dashboardData.value.largest_uploads
        : []

    return items.slice(0, 5).map((item) => ({
        ...item,
        displaySize: item.display_size ?? formatBytes(Number(item.file_size ?? 0)),
        typeLabel: item.type_label ?? resolveTypeLabel(item.type ?? 'other'),
    }))
})

const recentUploads = computed(() => {
    const items = Array.isArray(dashboardData.value?.recent_uploads)
        ? dashboardData.value.recent_uploads
        : []

    return items.slice(0, 5).map((item) => {
        const createdAt = item.created_at || item.createdAt
        const createdLabel =
            item.created_label ||
            (createdAt && dayjs(createdAt).isValid()
                ? dayjs(createdAt).format('DD/MM/YYYY HH:mm')
                : '—')

        return {
            ...item,
            createdLabel,
            displaySize: item.display_size ?? formatBytes(Number(item.file_size ?? 0)),
            typeLabel: item.type_label ?? resolveTypeLabel(item.type ?? 'other'),
            icon: item.icon || 'file',
            uploader: item.uploader,
        }
    })
})

const unusedUploads = computed(() => {
    const items = Array.isArray(dashboardData.value?.unused_uploads)
        ? dashboardData.value.unused_uploads
        : []

    return items.slice(0, 5).map((item) => ({
        ...item,
        displaySize: item.display_size ?? formatBytes(Number(item.file_size ?? 0)),
    }))
})

/** A list block is hidden only when the payload sends it as null. */
const unusedBlockVisible = computed(() => dashboardData.value?.unused_uploads !== null)
const recentBlockVisible = computed(() => dashboardData.value?.recent_uploads !== null)
const largestBlockVisible = computed(() => dashboardData.value?.largest_uploads !== null)

const storageUsage = computed(() => {
    const used = stats.value.totalBytes
    const capacity = stats.value.capacityBytes
    if (!capacity || capacity <= 0) {
        return { percent: used > 0 ? 100 : 0, capacityLabel: null }
    }

    return {
        percent: Math.min(100, Math.round((used / capacity) * 100)),
        capacityLabel: formatBytes(capacity),
    }
})

const lastUpdatedLabel = computed(() => {
    if (!lastUpdatedAt.value) {
        return ''
    }
    return dayjs(lastUpdatedAt.value).format('DD/MM/YYYY HH:mm')
})

const errorMessage = computed(() => {
    if (!error.value) {
        return ''
    }
    return error.value?.message || error.value?.response?.data?.message || t('text.media.load-error')
})

const hasDashboardData = computed(() => Object.keys(dashboardData.value).length > 0)

const isInitialLoading = computed(() => isLoading.value && !hasDashboardData.value)
const isRefreshing = computed(() => isFetching.value && !isInitialLoading.value)

const refreshDashboardWithMeta = async () => {
    const key = ['upload-dashboard', {}]
    await queryClient.fetchQuery({
        queryKey: key,
        queryFn: async ({ meta }) => {
            const base = {}
            const resolved = { ...base, ...(meta?.reload ? { reload: true } : {}) }
            return await getUploadDashboard(resolved)
        },
        meta: { reload: true },
    })
}
</script>
