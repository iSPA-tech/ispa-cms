<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-spin :spinning="loading">
        <div v-if="workflows.length" class="space-y-3">
            <a-card
                v-for="workflow in workflows"
                :key="workflow.id"
                class="transition-all border border-transparent hover:border-cyan-400/60 hover:shadow-sm"
                :class="{
                    'border-cyan-500 shadow-md': selectedId === workflow.id,
                }"
                @click="$emit('select', workflow)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-800 truncate">
                                {{ workflow.name }}
                            </span>
                            <a-tag
                                v-if="workflow.system_managed"
                                color="orange"
                                class="uppercase tracking-wide text-[10px]"
                            >
                                {{ t('text.workflow.list.system_managed') }}
                            </a-tag>
                            <a-tag
                                :color="activationState[workflow.id] ? 'green' : 'default'"
                                class="uppercase tracking-wide text-[10px]"
                            >
                                {{ activationState[workflow.id] ? t('text.workflow.list.active') : t('text.workflow.list.inactive') }}
                            </a-tag>
                            <!-- Guardrails: paused, never published, draft ahead of what runs, changes waiting. -->
                            <a-tag v-if="pausedInfo(workflow)" :color="pausedInfo(workflow).kind === 'integrity' ? 'red' : 'orange'" data-testid="list-paused">
                                {{ t('text.workflow.guard.paused.tag') }}
                            </a-tag>
                            <template v-if="versionState(workflow).known">
                                <a-tag v-if="!versionState(workflow).published" data-testid="list-never-published">
                                    {{ t('text.workflow.guard.draft.never_published_tag') }}
                                </a-tag>
                                <a-tag v-else-if="versionState(workflow).draftDiffers" color="gold" data-testid="list-draft-differs">
                                    {{ t('text.workflow.guard.draft.differs') }}
                                </a-tag>
                            </template>
                            <a-tag
                                v-if="setupCounts[workflow.id] > 0"
                                color="gold"
                                class="cursor-pointer"
                                role="link"
                                tabindex="0"
                                data-testid="list-needs-setup"
                                @click.stop="$emit('setup', workflow)"
                                @keydown.enter.stop="$emit('setup', workflow)"
                            >
                                {{ t('text.workflow.setup.list_badge', { count: setupCounts[workflow.id] }) }}
                            </a-tag>
                            <a-tag v-if="versionState(workflow).pending" color="orange" data-testid="list-pending">
                                {{ t('text.workflow.guard.pending.count_tag', { count: versionState(workflow).pending }) }}
                            </a-tag>
                        </div>
                        <p v-if="pausedInfo(workflow)" class="m-0 text-xs text-amber-700" data-testid="list-paused-reason">
                            {{ t(pausedInfo(workflow).reasonKey) }}
                        </p>
                        <p class="text-xs text-gray-500 line-clamp-2">
                            {{ workflow.description || t('text.workflow.list.no_description') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        <!-- The switch hands its click handler the new state, not the event: the card click is stopped one level up. -->
                        <span v-if="canUpdate" class="inline-flex" @click.stop>
                            <a-switch
                                v-model:checked="activationState[workflow.id]"
                                size="small"
                                :disabled="workflow.system_managed"
                                :loading="togglingId === workflow.id"
                                @change="(value) => $emit('toggle-active', workflow, value)"
                            />
                        </span>
                        <a-tooltip>
                            <template #title>{{ workflow.system_managed ? t('text.workflow.list.managed_tooltip') : t('text.workflow.list.design_tooltip') }}</template>
                            <a-button
                                size="small"
                                type="text"
                                :disabled="workflow.system_managed"
                                :icon="h(BranchesOutlined)"
                                @click.stop="$emit('open-builder', workflow)"
                            />
                        </a-tooltip>
                        <a-tooltip v-if="canUpdate && !workflow.system_managed && setupTotals[workflow.id] > 0">
                            <template #title>{{ t('text.workflow.setup.nav') }}</template>
                            <a-button
                                size="small"
                                type="text"
                                :aria-label="t('text.workflow.setup.nav')"
                                :icon="h(ControlOutlined)"
                                data-testid="workflow-setup-open"
                                @click.stop="$emit('setup', workflow)"
                            />
                        </a-tooltip>
                        <a-tooltip v-if="canExport">
                            <template #title>{{ t('text.workflow.package.export_action') }}</template>
                            <a-button
                                size="small"
                                type="text"
                                :aria-label="t('text.workflow.package.export_action')"
                                :icon="h(DownloadOutlined)"
                                :loading="exportingSlug === workflow.slug"
                                data-testid="workflow-export"
                                @click.stop="$emit('export', workflow)"
                            />
                        </a-tooltip>
                        <a-tooltip v-if="canUpdate">
                            <template #title>{{ $t('nav.b.edit') }}</template>
                            <a-button
                                size="small"
                                type="text"
                                :disabled="workflow.system_managed"
                                :icon="h(EditOutlined)"
                                @click.stop="$emit('edit', workflow)"
                            />
                        </a-tooltip>
                        <a-popconfirm
                            v-if="canDelete && !workflow.system_managed"
                            :title="$t('text.del-confirm')"
                            :ok-text="$t('nav.b.delete')"
                            :cancel-text="$t('nav.b.cancel')"
                            ok-type="danger"
                            @confirm="$emit('delete', workflow)"
                        >
                            <a-button
                                size="small"
                                type="text"
                                :icon="h(DeleteOutlined)"
                                danger
                                :loading="deletingId === workflow.id"
                                @click.stop
                            />
                        </a-popconfirm>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-gray-500">
                    <a-tag v-for="tag in workflow.tags" :key="tag" color="blue">
                        {{ tag }}
                    </a-tag>
                    <span class="truncate">{{ `${t('text.workflow.list.trigger')}:` }} {{ workflow.trigger_event || '—' }}</span>
                    <span class="truncate">{{ `${t('text.workflow.list.target')}:` }} {{ workflow.target_entity_type || '—' }}</span>
                    <span class="truncate">{{ `${t('text.workflow.list.steps')}:` }} {{ workflow.nodes?.length || 0 }}</span>
                    <span class="truncate">{{ `${t('text.workflow.list.branches')}:` }} {{ workflow.summary?.decisionCount || 0 }}</span>
                </div>
                <div class="mt-4 grid gap-2 sm:grid-cols-3 text-xs text-gray-600">
                    <div class="rounded-lg bg-slate-50 px-3 py-2">
                        <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.graph') }}</div>
                        <div class="mt-1 font-medium text-gray-700">
                            {{ t('text.workflow.list.graph_summary', { steps: workflow.summary?.nodeCount || 0, links: workflow.summary?.edgeCount || 0 }) }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2">
                        <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.validation') }}</div>
                        <div class="mt-1 font-medium text-gray-700">
                            {{ workflow.summary?.validationState || t('text.workflow.validation.state_not_designed') }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2">
                        <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('text.workflow.list.slug') }}</div>
                        <div class="mt-1 text-gray-700 break-all">{{ workflow.slug }}</div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-400">
                    <span>{{ t('text.workflow.list.updated') }} {{ formatDate(workflow.updated_at) }}</span>
                    <span class="font-mono text-gray-500">#{{ workflow.id }}</span>
                </div>
            </a-card>
        </div>
        <a-empty
            v-else
            :description="t('text.workflow.list.empty')"
        />
    </a-spin>
</template>

<script setup>
import dayjs from 'dayjs'
import { BranchesOutlined, ControlOutlined, DeleteOutlined, DownloadOutlined, EditOutlined } from '@ant-design/icons-vue'
import { h } from 'vue'
import { useI18n } from 'vue-i18n'
import { pausedInfo, versionState } from '../../../../workflow-guard-report'

defineOptions({
    name: 'WorkflowList',
})

const { t } = useI18n()

defineProps({
    workflows: {
        type: Array,
        default: () => [],
    },
    selectedId: {
        type: [Number, String],
        default: null,
    },
    activationState: {
        type: Object,
        default: () => ({}),
    },
    /** `{ [workflowId]: n }`: how many setup questions a workflow has at all (answered or not): the setup button. */
    setupTotals: {
        type: Object,
        default: () => ({}),
    },
    /** `{ [workflowId]: n }`: choices the site still has to make for a workflow (the setup screen). */
    setupCounts: {
        type: Object,
        default: () => ({}),
    },
    togglingId: {
        type: [Number, String],
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
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

defineEmits(['select', 'toggle-active', 'open-builder', 'edit', 'delete', 'export', 'setup'])

const formatDate = (value) => (value ? dayjs(value).format('MMM D, YYYY HH:mm') : '—')
</script>
