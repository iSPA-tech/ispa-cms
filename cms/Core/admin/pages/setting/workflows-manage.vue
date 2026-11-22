<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-6">
        <WorkflowsToolbar
            :can-create="canCreateWorkflow"
            :filters="filters"
            :show-infrastructure="filters.showInfrastructure"
            :stats="stats"
            :status-options="statusOptions"
            :tag-options="tagOptions"
            @create="openCreateForm"
            @update:search="(value) => (filters.search = value)"
            @update:status="(value) => (filters.status = value)"
            @update:tags="(value) => (filters.tags = value)"
            @update:show-infrastructure="(value) => (filters.showInfrastructure = value)"
        />

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.8fr)_minmax(0,1fr)]">
            <WorkflowList
                :activation-state="activationState"
                :can-delete="canDeleteWorkflow"
                :can-update="canUpdateWorkflow"
                :deleting-id="deletingId"
                :loading="isLoading || isFetching"
                :selected-id="selectedWorkflow?.id || null"
                :toggling-id="togglingId"
                :workflows="filteredWorkflows"
                @delete="removeWorkflow"
                @edit="openEditForm"
                @select="selectWorkflow"
                @toggle-active="toggleActive"
                @open-builder="openBuilder"
            />

            <div class="space-y-4">
                <WorkflowDetailCard
                    :activation-state="activationState"
                    :can-delete="canDeleteWorkflow"
                    :can-update="canUpdateWorkflow"
                    :deleting-id="deletingId"
                    :workflow="selectedWorkflow"
                    @delete="removeWorkflow"
                    @edit="openEditForm"
                    @open-builder="openBuilder"
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
        @saved="handleBuilderSaved"
    />
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import { iAlert, rule } from '@/mixins'
import { useWorkflowFunctions, useWorkflows } from '@/hooks'
import { deleteWorkflow, updateWorkflow } from '@/libs'
import { normalizeWorkflowProject } from './workflows-manage/workflow-project-utils'
import WorkflowBuilderModal from './workflows-manage/components/workflow-builder-modal.vue'
import WorkflowDetailCard from './workflows-manage/components/workflow-detail-card.vue'
import WorkflowFormModal from './workflows-manage/components/workflow-form-modal.vue'
import WorkflowList from './workflows-manage/components/workflow-list.vue'
import WorkflowsToolbar from './workflows-manage/components/workflows-toolbar.vue'

defineOptions({
    name: 'WorkflowsManage',
})

const { t } = useI18n()

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

const toggleActive = async (workflow, value) => {
    if (workflow?.system_managed) {
        activationState[workflow.id] = !value
        message.warning(t('text.workflow.list.system_managed_toggle_warn'))
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
        iAlert(error, true)
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
    const workflow = payload?.workflow ? normalizeWorkflowProject(payload.workflow) : null

    if (workflow) {
        selectedWorkflow.value = workflow
    }

    if (payload?.openBuilder && workflow) {
        openBuilder(workflow)
    }

    await refetch()
}

const handleBuilderSaved = async (workflow) => {
    const normalized = workflow ? normalizeWorkflowProject(workflow) : null

    if (normalized) {
        builderState.project = normalized
        selectedWorkflow.value = normalized
    }

    await refetch()
}

const canCreateWorkflow = computed(() => rule('workflow_project.create'))
const canUpdateWorkflow = computed(() => rule('workflow_project.update'))
const canDeleteWorkflow = computed(() => rule('workflow_project.delete'))
</script>
