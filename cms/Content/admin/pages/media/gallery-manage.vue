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
                name: 'media-dashboard',
                title: $t('nav.a.media'),
                icon: 'multimedia',
            },
            {
                text: $t('nav.a.galleries'),
                icon: 'galleries',
            },
        ]"
    />

    <div class="i-box">
        <div v-if="rule('gallery.viewAny')" class="">
            <i-filter
                :allowedFields="[getRouteType(route.name, 'ids')]"
                status-group="status"
                module="gallery"
                categoryType="gallery"
            />

            <div class="relative">
                <a-table
                    :data-source="dataRef?.data?.data || []"
                    :columns="columns"
                    size="small"
                    row-key="id"
                    :pagination="{
                        pageSize: dataRef?.data?.per_page || 10,
                        current: dataRef?.data?.current_page || 1,
                        total: dataRef?.data?.total || 0,
                        showSizeChanger: true,
                        pageSizeOptions: ['10', '20', '50', '100'],
                        showTotal: (total, range) => {
                            return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t(
                                'pagination.of'
                            )} ${total} ${$t('pagination.results')}`
                        },
                    }"
                    @change="tableChange"
                >
                    <template #headerCell="{ column }">
                        <template v-if="Array.isArray(column.title)">
                            {{ column.title.map((t) => $t(t)).join(' ') }}
                        </template>
                    </template>

                    <template #bodyCell="{ column: col, record: row, index }">
                        <template v-if="col.key === 'name'">
                            <div class="flex items-center space-x-3">
                                <image-v3
                                    :src="row.image"
                                    :alt="row.name"
                                    class="w-[40px]"
                                ></image-v3>

                                <div class="flex-1">
                                    <p class="m-0">
                                        <router-link
                                            v-if="row.id > 0 && !row.isDeleted"
                                            :to="{
                                                name: 'gallery-update',
                                                params: { id: row.id },
                                            }"
                                            class="text-cyan-500 hover:text-cyan-700"
                                        >
                                            {{ row.name }}
                                        </router-link>
                                        <span
                                            v-else
                                            :class="{
                                                'line-through text-gray-500': row.isDeleted,
                                            }"
                                        >
                                            {{ row.name }}
                                        </span>
                                        <span
                                            v-if="row.isPublished"
                                            class="text-sm pl-2 text-green-500"
                                        >
                                            <i-icon class="size-4" icon="check-circle" />
                                        </span>
                                    </p>
                                    <span class="text-green-500 text-xs line-clamp-1 italic">
                                        {{ row.slug || '' }}
                                    </span>
                                </div>
                            </div>
                        </template>
                        <template v-else-if="col.key === 'status'">
                            <a-switch
                                checkedValue="status_published"
                                unCheckedValue="status_draft"
                                @change="
                                    (checked) => {
                                        onChangeStatus(checked, row.id)
                                    }
                                "
                                v-model:checked="row.status"
                            />
                        </template>
                        <template v-else-if="col.key === 'category'">
                            {{ row.category?.name || 'N/a' }}
                        </template>
                        <template v-else-if="col.key === 'action'">
                            <template v-if="rule('gallery.update')">
                                <span class="mx-2.5 text-gray-300 bg-gray-300 w-px h-1/2"></span>
                                <span role="button" class="text-blue-500">
                                    <i-icon class="size-4" icon="edit" />
                                </span>
                            </template>
                            <span class="mx-2.5 text-gray-300 bg-gray-300 w-px h-1/2"></span>
                            <span v-if="row.isDeleted" role="button" class="text-green-500">
                                <i-icon class="size-4" icon="undo" />
                            </span>
                            <a-popconfirm
                                v-else
                                :title="$t('text.del-confirm')"
                                :ok-text="$t('nav.b.delete')"
                                :cancel-text="$t('nav.b.cancel')"
                                ok-type="danger"
                            >
                                <span role="button" class="text-rose-500">
                                    <i-icon class="size-4" icon="delete" />
                                </span>
                            </a-popconfirm>
                        </template>
                    </template>
                </a-table>
            </div>
        </div>
        <no-permission-error v-else></no-permission-error>
    </div>
</template>

<script setup>
import { defineComponent, inject, reactive, ref, watch } from 'vue'
import { initFilter, rule, getRouteType } from '@/mixins'
import { useRoute, useRouter } from 'vue-router'
import { galleryFilter } from '@/config'
import { useGalleries, useQuickGallery } from '@/hooks'

defineComponent({
    name: 'GalleryManage',
})

const route = useRoute()
const router = useRouter()
// const dataRef = ref({})
const filterDefault = route.name === galleryFilter
const queryData = reactive(
    initFilter(filterDefault, {
        ...route.query,
        type: 2,
    })
)
const { data: dataRef, refetch } = useGalleries(queryData)
const locale = inject('globalLocale')
//  Table columns
const columns = ref([
    {
        key: 'name',
        title: ['validation.attributes.title', 'nav.a.galleries'],
    },
    {
        key: 'category',
        title: ['nav.a.categories'],
    },
    {
        key: 'status',
        title: ['validation.attributes.status'],
    },
    { key: 'action', align: 'right' },
])

const form = ref({})
const formDefault = () => {
    return {
        id: 0,
        name: null,
        target_id: 0,
        target_type: null,
        language_id: 1,
        description: null,
        content: [{ type: 'text', value: null }],
        status: 'status_draft',
    }
}
const { mutate: updateGalleryStatus } = useQuickGallery()
const onChangeStatus = (checked, uuid) => {
    updateGalleryStatus(
        { id: uuid, status: checked },
        {
            onSuccess: () => {
                refetch()
            },
        }
    )
}

const resetForm = () => {
    form.value = formDefault()
}

const tableChange = (pagination) => {
    const newFilterParams = {
        ...queryData,
        page: pagination.current,
        pageSize: pagination.pageSize,
    }

    router.push({
        name: route.name,
        query: newFilterParams,
    })
}

watch(route, (val) => {
    if (Object.keys(val.query).length < 1) {
        Object.assign(queryData, filterDefault)
    } else {
        Object.assign(queryData, val.query)
    }
})
</script>
