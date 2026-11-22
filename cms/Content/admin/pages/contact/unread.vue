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
                text: $t('nav.a.contact-unread'),
                title: $t('nav.a.contact-unread'),
                icon: 'email',
            },
        ]"
    />
    <div class="i-box">
        <a-table
            :columns="columns"
            :custom-row="
                (record) => ({
                    onClick: () =>
                        router.push({ name: 'email-view', params: { uuid: record.uuid } }),
                })
            "
            :data-source="dataRef?.data?.data || []"
            rowClassName="cursor-pointer"
            size="small"
        >
            <template #headerCell="{ column }">
                <span v-if="Array.isArray(column.title)" class="whitespace-nowrap">
                    {{ column.title.map((t) => $t(t)).join(' ') }}
                </span>
            </template>

            <template #bodyCell="{ column: col, record: row }">
                <template v-if="col.key === 'from_name'">
                    <div class="whitespace-nowrap">{{ row.from_name }}</div>
                </template>
                <template v-if="col.key === 'subject'">
                    <div class="flex items-center">
                        <div class="flex-1 flex items-center space-x-2">
                            <span
                                class="font-semibold whitespace-nowrap max-w-[200px] line-clamp-1"
                            >
                                {{ row.subject }}
                            </span>
                            <p class="line-clamp-1 m-0">
                                {{ row.content }}
                            </p>
                        </div>
                        <div>
                            {{ row.created_at }}
                        </div>
                    </div>
                </template>
            </template>
        </a-table>
    </div>
</template>

<script setup>
import { defineComponent, inject, onMounted, reactive, ref } from 'vue'
import { useEmails } from '@/hooks'
import { useRoute, useRouter } from 'vue-router'
import { initFilter } from '@/mixins'

defineComponent({
    name: 'UnreadEmail',
})

const getCounter = inject('getCounter')

const route = useRoute()
const router = useRouter()
const queryData = reactive(initFilter({ type: 'unread' }, route.query))
const { data: dataRef } = useEmails(queryData, {
    keepPreviousData: true,
})
const columns = ref([
    {
        key: 'from_name',
        title: ['nav.a.contacts'],
    },
    {
        key: 'subject',
        title: ['validation.attributes.subject'],
    },
])

onMounted(() => {
    getCounter()
})
</script>
