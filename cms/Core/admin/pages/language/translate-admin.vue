<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <i-filter :allowedFields="[]" :schema="filterSchema" module="translate" />

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
                <p class="m-0">{{ t('text.language.usage_count', { count: row.counter }) }}</p>
            </template>
            <template v-else-if="col.key === 'name'">
                <p>
                    <a-tooltip :title="$t('text.copy')" placement="right">
                        <span
                            class="font-semibold hover:text-gray-900"
                            role="button"
                            @click="
                                iCopy(row.name
                                )
                            "
                        >
                        {{ row.name }}
                        </span>
                    </a-tooltip>
                </p>
            </template>
            <template v-else-if="col.key === 'text'">
                <div
                    v-for="(lang, ind) in (languages?.data || []).filter((e) => e.for_adm === true)"
                    :key="ind"
                    :class="[ind === 0 ? 'mt-0' : 'mt-2']"
                    class="flex items-start space-x-2"
                >
                    <img :src="`${apiUrl}images/flags/4x3/${lang.flag}`" alt="" class="w-6 mt-1" />
                    <p class="m-0 p-0">
                        {{ strReplace(row.text[lang.path]) }}
                    </p>
                </div>
            </template>
            <template v-else-if="col.key === 'modules'">
                <div class="flex flex-col gap-1">
                    <span
                        v-for="(mod, idx) in row.modules"
                        :key="idx"
                        class="text-xs px-1 py-0.5 bg-gray-100 rounded w-max"
                    >
                        {{ moduleList.find((m) => m.key === mod)?.name || mod }}
                    </span>
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
import { computed, defineComponent, inject } from 'vue'
import { iCopy, strReplace } from '@/mixins'
import { apiUrl } from '@/config'
import { useEnumerates } from '@/hooks'
import { useI18n } from 'vue-i18n'
import { useAppRuntime } from '@/runtime'

const { t } = useI18n()

defineComponent({
    name: 'TranslateAdmin',
})

const { data: enumerates } = useEnumerates()
const dataRef = inject('dataRef')
const moduleList = inject('moduleList')

const statusOptions = computed(() => {
    const source = enumerates?.value?.data ?? enumerates?.value ?? []
    const list = Array.isArray(source) ? source : []
    const options = list
        .filter((item) => item?.type === 'status')
        .map((item) => ({
            label: `${item.name} (${item.enum_key})`,
            value: item.enum_key,
        }))

    return [{ label: t('text.filter.all'), value: 'all' }, ...options]
})

const filterSchema = computed(() => [
    {
        key: 'keywords',
        type: 'search',
        placeholderKey: 'nav.b.search',
        debounce: 400,
        valueType: 'string',
        widthClass: 'w-56',
    },
    {
        key: 'is_used',
        type: 'select',
        placeholderKey: 'text.select',
        options: [
            {
                label: t('validation.attributes.used'),
                value: 1,
            },
            {
                label: t('validation.attributes.unused'),
                value: 0,
            },
        ],
        allowClear: true,
        changeOnSelect: true,
        valueType: 'number',
        widthClass: 'w-40',
    },
    {
        key: 'status',
        type: 'status',
        placeholderKey: 'validation.attributes.status',
        options: statusOptions,
        allowClear: true,
        emitChange: true,
        valueType: 'string',
        widthClass: 'w-32',
    },
])

const { languages } = useAppRuntime()
const columns = [
    {
        title: ['validation.attributes.group_name'],
        dataIndex: 'group_name',
        key: 'group_name',
        className: 'whitespace-nowrap',
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
        title: ['validation.attributes.modules'],
        dataIndex: 'modules',
        key: 'modules',
        className: 'whitespace-nowrap',
    },
    {
        key: 'action',
    },
]

const nameHandle = inject('nameHandle')
const openForm = inject('openForm')
const tableChange = inject('tableChange')
</script>
