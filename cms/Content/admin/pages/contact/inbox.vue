<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                name: 'contact-dashboard',
                title: $t('nav.a.contacts'),
                icon: 'email-outline',
            },
            {
                text: $t('nav.a.inbox'),
                title: $t('nav.a.inbox'),
                icon: 'inbox',
            },
        ]"
    >
        <router-link
            :to="{ name: 'email-compose' }"
            class="ml-auto text-gray-800 rounded-sm text-xs bg-blk-200/60 hover:bg-blk-300/80 transition-all px-2 py-1.5 inline-flex items-center space-x-1"
        >
            <i-icon icon="email-edit-outline" class="size-4" />
            <span>{{ $t('nav.a.email-send') }}</span>
        </router-link>
    </breadcrumbs>

    <div class="i-box">
        <div class="bg-white">
            <div class="border-b border-gray-200">
                <div class="flex items-center px-4 py-2 space-x-4">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        class="px-3 py-2 text-sm font-medium transition-all rounded-t-md border-b-2"
                        :class="[
                            activeTab === tab.key
                                ? 'border-indigo-500 text-indigo-600'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                        ]"
                        @click="switchTab(tab.key)"
                    >
                        {{ tab.label }}
                        <span
                            v-if="tab.key === 'unread' && unreadCount > 0"
                            class="ml-1.5 text-xs bg-rose-100 text-rose-600 rounded-full px-1.5 py-0.5"
                        >
                            {{ unreadCount }}
                        </span>
                    </button>
                </div>
            </div>

            <a-table
                :columns="columns"
                :custom-row="
                    (record) => ({
                        onClick: () =>
                            router.push({ name: 'email-view', params: { uuid: record.uuid } }),
                    })
                "
                :data-source="dataRef?.data?.data || []"
                :loading="isFetching"
                :pagination="{
                    pageSize: dataRef?.data?.per_page || 15,
                    current: dataRef?.data?.current_page || 1,
                    total: dataRef?.data?.total || 0,
                    showTotal: (total, range) => {
                        return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t('pagination.of')} ${total} ${$t(
                            'pagination.results'
                        )}`
                    },
                }"
                rowClassName="cursor-pointer"
                size="small"
                @change="tableChange"
            >
                <template #headerCell="{ column }">
                    <span v-if="Array.isArray(column.title)" class="whitespace-nowrap">
                        {{ column.title.map((t) => $t(t)).join(' ') }}
                    </span>
                </template>

                <template #bodyCell="{ column: col, record: row }">
                    <template v-if="col.key === 'from_name'">
                        <div class="whitespace-nowrap font-medium">
                            {{ row?.contact?.name || row.from_name || '—' }}
                        </div>
                        <p class="text-xs text-gray-500 m-0">
                            {{ row.from_email || row?.contact?.email || '' }}
                        </p>
                    </template>
                    <template v-if="col.key === 'subject'">
                        <div class="flex items-center">
                            <div class="flex-1 flex items-center space-x-2 min-w-0">
                                <span
                                    v-if="!row.last_read"
                                    class="size-2 bg-indigo-500 rounded-full shrink-0"
                                    :title="$t('nav.a.contact-unread')"
                                />
                                <span
                                    class="font-semibold whitespace-nowrap max-w-[200px] line-clamp-1"
                                >
                                    {{ row.subject }}
                                </span>
                                <p class="line-clamp-1 m-0 text-gray-500">
                                    {{ row.content }}
                                </p>
                            </div>
                            <div class="text-xs text-gray-400 whitespace-nowrap ml-4">
                                {{ row.created_at }}
                            </div>
                        </div>
                    </template>
                    <template v-if="col.key === 'type'">
                        <a-tag :color="row.type === 'sent' ? 'blue' : 'green'" class="text-xs">
                            {{ row.type }}
                        </a-tag>
                    </template>
                </template>
            </a-table>
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, onMounted, reactive, ref, watch } from 'vue'
import { useEmails } from '@/hooks'
import { useRoute, useRouter } from 'vue-router'
import { initFilter } from '@/mixins'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'InboxEmail',
})

const { t } = useI18n()
const getCounter = inject('getCounter')

const route = useRoute()
const router = useRouter()

const activeTab = ref(route.query.type || 'inbox')
const unreadCount = ref(0)

const tabs = computed(() => [
    { key: 'inbox', label: t('nav.a.inbox') },
    { key: 'unread', label: t('nav.a.contact-unread') },
    { key: 'sent', label: t('nav.a.email-sent') },
])

const queryData = reactive(initFilter({ type: activeTab.value }, route.query))
const { data: dataRef, isFetching, refetch } = useEmails(queryData, {
    keepPreviousData: true,
})

const columns = ref([
    {
        key: 'from_name',
        title: ['nav.a.contacts'],
        width: 180,
    },
    {
        key: 'subject',
        title: ['validation.attributes.subject'],
    },
    {
        key: 'type',
        title: ['validation.attributes.type'],
        width: 80,
    },
])

const switchTab = (tab) => {
    activeTab.value = tab
    queryData.type = tab
    queryData.page = 1
    router.push({
        name: route.name,
        query: { ...route.query, type: tab, page: 1 },
    })
}

const tableChange = (pagination) => {
    queryData.page = pagination.current
    queryData.pageSize = pagination.pageSize
    router.push({
        name: route.name,
        query: { ...route.query, page: pagination.current, pageSize: pagination.pageSize },
    })
}

watch(
    () => route.query,
    (val) => {
        if (val.type) {
            activeTab.value = val.type
        }
        Object.assign(queryData, initFilter({ type: activeTab.value }, val))
    }
)

onMounted(() => {
    getCounter()
})
</script>
