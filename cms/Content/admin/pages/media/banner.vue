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
                text: `${$t('nav.a.manage')} ${$t('nav.a.banners')}`,
                icon: 'banner',
            },
        ]"
    >
        <button
            v-if="route.name === 'banner-manage'"
            class="ml-auto text-gray-800 rounded-sm text-xs bg-blk-200/60 hover:bg-blk-300/80 transition-all px-2 py-1.5 inline-flex items-center space-x-1"
            @click="openModal"
        >
            <i-icon class="size-4" icon="plus-thick" />
            <span>{{ $t('nav.b.add') }} Banner</span>
        </button>
    </breadcrumbs>

    <div class="i-box">
        <template v-if="rule('banner.viewAny')">
            <i-filter module="banner"></i-filter>

            <a-table
                :columns="columns"
                :data-source="dataRef?.data || []"
                :pagination="{
                    pageSize: dataRef.perPage || 10,
                    current: dataRef.currentPage || 1,
                    total: dataRef.total || 0,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    showTotal: (total, range) => {
                        return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t(
                            'pagination.of'
                        )} ${total} ${$t('pagination.results')}`
                    },
                }"
                row-key="id"
                size="small"
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
                            <image-v3 :alt="row.name" :src="row.image" class="w-10 h-10"></image-v3>

                            <div class="flex-1">
                                <p class="m-0">
                                    <span
                                        :class="{
                                            'line-through text-gray-500': row.isDeleted,
                                        }"
                                    >
                                        {{ row.name }}
                                    </span>
                                </p>
                                <span class="text-green-500 text-xs line-clamp-1 italic">
                                    {{ row.url || '' }}
                                </span>
                            </div>
                        </div>
                    </template>
                    <template v-else-if="col.key === 'type'">
                        {{ $t(getBannerTypeName(row.type)) || 'N/a' }}
                    </template>
                    <template v-else-if="col.key === 'status'">
                        <a-tag class="m-0!">
                            {{ row.status || 'Not set' }}
                        </a-tag>
                    </template>
                    <template v-else-if="col.key === 'counter'">
                        <a-tag color="green">
                            <i-icon class="size-4" icon="eye" />
                            {{ row.view }}
                        </a-tag>
                        <a-tag class="m-0!" color="red">
                            <i-icon icon="mouse" class="size-4" />
                            {{ row.click }}
                        </a-tag>
                    </template>
                    <template v-else-if="col.key === 'action'">
                        <div class="inline-flex items-center justify-end">
                            <span
                                class="font-medium text-cyan-500 hover:text-cyan-700"
                                role="button"
                                @click="editBanner(row.id)"
                            >
                                <span class="text-blue-500" role="button">
                                    <i-icon class="size-4" icon="edit" />
                                </span>
                            </span>
                            <span class="mx-2.5 text-gray-300 bg-gray-300 w-px h-1/2"></span>
                            <a-popconfirm
                                :cancel-text="$t('nav.b.cancel')"
                                :ok-text="$t('nav.b.delete')"
                                :title="$t('text.del-confirm')"
                                ok-type="danger"
                                @confirm="() => delBanner(row.id)"
                            >
                                <span class="text-rose-500" role="button">
                                    <i-icon class="size-4" icon="delete" />
                                </span>
                            </a-popconfirm>
                        </div>
                    </template>
                </template>
            </a-table>

            <!-- Upload banner popup -->
            <a-modal
                v-model:open="modalState"
                :title="`${form.id > 0 ? $t('nav.b.update') : $t('nav.b.create')} ${$t('nav.a.banners')}`"
                width="75%"
                @cancel="modalClose"
            >
                <a-form id="bannerForm" :model="form" layout="vertical" @finish="submitForm">
                    <div
                        class="grid grid-cols-1 md:grid-cols-2 gap-x-0 md:gap-x-4 gap-y-3 md:gap-y-0"
                    >
                        <a-form-item
                            :label="$t('text.banner.name')"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', { a: $t('validation.attributes.name') }),
                                },
                            ]"
                            name="name"
                        >
                            <a-input
                                v-model:value="form.name"
                                :placeholder="$t('text.banner.name')"
                            ></a-input>
                        </a-form-item>

                        <a-form-item :label="$t('text.banner.title')" name="custom">
                            <a-input
                                v-model:value="form.custom.title"
                                :placeholder="$t('text.banner.title')"
                            ></a-input>
                        </a-form-item>

                        <a-form-item :label="$t('text.banner.type')" name="type">
                            <a-select
                                v-model:value="form.type"
                                :options="
                                    bannerTypeList.map((item) => {
                                        return {
                                            value: item.value,
                                            label: $t(item.label),
                                        }
                                    })
                                "
                            >
                                })">
                            </a-select>
                        </a-form-item>

                        <a-form-item
                            :label="$t('text.banner.url')"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', { a: $t('text.banner.url') }),
                                },
                            ]"
                            name="url"
                        >
                            <a-input
                                v-model:value="form.url"
                                :placeholder="$t('text.banner.url')"
                            ></a-input>
                        </a-form-item>

                        <a-form-item
                            :label="$t('validation.attributes.language_id')"
                            :rules="[
                                {
                                    required: true,
                                    type: 'number',
                                    min: 1,
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: $t('validation.attributes.language_id'),
                                    }),
                                },
                            ]"
                            name="language_id"
                        >
                            <a-select
                                v-model:value="form.language_id"
                                :disabled="form.parent_id && form.parent_id.length > 0"
                                :options="
                                    (languages?.data || []).map((l) => {
                                        return {
                                            value: l.id,
                                            label: l.name,
                                        }
                                    })
                                "
                                :placeholder="$t('text.select')"
                                class="w-full"
                            >
                            </a-select>
                        </a-form-item>

                        <a-form-item :label="$t('text.banner.limit_by')" name="limit_by">
                            <a-select v-model:value="form.limit_by" :placeholder="$t('text.select')">
                                <a-select-option
                                    v-for="(type, ind) in [0, 1, 2, 3]"
                                    :key="ind"
                                    :value="type"
                                >
                                    {{ $t(`text.banner.limit_${type}`) }}
                                </a-select-option>
                            </a-select>
                        </a-form-item>

                        <a-form-item
                            v-if="form.limit_by === 1"
                            :label="$t('text.banner.limit_' + form.limit_by)"
                            name="limit_date"
                        >
                            <a-range-picker
                                v-model:value="form.limit_date"
                                :disabled-date="disabledDate"
                                :disabled-time="disabledRangeTime"
                                :show-time="{
                                    hideDisabledOptions: true,
                                    defaultValue: [
                                        dayjs('00:00:00', 'HH:mm:ss'),
                                        dayjs('11:59:59', 'HH:mm:ss'),
                                    ],
                                }"
                                format="YYYY-MM-DD HH:mm:ss"
                                style="width: 400px"
                            />
                        </a-form-item>

                        <a-form-item
                            v-if="form.limit_by === 2"
                            :label="$t('text.banner.limit_' + form.limit_by)"
                            name="limit_click"
                        >
                            <a-input
                                v-model:value="form.limit_click"
                                :max="1000000"
                                :min="0"
                                type="number"
                            ></a-input>
                        </a-form-item>

                        <a-form-item
                            v-if="form.limit_by === 3"
                            :label="$t('text.banner.limit_' + form.limit_by)"
                            name="limit_view"
                        >
                            <a-input
                                v-model:value="form.limit_view"
                                :max="1000000"
                                :min="0"
                                type="number"
                            ></a-input>
                        </a-form-item>
                    </div>

                    <media-selection
                        v-model="form.images"
                        :limit="1"
                        folder="Banner"
                    ></media-selection>
                </a-form>

                <template #footer>
                    <div class="flex flex-nowrap">
                        <div class="flex-1"></div>
                        <a-button @click="modalState = false">
                            <span class="pr-2"><i-icon icon="close" /></span>
                            <span>{{ $t('nav.b.cancel') }}</span>
                        </a-button>
                        <a-button
                            :loading="btnState"
                            :disabled="btnState"
                            danger
                            form="bannerForm"
                            html-type="submit"
                            type="primary"
                        >
                            <span class="pr-2"><i-icon icon="content-save" class="size-4" /></span>
                            <span>{{ $t('nav.b.save') }}</span>
                        </a-button>
                    </div>
                </template>
            </a-modal>
        </template>
        <no-permission-error v-else></no-permission-error>
    </div>
</template>

<script setup>
import { defineComponent, reactive, ref, watch, inject } from 'vue'
import { useBanners } from '@/hooks'
import { getRouteType, iAlert, initFilter, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { useRoute, useRouter } from 'vue-router'
import { bannerFilter } from '@/config'
import { deleteBanner, getBanner, storeBanner, updateBanner } from '@/libs'
import { Form } from 'ant-design-vue'
import dayjs from 'dayjs'
import { useAppRuntime } from '@/runtime'

const useForm = Form.useForm

defineComponent({
    name: 'Banner',
})

const bannerTypeList = [
    { value: 'slider_main', label: 'text.banner.slider_main' },
    { value: 'slider_secondary', label: 'text.banner.slider_secondary' },
    { value: 'target', label: 'text.banner.target' },
    { value: 'popup', label: 'text.banner.popup' },
    { value: '970_90', label: 'text.banner.970_90' },
    { value: '728_90', label: 'text.banner.728_90' },
    { value: '336_280', label: 'text.banner.336_280' },
    { value: '300_600', label: 'text.banner.300_600' },
    { value: '320_50', label: 'text.banner.320_50' },
]
const range = (start, end) => {
    const result = []

    for (let i = start; i < end; i++) {
        result.push(i)
    }

    return result
}

const disabledDate = (current) => {
    // Can not select days before today and today
    return current && current < dayjs().endOf('day')
}
const disabledRangeTime = (_, type) => {
    if (type === 'start') {
        return {
            disabledHours: () => range(0, 60).splice(4, 20),
            disabledMinutes: () => range(30, 60),
            disabledSeconds: () => [55, 56],
        }
    }
    return {
        disabledHours: () => range(0, 60).splice(20, 4),
        disabledMinutes: () => range(0, 31),
        disabledSeconds: () => [55, 56],
    }
}
const route = useRoute()
const router = useRouter()
const dataRef = ref({})
const columns = ref([
    {
        key: 'name',
        title: ['validation.attributes.title', 'nav.a.banners'],
    },
    {
        key: 'type',
        title: ['validation.attributes.type'],
    },
    {
        key: 'status',
        title: ['validation.attributes.status'],
    },
    {
        key: 'counter',
    },
    { key: 'action', align: 'right' },
])

const getBannerTypeName = (type = 'slider_main') => {
    const item = bannerTypeList.find((item) => item.value === type)

    return item?.label || 'N/a'
}

const { languages } = useAppRuntime()

const formDefault = () => {
    return {
        id: 0,
        name: null,
        type: 'slider_main',
        url: null,
        click: 0,
        view: 0,
        limit_by: 0,
        limit_from: null,
        limit_to: null,
        limit_click: 0,
        limit_view: 0,
        limit_date: [undefined, undefined],
        language_id: 0,
        custom: {
            target_type: null,
            target_id: 0,
            title: null,
        },
        status: 'status_published',
        user_id: 0,
        images: [],
    }
}
const form = ref(formDefault())
const formRule = ref({})
const { resetFields } = useForm(form, formRule)
const resetForm = () => {
    resetFields()
}
const setForm = (data = {}) => {
    resetForm()
    form.value = objectAssignOnlyByKeyHasValue(form.value, data)
}
const editBanner = async (id) => {
    if (id > 0) {
        modalState.value = true
        await getBanner(id, true).then((res) => {
            if (res?.status === 'success') {
                //  limit_date to dayjs()
                if (res.data.limit_date) {
                    res.data.limit_date = [
                        dayjs(res.data.limit_date[0]),
                        dayjs(res.data.limit_date[1]),
                    ]
                }
                setForm(res.data)
            }
        })
    }
}
const queryData = reactive({
    ...initFilter(bannerFilter, route.query),
    status: route.query?.status || 'all', //  Override status
    target: getRouteType(route.name),
})

const { data: bannerResponse, refetch } = useBanners(queryData, {})

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

const submitForm = (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    const call = value.id > 0 ? updateBanner(value.id, value) : storeBanner(value)
    call.then((res) => {
        if (res?.status === 'success') {
            if (value.id === 0) {
                resetForm()
            }
        }
        if (res?.message) {
            iAlert(res)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}
/** form upload banner */
const modalState = ref(false)
const btnState = ref(false)
/** Reload the list after close Modal */
const modalClose = () => {
    resetForm()
    refetch()
}

const openModal = () => {
    modalState.value = true
    //  set default language into form from first item in language data
    if (languages.value.data && languages.value.data.length > 0) {
        form.value.language_id = languages.value.data[0].id
    }
}

const delBanner = async (id) => {
    if (id > 0) {
        await deleteBanner(id).then((res) => {
            if (res?.status === 'success') {
                refetch()
            }
            if (res?.message) {
                iAlert(res)
            }
        })
    }
}

watch(route, (val) => {
    if (Object.keys(val.query).length < 1) {
        Object.assign(queryData, bannerFilter)
    } else {
        Object.assign(queryData, val.query)
    }
})

watch(
    () => bannerResponse.value,
    (val) => {
        if (val?.data?.data) {
            dataRef.value = val.data
        }
    }
)
</script>
