<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-card v-if="workflow" size="small">
        <template #title>
            <div class="flex items-center justify-between gap-3">
                <span class="font-semibold text-gray-700">
                    {{ workflow.name }}
                </span>
                <div class="flex items-center gap-2">
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
import { BranchesOutlined, DeleteOutlined, EditOutlined } from '@ant-design/icons-vue'
import { computed, h } from 'vue'
import { useI18n } from 'vue-i18n'
import { summarizeWorkflowProject } from '../workflow-project-utils'

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
    deletingId: {
        type: [Number, String],
        default: null,
    },
})

defineEmits(['edit', 'delete', 'open-builder'])

const formatDate = (value) => (value ? dayjs(value).format('MMM D, YYYY HH:mm') : '—')
const summary = computed(() => summarizeWorkflowProject(props.workflow || {}))
</script>
