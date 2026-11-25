<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <template v-if="canViewAgentic">
        <div class="space-y-4">
            <breadcrumbs
                :items="[
                    { name: 'approvals', text: t('nav.a.approvals'), icon: 'clipboard-check-outline' },
                    { text: t('text.agentic.sla.summary_title') },
                ]"
            >
                <template #description>{{ t('text.agentic.sla.summary_description') }}</template>
                <router-link :to="{ name: 'approvals' }" data-testid="sla-back">
                    <a-button>{{ t('text.agentic.approval.back_to_list') }}</a-button>
                </router-link>
            </breadcrumbs>

            <!-- Period: two plain date fields, stacked on a phone -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end" data-testid="sla-range">
                <label class="block min-w-0">
                    <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('text.agentic.sla.from') }}</span>
                    <a-date-picker
                        v-model:value="range.from"
                        value-format="YYYY-MM-DD"
                        format="DD/MM/YYYY"
                        :allow-clear="false"
                        :disabled-date="afterTo"
                        class="w-full sm:w-44"
                        data-testid="sla-from"
                    />
                </label>
                <label class="block min-w-0">
                    <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('text.agentic.sla.to') }}</span>
                    <a-date-picker
                        v-model:value="range.to"
                        value-format="YYYY-MM-DD"
                        format="DD/MM/YYYY"
                        :allow-clear="false"
                        :disabled-date="beforeFrom"
                        class="w-full sm:w-44"
                        data-testid="sla-to"
                    />
                </label>
            </div>

            <a-alert v-if="isError" type="error" show-icon :message="t('text.agentic.sla.load_failed')" data-testid="sla-error" />

            <template v-else>
                <a-alert v-if="summary.truncated" type="warning" show-icon :message="t('text.agentic.sla.truncated')" data-testid="sla-truncated" />

                <!-- Totals of the period -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" data-testid="sla-totals">
                    <i-stat-tile
                        v-for="tile in tiles"
                        :key="tile.key"
                        :label="tile.label"
                        :value="tile.value"
                        :tone="tile.tone"
                        :badge="tile.badge"
                        :loading="isLoading"
                        :data-testid="`sla-total-${tile.key}`"
                    />
                </div>

                <!-- One row per reviewer -->
                <section class="min-w-0 space-y-2 rounded-xl border border-border bg-surface p-4">
                    <h3 class="m-0 text-sm font-semibold text-ink">{{ t('text.agentic.sla.reviewers_title') }}</h3>
                    <div v-if="isLoading" class="py-6 text-center"><a-spin /></div>
                    <div
                        v-else-if="reviewers.length === 0"
                        class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-ink-muted"
                        data-testid="sla-empty"
                    >
                        {{ t('text.agentic.sla.reviewers_empty') }}
                    </div>
                    <div v-else class="max-w-full overflow-x-auto">
                        <table class="w-full min-w-[640px] border-collapse text-sm" data-testid="sla-reviewers">
                            <thead>
                                <tr class="border-b border-border text-left text-xs text-ink-muted">
                                    <th scope="col" class="px-2 py-2 font-medium">{{ t('text.agentic.sla.col_reviewer') }}</th>
                                    <th scope="col" class="px-2 py-2 text-right font-medium">{{ t('text.agentic.sla.col_assigned') }}</th>
                                    <th scope="col" class="px-2 py-2 text-right font-medium">{{ t('text.agentic.sla.col_decided') }}</th>
                                    <th scope="col" class="px-2 py-2 text-right font-medium">{{ t('text.agentic.sla.col_within') }}</th>
                                    <th scope="col" class="px-2 py-2 text-right font-medium">{{ t('text.agentic.sla.col_late') }}</th>
                                    <th scope="col" class="px-2 py-2 text-right font-medium">{{ t('text.agentic.sla.col_overdue_now') }}</th>
                                    <th scope="col" class="px-2 py-2 text-right font-medium">{{ t('text.agentic.sla.col_median') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in reviewers" :key="row.uuid" class="border-b border-border last:border-b-0" data-testid="sla-reviewer-row">
                                    <th scope="row" class="break-words px-2 py-2 text-left font-medium text-ink">{{ row.name || t('text.agentic.sla.unknown_person') }}</th>
                                    <td class="px-2 py-2 text-right text-ink">{{ count(row.assigned) }}</td>
                                    <td class="px-2 py-2 text-right text-ink">
                                        {{ count(row.decided_by_them) }}
                                        <div v-if="row.decided_by_others > 0" class="text-xs text-ink-muted">
                                            {{ t('text.agentic.sla.decided_by_others', { n: row.decided_by_others }) }}
                                        </div>
                                    </td>
                                    <td class="px-2 py-2 text-right text-ink">{{ count(row.within_sla) }}</td>
                                    <td class="px-2 py-2 text-right" :class="row.late > 0 ? 'font-semibold text-danger-ink' : 'text-ink'">{{ count(row.late) }}</td>
                                    <td class="px-2 py-2 text-right" :class="row.overdue_now > 0 ? 'font-semibold text-danger-ink' : 'text-ink'">{{ count(row.overdue_now) }}</td>
                                    <td class="whitespace-nowrap px-2 py-2 text-right text-ink">{{ formatDuration(row.median_response_seconds, t) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </div>
    </template>
    <no-permission-error v-else />
</template>

<script setup>
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticApprovalSlaSummary } from '@/hooks'
import { formatDuration } from './approval-sentence'

defineOptions({ name: 'AgenticApprovalSla' })

const { t } = useI18n()
const { canViewAgentic } = useAgenticPermissions()

// The last 30 days by default, like the API.
const range = reactive({
    from: dayjs().subtract(29, 'day').format('YYYY-MM-DD'),
    to: dayjs().format('YYYY-MM-DD'),
})
const afterTo = (date) => Boolean(range.to) && dayjs(date).isAfter(dayjs(range.to), 'day')
const beforeFrom = (date) => (Boolean(range.from) && dayjs(date).isBefore(dayjs(range.from), 'day')) || dayjs(date).isAfter(dayjs(), 'day')

const { data, isLoading, isError } = useAgenticApprovalSlaSummary(range)
const summary = computed(() => data.value || {})
const totals = computed(() => summary.value.totals || {})
const reviewers = computed(() => (Array.isArray(summary.value.reviewers) ? summary.value.reviewers : []))

const count = (value) => Number(value) || 0

const tiles = computed(() => [
    { key: 'assigned', label: t('text.agentic.sla.col_assigned'), value: count(totals.value.assigned) },
    { key: 'decided', label: t('text.agentic.sla.col_decided'), value: count(totals.value.decided) },
    { key: 'within_sla', label: t('text.agentic.sla.col_within'), value: count(totals.value.within_sla) },
    { key: 'late', label: t('text.agentic.sla.col_late'), value: count(totals.value.late), tone: count(totals.value.late) > 0 ? 'danger' : 'default' },
    {
        key: 'overdue_now',
        label: t('text.agentic.sla.col_overdue_now'),
        value: count(totals.value.overdue_now),
        tone: count(totals.value.overdue_now) > 0 ? 'danger' : 'default',
        badge: count(totals.value.overdue_now) > 0 ? t('text.agentic.sla.needs_attention') : '',
    },
    { key: 'median', label: t('text.agentic.sla.col_median'), value: formatDuration(totals.value.median_response_seconds, t) },
])
</script>
