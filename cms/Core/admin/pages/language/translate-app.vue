<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <i-filter :allowedFields="[]" module="translate" />

    <a-table
        :columns="columns"
        :data-source="dataRef?.data?.data || []"
        :pagination="{
            pageSize: dataRef?.data?.perPage || 10,
            current: dataRef?.data?.currentPage || 1,
            total: dataRef?.data?.total || 0,
            showSizeChanger: true,
            pageSizeOptions: ['10', '20', '50', '100'],
            showTotal: (total, range) => {
                return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t('pagination.of')} ${total} ${$t(
                    'pagination.results'
                )}`
            },
        }"
        class="w-full"
        row-key="id"
        size="small"
        @change="tableChange"
    >
        <template #headerCell="{ column }">
            <template v-if="Array.isArray(column.title)">
                {{ column.title.map((t) => $t(t)).join(' ') }}
            </template>
        </template>
        <template #bodyCell="{ column: col, record: row }">
            <template v-if="col.key === 'group_name'">
                <p class="m-0">{{ row.group_name }}</p>
                <p class="m-0">{{ row.counter }}</p>
            </template>
            <template v-else-if="col.key === 'name'">
                <a-tooltip :title="$t('text.copy')" placement="right">
                    <span
                        class="font-semibold hover:text-gray-900"
                        role="button"
                        @click="
                            iCopy(
                                nameHandle(
                                    row.name.replace(`${row.group_name}.`, `${row.group_name[0]}.`)
                                )
                            )
                        "
                    >
                        {{
                            nameHandle(
                                row.name.replace(`${row.group_name}.`, `${row.group_name[0]}.`)
                            )
                        }}
                    </span>
                </a-tooltip>
            </template>
            <template v-else-if="col.key === 'text'">
                <div
                    v-for="(lang, ind) in (languages?.data || []).filter((e) => e.for_adm === true)"
                    :key="ind"
                    :class="[ind === 0 ? 'mt-0' : 'mt-2']"
                    class="flex items-start space-x-2"
                >
                    <img :src="`${apiUrl}images/flags/4x3/${lang.flag}`" alt="" class="w-6 mt-1" />
                    <p class="m-0 p-0">{{ strReplace(row.text[lang.path]) }}</p>
                </div>
            </template>
            <template v-else-if="col.key === 'action'">
                <div class="flex items-center pr-3">
                    <span class="flex-1"></span>
                    <a-tooltip :title="$t('nav.b.update')" placement="top">
                        <span class="text-blue-500 cursor-pointer" @click="openForm(row.id)">
                            <i-icon class="size-4" icon="edit" />
                        </span>
                    </a-tooltip>
                </div>
            </template>
        </template>
    </a-table>
</template>

<script setup>
import { defineComponent, inject } from 'vue'
import { iCopy, strReplace } from '@/mixins'
import { apiUrl } from '@/config'
import { useAppRuntime } from '@/runtime'

defineComponent({
    name: 'TranslateApp',
})

const dataRef = inject('dataRef')

const { languages } = useAppRuntime()
const columns = [
    {
        title: ['validation.attributes.group_name'],
        dataIndex: 'group_name',
        key: 'group_name',
    },
    {
        title: ['validation.attributes.name'],
        dataIndex: 'name',
        key: 'name',
    },
    {
        title: ['text.title.explain'],
        dataIndex: 'text',
        key: 'text',
    },
    {
        key: 'action',
    },
]

const nameHandle = inject('nameHandle')
const openForm = inject('openForm')
const tableChange = inject('tableChange')
</script>
