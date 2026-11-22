<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4">
        <breadcrumbs
            :items="[
                {
                    icon: 'content-save-edit',
                    title: $t('nav.a.pages'),
                    text: $t('nav.a.pages'),
                },
            ]"
        >
            <i-button @click="goToCreate">
                <template #icon>
                    <i-icon class="size-3.5" icon="plus-thick" />
                </template>
                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
        </breadcrumbs>

        <div class="i-box space-y-3">
            <template v-if="can('post', 'viewAny')">
                <i-filter
                    ref="filterRef"
                    :defaults="filterDefaults"
                    :schema="filterSchema"
                    :sync-route="true"
                    @submit="handleFilterSubmit"
                />

                <i-bulk-action
                    v-model="selectedRowKeys"
                    :actions="bulkActions"
                    :texts="bulkActionTexts"
                    @success="handleBulkSuccess"
                />

                <a-skeleton v-if="isLoading || isFetching" :rows="5" active />
                <a-table
                    v-else
                    :columns="columns"
                    :data-source="dataRef?.data || []"
                    :pagination="{
                        pageSize: dataRef?.perPage || 10,
                        current: dataRef?.currentPage || 1,
                        total: dataRef?.total || 0,
                        showSizeChanger: true,
                        pageSizeOptions: ['10', '20', '50', '100'],
                        showTotal: (total, range) => {
                            return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t(
                                'pagination.of'
                            )} ${total} ${$t('pagination.results')}`
                        },
                    }"
                    :row-selection="rowSelection"
                    row-key="uuid"
                    size="small"
                    @change="tableChange"
                >
                    <template #headerCell="{ column }">
                        <template v-if="column.key === 'name'">
                            <span>
                                {{ $t('validation.attributes.name') }}
                                {{ $t('nav.a.pages') }}
                            </span>
                        </template>
                        <template v-if="column.key === 'category'">
                            <span>
                                {{ $t('nav.a.categories') }}
                            </span>
                        </template>
                        <template v-if="column.key === 'status'">
                            <span class="whitespace-nowrap">
                                {{ $t('validation.attributes.status') }}
                            </span>
                        </template>
                    </template>

                    <template #bodyCell="{ column: col, record: row }">
                        <template v-if="col.key === 'name'">
                            <div class="flex items-center space-x-3">
                                <image-v3
                                    :alt="row.name"
                                    :src="row.image"
                                    class="w-[40px]"
                                ></image-v3>

                                <div class="flex-1">
                                    <p class="m-0 inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span
                                            v-if="row.id > 0 && !row.isDeleted"
                                            class="text-cyan-500 hover:text-cyan-700 cursor-pointer"
                                            role="button"
                                            @click="goToEditPage(row)"
                                        >
                                            {{ row.name }}
                                        </span>
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
                                            class="inline-flex items-center text-sm text-green-500"
                                        >
                                            <i-icon class="size-3" icon="check-circle" />
                                        </span>
                                        <span
                                            v-if="row.featured"
                                            class="inline-flex items-center text-sm text-yellow-500"
                                        >
                                            <i-icon class="size-3" icon="star" />
                                        </span>
                                        <span
                                            v-if="row.isLocked"
                                            class="inline-flex items-center text-sm text-slate-700"
                                        >
                                            <i-icon class="size-3" icon="lock" />
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
                                v-model:checked="row.status"
                                checkedValue="status_published"
                                unCheckedValue="status_draft"
                                @change="
                                    (checked) => {
                                        onChangeStatus(checked, row.uuid)
                                    }
                                "
                            />
                        </template>
                        <template v-else-if="col.key === 'category'">
                            {{ row.category?.name || $t('text.not_applicable') }}
                        </template>
                        <template v-else>
                            <div v-if="row.id > 0" class="flex items-center justify-end gap-2 whitespace-nowrap">
                                <span class="text-green-500 cursor-pointer" role="button" @click="goToViewPage(row)">
                                    <i-icon class="size-4" icon="eye" />
                                </span>
                                <span class="text-blue-500 cursor-pointer" role="button" @click="goToEditPage(row)">
                                    <i-icon class="size-4" icon="pencil" />
                                </span>
                                <span
                                    v-if="row.isDeleted"
                                    class="text-green-500 cursor-pointer"
                                    role="button"
                                    @click="restorePostByUuid(row.uuid)"
                                >
                                    <i-icon class="size-4" icon="undo" />
                                </span>
                                <a-popconfirm
                                    v-else
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.delete')"
                                    :title="$t('text.del-confirm')"
                                    ok-type="danger"
                                    @confirm="() => delPost(row.uuid)"
                                >
                                    <span class="text-rose-500 cursor-pointer" role="button">
                                        <i-icon class="size-4" icon="delete" />
                                    </span>
                                </a-popconfirm>
                            </div>
                        </template>
                    </template>
                </a-table>
            </template>
            <no-permission-error v-else></no-permission-error>
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, onMounted, reactive, ref } from 'vue'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { can } from '@/mixins'
import IBulkAction from '@/components/common/i-bulk-action.vue'
import { CATEGORY_TYPE, CATEGORY_TYPE_STR_POST } from '@/config'
import { deletePost, getPost, quickPost, restorePost } from '@/libs'
import { useBulkSelection, useCategories, useEnumerates, usePosts, useQuickPost } from '@/hooks'

const routeList = [
    'post-post',
    'post-audio',
    'post-video',
    'post-document',
    'post-notification',
    'post-admission',
    'post-page',
]
defineComponent({
    name: 'PostPageManage',
})

const { t } = useI18n()
const { mutate: updatePostStatus } = useQuickPost()
const getTypeByRouteName = (routeName) => {
    //    Split the route name by '-' and get the last element
    //    Example: post-manage => ['my', 'post', 'manage'] => 'manage'
    const type = routeName.split('-').pop()

    return type === 'manage' ? 'post' : type
}

const onChangeStatus = (checked, uuid) => {
    updatePostStatus(
        { id: uuid, status: checked },
        {
            onSuccess: () => {
                refetch()
            },
        }
    )
}

const route = useRoute()

const filterDefaults = {
    keywords: '',
    categoryId: [],
    status: 'all',
    page: 1,
    pageSize: 20,
    type: getTypeByRouteName(route.name),
}

const filters = reactive({
    ...filterDefaults,
    page: Number(route.query.page) || filterDefaults.page,
    pageSize: Number(route.query.pageSize ?? route.query.page_size) || filterDefaults.pageSize,
})

const {
    data: dataRef,
    refetch,
    isLoading,
    isFetching,
} = usePosts(filters, {
    staleTime: 1000 * 60 * 5,
    enabled: routeList.includes(route.name),
})

const goToCreate = () => {
    router.push({
        name: 'post-create',
        query: { type: filters.type },
    })
}

const router = useRouter()
const columns = [
    {
        key: 'name',
    },
    {
        key: 'category',
    },
    {
        key: 'status',
        width: 80,
    },
    {
        fixed: 'right',
        width: 160,
        align: 'right',
    },
]

const tableChange = (pagination) => {
    filters.page = pagination.current
    filters.pageSize = pagination.pageSize
    selectedRowKeys.value = []
    filterRef.value?.applyRoute?.(filters, { replace: true })
    refetch()
}

const restorePostByUuid = (id = 0) => {
    restorePost(id).then(() => {
        refetch()
    })
}

const delPost = (id = 0) => {
    deletePost(id).then(() => {
        refetch()
    })
}

const goToEditPage = (record = {}) => {
    router.push({
        name: 'post-update',
        params: { uuid: record.uuid },
        query: { ...filters },
    })
}

const goToViewPage = (record = {}) => {
    router.push({
        name: 'post-view',
        params: { uuid: record.uuid },
    })
}

const { data: enumerates } = useEnumerates()
const { data: categories } = useCategories({
    type: CATEGORY_TYPE[CATEGORY_TYPE_STR_POST],
})

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

const mapCategoryOptions = (items = []) => {
    return items.map((item) => ({
        label: item.id > 0 ? item.name : t('text.filter.all'),
        value: item.id > 0 ? parseInt(item.id, 10) : 'all',
        children: mapCategoryOptions(item.children || []),
    }))
}

const categoryOptions = computed(() => {
    const payload = categories?.value?.data ?? categories?.value ?? {}
    const list = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []
    const mapped = mapCategoryOptions(list)
    return [{ label: t('text.filter.no_category'), value: 0 }, ...mapped]
})

const flattenCategoryOptions = (items = [], prefix = '') => {
    const result = []

    items.forEach((item) => {
        if (!item || typeof item !== 'object') {
            return
        }

        const name = item.name || item.label || `${item.id ?? ''}`.trim()
        const label = prefix ? `${prefix} / ${name}` : name

        if (item.id !== undefined && item.id !== null) {
            result.push({ label, value: item.id })
        }

        if (Array.isArray(item.children) && item.children.length) {
            result.push(...flattenCategoryOptions(item.children, label))
        }
    })

    return result
}

const categorySelectOptions = computed(() => {
    const payload = categories?.value?.data ?? categories?.value ?? {}
    const list = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []
    return flattenCategoryOptions(list)
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
        key: 'categoryId',
        type: 'cascader',
        placeholderKey: 'text.select',
        options: categoryOptions,
        allowClear: true,
        changeOnSelect: true,
        valueType: 'array',
        arrayItemType: 'number',
        widthClass: 'w-40',
    },
    {
        key: 'status',
        type: 'status',
        placeholderKey: 'validation.attributes.status',
        allowClear: true,
        emitChange: true,
        valueType: 'string',
        widthClass: 'w-32',
    },
])

const filterRef = ref(null)

const applyFilterValues = (payload = {}, { resetPage = true } = {}) => {
    Object.assign(filters, {
        ...filterDefaults,
        page: resetPage ? 1 : filters.page,
        pageSize: filters.pageSize,
        type: getTypeByRouteName(route.name),
        ...payload,
    })
    selectedRowKeys.value = []
    refetch()
}

const handleFilterSubmit = (payload) => {
    applyFilterValues(payload, { resetPage: true })
}

const selectedRowKeys = ref([])
const { rowSelection } = useBulkSelection(selectedRowKeys, {
    messageApi: message,
    maxMessage: t('text.bulk.max-items', { number: 20 }),
})

const bulkActionTexts = computed(() => ({
    selectedLabel: t('text.selected'),
    maxMessage: t('text.bulk.max-items', { number: 20 }),
    rateLimitMessage: t('text.rate_limit'),
}))

const buildPreviewResponse = (ids) => ({
    status: 'success',
    data: {
        affected_count: ids.length,
        eligible_ids: ids,
        preview: true,
    },
})

const normalizeCategoryIds = (changes = {}) => {
    const source = changes?.category_id ?? changes?.category_ids
    const list = Array.isArray(source) ? source : source ? [source] : []

    return list
        .map((item) => {
            if (Array.isArray(item)) {
                return item[item.length - 1]
            }
            return typeof item === 'string' ? parseInt(item, 10) : item
        })
        .filter((item) => Number.isFinite(item))
}

const updatePagesStatusBulk = async ({ ids, dry_run, status } = {}) => {
    const targetIds = Array.isArray(ids) ? ids : []
    if (!targetIds.length) {
        return {
            status: 'error',
            message: t('text.bulk.no-items-selected'),
        }
    }

    if (dry_run) {
        return buildPreviewResponse(targetIds)
    }

    for (const id of targetIds) {
        const response = await quickPost(id, status).catch((error) => {
            throw error
        })

        if (response?.status && response.status !== 'success') {
            return {
                status: 'error',
                message:
                    response?.message ||
                    t('text.post.bulk_status_failed'),
            }
        }
    }

    return {
        status: 'success',
        data: {
            affected_count: targetIds.length,
            ids: targetIds,
        },
    }
}

const changeCategoriesBulk = async ({ ids, changes, dry_run } = {}) => {
    const targetIds = Array.isArray(ids) ? ids : []
    const categoryIds = normalizeCategoryIds(changes)

    if (!targetIds.length) {
        return {
            status: 'error',
            message: t('text.bulk.no-items-selected'),
        }
    }

    if (!categoryIds.length) {
        return {
            status: 'error',
            message: t('text.post.bulk_category_missing'),
        }
    }

    if (dry_run) {
        return buildPreviewResponse(targetIds)
    }

    for (const uuid of targetIds) {
        const detail = await getPost(uuid, true).catch((error) => {
            throw error
        })

        if (!detail?.data) {
            return {
                status: 'error',
                message:
                    detail?.message ||
                    t('text.post.bulk_category_load_failed'),
            }
        }

        const payload = {
            ...detail.data,
            category_id: categoryIds,
        }

        const response = await ajax
            .put(`post/${detail.data.id}`, payload)
            .then((res) => res.data || {})

        if (response?.status && response.status !== 'success') {
            return {
                status: 'error',
                message:
                    response?.message ||
                    t('text.post.bulk_category_failed'),
            }
        }
    }

    return {
        status: 'success',
        data: {
            affected_count: targetIds.length,
            ids: targetIds,
        },
    }
}

const publishBulk = (payload = {}) => updatePagesStatusBulk({ ...payload, status: 'status_published' })
const unpublishBulk = (payload = {}) => updatePagesStatusBulk({ ...payload, status: 'status_draft' })

const deletePagesBulk = async ({ ids, dry_run } = {}) => {
    const targetIds = Array.isArray(ids) ? ids : []
    if (!targetIds.length) {
        return {
            status: 'error',
            message: t('text.bulk.no-items-selected'),
        }
    }

    if (dry_run) {
        return buildPreviewResponse(targetIds)
    }

    for (const id of targetIds) {
        const response = await deletePost(id).catch((error) => {
            throw error
        })

        if (response?.status && response.status !== 'success') {
            return {
                status: 'error',
                message:
                    response?.message ||
                    t('text.post.bulk_delete_failed'),
            }
        }
    }

    return {
        status: 'success',
        data: {
            affected_count: targetIds.length,
            ids: targetIds,
        },
    }
}

const bulkActions = computed(() => [
    {
        key: 'publish',
        label: t('nav.b.publish'),
        icon: 'eye',
        type: 'primary',
        confirm: {
            title: t('text.bulk.publish-selected'),
            okText: t('nav.b.publish'),
            cancelText: t('nav.b.cancel'),
        },
        request: publishBulk,
    },
    {
        key: 'unpublish',
        label: t('nav.b.un-published'),
        icon: 'eye-slash',
        confirm: {
            title: t('text.bulk.unpublish-selected'),
            okText: t('nav.b.un-published'),
            cancelText: t('nav.b.cancel'),
        },
        request: unpublishBulk,
    },
    {
        key: 'change-category',
        label: t('text.change-category'),
        icon: 'file-tree',
        kind: 'change',
        modal: {
            title: t('text.change-category'),
            okText: t('nav.b.apply'),
            cancelText: t('nav.b.cancel'),
            fields: [
                {
                    key: 'category_id',
                    type: 'select',
                    mode: 'multiple',
                    required: true,
                    placeholder: t('nav.a.categories'),
                    options: categorySelectOptions,
                    allowClear: true,
                    props: () => ({
                        showSearch: true,
                        optionFilterProp: 'label',
                    }),
                },
            ],
        },
        buildPayload: (ids, formValues) => ({
            ids,
            changes: {
                category_id: formValues.category_id,
            },
        }),
        request: changeCategoriesBulk,
    },
    {
        key: 'delete',
        label: t('nav.b.delete'),
        icon: 'delete',
        danger: true,
        confirm: {
            title: t('text.bulk.delete-selected'),
            okText: t('nav.b.delete'),
            cancelText: t('nav.b.cancel'),
            okType: 'danger',
            contentSuffix: t('text.bulk.delete-selected-warning'),
        },
        request: deletePagesBulk,
    },
])

const handleBulkSuccess = ({ actionKey } = {}) => {
    if (actionKey === 'publish') {
        message.success(t('text.post.bulk_publish_success'))
    } else if (actionKey === 'unpublish') {
        message.success(t('text.post.bulk_unpublish_success'))
    } else if (actionKey === 'delete') {
        message.success(t('text.post.bulk_delete_success'))
    } else if (actionKey === 'change-category') {
        message.success(t('text.post.bulk_category_success'))
    } else {
        message.success(t('text.post.bulk_success'))
    }
    refetch()
}

onMounted(() => {
    const values = filterRef.value?.getValues?.()
    if (values) {
        applyFilterValues(values, { resetPage: false })
    }
})
</script>
