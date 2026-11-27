<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs :items="[{ title: $t('nav.dashboard'), text: $t('nav.dashboard') }]">
        <template v-if="canViewSummary">
            <a-segmented
                v-model:value="range"
                :options="rangeOptions"
                size="small"
                @change="() => loadSummary()"
            />
            <i-button variant="default" size="sm" data-testid="dashboard-reload" :loading="loading" @click="loadSummary(true)">
                <template #icon>
                    <i-icon icon="reload" class="size-3.5" />
                </template>
                <span>{{ $t('nav.b.reload') }}</span>
            </i-button>
        </template>
    </breadcrumbs>

    <div class="w-full pb-6">
        <div v-if="canViewSummary" class="space-y-6">
            <!-- iSPA Hub band first. -->
            <dashboard-hub-band
                v-if="summary?.hub"
                :hub="summary.hub"
                :name="accountName"
                :version="summary.hub.version || appVersion"
                :webmaster="webmaster"
                :locale="adminLocale"
            />
            <a-alert v-if="error" type="error" show-icon :message="error">
                <template #action>
                    <i-button variant="default" size="sm" @click="loadSummary()">
                        {{ $t('nav.b.reload') }}
                    </i-button>
                </template>
            </a-alert>

            <!-- "Cần xử lý" comes first, before settings notices (1c). -->
            <i-attention-strip v-if="summary?.attention?.length" :items="summary.attention" />

            <div
                v-for="notice in summary?.notices || []"
                :key="notice.key"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md bg-surface-sunken px-4 py-2.5"
                data-testid="dashboard-notice"
            >
                <p class="m-0 text-sm leading-6 text-ink">
                    <strong class="font-semibold">{{ $t('nav.a.settings') }}</strong>
                    <span aria-hidden="true" class="mx-2">&middot;</span>
                    {{ noticeMessage(notice) }}
                </p>
                <i-button variant="primary" size="sm" :to="{ name: notice.route, hash: notice.hash }">
                    <span>{{ $t('nav.b.update-now') }}</span>
                </i-button>
            </div>

            <section v-if="loading || summary?.traffic" aria-labelledby="dash-traffic">
                <h2 id="dash-traffic" class="m-0 text-sm font-semibold text-ink">
                    {{ $t('text.rp.summary') }}
                </h2>
                <i-stat-strip class="mt-3" :cols="4" data-testid="traffic-grid">
                    <!-- The period row follows the 7d/30d/90d chip, so its labels drop "total". -->
                    <i-stat-tile
                        :label="$t('text.rp.views')"
                        :value="summary?.traffic?.views ?? 0"
                        :previous="summary?.traffic?.previous?.views"
                        :to="viewsReportTo"
                        tone="info"
                        :spark="seriesValues('views')"
                        :spark-slot="1"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.dashboard.visitors')"
                        :value="summary?.traffic?.visitors ?? 0"
                        :previous="summary?.traffic?.previous?.visitors"
                        :to="viewsReportTo"
                        :spark="seriesValues('visitors')"
                        :spark-slot="3"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.rp.sessions')"
                        :value="summary?.traffic?.sessions ?? 0"
                        :previous="summary?.traffic?.previous?.sessions"
                        :to="viewsReportTo"
                        :spark="seriesValues('sessions')"
                        :spark-slot="2"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.dashboard.views_per_session')"
                        :value="viewsPerSession.value"
                        :previous="viewsPerSession.previous"
                        :fraction-digits="1"
                        :to="viewsReportTo"
                        :spark="viewsPerSession.spark"
                        :spark-slot="4"
                        :loading="loading"
                    />
                </i-stat-strip>
                <h3 class="mt-4 mb-0 text-sm font-medium text-ink-muted">
                    {{ $t('text.dashboard.lifetime') }}
                </h3>
                <i-stat-strip class="mt-3" :cols="4" data-testid="lifetime-row">
                    <i-stat-tile
                        v-for="tile in lifetimeTiles"
                        :key="tile.key"
                        :label="tile.label"
                        :value="summary?.traffic?.lifetime?.[tile.key] ?? 0"
                        :to="tile.to"
                        :loading="loading"
                    />
                </i-stat-strip>
                <i-box v-if="!loading && hasSeriesValues(viewsSeries)" class="mt-4" data-testid="views-chart-box">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $t('text.rp.views') }}</h2>
                    </div>
                    <i-chart type="trend" :series="viewsSeries" :categories="viewsCategories" />
                </i-box>
            </section>

            <section v-if="canViewPosts && (loading || summary?.content)" aria-labelledby="dash-content">
                <h2 id="dash-content" class="m-0 text-sm font-semibold text-ink">
                    {{ $t('nav.a.posts') }}
                </h2>
                <i-stat-strip class="mt-3" :cols="4" data-testid="content-grid">
                    <i-stat-tile
                        v-for="tile in contentTiles"
                        :key="tile.key"
                        :label="tile.label"
                        :value="summary?.content?.[tile.key] ?? 0"
                        :value-tone="statusTone(tile.tone)"
                        :to="tile.to"
                        :tone="tile.tone"
                        :loading="loading"
                    />
                </i-stat-strip>
            </section>

            <section v-if="canViewComments && (loading || summary?.comments)" aria-labelledby="dash-comments">
                <h2 id="dash-comments" class="m-0 text-sm font-semibold text-ink">
                    {{ $t('nav.a.comments') }}
                </h2>
                <i-stat-strip class="mt-3" :cols="5" data-testid="comments-grid">
                    <i-stat-tile
                        v-for="tile in commentTiles"
                        :key="tile.key"
                        :label="tile.label"
                        :value="summary?.comments?.[tile.key] ?? 0"
                        :value-tone="statusTone(tile.tone)"
                        :to="tile.to"
                        :tone="tile.tone"
                        :loading="loading"
                    />
                </i-stat-strip>
            </section>

            <section
                v-if="canViewOrders && summary?.modules?.ecommerce && (loading || summary?.commerce)"
                aria-labelledby="dash-commerce"
            >
                <h2 id="dash-commerce" class="m-0 text-sm font-semibold text-ink">
                    {{ $t('nav.a.orders') }}
                </h2>
                <i-stat-strip class="mt-3" :cols="4" data-testid="commerce-grid">
                    <i-stat-tile
                        :label="$t('text.dashboard.orders_new')"
                        :value="summary?.commerce?.orders_new ?? 0"
                        :previous="summary?.commerce?.previous?.orders_new"
                        :spark="seriesValues('orders')"
                        :spark-slot="2"
                        :to="{ name: 'order-manage' }"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.dashboard.orders_processing')"
                        :value="summary?.commerce?.orders_processing ?? 0"
                        :to="{ name: 'order-manage', query: { status_group: 'in_progress' } }"
                        tone="warning"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.dashboard.revenue_paid')"
                        :value="summary?.commerce?.revenue_paid ?? 0"
                        format="currency"
                        :previous="summary?.commerce?.previous?.revenue_paid"
                        :to="{ name: 'order-manage' }"
                        tone="success"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.dashboard.aov')"
                        :value="summary?.commerce?.aov ?? 0"
                        :to="{ name: 'order-manage' }"
                        format="currency"
                        :loading="loading"
                    />
                </i-stat-strip>
            </section>

            <section v-if="canViewUsers && (loading || summary?.people)" aria-labelledby="dash-people">
                <h2 id="dash-people" class="m-0 text-sm font-semibold text-ink">
                    {{ $t('nav.a.users') }}
                </h2>
                <i-stat-strip class="mt-3" :cols="peopleTileCount" data-testid="people-grid">
                    <i-stat-tile
                        :label="$t('text.dashboard.users_total')"
                        :value="summary?.people?.users_total ?? 0"
                        :to="{ name: 'user-manage' }"
                        :loading="loading"
                    />
                    <i-stat-tile
                        :label="$t('text.dashboard.users_new')"
                        :value="summary?.people?.users_new ?? 0"
                        :to="{ name: 'user-manage' }"
                        :loading="loading"
                    />
                    <i-stat-tile
                        v-if="summary?.people && summary.people.contacts_total !== undefined && canViewContacts"
                        :label="$t('text.dashboard.contacts_total')"
                        :value="summary.people.contacts_total ?? 0"
                        :to="{ name: 'contact-manage' }"
                        :loading="loading"
                    />
                    <template v-if="summary?.modules?.education && canViewStudentReport">
                        <i-stat-tile
                            :label="$t('text.dashboard.students_total')"
                            :value="summary?.people?.students_total ?? 0"
                            :to="{ name: 'student-manage' }"
                            :loading="loading"
                        />
                        <i-stat-tile
                            :label="$t('text.dashboard.students_active')"
                            :value="summary?.people?.students_active ?? 0"
                            :to="{ name: 'student-manage' }"
                            :loading="loading"
                        />
                    </template>
                </i-stat-strip>
            </section>
        </div>

        <!-- Accounts without summary access (e.g. helpdesk agents) still see their own attention row. -->
        <div v-else-if="taskItems.length" class="space-y-6">
            <i-attention-strip :items="taskItems" />
        </div>

        <div ref="belowFold" class="h-px"></div>

        <div v-if="canViewDashboardReport && topTargetsLoaded" class="mt-6" data-testid="top-targets">
            <i-box>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="m-0 text-sm font-semibold text-ink">
                        {{ $t('text.rp.top-targets') }}
                    </h2>
                    <a-select
                        class="min-w-40 sm:hidden"
                        data-testid="top-targets-range-select"
                        size="small"
                        :value="topTargets.range"
                        :options="topTargetRangeOptions"
                        @change="setTopTargetRange"
                    />
                    <a-segmented
                        class="max-w-full overflow-x-auto max-sm:hidden"
                        :value="topTargets.range"
                        :options="topTargetRangeOptions"
                        size="small"
                        @change="setTopTargetRange"
                    />
                </div>
                <a-segmented
                    v-if="topTargets.types.length"
                    class="mt-3 max-w-full overflow-x-auto"
                    :value="topTargets.type"
                    :options="topTargetTypeOptions"
                    size="small"
                    @change="setTopTargetType"
                />
                <p v-if="topCoverage" class="mb-0 mt-3 text-xs text-ink-faint" data-testid="top-targets-coverage">
                    {{ $t(topCoverage.key, topCoverage.params) }}
                </p>
                <div class="mt-3 overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-border"
                        :class="{ 'opacity-50': topTargetsLoading }"
                    >
                        <thead class="bg-surface-sunken">
                            <tr>
                                <th class="w-8 px-4 py-2 text-left text-xs font-medium text-ink-muted">#</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-ink-muted">
                                    {{ topTargets.type ? contentTypeLabel(topTargets.type) : $t('text.rp.top-content') }}
                                </th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-ink-muted">
                                    {{ $t('text.rp.views') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="item in topTargets.items"
                                :key="item.type + ':' + item.rank + ':' + (item.url || item.target_id)"
                                class="hover:bg-surface-hover"
                            >
                                <td class="px-4 py-2 text-sm tabular-nums text-ink-faint">{{ item.rank }}</td>
                                <td class="max-w-xs truncate px-4 py-2 text-sm font-medium text-ink">
                                    <a
                                        v-if="item.url"
                                        :href="item.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-ink hover:text-primary-ink"
                                        :title="item.name"
                                    >{{ item.name }}</a>
                                    <span v-else :title="item.name">{{ item.name }}</span>
                                </td>
                                <td class="px-4 py-2 text-right text-sm font-semibold tabular-nums text-ink">
                                    {{ numberFormat(item.views, '0,0') }}
                                </td>
                            </tr>
                            <tr v-if="!topTargets.items.length">
                                <td colspan="3" class="px-4 py-6 text-center text-sm text-ink-faint">
                                    {{ $t('text.rp.no-data') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </i-box>
        </div>

        <div v-if="canViewStudentReport" class="mt-6">
            <h2 class="m-0 text-sm font-semibold text-ink">
                {{ $t('nav.a.student') }}
            </h2>
            <student-report :can-view="canViewStudentReport" />
        </div>
    </div>
</template>

<script setup>
import { defineComponent, inject, onBeforeUnmount, onMounted, ref, computed, unref, watch } from 'vue'
import { useStorage } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { numberFormat, rule } from '@/mixins'
import { useActiveModules } from '@/hooks'
import ajax from '@/ajax'
import StudentReport from '@/components/student-report.vue'
import IStatTile from '@/components/common/i-stat-tile.vue'
import IAttentionStrip from '@/components/common/i-attention-strip.vue'
import IChart from '@/components/common/i-chart.vue'
import DashboardHubBand from '@/components/dashboard/dashboard-hub-band.vue'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config/constants'
import { runtimeConfig } from '@/config/runtime'
import { isWebmaster } from '@/hooks/useCan'
import { getDashboardSummary } from '@/libs/dashboard'
import { getMyTasks } from '@/libs/my-tasks'
import { humanizeI18nKey } from '@/config/i18n-message-utils'

const { t, te } = useI18n()
// Localize a content-type label from the served i18n pack (decoupled from backend lang files).
const contentTypeLabel = (type) => {
    const raw = String(type || 'other')
    const key = 'text.rp.ctype-' + raw.toLowerCase()

    return te(key) ? t(key) : humanizeI18nKey(raw)
}

defineComponent({
    name: 'Dashboard',
})

const { activeModuleCatalogLoaded, isModuleActive } = useActiveModules()
const canViewStudentReport = computed(
    () => activeModuleCatalogLoaded.value && isModuleActive('Education') && rule('user.student')
)
const canViewPosts = computed(() => rule('post.viewAny'))
const canViewComments = computed(() => rule('comment.viewAny'))
const canViewOrders = computed(() => rule('order.viewAny'))
const canViewContacts = computed(() => rule('contact.viewAny'))
const canViewUsers = computed(() => rule('user.viewAny'))
const canViewDashboardReport = computed(() =>
    rule(['post.viewAny', 'place.viewAny', 'place_item.viewAny', 'item.viewAny', 'contact.viewAny', 'comment.viewAny'])
)
const canViewSummary = computed(
    () =>
        canViewDashboardReport.value ||
        canViewOrders.value ||
        canViewUsers.value
)

// Summary state: one aggregate request per range change.
const range = ref('7d')
const rangeOptions = [
    { label: '7d', value: '7d' },
    { label: '30d', value: '30d' },
    { label: '90d', value: '90d' },
]
const summary = ref(null)
const loading = ref(false)
const error = ref('')

const loadSummary = async (reload = false) => {
    if (!canViewSummary.value) {
        return
    }

    loading.value = true
    error.value = ''
    try {
        summary.value = await getDashboardSummary({
            range: range.value,
            ...(reload ? { reload: 1 } : {}),
        })
    } catch (e) {
        error.value = e?.response?.data?.message || e?.message || t('text.dashboard.load_failed')
    } finally {
        loading.value = false
    }
}

// Attention rows for accounts that cannot read the summary: built from the personal task inbox.
const taskItems = ref([])
let tasksRequested = false

/**
 * Load the task inbox once for accounts without summary access and map each group to an attention row.
 * Secondary widget: failures are swallowed and nothing is rendered.
 * @returns {Promise<void>}
 */
const loadTaskItems = async () => {
    if (canViewSummary.value || tasksRequested) {
        return
    }
    tasksRequested = true
    try {
        const { items } = await getMyTasks()
        taskItems.value = items.map(({ key, count, route, query }) => ({ key, count, route, query }))
    } catch {
        taskItems.value = []
    }
}

/** Number of tiles in the people strip (contacts and student tiles depend on modules and permissions). */
const peopleTileCount = computed(() => {
    const people = summary.value?.people
    const contacts = people && people.contacts_total !== undefined && canViewContacts.value ? 1 : 0
    const students = summary.value?.modules?.education && canViewStudentReport.value ? 2 : 0
    return Math.min(2 + contacts + students, 6)
})

/** Views report link; undefined (tile stays unlinked) when the viewer cannot open post lists. */
const viewsReportTo = computed(() => (canViewPosts.value ? { name: 'post-report' } : undefined))

const lifetimeTiles = computed(() => [
    { key: 'views', label: t('text.rp.total-view'), to: viewsReportTo.value },
    { key: 'visitors', label: t('text.dashboard.visitors'), to: viewsReportTo.value },
    { key: 'sessions', label: t('text.rp.total-sessions'), to: viewsReportTo.value },
    { key: 'downloads', label: t('text.rp.total-downloads'), to: viewsReportTo.value },
])

/** Notice message per notice key; keys are the existing translation keys (no new keys are added). */
const NOTICE_MESSAGE_KEYS = {
    // DashboardSummaryService::notices() sends `captcha_missing` when the reCAPTCHA site key is empty.
    captcha_missing: 'text.s.gg-site-key',
}

/**
 * Resolve the plain-language message of a settings notice: mapped key first, then the notice's own message,
 * then the Google reCaptcha text (the only notice served today).
 * @param {{ key?: string, message?: string }} notice
 * @returns {string}
 */
const noticeMessage = (notice) => {
    const mapped = NOTICE_MESSAGE_KEYS[notice?.key]
    if (mapped) return t(mapped)
    if (notice?.message) return notice.message

    return t('text.s.gg-site-key')
}

const contentTiles = computed(() => [
    { key: 'posts_total', label: t('text.dashboard.posts_total'), to: { name: 'post-post' }, tone: 'default' },
    {
        key: 'posts_published',
        label: t('text.dashboard.posts_published'),
        to: { name: 'post-post', query: { status: 'status_published' } },
        tone: 'success',
    },
    {
        key: 'posts_pending',
        label: t('text.dashboard.posts_pending'),
        to: { name: 'post-post', query: { status: 'status_pending' } },
        tone: 'warning',
    },
    { key: 'posts_new', label: t('text.dashboard.posts_new'), to: { name: 'post-post' }, tone: 'default' },
])

const commentTiles = computed(() => [
    { key: 'total', label: t('text.dashboard.comments_total'), to: { name: 'post-comment' }, tone: 'default' },
    { key: 'new', label: t('text.rp.new-comment'), to: { name: 'post-comment' }, tone: 'default' },
    {
        key: 'pending',
        label: t('text.rp.pending'),
        to: { name: 'post-comment', query: { status: 'status_pending' } },
        tone: 'warning',
    },
    {
        key: 'confirmed',
        label: t('text.rp.confirmed'),
        to: { name: 'post-comment', query: { status: 'status_published' } },
        tone: 'success',
    },
    {
        key: 'rejected',
        label: t('text.rp.rejected'),
        to: { name: 'post-comment', query: { status: 'rejected' } },
        tone: 'danger',
    },
])

// Hub band inputs: the cached account (name), the admin language and the running version.
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const accountName = computed(() => String(accountData.value?.name || ''))
const injectedLocale = inject('globalLocale', 'vi')
const adminLocale = computed(() => (String(unref(injectedLocale) || 'vi').startsWith('vi') ? 'vi' : 'en'))
const appVersion = runtimeConfig().version || ''
const webmaster = computed(() => isWebmaster())

/** Daily values of one summary series in date order, for the small charts of the period tiles. */
const seriesValues = (key) => (summary.value?.series?.[key] || []).map((row) => Number(row?.[1]) || 0)

/** Views divided by sessions, rounded to one decimal; 0 when there was no session. */
const perSession = (views, sessions) =>
    Number(sessions) > 0 ? Math.round((Number(views) / Number(sessions)) * 10) / 10 : 0

/** Pages seen per visit in the period, the previous period (null when it had no session) and per day. */
const viewsPerSession = computed(() => {
    const traffic = summary.value?.traffic || {}
    const previous = traffic.previous || {}
    const sessions = seriesValues('sessions')

    return {
        value: perSession(traffic.views, traffic.sessions),
        previous: Number(previous.sessions) > 0 ? perSession(previous.views, previous.sessions) : null,
        spark: seriesValues('views').slice(0, sessions.length).map((views, i) => perSession(views, sessions[i])),
    }
})

/** Value colour for tiles whose tone is a status (published, pending, rejected); other tones keep the ink. */
const statusTone = (tone) => (['success', 'warning', 'danger'].includes(tone) ? tone : '')

/** True when at least one point of any series is above zero (an all-zero chart is shown as empty). */
const hasSeriesValues = (series) => (series || []).some((s) => (s?.data || []).some((v) => Number(v) > 0))

// Daily views/visitors trend from the summary series (no extra request); visitors are aligned to the views dates.
const viewsCategories = computed(() => (summary.value?.series?.views || []).map((r) => String(r[0])))
const viewsSeries = computed(() => {
    const visitorsByDay = new Map((summary.value?.series?.visitors || []).map((r) => [String(r[0]), Number(r[1]) || 0]))

    return [
        { name: t('text.rp.views'), data: (summary.value?.series?.views || []).map((r) => Number(r[1]) || 0) },
        { name: t('text.dashboard.visitors'), data: viewsCategories.value.map((d) => visitorsByDay.get(d) ?? 0) },
    ]
})

// Top targets per range, read from the precomputed tracking_top cache. Loaded
// lazily once the below-the-fold sentinel scrolls into view.
/** Fresh default top-targets state, also the fallback when the response has no data. */
const defaultTopTargets = () => ({
    range: '7d',
    ranges: ['7d', '1m', '3m', '6m', '1y'],
    type: '',
    types: [],
    items: [],
})
const topTargets = ref(defaultTopTargets())
// Coverage of the shown rows against the whole-site views of the same window.
const formatDayMonth = (ymd) => {
    const m = /^\d{4}-(\d{2})-(\d{2})/.exec(ymd || '')

    return m ? `${m[2]}/${m[1]}` : ''
}
const topCoverage = computed(() => {
    const items = topTargets.value.items || []
    if (!items.length) {
        return null
    }
    const sum = items.reduce((acc, it) => acc + (Number(it.views) || 0), 0)
    const total = Number(topTargets.value.rangeTotalViews) || 0
    const from = formatDayMonth(topTargets.value.rangeStart)
    const to = formatDayMonth(topTargets.value.rangeEnd)
    const params = {
        count: items.length,
        views: numberFormat(sum, '0,0'),
        from,
        to,
    }
    if (total > 0) {
        return {
            key: 'text.rp.top-coverage',
            params: {
                ...params,
                percent: ((sum / total) * 100).toFixed(1),
                total: numberFormat(total, '0,0'),
            },
        }
    }

    return { key: from && to ? 'text.rp.top-coverage-window' : 'text.rp.top-coverage-sum', params }
})
const topTargetsLoaded = ref(false)
const topTargetsLoading = ref(false)
const belowFold = ref(null)
let observer = null

const getTopTargets = async (range = topTargets.value.range, type = topTargets.value.type) => {
    if (!canViewDashboardReport.value) {
        return
    }

    topTargetsLoading.value = true
    try {
        const res = await ajax.get('track/top-targets', {
            params: { range, ...(type ? { type } : {}), limit: 10 },
        })
        if (res?.data?.status === 'success') {
            topTargets.value = res.data.data ?? defaultTopTargets()
            topTargetsLoaded.value = true
        }
    } catch (e) {
        // Secondary widget: stay hidden when it cannot load.
    } finally {
        topTargetsLoading.value = false
    }
}
/** Segmented options for the top-targets range switch (labels from the existing range keys). */
const topTargetRangeOptions = computed(() =>
    (topTargets.value.ranges || []).map((r) => ({ label: t('text.rp.range-' + r), value: r }))
)
/** Segmented options for the content-type filter, with the per-type view count. */
const topTargetTypeOptions = computed(() =>
    (topTargets.value.types || []).map((tp) => ({
        label: `${contentTypeLabel(tp.type)} · ${numberFormat(tp.views, '0,0')}`,
        value: tp.type,
    }))
)
const setTopTargetRange = (range) => getTopTargets(range, topTargets.value.type)
const setTopTargetType = (type) => getTopTargets(topTargets.value.range, type)

// Right after sign-in the permission index can arrive after mount: load once it does instead of staying empty.
watch(canViewSummary, (ok) => {
    if (ok && !summary.value && !loading.value) loadSummary()
})
watch(canViewDashboardReport, (ok) => {
    if (ok && !topTargetsLoaded.value && !topTargetsLoading.value) getTopTargets()
})

onMounted(() => {
    loadSummary()
    loadTaskItems()

    if (typeof IntersectionObserver === 'undefined' || !belowFold.value) {
        getTopTargets()

        return
    }
    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            observer?.disconnect()
            getTopTargets()
        }
    })
    observer.observe(belowFold.value)
})

onBeforeUnmount(() => observer?.disconnect())
</script>
