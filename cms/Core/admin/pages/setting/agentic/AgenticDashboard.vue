<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <template v-if="canViewAgentic">
        <div class="space-y-6">
            <!-- Header: the settings shell already renders breadcrumbs; title, welcome copy, health and reload here. -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="m-0 text-lg font-semibold text-ink">{{ t('text.agentic.cms') }}</h1>
                    <p class="m-0 text-xs text-ink-faint">{{ t('text.agentic.dashboard.welcome') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <AgenticHealthBadge :health="health" />
                    <i-button variant="default" size="sm" :loading="isHealthFetching" data-testid="agentic-refresh" @click="refetchHealth">
                        <template #icon><i-icon icon="reload" class="size-3.5" /></template>
                        {{ t('text.agentic.refresh_health') }}
                    </i-button>
                </div>
            </div>

            <!-- Secondary nav -->
            <AgenticSubNav>
                <template #setting-agentic-approvals>
                    <span v-if="pendingCount > 0" class="ml-1 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-warning-soft px-1.5 text-xs text-warning-ink">
                        {{ pendingCount }}
                    </span>
                </template>
            </AgenticSubNav>

            <!-- Needs the operator now -->
            <i-attention-strip :items="attentionItems" />

            <!-- KPI tiles -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4" data-testid="agentic-kpi-grid">
                <i-stat-tile
                    v-for="card in statCards"
                    :key="card.key"
                    :label="card.label"
                    :value="card.value"
                    :tone="card.tone"
                    :to="card.to"
                    :description="card.hint"
                    :loading="isStatsLoading"
                />
            </div>

            <!-- Admin operator controls (admin only) -->
            <i-box v-if="canProvisionAgentic">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-ink">{{ t('text.agentic.operator_controls') }}</div>
                        <div class="text-xs text-ink-muted">{{ t('text.agentic.operator_controls_desc') }}</div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <i-button variant="default" size="sm" :loading="isSyncPending" @click="handleSyncTools">
                            {{ t('text.agentic.sync_tools') }}
                        </i-button>
                        <a-popconfirm :title="t('text.agentic.dashboard.confirm_seed')" @confirm="handleSeedTemplates">
                            <i-button variant="default" size="sm" :loading="isSeedTemplatesPending">
                                {{ t('text.agentic.seed_templates') }}
                            </i-button>
                        </a-popconfirm>
                    </div>
                </div>
            </i-box>

            <!-- Task Launcher -->
            <AgenticTaskLauncher />

            <!-- Recent runs -->
            <AgenticRunsSection
                :runs="runs"
                :meta="runMeta"
                :filters="runFilters"
                :columns="runColumns"
                :status-options="runStatusOptions"
                :is-loading="isRunsLoading"
                :is-fetching="isRunsFetching"
                :is-stop-pending="false"
                :is-cancel-pending="false"
                :is-rerun-pending="false"
                :active-run-id="activeRunId"
                :run-action-mode="runActionMode"
                :on-open-run-detail="openRunDetail"
                :on-stop-run="(run) => handleStopRun(run.id)"
                :on-cancel-run="(run) => handleCancelRun(run.id)"
                :on-rerun-run="(run) => handleRerunRun(run.id)"
                :format-meta="formatMeta"
                :runtime-status-class="runtimeStatusClass"
                :can-operate="canOperateAgentic"
            />

            <!-- Run detail drawer -->
            <AgenticRunDetailDrawer
                v-model:open="runDetailOpen"
                :record="selectedRunDetail"
                :is-fetching="isRunDetailFetching"
                :runtime-status-class="runtimeStatusClass"
            />
        </div>

        <!-- First-time wizard -->
        <AgenticFirstTimeWizard />
    </template>

    <no-permission-error v-else />
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticMeta } from './composables/useAgenticMeta'
import { useAgenticFilters } from './composables/useAgenticFilters'
import { useAgenticMutations } from './composables/useAgenticMutations'
import {
    useAgenticHealth,
    useAgenticRuns,
    useAgenticApprovals,
    useAgenticRun,
} from '@/hooks'
import AgenticHealthBadge from './components/AgenticHealthBadge.vue'
import AgenticSubNav from './components/AgenticSubNav.vue'
import AgenticTaskLauncher from './components/AgenticTaskLauncher.vue'
import AgenticRunsSection from './components/AgenticRunsSection.vue'
import AgenticRunDetailDrawer from './components/AgenticRunDetailDrawer.vue'
import AgenticFirstTimeWizard from './components/AgenticFirstTimeWizard.vue'

defineOptions({ name: 'AgenticDashboard' })

const { t } = useI18n()
const { canViewAgentic, canProvisionAgentic, canOperateAgentic } = useAgenticPermissions()
const { runtimeStatusClass, formatMeta, runColumns, runStatusOptions } = useAgenticMeta()
const { runFilters } = useAgenticFilters()
const {
    handleSyncTools, handleSeedTemplates,
    handleStopRun, handleCancelRun, handleRerunRun,
    isSyncPending, isSeedTemplatesPending,
    activeRunId, runActionMode,
} = useAgenticMutations()

// ── Queries ────────────────────────────────────────────────────
const { data: healthQuery, isFetching: isHealthFetching, refetch: refetchHealth } = useAgenticHealth({ refetchOnWindowFocus: false })
const { data: runQuery, isLoading: isRunsLoading, isFetching: isRunsFetching } = useAgenticRuns(runFilters, { refetchOnWindowFocus: false })
const { data: approvalQuery } = useAgenticApprovals({ status: 'pending', page: 1, perPage: 1 }, { refetchOnWindowFocus: false })

const health = computed(() => healthQuery.value || null)
const runs = computed(() => runQuery.value?.data || [])
const runMeta = computed(() => runQuery.value?.meta || {})
const pendingCount = computed(() => approvalQuery.value?.meta?.total || 0)
const isStatsLoading = computed(() => isRunsLoading.value || isHealthFetching.value)

// ── Run detail ─────────────────────────────────────────────────
const selectedRunId = ref(null)
const { data: runDetailQuery, isFetching: isRunDetailFetching } = useAgenticRun(selectedRunId, { refetchOnWindowFocus: false })
const selectedRunDetail = computed(() => runDetailQuery.value || null)
const runDetailOpen = computed({
    get: () => Boolean(selectedRunId.value),
    set: (value) => { if (!value) selectedRunId.value = null },
})
const openRunDetail = (run) => { selectedRunId.value = run?.id || null }

// ── Attention strip + stat tiles ───────────────────────────────
const attentionItems = computed(() => [
    {
        key: 'pending-approvals',
        route: 'setting-agentic-approvals',
        count: pendingCount.value,
        label: t('text.agentic.stat.pending_approvals'),
    },
])

/** Maps the health status to a tile tone: ok is healthy, warn waits on the operator, error is failing. */
const healthTone = computed(() => {
    const status = health.value?.status
    if (status === 'ok') return 'success'
    if (status === 'warn') return 'warning'
    if (status === 'error') return 'danger'
    return 'default'
})

const statCards = computed(() => [
    {
        key: 'pending-approvals',
        label: t('text.agentic.stat.pending_approvals'),
        value: pendingCount.value,
        hint: t('text.agentic.stat.pending_approvals_hint'),
        tone: pendingCount.value > 0 ? 'warning' : 'default',
        to: { name: 'setting-agentic-approvals' },
    },
    {
        key: 'recent-runs',
        label: t('text.agentic.stat.recent_runs'),
        value: runMeta.value?.total || 0,
        hint: t('text.agentic.stat.recent_runs_hint'),
        tone: 'info',
        to: null,
    },
    {
        key: 'health',
        label: t('text.agentic.stat.health'),
        value: health.value?.status === 'ok' ? t('text.agentic.health_ok') : health.value?.status || '—',
        hint: t('text.agentic.stat.health_hint'),
        tone: healthTone.value,
        to: null,
    },
    {
        key: 'provider',
        label: t('text.agentic.stat.provider'),
        value: health.value?.provider?.ai_driver?.toUpperCase() || '—',
        hint: t('text.agentic.stat.provider_hint'),
        tone: 'default',
        to: null,
    },
])
</script>
