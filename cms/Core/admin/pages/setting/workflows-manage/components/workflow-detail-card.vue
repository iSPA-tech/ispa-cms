<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-card v-if="workflow" size="small">
        <template #title>
            <!-- The side column is narrow: the name, the state and the designer button wrap instead of being cut off. -->
            <div class="flex flex-wrap items-center justify-between gap-2 whitespace-normal py-2">
                <span class="min-w-0 break-words font-semibold text-gray-700">
                    {{ workflow.name }}
                </span>
                <div class="flex flex-wrap items-center gap-2">
                    <a-tag
                        v-if="workflow.system_managed"
                        color="orange"
                        class="uppercase text-[10px] tracking-wide"
                    >
                        {{ t('text.workflow.list.system_managed') }}
                    </a-tag>
                    <a-tag
                        :color="activationState[workflow.id] ? 'green' : 'default'"
                        class="uppercase text-[10px] tracking-wide"
                    >
                        {{ activationState[workflow.id] ? t('text.workflow.list.active') : t('text.workflow.list.inactive') }}
                    </a-tag>
                    <a-button
                        type="primary"
                        size="small"
                        :disabled="workflow.system_managed"
                        :icon="h(BranchesOutlined)"
                        @click="$emit('open-builder', workflow)"
                    >
                        {{ t('text.workflow.detail.update_flow') }}
                    </a-button>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <WorkflowPausedAlert
                :can-publish="abilities.canPublish"
                :workflow="workflow"
                @open-builder="$emit('open-builder', workflow)"
                @resumed="$emit('resumed', workflow)"
            />

            <!-- Which version runs, whether the draft is ahead of it, and the way into the history. -->
            <div class="rounded-lg border border-solid border-slate-200 px-3 py-3" data-testid="detail-version">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0 space-y-1">
                        <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.guard.draft.section') }}</div>
                        <div class="text-sm font-medium text-gray-700" data-testid="detail-version-text">{{ versionText }}</div>
                    </div>
                    <a-button size="small" :icon="h(HistoryOutlined)" data-testid="detail-history" @click="$emit('history', workflow)">
                        {{ t('text.workflow.guard.history.open') }}
                    </a-button>
                </div>
                <div v-if="state.draftDiffers || state.pending" class="mt-2 flex flex-wrap gap-2">
                    <a-tag v-if="state.draftDiffers" color="gold" class="!m-0" data-testid="detail-draft-differs">{{ t('text.workflow.guard.draft.differs') }}</a-tag>
                    <a-tag v-if="state.pending" color="orange" class="!m-0" data-testid="detail-pending">
                        {{ t('text.workflow.guard.pending.count_tag', { count: state.pending }) }}
                    </a-tag>
                </div>
                <p v-if="state.known && !state.published" class="m-0 mt-2 text-xs text-gray-500">{{ t('text.workflow.guard.draft.never_published_hint') }}</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.trigger') }}</div>
                    <div class="mt-1 text-sm font-medium text-gray-700 break-all">
                        {{ workflow.trigger_event || '—' }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.target') }}</div>
                    <div class="mt-1 text-sm font-medium text-gray-700 break-all">
                        {{ workflow.target_entity_type || '—' }}
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-slate-200 px-3 py-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.graph') }}</div>
                    <div class="mt-1 text-sm font-medium text-gray-700">
                        {{ t('text.workflow.detail.graph_steps_links', { steps: summary.nodeCount, links: summary.edgeCount }) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ t('text.workflow.detail.branching_node', { count: summary.decisionCount }) }}
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 px-3 py-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.validation') }}</div>
                    <div class="mt-1">
                        <a-tag :color="summary.validationTone">
                            {{ summary.validationState }}
                        </a-tag>
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ t('text.workflow.detail.inputs_outputs', { inputs: summary.inputCount, outputs: summary.outputCount }) }}
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 px-3 py-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">
                        {{ t('text.workflow.detail.editing_ownership') }}
                    </div>
                    <span class="text-xs text-gray-500">{{ workflow.slug }}</span>
                </div>
                <div class="mt-1 text-sm text-gray-700">
                    {{
                        workflow.system_managed
                            ? t('text.workflow.detail.managed_help')
                            : t('text.workflow.detail.editable_help')
                    }}
                </div>
            </div>

            <div class="rounded-lg border border-dashed border-slate-200 px-3 py-3">
                <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.detail.description') }}</div>
                <div class="mt-1 text-sm text-gray-600">
                    {{ workflow.description || t('text.workflow.detail.no_summary') }}
                </div>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
            <span>{{ t('text.workflow.detail.created') }} {{ formatDate(workflow.created_at) }}</span>
            <span>{{ t('text.workflow.detail.last_updated') }} {{ formatDate(workflow.updated_at) }}</span>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <a-button
                v-if="canUpdate"
                :disabled="workflow.system_managed"
                :icon="h(EditOutlined)"
                @click="$emit('edit', workflow)"
            >
                {{ $t('nav.b.edit') }}
            </a-button>
            <a-button
                v-if="canExport"
                :icon="h(DownloadOutlined)"
                :loading="exportingSlug === workflow.slug"
                data-testid="workflow-export-detail"
                @click="$emit('export', workflow)"
            >
                {{ t('text.workflow.package.export_action') }}
            </a-button>
            <a-popconfirm
                v-if="canDelete && !workflow.system_managed"
                :title="$t('text.del-confirm')"
                :ok-text="$t('nav.b.delete')"
                :cancel-text="$t('nav.b.cancel')"
                ok-type="danger"
                @confirm="$emit('delete', workflow)"
            >
                <a-button :icon="h(DeleteOutlined)" danger :loading="deletingId === workflow.id">
                    {{ $t('nav.b.delete') }}
                </a-button>
            </a-popconfirm>
        </div>
    </a-card>
    <a-card v-else size="small">
        <a-empty :description="t('text.workflow.detail.select_workflow')" />
    </a-card>
</template>

<script setup>
import dayjs from 'dayjs'
import { BranchesOutlined, DeleteOutlined, DownloadOutlined, EditOutlined, HistoryOutlined } from '@ant-design/icons-vue'
import { computed, h } from 'vue'
import { useI18n } from 'vue-i18n'
import { rule } from '@/mixins'
import { versionState, workflowAbilities } from '../../../../workflow-guard-report'
import { summarizeWorkflowProject } from '../workflow-project-utils'
import WorkflowPausedAlert from './workflow-paused-alert.vue'

defineOptions({
    name: 'WorkflowDetailCard',
})

const { t } = useI18n()

const props = defineProps({
    workflow: {
        type: Object,
        default: null,
    },
    activationState: {
        type: Object,
        default: () => ({}),
    },
    canUpdate: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    canExport: {
        type: Boolean,
        default: false,
    },
    exportingSlug: {
        type: String,
        default: null,
    },
    deletingId: {
        type: [Number, String],
        default: null,
    },
})

defineEmits(['edit', 'delete', 'open-builder', 'export', 'history', 'resumed'])

const formatDate = (value) => (value ? dayjs(value).format('MMM D, YYYY HH:mm') : '—')
const summary = computed(() => summarizeWorkflowProject(props.workflow || {}))

const abilities = computed(() => workflowAbilities(props.workflow, rule))
const state = computed(() => versionState(props.workflow))
const versionText = computed(() => {
    if (!state.value.known) return t('text.workflow.guard.draft.unknown')
    if (!state.value.published) return t('text.workflow.guard.draft.never_published')
    return state.value.number === null
        ? t('text.workflow.guard.draft.running')
        : t('text.workflow.guard.draft.running_version', { number: state.value.number })
})
</script>
