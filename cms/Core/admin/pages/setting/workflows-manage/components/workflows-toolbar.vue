<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="i-box space-y-4">
        <div class="flex flex-col gap-4">
            <div class="flex-1 min-w-0">
                <h1 class="text-lg font-semibold text-gray-800">
                    {{ $t('nav.a.workflows') }}
                </h1>
                <p class="text-sm text-gray-500 max-w-3xl">
                    {{ t('text.workflow.catalog.subtitle') }}
                </p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <a-input-search
                    v-model:value="searchModel"
                    :placeholder="$t('text.f.search')"
                    allow-clear
                    class="w-full sm:w-64"
                />
                <a-select
                    v-model:value="statusModel"
                    :options="statusOptions"
                    class="w-full sm:w-44"
                />
                <a-select
                    v-model:value="tagsModel"
                    :options="tagOptions"
                    :placeholder="$t('nav.b.filter')"
                    allow-clear
                    class="w-full sm:w-48"
                    mode="multiple"
                />
                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <a-switch v-model:checked="showInfrastructureModel" size="small" />
                    <span>{{ t('text.workflow.catalog.show_infra') }}</span>
                </label>
                <div class="flex flex-wrap gap-2 sm:ml-auto">
                    <a-badge v-if="showPending" :count="pendingCount" :offset="[-4, 2]">
                        <a-button :icon="h(AuditOutlined)" data-testid="workflow-pending-open" @click="$emit('pending')">
                            {{ t('text.workflow.guard.pending.title') }}
                        </a-button>
                    </a-badge>
                    <!-- Sample workflows from iSPA; bringing in a file is offered on that page. -->
                    <a-button :icon="h(AppstoreOutlined)" data-testid="workflow-library" @click="$emit('library')">
                        {{ t('text.workflow.library.open_action') }}
                    </a-button>
                    <a-button v-if="canCreate" :icon="h(PlusOutlined)" type="primary" @click="$emit('create')">
                        {{ $t('nav.b.create') }}
                    </a-button>
                </div>
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <a-card :body-style="{ padding: '12px' }" size="small">
                <div class="text-xs uppercase text-gray-400">{{ t('text.workflow.catalog.stat_workflows') }}</div>
                <div class="text-lg font-semibold text-gray-800">{{ stats.total }}</div>
                <div class="text-xs text-gray-500">{{ t('text.workflow.catalog.stat_visible') }}</div>
            </a-card>
            <a-card :body-style="{ padding: '12px' }" size="small">
                <div class="text-xs uppercase text-green-500">{{ t('text.workflow.catalog.stat_active') }}</div>
                <div class="text-lg font-semibold text-gray-800">{{ stats.active }}</div>
                <div class="text-xs text-gray-500">{{ t('text.workflow.catalog.stat_active_desc') }}</div>
            </a-card>
            <a-card :body-style="{ padding: '12px' }" size="small">
                <div class="text-xs uppercase text-amber-500">{{ t('text.workflow.catalog.stat_inactive') }}</div>
                <div class="text-lg font-semibold text-gray-800">{{ stats.inactive }}</div>
                <div class="text-xs text-gray-500">{{ t('text.workflow.catalog.stat_inactive_desc') }}</div>
            </a-card>
        </div>
    </div>
</template>

<script setup>
import { AppstoreOutlined, AuditOutlined, PlusOutlined } from '@ant-design/icons-vue'
import { computed, h } from 'vue'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'WorkflowsToolbar',
})

const { t } = useI18n()

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    statusOptions: {
        type: Array,
        required: true,
    },
    tagOptions: {
        type: Array,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    canCreate: {
        type: Boolean,
        default: false,
    },
    showInfrastructure: {
        type: Boolean,
        default: false,
    },
    /** Accounts that may publish get the list of sensitive changes that are not live yet. */
    showPending: {
        type: Boolean,
        default: false,
    },
    pendingCount: {
        type: Number,
        default: 0,
    },
})

const emit = defineEmits([
    'update:search',
    'update:status',
    'update:tags',
    'update:show-infrastructure',
    'create',
    'library',
    'pending',
])

const searchModel = computed({
    get: () => props.filters.search,
    set: (value) => emit('update:search', value),
})

const statusModel = computed({
    get: () => props.filters.status,
    set: (value) => emit('update:status', value),
})

const tagsModel = computed({
    get: () => props.filters.tags,
    set: (value) => emit('update:tags', value),
})

const showInfrastructureModel = computed({
    get: () => props.showInfrastructure,
    set: (value) => emit('update:show-infrastructure', value),
})
</script>
