<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <section class="i-box space-y-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="text-lg font-semibold text-ink">
                    {{ t('text.agentic.runs.title') }}
                </div>
                <div class="text-sm text-ink-muted">
                    {{ t('text.agentic.runs.subtitle') }}
                </div>
            </div>

            <div class="grid gap-2 sm:grid-cols-3">
                <a-input
                    v-model:value="searchInput"
                    :placeholder="t('text.agentic.runs.search_goal')"
                    allow-clear
                />
                <a-select
                    v-model:value="filters.status"
                    :options="statusOptions"
                    :placeholder="t('text.agentic.runs.filter_status')"
                    allow-clear
                />
                <a-select
                    v-model:value="filters.moduleScope"
                    :options="moduleScopeOptions"
                    :placeholder="t('text.agentic.runs.filter_module')"
                    allow-clear
                />
            </div>
        </div>

        <a-table
            :columns="columns"
            :data-source="runs"
            :loading="isLoading || isFetching"
            :pagination="false"
            :scroll="{ x: 980 }"
            row-key="id"
            size="small"
        >
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'goal'">
                    <div class="space-y-1">
                        <div class="font-medium text-ink">
                            {{ record.input_goal || t('text.agentic.run.no_goal') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ t('text.agentic.approval_detail.run_id', { id: record.id }) }} · {{ record.profile?.name || record.profile?.slug || t('text.agentic.run.unknown_profile') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ record.module_scope || t('text.agentic.schedules.scope_global') }} · {{ record.environment || t('text.agentic.section.env_local') }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'status'">
                    <div class="space-y-2">
                        <span class="agentic-pill" :class="runtimeStatusClass(record.status)">
                            {{ sl(record.status) }}
                        </span>
                        <div class="text-xs text-ink-muted">
                            {{ sl(record.post_approval_outcome || record.terminal_reason) }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'workflow'">
                    <div class="space-y-1 text-xs text-ink-muted">
                        <div>{{ t('text.agentic.run.workflow') }} #{{ record.workflow?.id || t('text.agentic.na') }}</div>
                        <div>{{ t('text.agentic.runs.wf_status') }}: {{ sl(record.workflow?.status) }}</div>
                        <div>{{ t('text.agentic.run.resume_step') }}: {{ record.workflow?.resume_step_id || t('text.agentic.none') }}</div>
                    </div>
                </template>
                <template v-else-if="column.key === 'latest_step'">
                    <div class="space-y-1">
                        <div class="text-sm font-medium text-ink">
                            {{ record.latest_step?.action_name || t('text.agentic.runs.no_step') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ sl(record.latest_step?.status) }} · {{ sl(record.latest_step?.state) }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ record.latest_step?.observation_summary || t('text.agentic.runs.no_observation') }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'inspect'">
                    <div class="flex flex-wrap gap-2">
                        <a-button
                            size="small"
                            type="default"
                            @click="onOpenRunDetail(record)"
                        >
                            {{ t('text.agentic.runs.btn_detail') }}
                        </a-button>
                        <a-popconfirm
                            v-if="record.actions?.can_stop"
                            :title="t('text.agentic.runs.confirm_stop')"
                            @confirm="onStopRun(record)"
                        >
                            <a-button
                                size="small"
                                :disabled="!canOperate"
                                :loading="activeRunId === record.id && runActionMode === 'stop' && isStopPending"
                            >
                                {{ t('text.agentic.runs.btn_stop') }}
                            </a-button>
                        </a-popconfirm>
                        <a-popconfirm
                            v-if="record.actions?.can_cancel"
                            :title="t('text.agentic.runs.confirm_cancel')"
                            @confirm="onCancelRun(record)"
                        >
                            <a-button
                                size="small"
                                danger
                                :disabled="!canOperate"
                                :loading="activeRunId === record.id && runActionMode === 'cancel' && isCancelPending"
                            >
                                {{ t('text.agentic.runs.btn_cancel') }}
                            </a-button>
                        </a-popconfirm>
                        <a-popconfirm
                            v-if="record.actions?.can_rerun"
                            :title="t('text.agentic.runs.confirm_rerun')"
                            @confirm="onRerunRun(record)"
                        >
                            <a-button
                                size="small"
                                :disabled="!canOperate"
                                :loading="activeRunId === record.id && runActionMode === 'rerun' && isRerunPending"
                            >
                                {{ t('text.agentic.runs.btn_rerun') }}
                            </a-button>
                        </a-popconfirm>
                    </div>
                </template>
            </template>
        </a-table>

        <div class="flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-xs text-ink-muted">
                {{ formatMeta(meta) }}
            </div>
            <a-pagination
                v-model:current="filters.page"
                :page-size="filters.perPage"
                :total="meta.total || 0"
                show-less-items
                size="small"
            />
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticMeta } from '../composables/useAgenticMeta'
import { agenticModules } from '../registry'

const { t } = useI18n()
const { sl, moduleLabel } = useAgenticMeta()

/** Module scope filter options built from the shared moduleLabel map. */
const moduleScopeOptions = computed(() => {
    // Modules that registered a run scope sit between the open modules and Core.
    const modules = ['content', 'product', 'education', ...agenticModules().filter((module) => module.scope).map((module) => module.key), 'core']
    return modules.map((key) => ({ label: moduleLabel(key), value: key }))
})

const props = defineProps({
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    columns: { type: Array, required: true },
    runs: { type: Array, required: true },
    meta: { type: Object, required: true },
    canOperate: { type: Boolean, default: false },
    isLoading: { type: Boolean, default: false },
    isFetching: { type: Boolean, default: false },
    isStopPending: { type: Boolean, default: false },
    isCancelPending: { type: Boolean, default: false },
    isRerunPending: { type: Boolean, default: false },
    activeRunId: { type: [Number, String], default: null },
    runActionMode: { type: String, default: null },
    runtimeStatusClass: { type: Function, required: true },
    formatMeta: { type: Function, required: true },
    onOpenRunDetail: { type: Function, required: true },
    onStopRun: { type: Function, required: true },
    onCancelRun: { type: Function, required: true },
    onRerunRun: { type: Function, required: true },
})

// ── Debounced search ──────────────────────────────────────────
const searchInput = ref(props.filters.search || '')
let searchTimer = null

watch(searchInput, (val) => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        props.filters.search = val
    }, 400)
})

watch(() => props.filters.search, (val) => {
    if (val !== searchInput.value) {
        searchInput.value = val ?? ''
    }
})

onBeforeUnmount(() => clearTimeout(searchTimer))
</script>
