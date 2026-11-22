<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <template v-if="canViewAgentic">
        <div class="space-y-6">
            <breadcrumbs
                :items="[
                    {
                        name: 'setting-dashboard',
                        title: $t('nav.a.settings'),
                        icon: 'cog-sync',
                    },
                    {
                        text: t('text.agentic.cms'),
                        icon: 'robot',
                    },
                ]"
            />

            <div class="agentic-panel">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-ink-muted">
                            {{ t('text.agentic.cms') }}
                        </div>
                        <div class="mt-2 text-2xl font-semibold text-ink">
                            {{ activeSurfaceMeta.title }}
                        </div>
                        <div class="mt-2 text-sm leading-6 text-ink-muted">
                            {{ activeSurfaceMeta.copy }}
                        </div>
                    </div>

                    <div class="flex flex-col items-start gap-2 lg:items-end">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="agentic-pill" :class="healthStatusClass">
                                {{ healthStatusLabel }}
                            </span>
                            <a-button
                                size="small"
                                :loading="isHealthLoading || isHealthFetching"
                                @click="refetchHealth"
                            >
                                {{ t('text.agentic.refresh_health') }}
                            </a-button>
                        </div>
                        <a-segmented
                            v-model:value="agenticSurface"
                            :options="agenticSurfaceOptions"
                            size="large"
                        />
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="card in surfaceHighlightCards"
                        :key="card.label"
                        class="rounded-xl border border-border bg-surface p-4"
                    >
                        <div class="text-[11px] uppercase tracking-[0.18em] text-ink-muted">
                            {{ card.label }}
                        </div>
                        <div class="mt-3 text-2xl font-semibold text-ink">
                            {{ card.value }}
                        </div>
                        <div class="mt-1 text-xs text-ink-muted">
                            {{ card.hint }}
                        </div>
                    </div>
                </div>
            </div>

            <template v-if="agenticSurface === 'configure'">
                <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-ink">
                            {{ t('text.agentic.operator_controls') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ t('text.agentic.page.operator_controls_intro') }}
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a-button
                            type="default"
                            :disabled="!canProvisionAgentic"
                            :loading="isSyncPending"
                            @click="handleSyncTools"
                        >
                            {{ t('text.agentic.sync_tools') }}
                        </a-button>
                        <a-button
                            type="primary"
                            :disabled="!canProvisionAgentic || !canGrantSelectedDefinition"
                            :loading="isGrantPending"
                            @click="handleGrantSelectedDefinition"
                        >
                            {{ t('text.agentic.page.grant_selected_tool') }}
                        </a-button>
                        <a-button
                            type="default"
                            :disabled="!canProvisionAgentic"
                            @click="openCreateProfileModal"
                        >
                            {{ t('text.agentic.admin.create_profile') }}
                        </a-button>
                        <a-button
                            type="default"
                            :disabled="!canProvisionAgentic"
                            :loading="isSeedTemplatesPending"
                            @click="handleSeedSampleProfiles"
                        >
                            {{ t('text.agentic.seed_templates') }}
                        </a-button>
                    </div>
                </div>

                <div class="agentic-panel">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="agentic-profile-helper-title">{{ t('text.agentic.page.health_title') }}</div>
                            <div class="agentic-profile-helper-copy">
                                {{ t('text.agentic.page.health_intro') }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="agentic-pill" :class="healthStatusClass">
                                {{ healthStatusLabel }}
                            </span>
                            <a-button
                                size="small"
                                :loading="isHealthLoading || isHealthFetching"
                                @click="refetchHealth"
                            >
                                {{ t('text.agentic.refresh_health') }}
                            </a-button>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">{{ t('text.agentic.stat.provider') }}</div>
                            <div class="agentic-profile-helper-value">{{ healthProviderLabel }}</div>
                            <div class="mt-2 text-xs text-ink-muted">
                                {{ t('text.agentic.page.embedding', { label: healthEmbeddingLabel }) }}
                            </div>
                        </div>
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">{{ t('text.agentic.health.api_key') }}</div>
                            <div class="agentic-profile-helper-value">{{ healthApiKeyLabel }}</div>
                            <div class="mt-2 text-xs text-ink-muted">
                                {{ t('text.agentic.page.api_key_note') }}
                            </div>
                        </div>
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">{{ t('text.agentic.health.scheduler') }}</div>
                            <div class="agentic-profile-helper-value">{{ healthSchedulerLabel }}</div>
                            <div class="mt-2 text-xs text-ink-muted">
                                {{ t('text.agentic.page.batch_limit', { value: health?.runtime?.scheduler_batch_limit ?? t('text.agentic.na') }) }}
                            </div>
                        </div>
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">{{ t('text.dashboard.queue') }}</div>
                            <div class="agentic-profile-helper-value">{{ healthQueueLabel }}</div>
                            <div class="mt-2 text-xs text-ink-muted">
                                {{ healthQueueCopy }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">{{ t('text.agentic.page.preflight') }}</div>
                            <div class="agentic-profile-helper-value">
                                {{ healthPreflightLabel }}
                            </div>
                            <div class="mt-2 text-xs text-ink-muted">
                                {{ t('text.agentic.page.resolved_services', { count: health?.preflight?.resolved_services?.length || 0 }) }}
                            </div>
                        </div>
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">{{ t('text.agentic.page.operator_note') }}</div>
                            <div class="agentic-profile-helper-value">
                                {{ t('text.agentic.page.operator_note_body') }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="healthIssues.length"
                        class="mt-4 space-y-2"
                    >
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.current_issues') }}</div>
                        <div
                            v-for="issue in healthIssues"
                            :key="issue.code"
                            class="rounded-lg border px-3 py-2 text-sm"
                            :class="issue.level === 'error' ? 'border-danger-soft bg-danger-soft text-danger-ink' : 'border-warning-soft bg-warning-soft text-warning-ink'"
                        >
                            <div class="font-medium">{{ issue.message }}</div>
                            <div
                                v-if="issue.details?.length"
                                class="mt-1 text-xs opacity-80"
                            >
                                {{ issue.details.join(' · ') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.75fr)]">
                    <div class="agentic-profile-helper-card">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="agentic-profile-helper-title">{{ t('text.agentic.page.quick_start') }}</div>
                                <div class="agentic-profile-helper-copy">
                                    {{ t('text.agentic.page.quick_start_intro') }}
                                </div>
                            </div>
                            <span class="agentic-pill" :class="setupChecklistCompletedCount >= 3 ? 'agentic-pill--ok' : 'agentic-pill--warn'">
                                {{ t('text.agentic.page.steps_progress', { done: setupChecklistCompletedCount }) }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <div
                                v-for="step in setupChecklist"
                                :key="step.key"
                                class="agentic-profile-helper-block"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="agentic-profile-helper-label">{{ step.title }}</div>
                                    <span class="agentic-pill" :class="step.done ? 'agentic-pill--ok' : 'agentic-pill--warn'">
                                        {{ step.done ? t('text.agentic.page.step_done') : t('text.agentic.page.step_next') }}
                                    </span>
                                </div>
                                <div class="agentic-profile-helper-value">{{ step.copy }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="agentic-panel">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.recommended_next_step') }}</div>
                        <div class="mt-2 text-base font-semibold text-ink">
                            {{ nextSetupAction.title }}
                        </div>
                        <div class="mt-2 text-sm leading-6 text-ink-muted">
                            {{ nextSetupAction.copy }}
                        </div>
                        <div class="mt-4 grid gap-2">
                            <div class="rounded-lg bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                                {{ t('text.agentic.schedules.current_profile') }}:
                                <span class="font-medium text-ink">{{ selectedProfile?.name || t('text.agentic.page.not_selected') }}</span>
                            </div>
                            <div class="rounded-lg bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                                {{ t('text.agentic.schedules.current_tool') }}:
                                <span class="font-medium text-ink">{{ selectedDefinition?.tool_name || t('text.agentic.page.not_selected') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="agentic-panel">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="agentic-profile-helper-title">{{ t('text.agentic.page.current_selection') }}</div>
                        <div class="agentic-profile-helper-copy">
                            {{ t('text.agentic.page.current_selection_intro') }}
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="rounded-lg bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                            {{ t('text.agentic.page.grant_status') }}
                            <span class="font-medium" :class="selectedDefinitionIsGranted ? 'text-success-ink' : 'text-warning-ink'">
                                {{ selectedDefinitionIsGranted ? t('text.agentic.page.grant_granted') : t('text.agentic.page.grant_not_granted') }}
                            </span>
                        </div>
                        <a-button
                            v-if="agenticSurface === 'operate'"
                            size="small"
                            type="default"
                            @click="agenticSurface = 'configure'"
                        >
                            {{ t('text.agentic.page.open_configure') }}
                        </a-button>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(220px,0.75fr)]">
                    <div class="agentic-profile-helper-block">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.agent_profile') }}</div>
                        <a-select
                            v-model:value="selectedProfileKey"
                            :options="profileSelectOptions"
                            allow-clear
                            show-search
                            option-filter-prop="label"
                            :placeholder="t('text.agentic.select_profile')"
                            class="mt-2 w-full"
                        />
                        <div class="mt-2 text-xs text-ink-muted">
                            {{ selectedProfile ? capabilityLabel(selectedProfile.capability_class) : t('text.agentic.page.profile_empty_hint') }}
                        </div>
                    </div>

                    <div class="agentic-profile-helper-block">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.tool_definition') }}</div>
                        <a-select
                            v-model:value="selectedDefinitionKey"
                            :options="definitionSelectOptions"
                            allow-clear
                            show-search
                            option-filter-prop="label"
                            :placeholder="t('text.agentic.select_tool')"
                            class="mt-2 w-full"
                        />
                        <div class="mt-2 text-xs text-ink-muted">
                            {{ selectedDefinition ? `${selectedDefinition.tool_family || t('text.agentic.page.family_general')} · ${capabilityLabel(selectedDefinition.capability_class)}` : t('text.agentic.page.definition_empty_hint') }}
                        </div>
                    </div>

                    <div class="agentic-profile-helper-block">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.current_status') }}</div>
                        <div class="mt-2 text-sm font-semibold text-ink">
                            {{ currentSelectionStatus.title }}
                        </div>
                        <div class="mt-2 text-xs leading-5 text-ink-muted">
                            {{ currentSelectionStatus.copy }}
                        </div>
                    </div>
                </div>
            </div>

            <template v-if="agenticSurface === 'operate'">
                <div class="agentic-panel">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="agentic-profile-helper-title">{{ t('text.agentic.page.starter_recipes') }}</div>
                            <div class="agentic-profile-helper-copy">
                                {{ t('text.agentic.page.recipes_intro') }}
                            </div>
                        </div>
                        <div class="rounded-lg bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                            {{
                                t('text.agentic.page.recipes_suggestion', { first: t('text.agentic.recipe.ops_health_overview'), second: t('text.agentic.recipe.inventory_alerts_digest') })
                            }}
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="recipe in starterRecipes"
                            :key="recipe.key"
                            class="agentic-profile-helper-block"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="agentic-profile-helper-label">{{ recipe.title }}</div>
                                <span class="agentic-pill" :class="recipe.ready ? 'agentic-pill--ok' : 'agentic-pill--warn'">
                                    {{ recipe.ready ? t('text.agentic.page.recipe_ready') : t('text.agentic.page.recipe_seed_first') }}
                                </span>
                            </div>
                            <div class="agentic-profile-helper-value">{{ recipe.copy }}</div>
                            <div class="mt-2 text-xs text-ink-muted">
                                {{ recipe.profileLabel }} · {{ recipe.toolLabel }}
                            </div>
                            <div class="mt-3">
                                <a-button
                                    size="small"
                                    :disabled="!recipe.ready"
                                    @click="applyStarterRecipe(recipe)"
                                >
                                    {{ t('text.agentic.page.use_recipe') }}
                                </a-button>
                            </div>
                        </div>
                    </div>
                </div>

                <section class="i-box space-y-4">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-lg font-semibold text-ink">{{ t('text.agentic.page.surface_operate') }}</div>
                            <div class="text-sm text-ink-muted">
                                {{ t('text.agentic.page.operate_intro') }}
                            </div>
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ t('text.agentic.page.operate_note') }}
                        </div>
                    </div>

                    <a-tabs v-model:activeKey="operatorSurfaceTab" size="small">
                        <a-tab-pane :key="'approvals'" :tab="t('text.agentic.page.tab_approvals', { count: approvalMeta.total || 0 })">
                            <AgenticApprovalsSection
                                :filters="approvalFilters"
                                :status-options="approvalStatusOptions"
                                :apply-outcome-options="applyOutcomeOptions"
                                :columns="approvalColumns"
                                :approvals="approvals"
                                :meta="approvalMeta"
                                :can-approve="canApproveAgentic"
                                :is-loading="isApprovalsLoading"
                                :is-fetching="isApprovalsFetching"
                                :is-approve-pending="isApprovePending"
                                :is-reject-pending="isRejectPending"
                                :is-apply-pending="isApplyPending"
                                :active-approval-action-id="activeApprovalActionId"
                                :approval-action-mode="approvalActionMode"
                                :runtime-status-class="runtimeStatusClass"
                                :format-meta="formatMeta"
                                :format-json="formatJson"
                                :format-scorecard-value="formatScorecardValue"
                                :should-show-apply="shouldShowApply"
                                :on-open-approval-detail="openApprovalDetail"
                                :on-approve-approval="handleApproveApproval"
                                :on-reject-approval="handleRejectApproval"
                                :on-apply-approval="handleApplyApproval"
                            />
                        </a-tab-pane>

                        <a-tab-pane :key="'runs'" :tab="t('text.agentic.page.tab_runs', { count: runMeta.total || 0 })">
                            <AgenticRunsSection
                                :filters="runFilters"
                                :status-options="runStatusOptions"
                                :columns="runColumns"
                                :runs="runs"
                                :meta="runMeta"
                                :can-operate="canOperateAgentic"
                                :is-loading="isRunsLoading"
                                :is-fetching="isRunsFetching"
                                :is-stop-pending="isStopRunPending"
                                :is-cancel-pending="isCancelRunPending"
                                :is-rerun-pending="isRerunRunPending"
                                :active-run-id="activeRunId"
                                :run-action-mode="runActionMode"
                                :runtime-status-class="runtimeStatusClass"
                                :format-meta="formatMeta"
                                :on-open-run-detail="openRunDetail"
                                :on-stop-run="handleStopRun"
                                :on-cancel-run="handleCancelRun"
                                :on-rerun-run="handleRerunRun"
                            />
                        </a-tab-pane>

                        <a-tab-pane
                            :key="'schedules'"
                            :tab="`${t('text.agentic.schedules.tab')} (${scheduleMeta.total || 0})`"
                        >
                            <AgenticSchedulesSection
                                :filters="scheduleFilters"
                                :draft="scheduleDraft"
                                :cadence-options="scheduleCadenceOptions"
                                :status-options="scheduleStatusOptions"
                                :columns="scheduleColumns"
                                :schedules="schedules"
                                :meta="scheduleMeta"
                                :selected-profile="selectedProfile"
                                :selected-definition="selectedDefinition"
                                :selected-profile-capability-meta="selectedScheduleCapabilityMeta"
                                :can-create-schedule="canCreateSchedule"
                                :schedule-eligibility-message="scheduleEligibilityMessage"
                                :schedule-eligibility-tone="scheduleEligibilityTone"
                                :default-schedule-name="defaultScheduleName"
                                :can-operate="canOperateAgentic"
                                :can-provision="canProvisionAgentic"
                                :is-create-pending="isCreateSchedulePending"
                                :is-loading="isSchedulesLoading"
                                :is-fetching="isSchedulesFetching"
                                :is-dispatch-pending="isDispatchSchedulePending"
                                :is-update-pending="isUpdateSchedulePending"
                                :active-schedule-id="activeScheduleId"
                                :schedule-action-mode="scheduleActionMode"
                                :runtime-status-class="runtimeStatusClass"
                                :format-meta="formatMeta"
                                :on-create-schedule="handleCreateSchedule"
                                :on-toggle-schedule="handleToggleSchedule"
                                :on-dispatch-schedule="handleDispatchSchedule"
                                :on-run-once-schedule="handleRunOnceSchedule"
                                :on-resume-dispatch-schedule="handleResumeDispatchSchedule"
                                :on-edit-schedule-cadence="handleEditScheduleCadence"
                                :on-open-run-detail="openRunDetail"
                            />
                        </a-tab-pane>
                    </a-tabs>
                </section>
            </template>

            <template v-else>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                    <div
                        v-for="card in statCards"
                        :key="card.label"
                        class="agentic-stat rounded-xl border border-border p-4"
                    >
                        <div class="text-[11px] uppercase tracking-[0.22em] text-ink-muted">
                            {{ card.label }}
                        </div>
                        <div class="mt-3 flex items-end justify-between">
                            <div class="text-3xl font-semibold text-ink">
                                {{ card.value }}
                            </div>
                            <span class="rounded-full bg-surface px-2 py-1 text-[11px] text-ink-muted">
                                {{ card.hint }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,0.95fr)]">
                <div class="space-y-4">
                    <section class="i-box space-y-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div class="text-lg font-semibold text-ink">
                                    {{ t('text.agentic.page.tool_definitions') }}
                                </div>
                                <div class="text-sm text-ink-muted">
                                    {{ t('text.agentic.page.tool_definitions_desc') }}
                                </div>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-3">
                                <a-input
                                    v-model:value="definitionFilters.search"
                                    allow-clear
                                    :placeholder="t('text.agentic.search_tool_name')"
                                />
                                <a-select
                                    v-model:value="definitionFilters.family"
                                    :options="definitionFamilyOptions"
                                    allow-clear
                                    :placeholder="t('text.agentic.col.family')"
                                />
                                <a-select
                                    v-model:value="definitionFilters.capabilityClass"
                                    :options="capabilityOptions"
                                    allow-clear
                                    :placeholder="t('text.agentic.col.capability')"
                                />
                            </div>
                        </div>

                        <a-table
                            class="agentic-wide-table"
                            :columns="definitionColumns"
                            :data-source="orderedDefinitions"
                            :loading="isDefinitionsLoading || isDefinitionsFetching"
                            :pagination="false"
                            row-key="id"
                            :scroll="{ x: 1080 }"
                            size="small"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'tool_name'">
                                    <div class="agentic-table-primary font-medium text-ink">{{ record.tool_name }}</div>
                                    <div class="text-xs text-ink-muted">{{ record.description || t('text.modules.no_desc') }}</div>
                                </template>
                                <template v-else-if="column.key === 'governance'">
                                    <div class="space-y-1">
                                        <div class="text-xs font-medium text-ink">
                                            {{ capabilityLabel(record.capability_class) }}
                                        </div>
                                        <div class="text-[11px] text-ink-muted">
                                            {{ capabilitySummary(record.capability_class) }}
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <span class="agentic-pill">{{ record.side_effect_class }}</span>
                                            <span class="agentic-pill">{{ record.safety_level }}</span>
                                            <span v-if="record.approval_required" class="agentic-pill agentic-pill--warn">
                                                {{ t('text.agentic.page.pill_approval') }}
                                            </span>
                                        </div>
                                    </div>
                                </template>
                                <template v-else-if="column.key === 'select'">
                                    <button
                                        class="rounded-md border px-2 py-1 text-xs transition"
                                        :class="
                                            selectedDefinitionKey === record.tool_name
                                                ? 'border-warning-soft bg-warning-soft text-warning-ink'
                                                : 'border-border text-ink hover:border-primary hover:text-ink'
                                        "
                                        @click="selectDefinition(record)"
                                    >
                                        {{ selectedDefinitionKey === record.tool_name ? t('text.selected') : t('text.agentic.col.select') }}
                                    </button>
                                </template>
                            </template>
                        </a-table>

                        <div class="flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs text-ink-muted">
                                {{ formatMeta(definitionMeta) }}
                            </div>
                            <a-pagination
                                v-model:current="definitionFilters.page"
                                :page-size="definitionFilters.perPage"
                                :total="definitionMeta.total || 0"
                                show-less-items
                                size="small"
                            />
                        </div>
                    </section>
                </div>

                <div class="space-y-4">
                    <section class="i-box space-y-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div class="text-lg font-semibold text-ink">{{ t('text.agentic.admin.profiles') }}</div>
                                <div class="text-sm text-ink-muted">
                                    {{ t('text.agentic.page.profiles_desc') }}
                                </div>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <a-input
                                        v-model:value="profileFilters.search"
                                        allow-clear
                                        :placeholder="t('text.agentic.search_profile')"
                                    />
                                    <a-select
                                        v-model:value="profileFilters.capabilityClass"
                                        :options="capabilityOptions"
                                        allow-clear
                                        :placeholder="t('text.agentic.col.capability')"
                                    />
                                </div>
                                <a-button
                                    type="default"
                                    :disabled="!canProvisionAgentic"
                                    @click="openCreateProfileModal"
                                >
                                    {{ t('text.agentic.page.new_profile') }}
                                </a-button>
                            </div>
                        </div>

                        <a-table
                            :columns="profileColumns"
                            :data-source="orderedProfiles"
                            :loading="isProfilesLoading || isProfilesFetching"
                            :pagination="false"
                            row-key="id"
                            size="small"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'name'">
                                    <div class="font-medium text-ink">{{ record.name }}</div>
                                    <div class="text-xs text-ink-muted">{{ record.slug }}</div>
                                </template>
                                <template v-else-if="column.key === 'capability_class'">
                                    <div class="font-medium text-ink">{{ capabilityLabel(record.capability_class) }}</div>
                                    <div class="text-xs text-ink-muted">{{ capabilitySummary(record.capability_class) }}</div>
                                </template>
                                <template v-else-if="column.key === 'inspect'">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            class="rounded-md border border-border px-2 py-1 text-xs text-ink transition hover:border-primary hover:text-ink"
                                            @click="selectProfile(record)"
                                        >
                                            {{ selectedProfileKey === (record.slug || record.id) ? t('text.selected') : t('text.agentic.page.inspect') }}
                                        </button>
                                        <a-button
                                            size="small"
                                            :disabled="!canProvisionAgentic"
                                            @click="openEditProfileModal(record)"
                                        >
                                            {{ t('text.agentic.admin.edit') }}
                                        </a-button>
                                    </div>
                                </template>
                            </template>
                        </a-table>

                        <div
                            v-if="!profiles.length"
                            class="rounded-xl border border-dashed border-border bg-primary-soft p-5 text-sm text-ink-muted"
                        >
                            <div class="font-medium text-ink">
                                {{ t('text.agentic.page.no_profiles') }}
                            </div>
                            <div class="mt-2">
                                {{ t('text.agentic.page.no_profiles_hint') }}
                            </div>
                            <a-button
                                class="mt-3"
                                type="default"
                                :disabled="!canProvisionAgentic"
                                @click="openCreateProfileModal"
                            >
                                {{ t('text.agentic.page.create_first_profile') }}
                            </a-button>
                            <a-button
                                class="mt-3 ml-2"
                                type="default"
                                :disabled="!canProvisionAgentic"
                                :loading="isSeedTemplatesPending"
                                @click="handleSeedSampleProfiles"
                            >
                                {{ t('text.agentic.seed_templates') }}
                            </a-button>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs text-ink-muted">
                                {{ formatMeta(profileMeta) }}
                            </div>
                            <a-pagination
                                v-model:current="profileFilters.page"
                                :page-size="profileFilters.perPage"
                                :total="profileMeta.total || 0"
                                show-less-items
                                size="small"
                            />
                        </div>
                    </section>

                    <section class="i-box space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-lg font-semibold text-ink">{{ t('text.agentic.card.granted_tools') }}</div>
                                <div class="text-sm text-ink-muted">
                                    {{ t('text.agentic.page.granted_tools_desc') }}
                                </div>
                            </div>
                            <div
                                v-if="selectedProfile"
                                class="rounded-full border border-border px-3 py-1 text-xs text-ink-muted"
                            >
                                {{ selectedProfile.slug }}
                            </div>
                        </div>

                        <template v-if="selectedProfile">
                            <div
                                v-if="selectedDefinition"
                                class="rounded-xl border border-border bg-primary-soft p-4"
                            >
                                <div class="text-[11px] uppercase tracking-[0.18em] text-ink-muted">
                                    {{ t('text.agentic.page.selected_definition') }}
                                </div>
                                <div class="mt-2 font-medium text-ink">
                                    {{ selectedDefinition.tool_name }}
                                </div>
                                <div class="mt-1 text-xs text-ink-muted">
                                    {{ selectedDefinition.contract_steward }} · {{ capabilityLabel(selectedDefinition.capability_class) }}
                                </div>
                                <div class="mt-3 flex flex-wrap gap-1">
                                    <span class="agentic-pill">{{ selectedDefinition.tool_family }}</span>
                                    <span class="agentic-pill">{{ selectedDefinition.side_effect_class }}</span>
                                    <span
                                        v-if="selectedDefinitionIsGranted"
                                        class="agentic-pill agentic-pill--ok"
                                    >
                                        {{ t('text.agentic.page.already_granted') }}
                                    </span>
                                </div>
                            </div>

                            <div class="rounded-xl border border-border bg-surface-sunken p-4">
                                <div class="text-sm font-semibold text-ink">
                                    {{ selectedProfile.name }}
                                </div>
                                <div class="mt-1 text-xs font-medium text-ink-muted">
                                    {{ capabilityLabel(selectedProfile.capability_class) }}
                                </div>
                                <div class="mt-1 text-xs text-ink-muted">
                                    {{ capabilitySummary(selectedProfile.capability_class) }}
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div class="rounded-lg bg-surface p-3">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-ink-muted">
                                            {{ t('text.agentic.page.grants_count') }}
                                        </div>
                                        <div class="mt-1 text-xl font-semibold text-ink">
                                            {{ grants.length }}
                                        </div>
                                    </div>
                                    <div class="rounded-lg bg-surface p-3">
                                        <div class="text-[11px] uppercase tracking-[0.16em] text-ink-muted">
                                            {{ t('text.agentic.col.runs') }}
                                        </div>
                                        <div class="mt-1 text-xl font-semibold text-ink">
                                            {{ selectedProfile.run_count || 0 }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="grants.length"
                                class="space-y-3"
                            >
                                <div
                                    v-for="grant in grants"
                                    :key="grant.id"
                                    class="rounded-xl border border-border p-4"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">
                                                {{ grant.tool_name }}
                                            </div>
                                            <div class="mt-1 text-xs text-ink-muted">
                                                {{ grant.contract_steward }} · {{ capabilityLabel(grant.capability_class) }}
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <span class="agentic-pill">{{ grant.tool_family }}</span>
                                            <span class="agentic-pill">{{ grant.status }}</span>
                                            <span
                                                class="agentic-pill"
                                                :class="grant.grant.enabled ? 'agentic-pill--ok' : 'agentic-pill--muted'"
                                            >
                                                {{ grant.grant.enabled ? t('text.agentic.page.pill_enabled') : t('text.agentic.page.pill_disabled') }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-2 text-xs text-ink-muted sm:grid-cols-2">
                                        <div>{{ t('text.agentic.page.bindings_count', { count: grant.binding_count }) }}</div>
                                        <div>{{ t('text.agentic.page.safety_override_value', { value: grant.grant.safety_override || t('text.agentic.none') }) }}</div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <a-button
                                            size="small"
                                            :disabled="!canProvisionAgentic"
                                            :loading="activeGrantToolId === grant.id && grantActionMode === 'safety' && isUpdateGrantPending"
                                            @click="handleEditGrantSafetyOverride(grant)"
                                        >
                                            {{ t('text.agentic.page.safety_override_button') }}
                                        </a-button>
                                        <a-button
                                            size="small"
                                            :disabled="!canProvisionAgentic"
                                            :loading="activeGrantToolId === grant.id && grantActionMode === 'toggle' && isUpdateGrantPending"
                                            @click="handleToggleGrant(grant)"
                                        >
                                            {{ grant.grant.enabled ? t('text.agentic.page.action_disable') : t('text.agentic.page.action_enable') }}
                                        </a-button>
                                        <a-button
                                            size="small"
                                            danger
                                            :disabled="!canProvisionAgentic"
                                            :loading="activeGrantToolId === grant.id && grantActionMode === 'revoke' && isRevokeGrantPending"
                                            @click="handleRevokeGrant(grant)"
                                        >
                                            {{ t('text.agentic.admin.revoke') }}
                                        </a-button>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="rounded-xl border border-dashed border-border p-5 text-sm text-ink-muted">
                                {{ t('text.agentic.page.no_grants') }}
                            </div>
                        </template>

                        <div
                            v-else
                            class="rounded-xl border border-dashed border-border p-5 text-sm text-ink-muted"
                        >
                            {{ t('text.agentic.page.select_profile_to_inspect') }}
                        </div>
                    </section>
                </div>
                </div>

                <a-collapse v-model:activeKey="advancedPanelKeys" class="bg-transparent">
                    <a-collapse-panel key="bindings" :header="t('text.agentic.page.advanced_bindings')">
                        <section class="space-y-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                                <div>
                                    <div class="text-lg font-semibold text-ink">{{ t('text.agentic.page.tool_bindings') }}</div>
                                    <div class="text-sm text-ink-muted">
                                        {{ t('text.agentic.page.bindings_intro') }}
                                    </div>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-3">
                                    <a-input
                                        v-model:value="bindingFilters.toolName"
                                        allow-clear
                                        :placeholder="t('text.agentic.tool_name')"
                                    />
                                    <a-select
                                        v-model:value="bindingFilters.moduleScope"
                                        :options="bindingModuleOptions"
                                        allow-clear
                                        :placeholder="t('text.agentic.module_scope')"
                                    />
                                    <a-select
                                        v-model:value="bindingFilters.enabled"
                                        :options="enabledOptions"
                                        allow-clear
                                        :placeholder="t('text.agentic.enabled')"
                                    />
                                </div>
                            </div>

                            <a-table
                                class="agentic-wide-table"
                                :columns="bindingColumns"
                                :data-source="bindings"
                                :loading="isBindingsLoading || isBindingsFetching"
                                :pagination="false"
                                row-key="id"
                                :scroll="{ x: 1240 }"
                                size="small"
                            >
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'binding_target'">
                                        <code class="agentic-inline-code block rounded-md bg-primary-soft px-2 py-1 text-[11px] text-ink">
                                            {{ record.binding_target }}
                                        </code>
                                    </template>
                                    <template v-else-if="column.key === 'scope'">
                                        <div class="space-y-1 text-xs text-ink-muted">
                                            <div>{{ t('text.agentic.page.binding_module', { value: record.module_scope || t('text.agentic.schedules.scope_global') }) }}</div>
                                            <div>{{ t('text.agentic.page.binding_env', { value: record.environment || t('text.agentic.page.hint_all') }) }}</div>
                                            <div>{{ t('text.agentic.page.binding_priority', { value: record.priority }) }}</div>
                                        </div>
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <div class="flex flex-wrap gap-2">
                                            <a-button
                                                size="small"
                                                :disabled="!canProvisionAgentic"
                                                :loading="activeBindingId === record.id && bindingActionMode === 'toggle' && isUpdateBindingPending"
                                                @click="handleToggleBinding(record)"
                                            >
                                                {{ record.enabled ? t('text.agentic.page.action_disable') : t('text.agentic.page.action_enable') }}
                                            </a-button>
                                            <a-button
                                                size="small"
                                                :disabled="!canProvisionAgentic"
                                                :loading="activeBindingId === record.id && bindingActionMode === 'priority' && isUpdateBindingPending"
                                                @click="handleEditBindingPriority(record)"
                                            >
                                                {{ t('text.modules.priority') }}
                                            </a-button>
                                        </div>
                                    </template>
                                </template>
                            </a-table>

                            <div class="flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="text-xs text-ink-muted">
                                    {{ formatMeta(bindingMeta) }}
                                </div>
                                <a-pagination
                                    v-model:current="bindingFilters.page"
                                    :page-size="bindingFilters.perPage"
                                    :total="bindingMeta.total || 0"
                                    show-less-items
                                    size="small"
                                />
                            </div>
                        </section>
                    </a-collapse-panel>
                </a-collapse>
            </template>
        </div>

        <AgenticRunDetailDrawer
            v-model:open="runDetailOpen"
            :record="selectedRunDetail"
            :is-fetching="isRunDetailFetching"
            :runtime-status-class="runtimeStatusClass"
        />

        <AgenticApprovalDetailDrawer
            v-model:open="approvalDetailOpen"
            :record="selectedApprovalDetail"
            :is-fetching="isApprovalDetailFetching"
            :runtime-status-class="runtimeStatusClass"
            :format-json="formatJson"
            :format-scorecard-value="formatScorecardValue"
        />

        <a-modal
            v-model:open="profileModalOpen"
            :title="profileModalMode === 'create' ? t('text.agentic.page.modal_create_title') : t('text.agentic.page.modal_edit_title')"
            :confirm-loading="isSaveProfilePending"
            :ok-text="t('text.agentic.save_profile')"
            width="760px"
            @ok="handleSaveProfile"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <a-form-item :label="$t('validation.attributes.name')">
                    <a-input
                        v-model:value="profileDraft.name"
                        :placeholder="t('text.agentic.content_reader')"
                    />
                </a-form-item>

                <a-form-item :label="t('text.agentic.admin.profile_slug')">
                    <a-input
                        v-model:value="profileDraft.slug"
                        placeholder="content-reader"
                    />
                </a-form-item>

                <a-form-item :label="t('text.agentic.capability_class')">
                    <a-select
                        v-model:value="profileDraft.capabilityClass"
                        :options="capabilityOptions"
                        :placeholder="t('text.agentic.select_capability')"
                    />
                    <div class="agentic-form-help">
                        {{ t('text.agentic.page.capability_help') }}
                    </div>
                </a-form-item>

                <a-form-item :label="$t('validation.attributes.status')">
                    <a-select
                        v-model:value="profileDraft.status"
                        :options="profileStatusOptions"
                        :placeholder="t('text.agentic.select_status')"
                    />
                    <div class="agentic-form-help">
                        {{ t('text.agentic.page.status_help') }}
                    </div>
                </a-form-item>
            </div>

            <div
                v-if="selectedCapabilityMeta"
                class="agentic-profile-helper-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="agentic-profile-helper-title">
                            {{ selectedCapabilityMeta.label }}
                        </div>
                        <div class="agentic-profile-helper-copy">
                            {{ selectedCapabilityMeta.summary }}
                        </div>
                    </div>
                    <span class="agentic-pill" :class="selectedCapabilityMeta.scheduleAllowed ? 'agentic-pill--ok' : 'agentic-pill--muted'">
                        {{ selectedCapabilityMeta.scheduleAllowed ? t('text.agentic.page.schedule_allowed') : t('text.agentic.page.schedule_not_allowed') }}
                    </span>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <div class="agentic-profile-helper-block">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.use_when') }}</div>
                        <div class="agentic-profile-helper-value">{{ selectedCapabilityMeta.useWhen }}</div>
                    </div>
                    <div class="agentic-profile-helper-block">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.allows') }}</div>
                        <div class="agentic-profile-helper-value">{{ selectedCapabilityMeta.allows }}</div>
                    </div>
                    <div class="agentic-profile-helper-block">
                        <div class="agentic-profile-helper-label">{{ t('text.agentic.page.requires_approval') }}</div>
                        <div class="agentic-profile-helper-value">{{ selectedCapabilityMeta.approvalLabel }}</div>
                    </div>
                </div>
            </div>

            <a-form-item :label="$t('validation.attributes.description')">
                <a-textarea
                    v-model:value="profileDraft.description"
                    :rows="3"
                    :placeholder="t('text.agentic.profile_description_placeholder')"
                />
            </a-form-item>

            <a-form-item :label="t('text.agentic.instruction_note_optional')">
                <a-textarea
                    v-model:value="profileDraft.systemPromptTemplate"
                    :rows="4"
                    :placeholder="t('text.agentic.instruction_note_placeholder')"
                />
                <div class="agentic-form-help">
                    {{ t('text.agentic.page.instruction_note_help') }}
                </div>
            </a-form-item>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="agentic-profile-helper-card">
                    <div class="agentic-profile-helper-title">{{ t('text.agentic.stat.provider') }}</div>
                    <div class="agentic-profile-helper-copy">
                        {{ t('text.agentic.page.provider_global_note') }}
                    </div>
                </div>

                <a-form-item :label="t('text.agentic.run_budget')">
                    <a-select
                        v-model:value="profileDraft.budgetPreset"
                        :options="availableBudgetPresetOptions"
                        :placeholder="t('text.agentic.select_run_budget')"
                    />
                    <div class="agentic-form-help">
                        {{ t('text.agentic.page.budget_help') }}
                    </div>
                </a-form-item>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div
                    v-if="selectedBudgetPresetMeta"
                    class="agentic-profile-helper-card"
                >
                    <div class="agentic-profile-helper-title">{{ selectedBudgetPresetMeta.label }}</div>
                    <div class="agentic-profile-helper-copy">{{ selectedBudgetPresetMeta.summary }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="agentic-pill">{{ t('text.agentic.page.max_steps', { count: selectedBudgetPresetMeta.maxSteps }) }}</span>
                    </div>
                </div>
            </div>
        </a-modal>
    </template>

    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { computed, defineComponent, reactive, ref, watch } from 'vue'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { rule } from '@/mixins'
import AgenticApprovalDetailDrawer from './agentic/components/AgenticApprovalDetailDrawer.vue'
import AgenticApprovalsSection from './agentic/components/AgenticApprovalsSection.vue'
import AgenticRunDetailDrawer from './agentic/components/AgenticRunDetailDrawer.vue'
import AgenticRunsSection from './agentic/components/AgenticRunsSection.vue'
import AgenticSchedulesSection from './agentic/components/AgenticSchedulesSection.vue'
import { withAgenticStarterTools } from './agentic/registry'
import {
    useAgenticApproval,
    useAgenticApprovals,
    useAgenticCreateProfile,
    useAgenticGrantProfileTools,
    useAgenticHealth,
    useAgenticProfileGrants,
    useAgenticProfiles,
    useAgenticRevokeProfileToolGrant,
    useAgenticRun,
    useAgenticRuns,
    useAgenticSchedules,
    useAgenticSeedProfileTemplates,
    useAgenticSyncTools,
    useAgenticToolBindings,
    useAgenticToolDefinitions,
    useAgenticUpdateToolBinding,
    useAgenticUpdateProfile,
    useAgenticUpdateProfileToolGrant,
    useApplyAgenticApproval,
    useApproveAgenticApproval,
    useCancelAgenticRun,
    useCreateAgenticSchedule,
    useDispatchAgenticSchedule,
    useRejectAgenticApproval,
    useResumeDispatchAgenticSchedule,
    useRerunAgenticRun,
    useRunOnceAgenticSchedule,
    useStopAgenticRun,
    useUpdateAgenticSchedule,
} from '@/hooks'

defineComponent({
    name: 'SettingAgentic',
})

const { t } = useI18n()
const canViewAgentic = computed(() => rule(['agentic.viewAny', 'workflow.viewAny', 'workflow_project.viewAny']))
const canProvisionAgentic = computed(() => rule(['agentic.update', 'workflow.update', 'workflow_project.update']))
const canApproveAgentic = computed(() => rule(['agentic.approve', 'agentic.update']))
const canOperateAgentic = computed(() => rule(['agentic.update', 'workflow.update']))

const definitionFilters = reactive({
    search: '',
    family: undefined,
    capabilityClass: undefined,
    page: 1,
    perPage: 8,
})

const bindingFilters = reactive({
    toolName: '',
    moduleScope: undefined,
    enabled: undefined,
    page: 1,
    perPage: 8,
})

const profileFilters = reactive({
    search: '',
    capabilityClass: undefined,
    page: 1,
    perPage: 8,
})

const runFilters = reactive({
    search: '',
    status: undefined,
    moduleScope: undefined,
    page: 1,
    perPage: 6,
})

const approvalFilters = reactive({
    status: 'pending',
    applyOutcome: undefined,
    artifactType: undefined,
    page: 1,
    perPage: 6,
})

const scheduleFilters = reactive({
    search: '',
    status: 'active',
    profile: undefined,
    toolName: undefined,
    moduleScope: undefined,
    page: 1,
    perPage: 6,
})

const scheduleDraft = reactive({
    name: '',
    cadenceMinutes: 1440,
    showAdvanced: false,
    environment: '',
    moduleScope: '',
    goal: '',
    targetType: '',
    targetId: '',
})

const profileDraft = reactive({
    name: '',
    slug: '',
    description: '',
    capabilityClass: 'class_0_read_only',
    status: 'active',
    systemPromptTemplate: '',
    budgetPreset: 'standard',
    existingBudgetConfig: null,
})

const capabilityMetaMap = computed(() => ({
    class_0_read_only: {
        value: 'class_0_read_only',
        label: t('text.agentic.capability.class_0_label'),
        shortLabel: t('text.agentic.capability.short_class_0'),
        summary: t('text.agentic.capability.class_0_summary'),
        useWhen: t('text.agentic.page.cap0_use_when'),
        allows: t('text.agentic.page.cap0_allows'),
        approvalLabel: t('text.agentic.page.cap0_approval'),
        scheduleAllowed: false,
    },
    class_1_draft_generation: {
        value: 'class_1_draft_generation',
        label: t('text.agentic.capability.class_1_label'),
        shortLabel: t('text.agentic.capability.short_class_1'),
        summary: t('text.agentic.page.cap1_summary'),
        useWhen: t('text.agentic.page.cap1_use_when'),
        allows: t('text.agentic.page.cap1_allows'),
        approvalLabel: t('text.agentic.page.cap1_approval'),
        scheduleAllowed: false,
    },
    class_2_staged_mutation: {
        value: 'class_2_staged_mutation',
        label: t('text.agentic.capability.class_2_label'),
        shortLabel: t('text.agentic.capability.short_class_2'),
        summary: t('text.agentic.page.cap2_summary'),
        useWhen: t('text.agentic.page.cap2_use_when'),
        allows: t('text.agentic.page.cap2_allows'),
        approvalLabel: t('text.agentic.page.cap2_approval'),
        scheduleAllowed: false,
    },
    class_3_autonomous_maintenance: {
        value: 'class_3_autonomous_maintenance',
        label: t('text.agentic.capability.class_3_label'),
        shortLabel: t('text.agentic.capability.short_class_3'),
        summary: t('text.agentic.page.cap3_summary'),
        useWhen: t('text.agentic.page.cap3_use_when'),
        allows: t('text.agentic.page.cap3_allows'),
        approvalLabel: t('text.agentic.page.cap3_approval'),
        scheduleAllowed: true,
    },
}))

const capabilityOptions = computed(() => Object.values(capabilityMetaMap.value).map((item) => ({
    label: item.label,
    value: item.value,
})))

const scheduleCadenceOptions = computed(() => [
    { label: t('text.agentic.cadence.hourly_1'), value: 60 },
    { label: t('text.agentic.cadence.hourly_4'), value: 240 },
    { label: t('text.agentic.cadence.daily'), value: 1440 },
])

const agenticSurfaceOptions = computed(() => [
    { label: t('text.agentic.page.surface_operate'), value: 'operate' },
    { label: t('text.agentic.page.surface_configure'), value: 'configure' },
])

const budgetPresetMetaMap = computed(() => ({
    strict: {
        value: 'strict',
        label: t('text.agentic.budget.strict_label'),
        summary: t('text.agentic.budget.strict_summary'),
        maxSteps: 3,
        config: { max_steps: 3 },
    },
    standard: {
        value: 'standard',
        label: t('text.agentic.budget.standard_label'),
        summary: t('text.agentic.budget.standard_summary'),
        maxSteps: 5,
        config: { max_steps: 5 },
    },
    extended: {
        value: 'extended',
        label: t('text.agentic.budget.extended_label'),
        summary: t('text.agentic.page.budget_extended_summary'),
        maxSteps: 8,
        config: { max_steps: 8 },
    },
    deep_review: {
        value: 'deep_review',
        label: t('text.agentic.budget.deep_review_label'),
        summary: t('text.agentic.page.budget_deep_review_summary'),
        maxSteps: 12,
        config: { max_steps: 12 },
    },
    custom_existing: {
        value: 'custom_existing',
        label: t('text.agentic.budget.keep_existing_label'),
        summary: t('text.agentic.budget.keep_existing_summary'),
        maxSteps: null,
        config: null,
    },
}))

const budgetPresetOptions = computed(() => Object.values(budgetPresetMetaMap.value).map((item) => ({
    label: item.label,
    value: item.value,
})))

const enabledOptions = computed(() => [
    { label: t('text.agentic.enabled'), value: 'true' },
    { label: t('text.agentic.disabled'), value: 'false' },
])

const approvalStatusOptions = computed(() => [
    { label: t('text.agentic.inbox.pending'), value: 'pending' },
    { label: t('text.agentic.inbox.approved'), value: 'approved' },
    { label: t('text.agentic.inbox.rejected'), value: 'rejected' },
    { label: t('text.agentic.label.stale'), value: 'stale' },
    { label: t('text.agentic.label.failed'), value: 'failed' },
])

const applyOutcomeOptions = computed(() => [
    { label: t('text.agentic.status.awaiting_apply'), value: 'awaiting_apply' },
    { label: t('text.agentic.label.applied'), value: 'applied' },
    { label: t('text.agentic.status.apply_failed'), value: 'apply_failed' },
    { label: t('text.agentic.status.approval_stale'), value: 'approval_stale' },
    { label: t('text.agentic.inbox.rejected'), value: 'approval_rejected' },
])

const runStatusOptions = computed(() => [
    { label: t('text.agentic.label.running'), value: 'running' },
    { label: t('text.agentic.status.awaiting_approval'), value: 'awaiting_approval' },
    { label: t('text.agentic.status.awaiting_apply'), value: 'awaiting_apply' },
    { label: t('text.agentic.label.applied'), value: 'applied' },
    { label: t('text.agentic.label.cancelled'), value: 'cancelled' },
    { label: t('text.agentic.label.completed'), value: 'completed' },
    { label: t('text.agentic.inbox.rejected'), value: 'rejected' },
    { label: t('text.agentic.label.failed'), value: 'failed' },
])

const scheduleStatusOptions = computed(() => [
    { label: t('text.agentic.schedules.status.active'), value: 'active' },
    { label: t('text.agentic.schedules.status.running'), value: 'running' },
    { label: t('text.agentic.schedules.status.paused'), value: 'paused' },
])

const profileStatusOptions = computed(() => [
    { label: t('text.agentic.label.active'), value: 'active' },
    { label: t('text.agentic.label.inactive'), value: 'inactive' },
])

const definitionColumns = computed(() => [
    { key: 'tool_name', title: t('text.agentic.col.tool'), dataIndex: 'tool_name', width: 320 },
    { key: 'tool_family', title: t('text.agentic.col.family'), dataIndex: 'tool_family', width: 130 },
    { key: 'contract_steward', title: t('text.agentic.col.steward'), dataIndex: 'contract_steward', width: 130 },
    { key: 'governance', title: t('text.agentic.col.governance'), width: 240 },
    { key: 'binding_count', title: t('text.agentic.col.bindings'), dataIndex: 'binding_count', width: 90 },
    { key: 'profile_count', title: t('text.agentic.col.profiles'), dataIndex: 'profile_count', width: 90 },
    { key: 'select', title: t('text.agentic.col.select'), width: 92 },
])

const bindingColumns = computed(() => [
    { key: 'tool_name', title: t('text.agentic.col.tool'), dataIndex: 'tool_name', width: 250 },
    {
        key: 'binding_target',
        title: t('text.agentic.binding_target'),
        dataIndex: 'binding_target',
        width: 520,
    },
    { key: 'scope', title: t('text.agentic.col.scope'), width: 180 },
    { key: 'enabled', title: t('text.agentic.col.enabled'), dataIndex: 'enabled', width: 90 },
    { key: 'actions', title: t('text.agentic.col.actions'), width: 170 },
])

const profileColumns = computed(() => [
    { key: 'name', title: t('text.agentic.col.profile'), dataIndex: 'name' },
    { key: 'capability_class', title: t('text.agentic.col.capability'), dataIndex: 'capability_class', width: 180 },
    { key: 'status', title: t('text.agentic.col.status'), dataIndex: 'status', width: 110 },
    { key: 'tool_definition_count', title: t('text.agentic.col.tools'), dataIndex: 'tool_definition_count', width: 80 },
    { key: 'run_count', title: t('text.agentic.col.runs'), dataIndex: 'run_count', width: 80 },
    { key: 'inspect', title: t('text.agentic.col.actions'), width: 170 },
])

const approvalColumns = computed(() => [
    { key: 'summary', title: t('text.agentic.inbox.proposal') },
    { key: 'status', title: t('text.agentic.col.status'), width: 140 },
    { key: 'diff', title: t('text.agentic.col.diff') },
    { key: 'actions', title: t('text.agentic.col.actions'), width: 220 },
])

const runColumns = computed(() => [
    { key: 'goal', title: t('text.agentic.col.run'), width: 240 },
    { key: 'status', title: t('text.agentic.col.status'), width: 120 },
    { key: 'workflow', title: t('text.agentic.col.workflow'), width: 160 },
    { key: 'latest_step', title: t('text.agentic.latest_step') },
    { key: 'inspect', title: t('text.agentic.col.actions'), width: 210 },
])

const scheduleColumns = computed(() => [
    { key: 'summary', title: t('text.agentic.schedules.column.schedule'), width: 250 },
    { key: 'cadence', title: t('text.agentic.schedules.column.cadence'), width: 140 },
    { key: 'last_run', title: t('text.agentic.last_run') },
    { key: 'actions', title: t('text.agentic.schedules.column.actions'), width: 240 },
])

const {
    data: healthQuery,
    isLoading: isHealthLoading,
    isFetching: isHealthFetching,
    refetch: refetchAgenticHealth,
} = useAgenticHealth({
    refetchOnWindowFocus: false,
})

const {
    data: definitionQuery,
    isLoading: isDefinitionsLoading,
    isFetching: isDefinitionsFetching,
} = useAgenticToolDefinitions(definitionFilters, {
    refetchOnWindowFocus: false,
})

const {
    data: bindingQuery,
    isLoading: isBindingsLoading,
    isFetching: isBindingsFetching,
} = useAgenticToolBindings(bindingFilters, {
    refetchOnWindowFocus: false,
})

const {
    data: profileQuery,
    isLoading: isProfilesLoading,
    isFetching: isProfilesFetching,
} = useAgenticProfiles(profileFilters, {
    refetchOnWindowFocus: false,
})

const {
    data: runQuery,
    isLoading: isRunsLoading,
    isFetching: isRunsFetching,
} = useAgenticRuns(runFilters, {
    refetchOnWindowFocus: false,
})

const {
    data: approvalQuery,
    isLoading: isApprovalsLoading,
    isFetching: isApprovalsFetching,
} = useAgenticApprovals(approvalFilters, {
    refetchOnWindowFocus: false,
})

const {
    data: scheduleQuery,
    isLoading: isSchedulesLoading,
    isFetching: isSchedulesFetching,
} = useAgenticSchedules(scheduleFilters, {
    refetchOnWindowFocus: false,
})

const health = computed(() => healthQuery.value || null)
const definitions = computed(() => definitionQuery.value?.data || [])
const definitionMeta = computed(() => definitionQuery.value?.meta || {})
const bindings = computed(() => bindingQuery.value?.data || [])
const bindingMeta = computed(() => bindingQuery.value?.meta || {})
const profiles = computed(() => profileQuery.value?.data || [])
const profileMeta = computed(() => profileQuery.value?.meta || {})
const runs = computed(() => runQuery.value?.data || [])
const runMeta = computed(() => runQuery.value?.meta || {})
const approvals = computed(() => approvalQuery.value?.data || [])
const approvalMeta = computed(() => approvalQuery.value?.meta || {})
const schedules = computed(() => scheduleQuery.value?.data || [])
const scheduleMeta = computed(() => scheduleQuery.value?.meta || {})

const definitionFamilyOptions = computed(() => {
    const set = new Set(definitions.value.map((item) => item.tool_family).filter(Boolean))

    return Array.from(set).map((value) => ({
        label: value,
        value,
    }))
})

const bindingModuleOptions = computed(() => {
    const set = new Set(bindings.value.map((item) => item.module_scope).filter(Boolean))

    return Array.from(set).map((value) => ({
        label: value,
        value,
    }))
})

const starterProfilePriority = [
    'ops-maintainer',
    'content-editor',
    'content-drafter',
    'content-reader',
]

// Tools a module adds to the starter order are placed after their anchor.
const starterToolPriority = withAgenticStarterTools([
    'core.agentic_health_overview',
    'product.inventory_alerts_summary',
    'content.propose_post_seo_refresh',
    'content.search_posts',
    'content.propose_post_description_refresh',
    'product.inspect_catalog_normalization',
])

const priorityIndex = (priorityList, value) => {
    const index = priorityList.indexOf(value)
    return index === -1 ? Number.MAX_SAFE_INTEGER : index
}

const orderedProfiles = computed(() => [...profiles.value].sort((left, right) => {
    const priorityCompare = priorityIndex(starterProfilePriority, left.slug || String(left.id))
        - priorityIndex(starterProfilePriority, right.slug || String(right.id))

    if (priorityCompare !== 0) {
        return priorityCompare
    }

    return String(left.name || left.slug || left.id).localeCompare(String(right.name || right.slug || right.id))
}))

const orderedDefinitions = computed(() => [...definitions.value].sort((left, right) => {
    const priorityCompare = priorityIndex(starterToolPriority, left.tool_name)
        - priorityIndex(starterToolPriority, right.tool_name)

    if (priorityCompare !== 0) {
        return priorityCompare
    }

    return String(left.tool_name || '').localeCompare(String(right.tool_name || ''))
}))

const profileSelectOptions = computed(() => orderedProfiles.value.map((item) => ({
    label: `${item.name} (${item.slug})`,
    value: item.slug || item.id,
})))
const definitionSelectOptions = computed(() => orderedDefinitions.value.map((item) => ({
    label: `${item.tool_name} · ${item.tool_family || t('text.agentic.page.family_general')}`,
    value: item.tool_name,
})))

const selectedProfileKey = ref(null)
const selectedDefinitionKey = ref(null)
const selectedRunId = ref(null)
const selectedApprovalId = ref(null)
const agenticSurface = ref('operate')
const operatorSurfaceTab = ref('approvals')
const advancedPanelKeys = ref([])
const profileModalOpen = ref(false)
const profileModalMode = ref('create')
const editingProfileKey = ref(null)
const activeApprovalActionId = ref(null)
const approvalActionMode = ref(null)
const activeGrantToolId = ref(null)
const grantActionMode = ref(null)
const activeBindingId = ref(null)
const bindingActionMode = ref(null)
const activeRunId = ref(null)
const runActionMode = ref(null)
const activeScheduleId = ref(null)
const scheduleActionMode = ref(null)

watch(
    orderedProfiles,
    (items) => {
        if (!items.length) {
            selectedProfileKey.value = null
            return
        }

        const exists = items.some((item) => (item.slug || item.id) === selectedProfileKey.value)
        if (!exists) {
            selectedProfileKey.value = items[0].slug || items[0].id
        }
    },
    { immediate: true }
)

watch(
    orderedDefinitions,
    (items) => {
        if (!items.length) {
            selectedDefinitionKey.value = null
            return
        }

        const exists = items.some((item) => item.tool_name === selectedDefinitionKey.value)
        if (!exists) {
            selectedDefinitionKey.value = items[0].tool_name
        }
    },
    { immediate: true }
)

const {
    data: grantsQuery,
} = useAgenticProfileGrants(selectedProfileKey, {
    refetchOnWindowFocus: false,
})

const {
    data: runDetailQuery,
    isFetching: isRunDetailFetching,
} = useAgenticRun(selectedRunId, {
    refetchOnWindowFocus: false,
})

const {
    data: approvalDetailQuery,
    isFetching: isApprovalDetailFetching,
} = useAgenticApproval(selectedApprovalId, {
    refetchOnWindowFocus: false,
})

const selectedProfile = computed(() => grantsQuery.value?.profile || null)
const grants = computed(() => grantsQuery.value?.grants || [])
const selectedRunDetail = computed(() => runDetailQuery.value || null)
const selectedApprovalDetail = computed(() => approvalDetailQuery.value || null)
const selectedDefinition = computed(() =>
    orderedDefinitions.value.find((item) => item.tool_name === selectedDefinitionKey.value) || null
)
const grantedToolNames = computed(() => new Set(grants.value.map((grant) => grant.tool_name)))
const selectedDefinitionIsGranted = computed(() =>
    selectedDefinition.value ? grantedToolNames.value.has(selectedDefinition.value.tool_name) : false
)
const selectedCapabilityMeta = computed(() => capabilityMetaMap.value[profileDraft.capabilityClass] || null)
const selectedScheduleCapabilityMeta = computed(() =>
    capabilityMetaMap.value[selectedProfile.value?.capability_class] || null
)
const selectedBudgetPresetMeta = computed(() => budgetPresetMetaMap.value[profileDraft.budgetPreset] || null)
const availableBudgetPresetOptions = computed(() =>
    profileModalMode.value === 'edit'
        ? budgetPresetOptions.value
        : budgetPresetOptions.value.filter((item) => item.value !== 'custom_existing')
)
const healthIssues = computed(() => Array.isArray(health.value?.issues) ? health.value.issues : [])
const healthStatusClass = computed(() => {
    const status = health.value?.status

    if (status === 'ok') {
        return 'agentic-pill--ok'
    }

    if (status === 'warn') {
        return 'agentic-pill--warn'
    }

    if (status === 'error') {
        return 'agentic-pill--danger'
    }

    return 'agentic-pill--muted'
})
const healthStatusLabel = computed(() => {
    const status = health.value?.status

    if (status === 'ok') {
        return t('text.agentic.page.health_ready')
    }

    if (status === 'warn') {
        return t('text.agentic.page.health_needs_review')
    }

    if (status === 'error') {
        return t('text.agentic.page.health_blocked')
    }

    return t('text.agentic.health.unknown')
})
const healthProviderLabel = computed(() => {
    const driver = health.value?.provider?.ai_driver

    if (!driver || driver === 'null') {
        return t('text.agentic.page.no_ai_provider')
    }

    return String(driver).toUpperCase()
})
const healthEmbeddingLabel = computed(() => {
    const driver = health.value?.provider?.embedding_driver

    if (!driver || driver === 'null') {
        return t('text.agentic.page.no_embedding_provider')
    }

    return String(driver).toUpperCase()
})
const healthApiKeyLabel = computed(() =>
    health.value?.provider?.api_key_configured ? t('text.agentic.page.key_configured') : t('text.ai_runtime.providers.key_missing')
)
const healthSchedulerLabel = computed(() =>
    health.value?.runtime?.scheduler_enabled ? t('text.agentic.enabled') : t('text.agentic.disabled')
)
const healthQueueLabel = computed(() => {
    const driver = health.value?.runtime?.queue_driver

    if (!driver) {
        return t('text.agentic.health.unknown')
    }

    return String(driver)
})
const healthQueueCopy = computed(() =>
    health.value?.runtime?.queue_driver === 'sync'
        ? t('text.agentic.page.queue_sync_hint')
        : t('text.agentic.page.queue_async_hint')
)
const healthPreflightLabel = computed(() => {
    if (health.value?.preflight?.sync_ready && health.value?.preflight?.grant_ready) {
        return t('text.agentic.page.preflight_ready')
    }

    if (health.value?.preflight?.sync_ready || health.value?.preflight?.grant_ready) {
        return t('text.agentic.page.preflight_partial')
    }

    return t('text.agentic.page.preflight_not_ready')
})
const selectedProfileCanSchedule = computed(() =>
    Boolean(selectedScheduleCapabilityMeta.value?.scheduleAllowed)
)
const hasSyncedDefinitions = computed(() =>
    Number(definitionMeta.value?.total || definitions.value.length || 0) > 0
)
const hasProfiles = computed(() =>
    Number(profileMeta.value?.total || profiles.value.length || 0) > 0
)
const hasGrantedSelection = computed(() =>
    Boolean(selectedProfile.value?.id) &&
    Boolean(selectedDefinition.value?.id) &&
    selectedDefinitionIsGranted.value
)
const defaultScheduleName = computed(() => {
    if (!selectedProfile.value?.slug || !selectedDefinition.value?.tool_name) {
        return t('text.agentic.page.default_schedule_name')
    }

    return `${selectedProfile.value.slug} · ${selectedDefinition.value.tool_name}`
})
const scheduleEligibilityMessage = computed(() => {
    if (!selectedProfile.value?.id) {
        return t('text.agentic.page.elig_select_profile', { seed: t('text.agentic.seed_templates'), create: t('text.agentic.admin.create_profile') })
    }

    if (!selectedDefinition.value?.id) {
        return t('text.agentic.page.elig_select_tool')
    }

    if (!selectedDefinitionIsGranted.value) {
        return t('text.agentic.page.elig_grant_first')
    }

    if (!selectedProfileCanSchedule.value) {
        return t('text.agentic.page.elig_not_schedulable', { name: selectedProfile.value.name || selectedProfile.value.slug, group: selectedScheduleCapabilityMeta.value?.shortLabel || t('text.agentic.page.group_unsuitable'), type: t('text.agentic.capability.short_class_3') })
    }

    return t('text.agentic.page.elig_ready')
})
const scheduleEligibilityTone = computed(() => (selectedProfileCanSchedule.value ? 'ok' : 'warn'))
const canCreateSchedule = computed(() =>
    Boolean(selectedProfile.value?.id) &&
    Boolean(selectedDefinition.value?.id) &&
    selectedDefinitionIsGranted.value &&
    selectedProfileCanSchedule.value &&
    !isCreateSchedulePending.value
)
const setupChecklist = computed(() => ([
    {
        key: 'sync',
        title: t('text.agentic.checklist.sync_tools'),
        done: hasSyncedDefinitions.value,
        copy: hasSyncedDefinitions.value
            ? t('text.agentic.page.check_sync_done')
            : t('text.agentic.page.check_sync_todo', { sync: t('text.agentic.sync_tools') }),
    },
    {
        key: 'profiles',
        title: t('text.agentic.checklist.create_profile'),
        done: hasProfiles.value,
        copy: hasProfiles.value
            ? t('text.agentic.page.check_profile_done')
            : t('text.agentic.page.check_profile_todo', { seed: t('text.agentic.seed_templates'), create: t('text.agentic.admin.create_profile') }),
    },
    {
        key: 'grant',
        title: t('text.agentic.checklist.grant_tool'),
        done: hasGrantedSelection.value,
        copy: hasGrantedSelection.value
            ? t('text.agentic.page.check_grant_done')
            : t('text.agentic.page.check_grant_todo', { grant: t('text.agentic.page.grant_selected_tool') }),
    },
    {
        key: 'operate',
        title: t('text.agentic.checklist.operate'),
        done: Boolean((runMeta.value?.total || 0) > 0 || (scheduleMeta.value?.total || 0) > 0 || (approvalMeta.value?.total || 0) > 0),
        copy: t('text.agentic.page.check_operate'),
    },
]))
const setupChecklistCompletedCount = computed(() =>
    setupChecklist.value.filter((step) => step.done).length
)
const nextSetupAction = computed(() => {
    if (!hasSyncedDefinitions.value) {
        return {
            title: t('text.agentic.next_action.sync_tools'),
            copy: t('text.agentic.page.next_sync_copy'),
        }
    }

    if (!hasProfiles.value) {
        return {
            title: t('text.agentic.next_action.seed_setup'),
            copy: t('text.agentic.page.next_seed_copy', { seed: t('text.agentic.seed_templates') }),
        }
    }

    if (!selectedProfile.value?.id) {
        return {
            title: t('text.agentic.next_action.select_profile'),
            copy: t('text.agentic.page.next_select_profile_copy'),
        }
    }

    if (!selectedDefinition.value?.id) {
        return {
            title: t('text.agentic.next_action.select_tool'),
            copy: t('text.agentic.page.next_select_tool_copy'),
        }
    }

    if (!selectedDefinitionIsGranted.value) {
        return {
            title: t('text.agentic.next_action.grant_tool'),
            copy: t('text.agentic.page.next_grant_copy', { grant: t('text.agentic.page.grant_selected_tool') }),
        }
    }

    if (selectedProfileCanSchedule.value) {
        return {
            title: t('text.agentic.next_action.create_schedule_or_run'),
            copy: t('text.agentic.page.next_schedule_copy'),
        }
    }

    return {
        title: t('text.agentic.next_action.setup_complete'),
        copy: t('text.agentic.page.next_complete_copy'),
    }
})
const currentSelectionStatus = computed(() => {
    if (!selectedProfile.value?.id) {
        return {
            title: t('text.agentic.selection.no_profile'),
            copy: t('text.agentic.page.sel_no_profile_copy'),
        }
    }

    if (!selectedDefinition.value?.id) {
        return {
            title: t('text.agentic.selection.no_tool'),
            copy: t('text.agentic.page.sel_no_tool_copy'),
        }
    }

    if (!selectedDefinitionIsGranted.value) {
        return {
            title: t('text.agentic.selection.ready_to_grant'),
            copy: t('text.agentic.page.sel_ready_grant_copy', { grant: t('text.agentic.page.grant_selected_tool') }),
        }
    }

    if (selectedProfileCanSchedule.value) {
        return {
            title: t('text.agentic.selection.ready_to_operate'),
            copy: t('text.agentic.page.sel_ready_operate_copy'),
        }
    }

    return {
        title: t('text.agentic.selection.ready_for_manual_run'),
        copy: t('text.agentic.page.sel_manual_copy'),
    }
})

const activeSurfaceMeta = computed(() => {
    if (agenticSurface.value === 'configure') {
        return {
            title: t('text.agentic.surface.configure_title'),
            copy: t('text.agentic.page.surface_configure_copy'),
        }
    }

    return {
        title: t('text.agentic.surface.operate_title'),
        copy: t('text.agentic.page.surface_operate_copy'),
    }
})

const starterRecipes = computed(() => ([
    {
        key: 'ops-health',
        title: t('text.agentic.recipe.ops_health_overview'),
        copy: t('text.agentic.page.recipe_ops_copy'),
        profileSlug: 'ops-maintainer',
        toolName: 'core.agentic_health_overview',
        targetTab: 'schedules',
    },
    {
        key: 'inventory-alerts',
        title: t('text.agentic.recipe.inventory_alerts_digest'),
        copy: t('text.agentic.page.recipe_inventory_copy'),
        profileSlug: 'ops-maintainer',
        toolName: 'product.inventory_alerts_summary',
        targetTab: 'schedules',
    },
    {
        key: 'seo-refresh',
        title: t('text.agentic.recipe.seo_refresh_proposals'),
        copy: t('text.agentic.page.recipe_seo_copy'),
        profileSlug: 'content-editor',
        toolName: 'content.propose_post_seo_refresh',
        targetTab: 'approvals',
    },
    {
        key: 'content-research',
        title: t('text.agentic.recipe.content_research'),
        copy: t('text.agentic.page.recipe_research_copy'),
        profileSlug: 'content-reader',
        toolName: 'content.search_posts',
        targetTab: 'runs',
    },
]).map((recipe) => {
    const profile = orderedProfiles.value.find((item) => (item.slug || item.id) === recipe.profileSlug) || null
    const definition = orderedDefinitions.value.find((item) => item.tool_name === recipe.toolName) || null

    return {
        ...recipe,
        profile,
        definition,
        ready: Boolean(profile && definition),
        profileLabel: profile?.name || recipe.profileSlug,
        toolLabel: definition?.tool_name || recipe.toolName,
    }
}))

const syncToolsMutation = useAgenticSyncTools()
const updateBindingMutation = useAgenticUpdateToolBinding()
const createProfileMutation = useAgenticCreateProfile()
const seedTemplatesMutation = useAgenticSeedProfileTemplates()
const updateProfileMutation = useAgenticUpdateProfile()
const grantProfileToolsMutation = useAgenticGrantProfileTools()
const updateGrantMutation = useAgenticUpdateProfileToolGrant()
const revokeGrantMutation = useAgenticRevokeProfileToolGrant()
const approveApprovalMutation = useApproveAgenticApproval()
const rejectApprovalMutation = useRejectAgenticApproval()
const applyApprovalMutation = useApplyAgenticApproval()
const cancelRunMutation = useCancelAgenticRun()
const stopRunMutation = useStopAgenticRun()
const rerunRunMutation = useRerunAgenticRun()
const createScheduleMutation = useCreateAgenticSchedule()
const updateScheduleMutation = useUpdateAgenticSchedule()
const dispatchScheduleMutation = useDispatchAgenticSchedule()
const runOnceScheduleMutation = useRunOnceAgenticSchedule()
const resumeDispatchScheduleMutation = useResumeDispatchAgenticSchedule()

const isSyncPending = computed(() => syncToolsMutation.isPending.value)
const isUpdateBindingPending = computed(() => updateBindingMutation.isPending.value)
const isCreateProfilePending = computed(() => createProfileMutation.isPending.value)
const isSeedTemplatesPending = computed(() => seedTemplatesMutation.isPending.value)
const isUpdateProfilePending = computed(() => updateProfileMutation.isPending.value)
const isGrantPending = computed(() => grantProfileToolsMutation.isPending.value)
const isUpdateGrantPending = computed(() => updateGrantMutation.isPending.value)
const isRevokeGrantPending = computed(() => revokeGrantMutation.isPending.value)
const isApprovePending = computed(() => approveApprovalMutation.isPending.value)
const isRejectPending = computed(() => rejectApprovalMutation.isPending.value)
const isApplyPending = computed(() => applyApprovalMutation.isPending.value)
const isCancelRunPending = computed(() => cancelRunMutation.isPending.value)
const isStopRunPending = computed(() => stopRunMutation.isPending.value)
const isRerunRunPending = computed(() => rerunRunMutation.isPending.value)
const isCreateSchedulePending = computed(() => createScheduleMutation.isPending.value)
const isUpdateSchedulePending = computed(() => updateScheduleMutation.isPending.value)
const isDispatchSchedulePending = computed(() =>
    dispatchScheduleMutation.isPending.value
    || runOnceScheduleMutation.isPending.value
    || resumeDispatchScheduleMutation.isPending.value
)
const isSaveProfilePending = computed(() => isCreateProfilePending.value || isUpdateProfilePending.value)

const canGrantSelectedDefinition = computed(() =>
    Boolean(selectedProfileKey.value) &&
    Boolean(selectedDefinition.value?.tool_name) &&
    !selectedDefinitionIsGranted.value &&
    !isGrantPending.value
)

const statCards = computed(() => [
    {
        label: t('text.agentic.page.stat_definitions'),
        value: definitionMeta.value.total || definitions.value.length,
        hint: t('text.agentic.page.stat_definitions_hint'),
    },
    {
        label: t('text.agentic.col.bindings'),
        value: bindingMeta.value.total || bindings.value.length,
        hint: t('text.agentic.page.stat_bindings_hint'),
    },
    {
        label: t('text.agentic.col.profiles'),
        value: profileMeta.value.total || profiles.value.length,
        hint: t('text.agentic.page.stat_profiles_hint'),
    },
    {
        label: t('text.agentic.col.runs'),
        value: runMeta.value.total || runs.value.length,
        hint: runFilters.status || t('text.agentic.page.hint_all'),
    },
    {
        label: t('text.agentic.page.stat_inbox'),
        value: approvalMeta.value.total || approvals.value.length,
        hint: approvalFilters.status || t('text.agentic.page.hint_all'),
    },
    {
        label: t('text.agentic.schedules.tab'),
        value: scheduleMeta.value.total || schedules.value.length,
        hint: scheduleFilters.status || t('text.agentic.page.hint_all'),
    },
])

const surfaceHighlightCards = computed(() => {
    if (agenticSurface.value === 'configure') {
        return [
            {
                label: t('text.agentic.page.stat_definitions'),
                value: definitionMeta.value.total || definitions.value.length,
                hint: t('text.agentic.page.stat_definitions_hint'),
            },
            {
                label: t('text.agentic.col.profiles'),
                value: profileMeta.value.total || profiles.value.length,
                hint: t('text.agentic.page.stat_profiles_hint'),
            },
            {
                label: t('text.agentic.card.granted_tools'),
                value: grants.value.length,
                hint: selectedProfile.value?.slug || t('text.agentic.page.hint_selected_profile'),
            },
            {
                label: t('text.agentic.page.stat_health'),
                value: healthStatusLabel.value,
                hint: health.value?.status || t('text.agentic.page.hint_unknown'),
            },
        ]
    }

    return [
        {
            label: t('text.agentic.card.pending_approvals'),
            value: approvalMeta.value.total || approvals.value.length,
            hint: approvalFilters.status || t('text.agentic.page.hint_inbox'),
        },
        {
            label: t('text.agentic.card.recent_runs'),
            value: runMeta.value.total || runs.value.length,
            hint: runFilters.status || t('text.agentic.page.hint_all'),
        },
        {
            label: t('text.agentic.schedules.tab'),
            value: scheduleMeta.value.total || schedules.value.length,
            hint: scheduleFilters.status || t('text.agentic.page.hint_active'),
        },
        {
            label: t('text.agentic.page.stat_health'),
            value: healthStatusLabel.value,
            hint: health.value?.status || t('text.agentic.page.hint_unknown'),
        },
    ]
})

const formatMeta = (meta = {}) => {
    const total = meta.total || 0
    const currentPage = meta.page || 1
    const lastPage = meta.last_page || 1

    return t('text.agentic.page.pagination_meta', { total, current: currentPage, last: lastPage })
}

const cloneConfig = (value) => {
    if (!value || typeof value !== 'object') {
        return null
    }

    return JSON.parse(JSON.stringify(value))
}

const inferBudgetPreset = (config) => {
    if (!config || Object.keys(config).length === 0) {
        return 'standard'
    }

    const presetEntry = Object.values(budgetPresetMetaMap.value).find((item) => {
        if (!item.config) {
            return false
        }

        return JSON.stringify(item.config) === JSON.stringify(config)
    })

    return presetEntry?.value || 'custom_existing'
}

const resolveBudgetConfig = () => {
    const preset = budgetPresetMetaMap.value[profileDraft.budgetPreset]
    if (!preset) {
        return null
    }

    if (profileDraft.budgetPreset === 'custom_existing') {
        return cloneConfig(profileDraft.existingBudgetConfig)
    }

    return cloneConfig(preset.config)
}

const capabilityLabel = (value) => capabilityMetaMap.value[value]?.label || value || t('text.agentic.health.unknown')
const capabilitySummary = (value) => capabilityMetaMap.value[value]?.summary || t('text.workflow.list.no_description')

const selectProfile = (profile) => {
    selectedProfileKey.value = profile.slug || profile.id
}

const resetProfileDraft = () => {
    profileDraft.name = ''
    profileDraft.slug = ''
    profileDraft.description = ''
    profileDraft.capabilityClass = 'class_0_read_only'
    profileDraft.status = 'active'
    profileDraft.systemPromptTemplate = ''
    profileDraft.budgetPreset = 'standard'
    profileDraft.existingBudgetConfig = null
}

const hydrateProfileDraft = (profile = null) => {
    profileDraft.name = profile?.name || ''
    profileDraft.slug = profile?.slug || ''
    profileDraft.description = profile?.description || ''
    profileDraft.capabilityClass = profile?.capability_class || 'class_0_read_only'
    profileDraft.status = profile?.status || 'active'
    profileDraft.systemPromptTemplate = profile?.system_prompt_template || ''
    profileDraft.existingBudgetConfig = cloneConfig(profile?.budget_config)
    profileDraft.budgetPreset = inferBudgetPreset(profile?.budget_config)
}

const openCreateProfileModal = () => {
    profileModalMode.value = 'create'
    editingProfileKey.value = null
    resetProfileDraft()
    profileModalOpen.value = true
}

const openEditProfileModal = (profile) => {
    profileModalMode.value = 'edit'
    editingProfileKey.value = profile?.slug || profile?.id || null
    hydrateProfileDraft(profile)
    profileModalOpen.value = true
}

const selectDefinition = (definition) => {
    selectedDefinitionKey.value = definition.tool_name
}

const applyStarterRecipe = (recipe) => {
    if (!recipe?.ready) {
        return
    }

    agenticSurface.value = 'operate'
    selectedProfileKey.value = recipe.profile?.slug || recipe.profile?.id || selectedProfileKey.value
    selectedDefinitionKey.value = recipe.definition?.tool_name || selectedDefinitionKey.value

    if (recipe.targetTab) {
        operatorSurfaceTab.value = recipe.targetTab
    }
}

const openRunDetail = (run) => {
    selectedRunId.value = run?.id || null
}

const openApprovalDetail = (approval) => {
    selectedApprovalId.value = approval?.id || null
}

const runDetailOpen = computed({
    get: () => Boolean(selectedRunId.value),
    set: (value) => {
        if (!value) {
            selectedRunId.value = null
        }
    },
})

const approvalDetailOpen = computed({
    get: () => Boolean(selectedApprovalId.value),
    set: (value) => {
        if (!value) {
            selectedApprovalId.value = null
        }
    },
})

const resolveErrorMessage = (error, fallbackMessage) => {
    return error?.response?.data?.message || error?.message || fallbackMessage
}

const formatJson = (value) => {
    try {
        return JSON.stringify(value, null, 2)
    } catch (error) {
        return '{}'
    }
}

const formatScorecardValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return t('text.agentic.na')
    }

    const parsed = Number(value)

    if (Number.isNaN(parsed)) {
        return String(value)
    }

    return parsed.toFixed(2)
}

const runtimeStatusClass = (status) => {
    if (['approved', 'applied', 'completed', 'running'].includes(status)) return 'agentic-pill--ok'
    if (['pending', 'awaiting_approval', 'awaiting_apply', 'cancelling', 'stop_requested', 'stop_acknowledged'].includes(status)) return 'agentic-pill--warn'
    if (['rejected', 'failed', 'stale', 'apply_failed', 'approval_stale', 'approval_rejected', 'cancelled', 'paused'].includes(status)) {
        return 'agentic-pill--danger'
    }

    return 'agentic-pill--muted'
}

const scheduleStatusClass = (status) => {
    if (['active'].includes(status)) return 'agentic-pill--ok'
    if (['running'].includes(status)) return 'agentic-pill--warn'
    if (['paused', 'failed', 'cancelled'].includes(status)) return 'agentic-pill--danger'

    return 'agentic-pill--muted'
}

const shouldShowApply = (approval) => {
    return approval?.status === 'approved'
        && [null, 'awaiting_apply', 'apply_failed', 'approval_stale'].includes(approval?.apply_outcome ?? null)
}

const withApprovalAction = async (mode, approvalId, callback) => {
    activeApprovalActionId.value = approvalId
    approvalActionMode.value = mode

    try {
        await callback()
    } finally {
        activeApprovalActionId.value = null
        approvalActionMode.value = null
    }
}

const withGrantAction = async (mode, toolDefinitionId, callback) => {
    activeGrantToolId.value = toolDefinitionId
    grantActionMode.value = mode

    try {
        await callback()
    } finally {
        activeGrantToolId.value = null
        grantActionMode.value = null
    }
}

const withBindingAction = async (mode, bindingId, callback) => {
    activeBindingId.value = bindingId
    bindingActionMode.value = mode

    try {
        await callback()
    } finally {
        activeBindingId.value = null
        bindingActionMode.value = null
    }
}

const withRunAction = async (mode, runId, callback) => {
    activeRunId.value = runId
    runActionMode.value = mode

    try {
        await callback()
    } finally {
        activeRunId.value = null
        runActionMode.value = null
    }
}

const withScheduleAction = async (mode, scheduleId, callback) => {
    activeScheduleId.value = scheduleId
    scheduleActionMode.value = mode

    try {
        await callback()
    } finally {
        activeScheduleId.value = null
        scheduleActionMode.value = null
    }
}

const handleSyncTools = async () => {
    if (!canProvisionAgentic.value) {
        return
    }

    try {
        const response = await syncToolsMutation.mutateAsync({})
        message.success(response?.message || t('text.agentic.msg.sync_completed'))
    } catch (error) {
        message.error(resolveErrorMessage(error, t('text.agentic.msg.sync_failed')))
    }
}

const handleSeedSampleProfiles = async () => {
    if (!canProvisionAgentic.value || isSeedTemplatesPending.value) {
        return
    }

    try {
        const response = await seedTemplatesMutation.mutateAsync()
        const starterSelection = response?.data?.starter_selection || null
        if (starterSelection?.profile_slug) {
            selectedProfileKey.value = starterSelection.profile_slug
        } else {
            const firstProfile = response?.data?.profiles?.[0] || null
            if (firstProfile) {
                selectedProfileKey.value = firstProfile.slug || firstProfile.id || selectedProfileKey.value
            }
        }
        if (starterSelection?.tool_name) {
            selectedDefinitionKey.value = starterSelection.tool_name
        }
        if (Number(response?.data?.schedules_created || 0) > 0 || Number(response?.data?.schedules_existing || 0) > 0) {
            operatorSurfaceTab.value = 'schedules'
        }
        message.success(response?.message || t('text.agentic.msg.seed_completed'))
    } catch (error) {
        message.error(resolveErrorMessage(error, t('text.agentic.msg.seed_failed')))
    }
}

const handleGrantSelectedDefinition = async () => {
    if (!canProvisionAgentic.value || !selectedProfileKey.value || !selectedDefinition.value?.tool_name) {
        return
    }

    try {
        const response = await grantProfileToolsMutation.mutateAsync({
            profileKey: selectedProfileKey.value,
            toolNames: [selectedDefinition.value.tool_name],
        })
        message.success(response?.message || t('text.agentic.msg.grant_completed'))
    } catch (error) {
        message.error(resolveErrorMessage(error, t('text.agentic.page.msg_grant_selected_failed')))
    }
}

const handleSaveProfile = async () => {
    if (!canProvisionAgentic.value || isSaveProfilePending.value) {
        return
    }

    const name = profileDraft.name.trim()
    const slug = profileDraft.slug.trim()

    if (!name) {
        message.error(t('text.validation.profile_name_required'))
        return
    }

    if (!slug) {
        message.error(t('text.agentic.page.msg_profile_slug_required'))
        return
    }

    const payload = {
        name,
        slug,
        description: profileDraft.description?.trim() || null,
        capability_class: profileDraft.capabilityClass,
        status: profileDraft.status || 'active',
        system_prompt_template: profileDraft.systemPromptTemplate?.trim() || null,
        budget_config: resolveBudgetConfig(),
    }

    try {
        const response = profileModalMode.value === 'create'
            ? await createProfileMutation.mutateAsync(payload)
            : await updateProfileMutation.mutateAsync({
                profileKey: editingProfileKey.value,
                payload,
            })

        const savedProfile = response?.data?.profile || response?.profile || null
        selectedProfileKey.value = savedProfile?.slug || savedProfile?.id || selectedProfileKey.value
        profileModalOpen.value = false
        message.success(response?.message || t('text.agentic.page.msg_profile_saved'))
    } catch (error) {
        message.error(resolveErrorMessage(error, t('text.agentic.page.msg_profile_save_failed')))
    }
}

const refetchHealth = () => refetchAgenticHealth()

const handleToggleBinding = async (binding) => {
    if (!canProvisionAgentic.value || !binding?.id) {
        return
    }

    await withBindingAction('toggle', binding.id, async () => {
        try {
            const response = await updateBindingMutation.mutateAsync({
                bindingId: binding.id,
                payload: {
                    enabled: !binding.enabled,
                },
            })
            message.success(response?.message || t('text.agentic.msg.binding_updated'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_binding_toggle_failed')))
        }
    })
}

const handleEditBindingPriority = async (binding) => {
    if (!canProvisionAgentic.value || !binding?.id) {
        return
    }

    const nextValue = window.prompt(t('text.agentic.page.prompt_binding_priority'), String(binding.priority ?? 0))
    if (nextValue === null) {
        return
    }

    const parsedPriority = Number.parseInt(nextValue, 10)
    if (Number.isNaN(parsedPriority) || parsedPriority < 0) {
        message.error(t('text.agentic.page.msg_priority_invalid'))
        return
    }

    await withBindingAction('priority', binding.id, async () => {
        try {
            const response = await updateBindingMutation.mutateAsync({
                bindingId: binding.id,
                payload: {
                    priority: parsedPriority,
                },
            })
            message.success(response?.message || t('text.agentic.msg.binding_priority_updated'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.binding_priority_failed')))
        }
    })
}

const handleToggleGrant = async (grant) => {
    if (!canProvisionAgentic.value || !selectedProfileKey.value || !grant?.id) {
        return
    }

    await withGrantAction('toggle', grant.id, async () => {
        try {
            const response = await updateGrantMutation.mutateAsync({
                profileKey: selectedProfileKey.value,
                toolDefinitionKey: grant.id,
                payload: {
                    enabled: !grant.grant?.enabled,
                },
            })
            message.success(response?.message || t('text.agentic.msg.grant_updated'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_grant_toggle_failed')))
        }
    })
}

const handleEditGrantSafetyOverride = async (grant) => {
    if (!canProvisionAgentic.value || !selectedProfileKey.value || !grant?.id) {
        return
    }

    const nextValue = window.prompt(
        t('text.agentic.page.prompt_safety_override'),
        grant.grant?.safety_override || ''
    )

    if (nextValue === null) {
        return
    }

    await withGrantAction('safety', grant.id, async () => {
        try {
            const response = await updateGrantMutation.mutateAsync({
                profileKey: selectedProfileKey.value,
                toolDefinitionKey: grant.id,
                payload: {
                    safety_override: nextValue.trim() || null,
                },
            })
            message.success(response?.message || t('text.agentic.page.msg_safety_override_updated'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_safety_override_failed')))
        }
    })
}

const handleRevokeGrant = async (grant) => {
    if (!canProvisionAgentic.value || !selectedProfileKey.value || !grant?.id) {
        return
    }

    if (!window.confirm(t('text.agentic.page.confirm_revoke', { tool: grant.tool_name, profile: selectedProfile.value?.slug || t('text.agentic.page.this_profile') }))) {
        return
    }

    await withGrantAction('revoke', grant.id, async () => {
        try {
            const response = await revokeGrantMutation.mutateAsync({
                profileKey: selectedProfileKey.value,
                toolDefinitionKey: grant.id,
            })
            message.success(response?.message || t('text.agentic.page.msg_grant_revoked'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.revoke_failed')))
        }
    })
}

const handleApproveApproval = async (approval) => {
    if (!canApproveAgentic.value || approval?.status !== 'pending') {
        return
    }

    const reviewerNote = window.prompt(t('text.agentic.inbox.note_placeholder'), approval?.reviewer_note || '')
    if (reviewerNote === null) {
        return
    }

    await withApprovalAction('approve', approval.id, async () => {
        try {
            const response = await approveApprovalMutation.mutateAsync({
                approvalId: approval.id,
                reviewerNote: reviewerNote || null,
            })
            const workflowStatus = response?.data?.workflow?.status
            message.success(response?.message || (workflowStatus ? t('text.agentic.page.msg_approval_recorded_wf', { status: workflowStatus }) : t('text.agentic.page.msg_approval_recorded')))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.proposal_approve_failed')))
        }
    })
}

const handleRejectApproval = async (approval) => {
    if (!canApproveAgentic.value || approval?.status !== 'pending') {
        return
    }

    const reviewerNote = window.prompt(t('text.agentic.page.prompt_reject_reason'), approval?.reviewer_note || '')
    if (reviewerNote === null) {
        return
    }

    await withApprovalAction('reject', approval.id, async () => {
        try {
            const response = await rejectApprovalMutation.mutateAsync({
                approvalId: approval.id,
                reviewerNote: reviewerNote || null,
            })
            const workflowStatus = response?.data?.workflow?.status
            message.success(response?.message || (workflowStatus ? t('text.agentic.page.msg_rejected_wf', { status: workflowStatus }) : t('text.agentic.msg.proposal_rejected')))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.proposal_reject_failed')))
        }
    })
}

const handleApplyApproval = async (approval) => {
    if (!canApproveAgentic.value || !shouldShowApply(approval)) {
        return
    }

    if (!window.confirm(t('text.agentic.page.confirm_apply'))) {
        return
    }

    await withApprovalAction('apply', approval.id, async () => {
        try {
            const response = await applyApprovalMutation.mutateAsync({
                approvalId: approval.id,
            })
            const workflowStatus = response?.data?.workflow?.status
            message.success(response?.message || (workflowStatus ? t('text.agentic.page.msg_apply_finished_wf', { status: workflowStatus }) : t('text.agentic.page.msg_apply_finished')))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_apply_failed')))
        }
    })
}

const handleCancelRun = async (run) => {
    if (!canOperateAgentic.value || !run?.id || !run?.actions?.can_cancel) {
        return
    }

    const note = window.prompt(t('text.agentic.page.prompt_cancel_note'), '')
    if (note === null) {
        return
    }

    await withRunAction('cancel', run.id, async () => {
        try {
            const response = await cancelRunMutation.mutateAsync({
                runId: run.id,
                note: note || null,
            })
            message.success(response?.message || t('text.agentic.msg.run_cancelled'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.run_cancel_failed')))
        }
    })
}

const handleStopRun = async (run) => {
    if (!canOperateAgentic.value || !run?.id || !run?.actions?.can_stop) {
        return
    }

    const note = window.prompt(t('text.agentic.page.prompt_stop_note'), '')
    if (note === null) {
        return
    }

    await withRunAction('stop', run.id, async () => {
        try {
            const response = await stopRunMutation.mutateAsync({
                runId: run.id,
                note: note || null,
            })
            message.success(response?.message || t('text.agentic.page.msg_run_stop_requested'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_run_stop_failed')))
        }
    })
}

const handleRerunRun = async (run) => {
    if (!canOperateAgentic.value || !run?.id || !run?.actions?.can_rerun) {
        return
    }

    if (!window.confirm(t('text.agentic.page.confirm_rerun'))) {
        return
    }

    await withRunAction('rerun', run.id, async () => {
        try {
            const response = await rerunRunMutation.mutateAsync({
                runId: run.id,
            })
            const newRunId = response?.data?.id
            if (newRunId) {
                selectedRunId.value = newRunId
            }
            message.success(response?.message || t('text.agentic.msg.run_redispatched'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_rerun_failed')))
        }
    })
}

const handleCreateSchedule = async () => {
    if (!canProvisionAgentic.value || !canCreateSchedule.value) {
        return
    }

    const cadenceMinutes = Number.parseInt(String(scheduleDraft.cadenceMinutes || 0), 10)
    if (Number.isNaN(cadenceMinutes) || cadenceMinutes < 1) {
        message.error(t('text.agentic.page.msg_cadence_min'))
        return
    }

    const name = scheduleDraft.name?.trim() || defaultScheduleName.value

    try {
        const response = await createScheduleMutation.mutateAsync({
            agent_profile_id: selectedProfile.value.id,
            tool_definition_id: selectedDefinition.value.id,
            name,
            environment: scheduleDraft.environment || undefined,
            module_scope: scheduleDraft.moduleScope || undefined,
            goal: scheduleDraft.goal || undefined,
            target_type: scheduleDraft.targetType || undefined,
            target_id: scheduleDraft.targetId || undefined,
            cadence_minutes: cadenceMinutes,
            tool_input: {},
        })
        scheduleDraft.name = ''
        scheduleDraft.showAdvanced = false
        scheduleDraft.goal = ''
        scheduleDraft.targetType = ''
        scheduleDraft.targetId = ''
        scheduleDraft.environment = ''
        scheduleDraft.moduleScope = ''
        scheduleDraft.cadenceMinutes = 1440
        message.success(response?.message || t('text.agentic.page.msg_schedule_created'))
    } catch (error) {
        message.error(resolveErrorMessage(error, t('text.agentic.page.msg_schedule_create_failed')))
    }
}

const handleToggleSchedule = async (schedule) => {
    if (!canProvisionAgentic.value || !schedule?.id || (!schedule?.actions?.can_pause && !schedule?.actions?.can_resume)) {
        return
    }

    const nextStatus = schedule.status === 'paused' ? 'active' : 'paused'

    await withScheduleAction('toggle', schedule.id, async () => {
        try {
            const response = await updateScheduleMutation.mutateAsync({
                scheduleId: schedule.id,
                payload: {
                    status: nextStatus,
                },
            })
            message.success(response?.message || t('text.agentic.page.msg_schedule_updated'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.msg.schedule_update_failed')))
        }
    })
}

const handleDispatchSchedule = async (schedule) => {
    if (!canOperateAgentic.value || !schedule?.id || !schedule?.actions?.can_dispatch) {
        return
    }

    await withScheduleAction('dispatch', schedule.id, async () => {
        try {
            const response = await dispatchScheduleMutation.mutateAsync({
                scheduleId: schedule.id,
            })
            message.success(response?.message || t('text.agentic.page.msg_schedule_queued'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_schedule_queue_failed')))
        }
    })
}

const handleRunOnceSchedule = async (schedule) => {
    if (!canOperateAgentic.value || !schedule?.id || !schedule?.actions?.can_run_once) {
        return
    }

    await withScheduleAction('run_once', schedule.id, async () => {
        try {
            const response = await runOnceScheduleMutation.mutateAsync({
                scheduleId: schedule.id,
            })
            message.success(response?.message || t('text.agentic.page.msg_schedule_once_queued'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_schedule_once_failed')))
        }
    })
}

const handleResumeDispatchSchedule = async (schedule) => {
    if (!canProvisionAgentic.value || !schedule?.id || !schedule?.actions?.can_resume_and_dispatch) {
        return
    }

    await withScheduleAction('resume_dispatch', schedule.id, async () => {
        try {
            const response = await resumeDispatchScheduleMutation.mutateAsync({
                scheduleId: schedule.id,
            })
            message.success(response?.message || t('text.agentic.page.msg_schedule_resume_queued'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_schedule_resume_queue_failed')))
        }
    })
}

const handleEditScheduleCadence = async (schedule) => {
    if (!canProvisionAgentic.value || !schedule?.id) {
        return
    }

    const nextValue = window.prompt(t('text.agentic.page.prompt_cadence'), String(schedule.cadence_minutes || 60))
    if (nextValue === null) {
        return
    }

    const parsed = Number.parseInt(nextValue, 10)
    if (Number.isNaN(parsed) || parsed < 1) {
        message.error(t('text.agentic.page.msg_cadence_min'))
        return
    }

    await withScheduleAction('cadence', schedule.id, async () => {
        try {
            const response = await updateScheduleMutation.mutateAsync({
                scheduleId: schedule.id,
                payload: {
                    cadence_minutes: parsed,
                },
            })
            message.success(response?.message || t('text.agentic.page.msg_cadence_updated'))
        } catch (error) {
            message.error(resolveErrorMessage(error, t('text.agentic.page.msg_cadence_update_failed')))
        }
    })
}
</script>

<style src="./agentic/agentic.css"></style>
