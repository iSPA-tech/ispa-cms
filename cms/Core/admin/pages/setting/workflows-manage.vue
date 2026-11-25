<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-6">
        <WorkflowGlobalPause @changed="(value) => (pausedAll = value)" />

        <WorkflowsToolbar
            :can-create="canCreateWorkflow"
            :filters="filters"
            :pending-count="pending.rows.length"
            :show-infrastructure="filters.showInfrastructure"
            :show-pending="canPublishWorkflow"
            :stats="stats"
            :status-options="statusOptions"
            :tag-options="tagOptions"
            @create="openCreateForm"
            @library="openLibrary"
            @pending="pending.open = true"
            @update:search="(value) => (filters.search = value)"
            @update:status="(value) => (filters.status = value)"
            @update:tags="(value) => (filters.tags = value)"
            @update:show-infrastructure="(value) => (filters.showInfrastructure = value)"
        />

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.8fr)_minmax(0,1fr)]">
            <WorkflowList
                :activation-state="activationState"
                :can-delete="canDeleteWorkflow"
                :can-export="canExportWorkflow"
                :can-update="canUpdateWorkflow"
                :deleting-id="deletingId"
                :exporting-slug="exportingSlug"
                :loading="isLoading || isFetching"
                :selected-id="selectedWorkflow?.id || null"
                :setup-counts="setupCounts"
                :toggling-id="togglingId"
                :workflows="filteredWorkflows"
                @delete="removeWorkflow"
                @edit="openEditForm"
                @export="exportWorkflow"
                @select="selectWorkflow"
                @setup="openSetup"
                @toggle-active="toggleActive"
                @open-builder="openBuilder"
            />

            <div class="space-y-4">
                <WorkflowDetailCard
                    :activation-state="activationState"
                    :can-delete="canDeleteWorkflow"
                    :can-export="canExportWorkflow"
                    :can-update="canUpdateWorkflow"
                    :deleting-id="deletingId"
                    :exporting-slug="exportingSlug"
                    :workflow="selectedWorkflow"
                    @delete="removeWorkflow"
                    @edit="openEditForm"
                    @export="exportWorkflow"
                    @history="openHistory"
                    @open-builder="openBuilder"
                    @resumed="refreshGuardState"
                />
                <WorkflowRunPanel
                    v-if="selectedWorkflow"
                    :can-run="selectedAbilities.canRun"
                    :can-update="canUpdateWorkflow"
                    :is-active="Boolean(activationState[selectedWorkflow.id])"
                    :never-published="needsPublishBeforeOn(selectedWorkflow)"
                    :paused-all="pausedAll"
                    :workflow="selectedWorkflow"
                    @changed="refetch"
                />
            </div>
        </div>
    </div>

    <WorkflowFormModal
        ref="formModalRef"
        :activation-state="activationState"
        :tag-options="tagOptions"
        :target-options="targetOptions"
        :trigger-options="triggerOptions"
        @saved="handleWorkflowSaved"
    />

    <WorkflowBuilderModal
        :grouped-functions="groupedFunctions"
        :open="builderState.open"
        :workflow="builderState.project"
        @close="closeBuilder"
        @published="refreshGuardState"
        @saved="handleBuilderSaved"
    />

    <WorkflowVersionDrawer
        :can-publish="historyAbilities.canPublish"
        :names="historyNames"
        :open="historyState.open"
        :workflow="historyState.project"
        @changed="refreshGuardState"
        @close="historyState.open = false"
    />

    <WorkflowPendingChanges
        :load-error="pending.error"
        :loading="pending.loading"
        :open="pending.open"
        :rows="pending.rows"
        @changed="refetch"
        @close="pending.open = false"
        @reload="loadPending"
    />
</template>

<script setup>
import { computed, h, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { message, Modal } from 'ant-design-vue'
import { iAlert, rule } from '@/mixins'
import { useWorkflowFunctions, useWorkflows } from '@/hooks'
import { deleteWorkflow, updateWorkflow } from '@/libs'
import { downloadWorkflowPackage } from '../../workflow-package-api'
import { describeProblems } from '../../workflow-package-report'
import { normalizeWorkflowProject } from './workflows-manage/workflow-project-utils'
import WorkflowBuilderModal from './workflows-manage/components/workflow-builder-modal.vue'
import WorkflowDetailCard from './workflows-manage/components/workflow-detail-card.vue'
import WorkflowFormModal from './workflows-manage/components/workflow-form-modal.vue'
import WorkflowRunPanel from './workflows-manage/components/workflow-run-panel.vue'
import WorkflowList from './workflows-manage/components/workflow-list.vue'
import WorkflowsToolbar from './workflows-manage/components/workflows-toolbar.vue'
import WorkflowGlobalPause from './workflows-manage/components/workflow-global-pause.vue'
import WorkflowPendingChanges from './workflows-manage/components/workflow-pending-changes.vue'
import WorkflowVersionDrawer from './workflows-manage/components/workflow-version-drawer.vue'
import { NOT_PUBLISHED_CODES, fetchPendingChanges, guardErrorCode } from '../../workflow-guard-api'
import { needsPublishBeforeOn, stepNames, workflowAbilities } from '../../workflow-guard-report'
import { fetchWorkflowSetup, openQuestionCount } from '../../workflow-setup-api'

defineOptions({
    name: 'WorkflowsManage',
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const filters = reactive({
    search: '',
    status: 'all',
    tags: [],
    showInfrastructure: false,
})

const {
    data: workflowsQuery,
    isLoading,
    isFetching,
    refetch,
} = useWorkflows(
    {},
    {
        refetchOnWindowFocus: false,
    }
)

const { data: functionListQuery } = useWorkflowFunctions({
    refetchOnWindowFocus: false,
})

const workflows = computed(() => {
    const raw = workflowsQuery.value
    if (!raw) {
        return []
    }

    if (Array.isArray(raw)) {
        return raw.map(normalizeWorkflowProject)
    }

    if (Array.isArray(raw.data)) {
        return raw.data.map(normalizeWorkflowProject)
    }

    return []
})

const availableTags = computed(() => {
    const tagSet = new Set()
    workflows.value.forEach((item) => {
        ;(item.tags || []).forEach((tag) => tagSet.add(tag))
    })
    return Array.from(tagSet)
})

const tagOptions = computed(() =>
    availableTags.value.map((tag) => ({
        label: tag,
        value: tag,
    }))
)

watch(
    availableTags,
    (tags) => {
        filters.tags = (filters.tags || []).filter((tag) => tags.includes(tag))
    },
    { immediate: true }
)

const triggerSuggestions = computed(() => {
    const set = new Set()
    workflows.value.forEach((item) => {
        if (item.trigger_event) {
            set.add(item.trigger_event)
        }
    })
    return Array.from(set)
})

const targetEntitySuggestions = computed(() => {
    const set = new Set()
    workflows.value.forEach((item) => {
        if (item.target_entity_type) {
            set.add(item.target_entity_type)
        }
    })
    return Array.from(set)
})

const triggerOptions = computed(() =>
    triggerSuggestions.value.map((item) => ({
        value: item,
    }))
)

const targetOptions = computed(() =>
    targetEntitySuggestions.value.map((item) => ({
        value: item,
    }))
)

const statusOptions = computed(() => [
    { value: 'all', label: t('text.workflow.catalog.filter_all') },
    { value: 'active', label: t('text.workflow.catalog.filter_active') },
    { value: 'inactive', label: t('text.workflow.catalog.filter_inactive') },
])

const visibleWorkflows = computed(() => {
    if (filters.showInfrastructure) {
        return workflows.value
    }

    return workflows.value.filter((item) => !item.system_managed)
})

const filteredWorkflows = computed(() => {
    let list = [...visibleWorkflows.value]

    if (filters.status !== 'all') {
        const expectActive = filters.status === 'active'
        list = list.filter((item) => item.is_active === expectActive)
    }

    if (filters.tags.length) {
        list = list.filter((item) => {
            const tags = item.tags || []
            return filters.tags.every((tag) => tags.includes(tag))
        })
    }

    if (filters.search) {
        const keyword = filters.search.toLowerCase()
        list = list.filter((item) => {
            return (
                item.name?.toLowerCase().includes(keyword) ||
                item.slug?.toLowerCase().includes(keyword) ||
                item.trigger_event?.toLowerCase().includes(keyword) ||
                item.target_entity_type?.toLowerCase().includes(keyword)
            )
        })
    }

    return list
})

const stats = computed(() => ({
    total: visibleWorkflows.value.length,
    active: visibleWorkflows.value.filter((item) => item.is_active).length,
    inactive: visibleWorkflows.value.filter((item) => !item.is_active).length,
}))

const selectedWorkflow = ref(null)

watch(
    filteredWorkflows,
    (list) => {
        if (!list.length) {
            selectedWorkflow.value = null
            return
        }

        const matched = list.find((item) => item.id === selectedWorkflow.value?.id)
        selectedWorkflow.value = matched || list[0] || null
    },
    { immediate: true }
)

const selectWorkflow = (workflow) => {
    selectedWorkflow.value = workflow
}

const activationState = reactive({})

watch(
    () => workflows.value,
    (list) => {
        const validIds = new Set(list.map((item) => item.id))
        Object.keys(activationState).forEach((key) => {
            if (!validIds.has(Number(key))) {
                delete activationState[key]
            }
        })

        list.forEach((item) => {
            activationState[item.id] = item.is_active
        })
    },
    { immediate: true }
)

const togglingId = ref(null)

/** A workflow that was never published has nothing to run: say so and offer the designer, where it is published. */
const explainNotPublished = (workflow) => {
    Modal.confirm({
        title: t('text.workflow.guard.activate.not_published_title'),
        content: t('text.workflow.guard.activate.not_published_body'),
        okText: t('text.workflow.guard.paused.open_designer'),
        cancelText: t('text.workflow.guard.close'),
        onOk: () => openBuilder(workflow),
    })
}

const toggleActive = async (workflow, value) => {
    if (workflow?.system_managed) {
        activationState[workflow.id] = !value
        message.warning(t('text.workflow.list.system_managed_toggle_warn'))
        return
    }

    if (value && needsPublishBeforeOn(workflow)) {
        activationState[workflow.id] = false
        explainNotPublished(workflow)
        return
    }

    togglingId.value = workflow.id
    try {
        const res = await updateWorkflow(workflow.id, { is_active: value })
        if (res?.status === 'success') {
            iAlert(res)
            await refetch()
        } else {
            activationState[workflow.id] = !value
            iAlert(res, true)
        }
    } catch (error) {
        activationState[workflow.id] = !value
        if (NOT_PUBLISHED_CODES.includes(guardErrorCode(error))) explainNotPublished(workflow)
        else iAlert(error, true)
    } finally {
        togglingId.value = null
    }
}

const deletingId = ref(null)

const builderState = reactive({
    open: false,
    project: null,
})

const closeBuilder = () => {
    builderState.open = false
    builderState.project = null
}

const openBuilder = (workflow) => {
    if (workflow?.system_managed) {
        message.warning(t('text.workflow.list.system_managed_edit_warn'))
        return
    }

    builderState.project = workflow
    builderState.open = true
}

const removeWorkflow = async (workflow) => {
    if (workflow?.system_managed) {
        message.warning(t('text.workflow.list.system_managed_delete_warn'))
        return
    }

    deletingId.value = workflow.id
    try {
        const res = await deleteWorkflow(workflow.id)
        if (res?.status === 'success') {
            iAlert(res)
            if (builderState.project?.id === workflow.id) {
                closeBuilder()
            }
            await refetch()
        } else {
            iAlert(res, true)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        deletingId.value = null
    }
}

const functionLibrary = computed(() => {
    const raw = functionListQuery.value
    if (!raw) {
        return []
    }
    if (Array.isArray(raw)) {
        return raw
    }
    if (Array.isArray(raw.data)) {
        return raw.data
    }
    return []
})

const categoryLabels = {
    service: 'Services',
    job: 'Jobs',
    command: 'Commands',
    event: 'Events',
    unknown: 'Other',
}

const groupedFunctions = computed(() => {
    const groups = functionLibrary.value.reduce((acc, item) => {
        const key = item.category || 'unknown'
        if (!acc[key]) {
            acc[key] = []
        }
        acc[key].push(item)
        return acc
    }, {})

    return Object.entries(groups).map(([category, items]) => ({
        category,
        label: categoryLabels[category] || category,
        items,
    }))
})

const formModalRef = ref(null)

const openCreateForm = () => {
    formModalRef.value?.openCreate()
}

const openEditForm = (workflow) => {
    if (workflow?.system_managed) {
        message.warning(t('text.workflow.list.system_managed_edit_warn'))
        return
    }

    formModalRef.value?.openEdit(workflow)
}

const handleWorkflowSaved = async (payload) => {
    let workflow = payload?.workflow ? normalizeWorkflowProject(payload.workflow) : null

    await refetch()

    // The create answer carries no record: find the new workflow in the refreshed list by its slug.
    if (!workflow && payload?.slug) {
        workflow = workflows.value.find((item) => item.slug === payload.slug) || null
    }

    if (workflow) {
        selectedWorkflow.value = workflow
    }

    // A new workflow goes straight to the designer.
    if (payload?.openBuilder && workflow) {
        openBuilder(workflow)
    }
}

const handleBuilderSaved = async (workflow) => {
    const normalized = workflow ? normalizeWorkflowProject(workflow) : null

    if (normalized) {
        builderState.project = normalized
        selectedWorkflow.value = normalized
    }

    await refetch()
    syncOpenProjects()
}

// ---------------------------------------------------------------------------------------------------------------------
// Guardrails: versions, changes waiting for review, pauses
// ---------------------------------------------------------------------------------------------------------------------

/** True while a Webmaster has paused all workflows (only Webmasters are told). */
const pausedAll = ref(false)

/** The designer and the history keep their own copy of a row: give them the freshly read one (version, pause, abilities). */
const syncOpenProjects = () => {
    const fresh = (project) => (project ? workflows.value.find((item) => item.id === project.id) || project : project)
    if (builderState.open) builderState.project = fresh(builderState.project)
    if (historyState.open) historyState.project = fresh(historyState.project)
}

const historyState = reactive({ open: false, project: null })
const openHistory = (workflow) => {
    historyState.project = workflow
    historyState.open = true
}
const historyAbilities = computed(() => workflowAbilities(historyState.project, rule))
const historyNames = computed(() => stepNames(historyState.project?.nodes))
const selectedAbilities = computed(() => workflowAbilities(selectedWorkflow.value, rule))

const pending = reactive({ open: false, rows: [], loading: false, error: false })

/** Sensitive changes that are not live yet; only accounts that may publish are told. */
const loadPending = async () => {
    if (!canPublishWorkflow.value || pending.loading) return
    pending.loading = true
    try {
        pending.rows = await fetchPendingChanges()
        pending.error = false
    } catch (error) {
        pending.rows = []
        pending.error = true
    } finally {
        pending.loading = false
    }
}

/** After a publish, a rollback, an approval or a resume: read the rows and the waiting list again. */
const refreshGuardState = async () => {
    await refetch()
    syncOpenProjects()
    loadPending()
}

const canCreateWorkflow = computed(() => rule('workflow_project.create'))
const canUpdateWorkflow = computed(() => rule('workflow_project.update'))
const canDeleteWorkflow = computed(() => rule('workflow_project.delete'))
const canExportWorkflow = computed(() => rule('workflow_project.viewAny'))
/** Publish, roll back, approve and resume; rows answer for themselves (`can_publish`) where the server says so. */
const canPublishWorkflow = computed(() => rule('workflow_project.publish') || workflows.value.some((item) => item.can_publish === true))

watch(canPublishWorkflow, (value) => value && loadPending(), { immediate: true })

const openLibrary = () => router.push({ name: 'setting-workflows-library' })

// "Needs setup (n)": a workflow that is switched off (a sample just installed) may still wait for choices only this
// site can make. Asked once per workflow, quietly; a failed request simply shows no badge.
const setupCounts = reactive({})
const setupAsked = new Set()
watch(
    () => workflows.value.filter((item) => !item.is_active && !item.system_managed).map((item) => item.id).join(','),
    (ids) => {
        ids.split(',').filter((id) => id && !setupAsked.has(id)).slice(0, 30).forEach(async (id) => {
            setupAsked.add(id)
            try {
                setupCounts[id] = openQuestionCount(await fetchWorkflowSetup(id))
            } catch (error) {
                setupCounts[id] = 0
            }
        })
    },
    { immediate: true }
)
const openSetup = (workflow) => router.push({ name: 'setting-workflows-setup', params: { project: workflow.id } })

/** Slug of the workflow whose package is being downloaded. */
const exportingSlug = ref(null)

/** Download a workflow as a package file for another site; a refusal is explained in plain words. */
const exportWorkflow = async (workflow) => {
    if (exportingSlug.value || !workflow?.slug) return
    exportingSlug.value = workflow.slug
    try {
        await downloadWorkflowPackage(workflow.slug)
        message.success(t('text.workflow.package.export_done'))
    } catch (error) {
        const names = Object.fromEntries((workflow.nodes || []).map((node) => [node.step_code || node.data?.step_code, node.name || node.data?.label]).filter(([code, name]) => code && name))
        const rows = describeProblems(error?.problems, { t, names })
        Modal.error({
            title: t('text.workflow.package.export_failed_title', { name: workflow.name }),
            content: rows.length
                ? h('ul', { class: 'm-0 space-y-1 pl-5 text-sm' }, rows.map((row) => h('li', { class: 'break-words' }, row.steps.length ? `${row.text} ${t('text.workflow.package.at_steps', { steps: row.steps.join(', ') })}` : row.text)))
                : t(error?.status === 403 ? 'text.workflow.package.export_failed_forbidden' : 'text.workflow.package.export_failed_hint'),
            okText: t('text.workflow.package.close'),
        })
    } finally {
        exportingSlug.value = null
    }
}

// A freshly installed package links here with `?open=<slug>`: select that workflow and open it in the designer.
// The cached list does not know the new workflow yet, so it is loaded again first.
watch(
    () => route.query.open,
    async (slug) => {
        if (typeof slug !== 'string' || !slug) return
        router.replace({ query: { ...route.query, open: undefined } })
        await refetch()
        const workflow = workflows.value.find((item) => item.slug === slug)
        if (!workflow) return
        filters.search = ''
        filters.status = 'all'
        filters.tags = []
        selectedWorkflow.value = workflow
        openBuilder(workflow)
    },
    { immediate: true }
)
</script>
