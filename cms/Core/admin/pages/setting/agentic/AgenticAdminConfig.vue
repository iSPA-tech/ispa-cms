<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <template v-if="canProvisionAgentic">
        <div class="space-y-4">
            <!-- Breadcrumbs -->
            <breadcrumbs
                :items="[
                    { name: 'setting-dashboard', title: $t('nav.a.settings'), icon: 'cog-sync' },
                    { name: 'setting-agentic', text: t('text.agentic.cms'), icon: 'robot' },
                    { text: t('text.agentic.admin.title') },
                ]"
            />

            <!-- Secondary nav -->
            <AgenticSubNav />

            <!-- Tab panels -->
            <a-tabs v-model:activeKey="activeTab" size="small">
                <a-tab-pane key="profiles" :tab="t('text.agentic.admin.profiles')">
                    <!-- Profiles table + create/edit inline — reuses existing monolith logic via composables -->
                    <div class="rounded-xl border border-border bg-surface p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold">{{ t('text.agentic.admin.profiles') }}</h3>
                            <a-button type="primary" size="small" @click="openCreateProfile">
                                {{ t('text.agentic.admin.create_profile') }}
                            </a-button>
                        </div>
                        <a-table
                            :data-source="profiles"
                            :columns="profileColumns"
                            :loading="isProfilesLoading"
                            :pagination="{ current: profileFilters.page, pageSize: profileFilters.perPage, total: profileMeta.total, onChange: (p) => { profileFilters.page = p } }"
                            :scroll="{ x: 870 }"
                            size="small"
                            row-key="id"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'capability_class'">
                                    <span class="agentic-pill" :class="runtimeStatusClass(record.capability_class)">
                                        {{ capabilityMetaMap[record.capability_class]?.shortLabel || record.capability_class }}
                                    </span>
                                </template>
                                <template v-else-if="column.key === 'status'">
                                    <a-switch
                                        :checked="record.status === 'active'"
                                        size="small"
                                        @change="(checked) => handleUpdateProfile(record.slug || record.id, { status: checked ? 'active' : 'inactive' })"
                                    />
                                </template>
                                <template v-else-if="column.key === 'inspect'">
                                    <a-button size="small" @click="openEditProfile(record)">
                                        {{ t('text.agentic.admin.edit') }}
                                    </a-button>
                                </template>
                            </template>
                        </a-table>
                    </div>
                </a-tab-pane>

                <a-tab-pane key="tools" :tab="t('text.agentic.admin.tools')">
                    <div class="rounded-xl border border-border bg-surface p-4">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                            <a-input
                                v-model:value="definitionSearchInput"
                                :placeholder="t('text.agentic.admin.search_tools')"
                                allow-clear
                                size="small"
                                class="max-w-xs"
                            />
                        </div>
                        <a-table
                            :data-source="definitions"
                            :columns="definitionColumns"
                            :loading="isDefinitionsLoading"
                            :pagination="{ current: definitionFilters.page, pageSize: definitionFilters.perPage, total: definitionMeta.total, onChange: (p) => { definitionFilters.page = p } }"
                            :scroll="{ x: 1142 }"
                            size="small"
                            row-key="id"
                        />
                    </div>
                </a-tab-pane>

                <a-tab-pane key="grants" :tab="t('text.agentic.admin.grants')">
                    <div class="rounded-xl border border-border bg-surface p-4">
                        <div class="mb-3">
                            <a-select
                                v-model:value="selectedProfileKey"
                                :options="profileSelectOptions"
                                :placeholder="t('text.agentic.admin.select_profile')"
                                class="w-full max-w-sm"
                                size="small"
                            />
                        </div>
                        <div v-if="grants.length > 0">
                            <a-table
                                :data-source="grants"
                                :columns="grantsColumns"
                                :scroll="{ x: 570 }"
                                size="small"
                                row-key="id"
                                :pagination="false"
                            >
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'enabled'">
                                        <a-switch
                                            :checked="record.grant?.enabled ?? record.enabled ?? true"
                                            size="small"
                                            @change="(checked) => handleUpdateGrant(selectedProfileKey, record.id, { enabled: checked })"
                                        />
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <a-popconfirm
                                            :title="t('text.agentic.admin.revoke_confirm')"
                                            @confirm="handleRevokeTool(selectedProfileKey, record.id)"
                                        >
                                            <a-button size="small" danger>{{ t('text.agentic.admin.revoke') }}</a-button>
                                        </a-popconfirm>
                                    </template>
                                </template>
                            </a-table>
                        </div>
                        <AgenticEmptyState
                            v-else-if="selectedProfileKey"
                            icon="🔗"
                            :title-key="'text.agentic.admin.no_grants'"
                            :description-key="'text.agentic.admin.no_grants_desc'"
                        />
                    </div>
                </a-tab-pane>

                <a-tab-pane key="bindings" :tab="t('text.agentic.admin.bindings')">
                    <div class="rounded-xl border border-border bg-surface p-4">
                        <a-table
                            :data-source="bindings"
                            :columns="bindingColumns"
                            :loading="isBindingsLoading"
                            :pagination="{ current: bindingFilters.page, pageSize: bindingFilters.perPage, total: bindingMeta.total, onChange: (p) => { bindingFilters.page = p } }"
                            :scroll="{ x: 1260 }"
                            size="small"
                            row-key="id"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'enabled'">
                                    <a-switch
                                        :checked="record.enabled"
                                        size="small"
                                        @change="(checked) => handleToggleBinding(record.id, checked)"
                                    />
                                </template>
                            </template>
                        </a-table>
                    </div>
                </a-tab-pane>

                <a-tab-pane key="schedules" :tab="t('text.agentic.admin.schedules')">
                    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-border bg-surface p-4 sm:flex-row">
                        <div class="flex-1">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                                {{ t('text.agentic.schedules.select_profile') }}
                            </div>
                            <a-select
                                v-model:value="scheduleProfileKey"
                                :options="profileSelectOptions"
                                :placeholder="t('text.agentic.admin.select_profile')"
                                allow-clear
                                class="w-full"
                                size="small"
                            />
                        </div>
                        <div class="flex-1">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                                {{ t('text.agentic.schedules.select_tool') }}
                            </div>
                            <a-select
                                v-model:value="scheduleToolKey"
                                :options="definitionSelectOptions"
                                :placeholder="t('text.agentic.schedules.select_tool')"
                                allow-clear
                                class="w-full"
                                size="small"
                            />
                        </div>
                    </div>
                    <AgenticSchedulesSection
                        :schedules="schedules"
                        :meta="scheduleMeta"
                        :filters="scheduleFilters"
                        :draft="scheduleDraft"
                        :columns="scheduleColumns"
                        :status-options="scheduleStatusOptions"
                        :cadence-options="scheduleCadenceOptions"
                        :selected-profile="scheduleSelectedProfile"
                        :selected-definition="scheduleSelectedDefinition"
                        :selected-profile-capability-meta="scheduleCapabilityMeta"
                        :can-create-schedule="canCreateScheduleComputed"
                        :schedule-eligibility-message="scheduleEligibilityMessage"
                        :schedule-eligibility-tone="scheduleEligibilityTone"
                        :default-schedule-name="scheduleDefaultName"
                        :can-operate="canProvisionAgentic"
                        :can-provision="canProvisionAgentic"
                        :is-loading="isSchedulesLoading"
                        :is-fetching="isSchedulesFetching"
                        :is-create-pending="false"
                        :is-dispatch-pending="false"
                        :is-update-pending="false"
                        :active-schedule-id="activeScheduleId"
                        :schedule-action-mode="scheduleActionMode"
                        :runtime-status-class="runtimeStatusClass"
                        :format-meta="formatMeta"
                        :on-create-schedule="handleCreateSchedule"
                        :on-toggle-schedule="(s) => handlePauseSchedule(s.id, s.status !== 'paused')"
                        :on-dispatch-schedule="(s) => handleDispatchSchedule(s.id)"
                        :on-run-once-schedule="(s) => handleRunOnceSchedule(s.id)"
                        :on-resume-dispatch-schedule="(s) => handleResumeDispatch(s.id)"
                    />
                </a-tab-pane>
            </a-tabs>
        </div>

        <!-- Profile create/edit modal -->
        <a-modal
            v-model:open="profileModalOpen"
            :title="profileModalMode === 'create' ? t('text.agentic.admin.create_profile') : t('text.agentic.admin.edit_profile')"
            :confirm-loading="isSaveProfilePending"
            @ok="saveProfile"
        >
            <a-form ref="profileFormRef" :model="profileDraft" :rules="profileFormRules" layout="vertical">
                <a-form-item :label="t('text.agentic.admin.profile_name')" name="name">
                    <a-input v-model:value="profileDraft.name" />
                </a-form-item>
                <a-form-item :label="t('text.agentic.admin.profile_slug')" name="slug">
                    <a-input v-model:value="profileDraft.slug" :disabled="profileModalMode === 'edit'" />
                </a-form-item>
                <a-form-item :label="t('text.agentic.admin.capability_class')" name="capabilityClass">
                    <a-select v-model:value="profileDraft.capabilityClass" :options="capabilityOptions" />
                    <div v-if="selectedCapabilityMeta" class="mt-2 rounded border border-border bg-surface-sunken p-3 text-xs text-ink-muted">
                        {{ selectedCapabilityMeta.summary }}
                    </div>
                </a-form-item>
                <a-form-item :label="t('text.agentic.run_budget')" name="budgetPreset">
                    <a-select v-model:value="profileDraft.budgetPreset" :options="budgetPresetOptions" />
                </a-form-item>
            </a-form>
        </a-modal>
    </template>
    <no-permission-error v-else />
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticMeta } from './composables/useAgenticMeta'
import { useAgenticFilters } from './composables/useAgenticFilters'
import { useAgenticMutations } from './composables/useAgenticMutations'
import {
    useAgenticToolDefinitions,
    useAgenticToolBindings,
    useAgenticProfiles,
    useAgenticProfileGrants,
    useAgenticSchedules,
} from '@/hooks'
import AgenticSchedulesSection from './components/AgenticSchedulesSection.vue'
import AgenticSubNav from './components/AgenticSubNav.vue'
import AgenticEmptyState from './components/AgenticEmptyState.vue'

defineOptions({ name: 'AgenticAdminConfig' })

const { t } = useI18n()
const { canProvisionAgentic } = useAgenticPermissions()
const {
    capabilityMetaMap, capabilityOptions, budgetPresetMetaMap, budgetPresetOptions,
    profileColumns, definitionColumns, bindingColumns, scheduleColumns,
    scheduleStatusOptions, scheduleCadenceOptions,
    runtimeStatusClass, formatMeta,
} = useAgenticMeta()
const { definitionFilters, bindingFilters, profileFilters, scheduleFilters } = useAgenticFilters()
const {
    handleCreateProfile, handleUpdateProfile,
    handleGrantTool, handleRevokeTool, handleUpdateGrant,
    handleToggleBinding,
    handleCreateSchedule, handleDispatchSchedule, handlePauseSchedule,
    handleRunOnceSchedule, handleResumeDispatch,
    isSaveProfilePending,
    activeScheduleId, scheduleActionMode,
} = useAgenticMutations()

// ── Debounced definition search ───────────────────────────────
const definitionSearchInput = ref(definitionFilters.search || '')
let defSearchTimer = null

watch(definitionSearchInput, (val) => {
    clearTimeout(defSearchTimer)
    defSearchTimer = setTimeout(() => {
        definitionFilters.search = val
    }, 400)
})

watch(() => definitionFilters.search, (val) => {
    if (val !== definitionSearchInput.value) {
        definitionSearchInput.value = val ?? ''
    }
})

onBeforeUnmount(() => clearTimeout(defSearchTimer))

// ── Queries ────────────────────────────────────────────────────
const { data: defQuery, isLoading: isDefinitionsLoading } = useAgenticToolDefinitions(definitionFilters, { refetchOnWindowFocus: false })
const { data: bindQuery, isLoading: isBindingsLoading } = useAgenticToolBindings(bindingFilters, { refetchOnWindowFocus: false })
const { data: profQuery, isLoading: isProfilesLoading } = useAgenticProfiles(profileFilters, { refetchOnWindowFocus: false })
const { data: schedQuery, isLoading: isSchedulesLoading, isFetching: isSchedulesFetching } = useAgenticSchedules(scheduleFilters, { refetchOnWindowFocus: false })

const definitions = computed(() => defQuery.value?.data || [])
const definitionMeta = computed(() => defQuery.value?.meta || {})
const bindings = computed(() => bindQuery.value?.data || [])
const bindingMeta = computed(() => bindQuery.value?.meta || {})
const profiles = computed(() => profQuery.value?.data || [])
const profileMeta = computed(() => profQuery.value?.meta || {})
const schedules = computed(() => schedQuery.value?.data || [])
const scheduleMeta = computed(() => schedQuery.value?.meta || {})

const profileSelectOptions = computed(() => profiles.value.map((p) => ({
    label: `${p.name} (${p.slug})`,
    value: p.slug || p.id,
})))

// ── Grants ─────────────────────────────────────────────────────
const grantsColumns = computed(() => [
    { key: 'tool_name', title: t('text.agentic.col.tool'), dataIndex: 'tool_name' },
    { key: 'enabled', title: t('text.agentic.col.enabled'), width: 100 },
    { key: 'actions', title: t('text.agentic.col.actions'), width: 120 },
])
const selectedProfileKey = ref(null)
const { data: grantsQuery } = useAgenticProfileGrants(selectedProfileKey, { refetchOnWindowFocus: false })
const grants = computed(() => grantsQuery.value?.grants || [])

// ── Schedule draft ─────────────────────────────────────────────
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
// ── Schedule selections ────────────────────────────────────────
const scheduleProfileKey = ref(null)
const scheduleToolKey = ref(null)

const scheduleSelectedProfile = computed(() => profiles.value.find((p) => (p.slug || p.id) === scheduleProfileKey.value) || null)
const scheduleSelectedDefinition = computed(() => definitions.value.find((d) => d.tool_name === scheduleToolKey.value) || null)
const scheduleCapabilityMeta = computed(() => capabilityMetaMap[scheduleSelectedProfile.value?.capability_class] || null)

const scheduleDefaultName = computed(() => {
    if (!scheduleSelectedProfile.value || !scheduleSelectedDefinition.value) return ''
    return `${scheduleSelectedProfile.value.slug} · ${scheduleSelectedDefinition.value.tool_name}`
})

const canCreateScheduleComputed = computed(() =>
    Boolean(scheduleSelectedProfile.value?.id)
    && Boolean(scheduleSelectedDefinition.value?.id)
    && Boolean(scheduleCapabilityMeta.value?.scheduleAllowed)
)

const scheduleEligibilityMessage = computed(() => {
    if (!scheduleSelectedProfile.value) return t('text.agentic.schedules.select_profile_first')
    if (!scheduleSelectedDefinition.value) return t('text.agentic.schedules.select_tool_first')
    if (!scheduleCapabilityMeta.value?.scheduleAllowed) return t('text.agentic.schedules.profile_not_schedulable')
    return t('text.agentic.schedules.ready_to_create')
})

const scheduleEligibilityTone = computed(() => canCreateScheduleComputed.value ? 'ok' : 'warn')

const definitionSelectOptions = computed(() => definitions.value.map((d) => ({
    label: `${d.tool_name} · ${d.tool_family || t('text.agentic.page.family_general')}`,
    value: d.tool_name,
})))

// ── Profile modal ──────────────────────────────────────────────
const activeTab = ref('profiles')
const profileModalOpen = ref(false)
const profileModalMode = ref('create')
const profileFormRef = ref(null)
const profileDraft = reactive({
    name: '',
    slug: '',
    capabilityClass: 'class_0_read_only',
    budgetPreset: 'standard',
})

/** Validation rules for profile create/edit form. */
const profileFormRules = computed(() => ({
    name: [
        { required: true, message: t('text.validation.profile_name_required'), trigger: 'blur' },
        { min: 2, message: t('text.validation.profile_name_min'), trigger: 'blur' },
    ],
    slug: [
        { required: true, message: t('text.validation.profile_slug_required'), trigger: 'blur' },
        { pattern: /^[a-z0-9-]+$/, message: t('text.validation.profile_slug_pattern'), trigger: 'blur' },
    ],
}))

const selectedCapabilityMeta = computed(() => capabilityMetaMap[profileDraft.capabilityClass] || null)

/** Open the modal in create mode with a fresh draft. */
function openCreateProfile() {
    profileDraft.name = ''
    profileDraft.slug = ''
    profileDraft.capabilityClass = 'class_0_read_only'
    profileDraft.budgetPreset = 'standard'
    profileModalMode.value = 'create'
    profileModalOpen.value = true
}

function openEditProfile(profile) {
    profileModalMode.value = 'edit'
    profileDraft.name = profile.name
    profileDraft.slug = profile.slug
    profileDraft.capabilityClass = profile.capability_class
    profileDraft.budgetPreset = 'standard'
    profileModalOpen.value = true
}

async function saveProfile() {
    try {
        await profileFormRef.value?.validate()
    } catch {
        return
    }

    const budgetConfig = budgetPresetMetaMap[profileDraft.budgetPreset]?.config || { max_steps: 5 }
    const payload = {
        name: profileDraft.name.trim(),
        slug: profileDraft.slug.trim(),
        capability_class: profileDraft.capabilityClass,
        budget_config: budgetConfig,
        status: 'active',
    }

    if (profileModalMode.value === 'create') {
        await handleCreateProfile(payload)
    } else {
        await handleUpdateProfile(profileDraft.slug, payload)
    }
    profileModalOpen.value = false
}
</script>
