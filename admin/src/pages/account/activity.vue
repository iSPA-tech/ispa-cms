<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">{{ t('text.acc.activity-history') }}</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ t('text.acc.activity-history-desc') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <app-stat-pill
                    icon="history"
                    :label="t('text.total')"
                    :value="activities?.total || 0"
                    variant="muted"
                />
                <router-link :to="{ name: 'account-dashboard' }">
                    <a-button size="small">{{ t('text.acc.back-to-overview') }}</a-button>
                </router-link>
            </div>
        </div>

        <a-spin :spinning="loadingTable" class="mt-6 block">
            <a-table
                size="small"
                :columns="columns"
                :data-source="activities.data || []"
                :locale="{ emptyText: t('text.acc.no-activity-logged') }"
                :pagination="activityPagination"
                @change="tableChange"
            >
                <template #bodyCell="{ column: col, record: row }">
                    <template v-if="col.key === 'method'">
                        <span
                            class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                            :class="{
                                'bg-slate-100 text-slate-600': row.options?.method === 'GET',
                                'bg-rose-100 text-rose-700': row.options?.method === 'DELETE',
                                'bg-cyan-100 text-cyan-700': row.options?.method === 'PUT',
                                'bg-emerald-100 text-emerald-700': row.options?.method === 'POST',
                            }"
                        >
                            {{ row.options?.method || t('text.acc.not-applicable') }}
                        </span>
                    </template>
                    <template v-else-if="col.key === 'action'">
                        {{ $t(row.action?.replace('action.', 'a.')) }}
                    </template>
                    <template v-else-if="col.key === 'target'">
                        <div class="space-y-1">
                            <p class="m-0 text-xs text-slate-400">
                                {{ formatTargetType(row.targetType) }}
                            </p>
                            <span class="text-sm text-slate-700">
                                {{ row.target?.name || t('text.acc.no-linked-target') }}
                            </span>
                        </div>
                    </template>
                    <template v-else-if="col.key === 'created_at'">
                        {{ formatTimestamp(row.createdAt, t('text.acc.unknown-time')) }}
                    </template>
                </template>
            </a-table>
        </a-spin>
    </section>
</template>

<script setup>
import { computed, defineComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { dateFormat, iAlert } from '@/mixins'

defineComponent({
    name: 'AccountActivity',
})

const { t } = useI18n()
const loadingTable = ref(false)
const activities = ref({})
const currentParams = ref({
    page: 1,
    pageSize: 20,
})

const columns = computed(() => [
    {
        key: 'method',
        dataIndex: 'method',
        title: t('text.acc.activity-method'),
        width: 110,
    },
    {
        key: 'action',
        dataIndex: 'action',
        title: t('text.action'),
    },
    {
        key: 'target',
        dataIndex: 'target',
        title: t('text.acc.activity-target'),
    },
    {
        key: 'created_at',
        dataIndex: 'created_at',
        title: t('text.date'),
        align: 'right',
        width: 180,
    },
])

const activityPagination = computed(() => ({
    pageSize: activities.value?.perPage || currentParams.value.pageSize,
    current: activities.value?.currentPage || currentParams.value.page,
    total: activities.value?.total || 0,
    showSizeChanger: true,
    pageSizeOptions: ['10', '20', '50', '100'],
    showTotal: (total, range) =>
        `${t('pagination.showing')} ${range[0]}-${range[1]} ${t('pagination.of')} ${total} ${t('pagination.results')}`,
}))

function formatTimestamp(value, fallback = t('text.acc.not-available')) {
    return value ? dateFormat(value, 'YYYY-MM-DD HH:mm') : fallback
}

function formatTargetType(value = '') {
    return (value || '')
        .replace('App\\Models\\', '')
        .replace('Cms\\Core\\Models\\', '')
}

function tableChange(pagination) {
    currentParams.value = {
        ...currentParams.value,
        page: pagination.current,
        pageSize: pagination.pageSize,
    }

    getActivities()
}

function getActivities() {
    loadingTable.value = true
    ajax.get('account/activities', { params: currentParams.value })
        .then((res) => {
            if (res.data.status === 'success') {
                activities.value = res.data.data
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingTable.value = false
        })
}

onMounted(() => {
    getActivities()
})
</script>
