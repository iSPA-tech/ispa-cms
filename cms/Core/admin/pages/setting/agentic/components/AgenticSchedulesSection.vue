<template>
    <section class="i-box space-y-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="text-lg font-semibold text-ink">
                    {{ t('text.agentic.schedules.title') }}
                </div>
                <div class="text-sm text-ink-muted">
                    {{ t('text.agentic.schedules.desc') }}
                </div>
            </div>

            <div class="grid gap-2 sm:grid-cols-3">
                <a-input
                    v-model:value="searchInput"
                    allow-clear
                    :placeholder="t('text.agentic.schedules.search_schedule')"
                />
                <a-select
                    v-model:value="filters.status"
                    :options="statusOptions"
                    allow-clear
                    :placeholder="t('text.agentic.select_status')"
                />
                <a-select
                    v-model:value="filters.moduleScope"
                    :options="moduleScopeOptions"
                    allow-clear
                    :placeholder="t('text.agentic.module_scope')"
                />
            </div>
        </div>

        <div class="rounded-xl border border-border bg-surface-sunken p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="text-sm font-semibold text-ink">
                        {{ t('text.agentic.schedules.create_from_selection') }}
                    </div>
                    <div class="text-xs text-ink-muted">
                        {{ t('text.agentic.schedules.section_desc') }}
                    </div>
                </div>
                <a-button
                    type="primary"
                    :disabled="!canProvision || !canCreateSchedule"
                    :loading="isCreatePending"
                    @click="onCreateSchedule"
                >
                    {{ t('text.agentic.schedules.create_button') }}
                </a-button>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(220px,0.8fr)]">
                <div class="agentic-profile-helper-card">
                    <div class="agentic-profile-helper-title">{{ t('text.agentic.schedules.what_is_scheduler') }}</div>
                    <!-- Developer-controlled i18n; safe for v-html -->
                    <div class="agentic-profile-helper-copy" v-html="t('text.agentic.schedules.what_is_scheduler_desc')" />

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">
                                {{ t('text.agentic.schedules.current_profile') }}
                            </div>
                            <div class="agentic-profile-helper-value">
                                {{ selectedProfile?.name || t('text.agentic.schedules.no_profile_selected') }}
                            </div>
                            <div class="mt-1 text-xs text-ink-muted">
                                {{ selectedProfileCapabilityMeta?.label || t('text.agentic.schedules.select_profile_hint') }}
                            </div>
                        </div>
                        <div class="agentic-profile-helper-block">
                            <div class="agentic-profile-helper-label">
                                {{ t('text.agentic.schedules.current_tool') }}
                            </div>
                            <div class="agentic-profile-helper-value">
                                {{ selectedDefinition?.tool_name || t('text.agentic.schedules.no_tool_selected') }}
                            </div>
                            <div class="mt-1 text-xs text-ink-muted">
                                {{ selectedDefinition ? t('text.agentic.schedules.tool_call_hint') : t('text.agentic.schedules.select_tool_hint') }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-4 rounded-xl px-4 py-3 text-sm"
                        :class="scheduleEligibilityTone === 'ok'
                            ? 'border border-success-soft bg-success-soft text-success-ink'
                            : 'border border-warning-soft bg-warning-soft text-warning-ink'"
                    >
                        {{ scheduleEligibilityMessage }}
                    </div>
                </div>

                <div class="agentic-panel space-y-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                            {{ t('text.agentic.schedules.schedule_name') }}
                        </div>
                        <a-input
                            v-model:value="draft.name"
                            allow-clear
                            :placeholder="t('text.agentic.schedules.auto_name_placeholder')"
                        />
                        <div class="agentic-form-help">
                            {{ t('text.agentic.schedules.suggested_name') }}:
                            <span class="font-medium text-ink">{{ defaultScheduleName }}</span>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                            {{ t('text.agentic.schedules.cadence') }}
                        </div>
                        <a-select
                            v-model:value="draft.cadenceMinutes"
                            :options="cadenceOptions"
                            class="w-full"
                            :placeholder="t('text.agentic.schedules.select_cadence')"
                        />
                        <!-- Developer-controlled i18n; safe for v-html -->
                        <div class="agentic-form-help">
                            <span v-html="t('text.agentic.schedules.cadence_hint')" />
                        </div>
                    </div>

                    <a-button
                        type="link"
                        class="!px-0"
                        @click="draft.showAdvanced = !draft.showAdvanced"
                    >
                        {{
                            draft.showAdvanced
                                ? t('text.agentic.schedules.hide_advanced')
                                : t('text.agentic.schedules.show_advanced')
                        }}
                    </a-button>
                </div>
            </div>

            <div
                v-if="draft.showAdvanced"
                class="mt-4 rounded-xl border border-border bg-surface p-4"
            >
                <div class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                    {{ t('text.agentic.schedules.advanced_options') }}
                </div>
                <div class="grid gap-3 lg:grid-cols-5">
                    <a-input
                        v-model:value="draft.environment"
                        allow-clear
                        :placeholder="t('text.agentic.schedules.environment_placeholder')"
                    />
                    <a-input
                        v-model:value="draft.moduleScope"
                        allow-clear
                        :placeholder="t('text.agentic.schedules.module_scope_placeholder')"
                    />
                    <a-input
                        v-model:value="draft.targetType"
                        allow-clear
                        :placeholder="t('text.agentic.schedules.target_type_placeholder')"
                    />
                    <a-input
                        v-model:value="draft.targetId"
                        allow-clear
                        :placeholder="t('text.agentic.schedules.target_id_placeholder')"
                    />
                    <a-input
                        v-model:value="draft.goal"
                        allow-clear
                        :placeholder="t('text.agentic.schedules.goal_override_placeholder')"
                    />
                </div>
                <div class="agentic-form-help">
                    {{ t('text.agentic.schedules.advanced_hint') }}
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-surface-sunken px-4 py-3">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                {{ t('text.agentic.schedules.manual_actions') }}
            </div>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-border bg-surface px-3 py-3 text-sm text-ink-muted">
                    <div class="font-semibold text-ink">
                        {{ t('text.agentic.schedules.active_schedule') }}
                    </div>
                    <!-- Developer-controlled i18n; safe for v-html -->
                    <div class="mt-1">
                        <span v-html="t('text.agentic.schedules.run_now_desc')" />
                    </div>
                </div>
                <div class="rounded-lg border border-border bg-surface px-3 py-3 text-sm text-ink-muted">
                    <div class="font-semibold text-ink">
                        {{ t('text.agentic.schedules.paused_schedule') }}
                    </div>
                    <!-- Developer-controlled i18n; safe for v-html -->
                    <div class="mt-1">
                        <span v-html="t('text.agentic.schedules.run_once_desc')" />
                    </div>
                </div>
                <div class="rounded-lg border border-border bg-surface px-3 py-3 text-sm text-ink-muted">
                    <div class="font-semibold text-ink">
                        {{ t('text.agentic.schedules.resume_and_run_now') }}
                    </div>
                    <div class="mt-1">
                        {{ t('text.agentic.schedules.resume_desc') }}
                    </div>
                </div>
            </div>
        </div>

        <a-table
            :columns="columns"
            :data-source="schedules"
            :loading="isLoading || isFetching"
            :pagination="false"
            :scroll="{ x: 880 }"
            row-key="id"
            size="small"
        >
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'summary'">
                    <div class="space-y-1">
                        <div class="font-medium text-ink">
                            {{ record.name }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{
                                record.profile?.slug || t('text.agentic.schedules.unknown_profile')
                            }}
                            ·
                            {{
                                record.tool_definition?.tool_name || t('text.agentic.schedules.unknown_tool')
                            }}
                        </div>
                        <div class="text-xs text-ink">
                            {{ scheduleToolDescription(record) }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ record.module_scope || t('text.agentic.schedules.scope_global') }} · {{ record.environment || t('text.agentic.schedules.env_default') }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ t('text.agentic.schedules.execution_flow') }}
                            {{ record.workflow_project?.slug || `pending-sync-${record.id}` }}
                        </div>
                        <div class="text-xs text-success-ink">
                            {{ scheduleOutcomeSummary(record) }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{
                                record.goal
                                    ? `${t('text.agentic.schedules.goal_override')} ${record.goal}`
                                    : t('text.agentic.schedules.no_goal_override')
                            }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'cadence'">
                    <div class="space-y-2">
                        <span class="agentic-pill" :class="runtimeStatusClass(record.status)">
                            {{ scheduleStatusLabel(record.status) }}
                        </span>
                        <div class="text-xs text-ink-muted">
                            {{ t('text.agentic.schedules.every_minutes', { count: record.cadence_minutes }) }}
                        </div>
                        <div class="text-xs text-ink-muted">
                            {{ scheduleNextRunLabel(record) }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'last_run'">
                    <div class="space-y-1 text-xs text-ink-muted">
                        <div>{{ t('text.agentic.schedules.run_number') }} {{ record.last_run?.id || t('text.not_applicable') }}</div>
                        <div>{{ t('text.agentic.schedules.status_label') }} {{ record.last_outcome || record.last_run?.status || t('text.not_applicable') }}</div>
                        <div>{{ t('text.agentic.schedules.at_label') }} {{ record.last_run_at || t('text.agentic.schedules.never') }}</div>
                        <div>{{ t('text.agentic.schedules.workflow_label') }} {{ record.workflow_project?.id ? `#${record.workflow_project.id}` : t('text.agentic.schedules.pending_sync') }}</div>
                        <div v-if="record.last_error" class="text-danger-ink">
                            {{ record.last_error }}
                        </div>
                    </div>
                </template>
                <template v-else-if="column.key === 'actions'">
                    <div class="flex flex-wrap gap-2">
                        <a-popconfirm
                            v-if="record.actions?.can_dispatch"
                            :title="t('text.agentic.schedules.confirm_run_now')"
                            @confirm="onDispatchSchedule(record)"
                        >
                            <a-button
                                size="small"
                                :disabled="!canOperate || !record.actions?.can_dispatch"
                                :loading="activeScheduleId === record.id && scheduleActionMode === 'dispatch' && isDispatchPending"
                            >
                                {{ t('text.agentic.schedules.run_now') }}
                            </a-button>
                        </a-popconfirm>
                        <a-popconfirm
                            v-if="record.actions?.can_run_once"
                            :title="t('text.agentic.schedules.confirm_run_once')"
                            @confirm="onRunOnceSchedule(record)"
                        >
                            <a-button
                                size="small"
                                :disabled="!canOperate"
                                :loading="activeScheduleId === record.id && scheduleActionMode === 'run_once' && isDispatchPending"
                            >
                                {{ t('text.agentic.schedules.run_once') }}
                            </a-button>
                        </a-popconfirm>
                        <a-popconfirm
                            v-if="record.actions?.can_resume_and_dispatch"
                            :title="t('text.agentic.schedules.confirm_resume')"
                            @confirm="onResumeDispatchSchedule(record)"
                        >
                            <a-button
                                size="small"
                                :disabled="!canProvision"
                                :loading="activeScheduleId === record.id && scheduleActionMode === 'resume_dispatch' && isDispatchPending"
                            >
                                {{ t('text.agentic.schedules.resume_and_run_now') }}
                            </a-button>
                        </a-popconfirm>
                        <a-button
                            size="small"
                            :disabled="!canProvision || (!record.actions?.can_pause && !record.actions?.can_resume)"
                            :loading="activeScheduleId === record.id && scheduleActionMode === 'toggle' && isUpdatePending"
                            @click="onToggleSchedule(record)"
                        >
                            {{
                                record.status === 'paused'
                                    ? t('text.agentic.schedules.resume')
                                    : t('text.agentic.schedules.pause')
                            }}
                        </a-button>
                        <a-popover
                            :title="t('text.agentic.schedules.edit_cadence')"
                            trigger="click"
                            placement="bottomRight"
                        >
                            <template #content>
                                <div class="flex items-end gap-2" style="min-width: 220px">
                                    <a-select
                                        v-model:value="cadenceEditValue"
                                        :options="cadenceOptions"
                                        class="flex-1"
                                        size="small"
                                        :placeholder="t('text.agentic.schedules.select_cadence')"
                                    />
                                    <a-button
                                        type="primary"
                                        size="small"
                                        :loading="activeScheduleId === record.id && scheduleActionMode === 'cadence' && isUpdatePending"
                                        @click="handleSaveCadence(record)"
                                    >
                                        {{ t('text.agentic.admin.save') }}
                                    </a-button>
                                </div>
                            </template>
                            <a-button
                                size="small"
                                :disabled="!canProvision"
                                @click="cadenceEditValue = record.cadence_minutes"
                            >
                                {{ t('text.agentic.schedules.edit_cadence') }}
                            </a-button>
                        </a-popover>
                        <a-button
                            v-if="record.last_run?.id"
                            size="small"
                            type="default"
                            @click="onOpenRunDetail(record.last_run)"
                        >
                            {{ t('text.agentic.schedules.last_run_button') }}
                        </a-button>
                        <div
                            v-if="record.actions?.can_run_once || record.actions?.can_resume_and_dispatch || record.actions?.can_dispatch"
                            class="basis-full text-[11px] leading-5 text-ink-muted"
                        >
                            {{
                                record.status === 'paused'
                                    ? t('text.agentic.schedules.run_once_tooltip')
                                    : t('text.agentic.schedules.run_now_tooltip')
                            }}
                        </div>
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
const { moduleLabel } = useAgenticMeta()

/** Module scope filter options built from the shared moduleLabel map. */
const moduleScopeOptions = computed(() => {
    // Modules that registered a run scope sit between the open modules and Core.
    const modules = ['content', 'product', 'education', ...agenticModules().filter((module) => module.scope).map((module) => module.key), 'core']
    return modules.map((key) => ({ label: moduleLabel(key), value: key }))
})

/** Temporary cadence value for the inline edit popover. */
const cadenceEditValue = ref(null)

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    draft: {
        type: Object,
        required: true,
    },
    statusOptions: {
        type: Array,
        required: true,
    },
    columns: {
        type: Array,
        required: true,
    },
    schedules: {
        type: Array,
        required: true,
    },
    meta: {
        type: Object,
        required: true,
    },
    selectedProfile: {
        type: Object,
        default: null,
    },
    selectedDefinition: {
        type: Object,
        default: null,
    },
    selectedProfileCapabilityMeta: {
        type: Object,
        default: null,
    },
    canCreateSchedule: {
        type: Boolean,
        default: false,
    },
    scheduleEligibilityMessage: {
        type: String,
        default: '',
    },
    scheduleEligibilityTone: {
        type: String,
        default: 'warn',
    },
    defaultScheduleName: {
        type: String,
        default: '',
    },
    cadenceOptions: {
        type: Array,
        required: true,
    },
    canOperate: {
        type: Boolean,
        default: false,
    },
    canProvision: {
        type: Boolean,
        default: false,
    },
    isCreatePending: {
        type: Boolean,
        default: false,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    isFetching: {
        type: Boolean,
        default: false,
    },
    isDispatchPending: {
        type: Boolean,
        default: false,
    },
    isUpdatePending: {
        type: Boolean,
        default: false,
    },
    activeScheduleId: {
        type: [Number, String],
        default: null,
    },
    scheduleActionMode: {
        type: String,
        default: null,
    },
    runtimeStatusClass: {
        type: Function,
        required: true,
    },
    formatMeta: {
        type: Function,
        required: true,
    },
    onCreateSchedule: {
        type: Function,
        required: true,
    },
    onToggleSchedule: {
        type: Function,
        required: true,
    },
    onDispatchSchedule: {
        type: Function,
        required: true,
    },
    onRunOnceSchedule: {
        type: Function,
        required: true,
    },
    onResumeDispatchSchedule: {
        type: Function,
        required: true,
    },
    onEditScheduleCadence: {
        type: Function,
        default: () => () => {},
    },
    onOpenRunDetail: {
        type: Function,
        required: true,
    },
})

/**
 * Save the edited cadence value via the onEditScheduleCadence prop callback.
 * @param {Object} record - the schedule row being edited
 */
function handleSaveCadence(record) {
    if (cadenceEditValue.value != null) {
        props.onEditScheduleCadence({ ...record, cadence_minutes: cadenceEditValue.value })
    }
}

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

const scheduleToolDescription = (record) => {
    const description = record?.tool_definition?.description?.trim?.()

    if (description) {
        return description
    }

    return t('text.agentic.schedules.no_tool_description')
}

const scheduleOutcomeSummary = (record) => {
    const sideEffectClass = record?.tool_definition?.side_effect_class
    const approvalRequired = Boolean(record?.tool_definition?.approval_required)

    if (sideEffectClass === 'read_only') {
        return t('text.agentic.schedules.outcome.read_only')
    }

    if (approvalRequired) {
        return t('text.agentic.schedules.outcome.awaiting_approval')
    }

    if (sideEffectClass === 'draft_write') {
        return t('text.agentic.schedules.outcome.draft_write')
    }

    if (sideEffectClass === 'autonomous_maintenance') {
        return t('text.agentic.schedules.outcome.autonomous_maintenance')
    }

    return t('text.agentic.schedules.outcome.default')
}

const scheduleNextRunLabel = (record) => {
    if (record?.status === 'paused') {
        return t('text.agentic.schedules.next_paused')
    }

    return t('text.agentic.schedules.next_at', {
        value: record?.next_run_at || t('text.not_applicable'),
    })
}

const scheduleStatusLabel = (status) => {
    if (!status) {
        return t('text.not_applicable')
    }

    return t(`text.agentic.schedules.status.${status}`, status)
}
</script>
