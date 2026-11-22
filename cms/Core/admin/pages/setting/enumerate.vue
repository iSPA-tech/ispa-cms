<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="rule('enumerate.viewAny')" class="px-2 md:px-1 py-4 mb-12">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="col-span-1">
                <a-form :model="form" layout="vertical" @finish="submit">
                    <a-form-item :label="$t('validation.attributes.name')" name="name" required>
                        <a-input v-model:value="form.name" :placeholder="$t('validation.attributes.name')" />
                    </a-form-item>
                    <a-form-item :label="$t('validation.attributes.name_alias')" name="name_alias">
                        <a-input
                            v-model:value="form.name_alias"
                            :placeholder="$t('validation.attributes.name_alias')"
                        />
                    </a-form-item>
                    <a-form-item :label="$t('validation.attributes.enum_key')" name="enum_key" required>
                        <a-input v-model:value="form.enum_key" :placeholder="$t('validation.attributes.enum_key')" />
                    </a-form-item>
                    <a-form-item :label="$t('validation.attributes.input_type')" name="input_type">
                        <a-input
                            v-model:value="form.input_type"
                            :placeholder="$t('validation.attributes.input_type')"
                        />
                    </a-form-item>

                    <select-input-enumerate-type
                        v-model="form.type_id"
                        :label="$t('validation.attributes.type')"
                        name="type_id"
                        @change="(val) => setType(val)"
                    />

                    <a-form-item :label="$t('validation.attributes.is_filter')" name="is_filter">
                        <a-switch
                            v-model:checked="form.is_filter"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.status')" name="status">
                        <a-switch
                            v-model:checked="form.status"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                        />
                    </a-form-item>
                </a-form>

                <div class="flex justify-end space-x-3">
                    <a-button @click="resetForm">
                        <span><i-icon class="size-4" icon="close" /></span>
                        <span>{{ $t('nav.b.cancel') }}</span>
                    </a-button>
                    <a-button :loading="btnState" danger type="primary" @click="submit">
                        <span><i-icon icon="content-save" /></span>
                        <span>{{ $t('nav.b.save') }}</span>
                    </a-button>
                </div>
            </div>
            <div class="col-span-1 lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-start gap-2">
                    <select-input-enumerate-type
                        v-model="filters.type_id"
                        class="m-0"
                        :label="$t('validation.attributes.type')"
                        name="type_id"
                    />
                    <a-input
                        v-model:value="filters.keyword"
                        allow-clear
                        class="w-full md:w-72"
                        :placeholder="$t('text.f.search')"
                    />
                    <a-button @click="clearFilters">{{ $t('nav.b.clear') }}</a-button>
                    <div class="ml-auto">
                        <a-popconfirm
                            :cancel-text="$t('nav.b.cancel')"
                            :ok-text="$t('nav.b.generate')"
                            :title="$t('text.enumerate.generate_prompt')"
                            @confirm="generateEnums"
                        >
                            <a-button
                                :disabled="generateLoading"
                                :loading="generateLoading"
                                type="primary"
                            >
                                <span><i-icon class="size-4" icon="cog-sync" /></span>
                                <span>{{ $t('text.enumerate.generate_button') }}</span>
                            </a-button>
                        </a-popconfirm>
                    </div>
                </div>
                <a-table
                    :columns="columns"
                    :data-source="sortedRows"
                    :loading="isLoading || isFetching"
                    :pagination="pagination"
                    size="small"
                    @change="handleTableChange"
                >
                    <template #headerCell="{ column }">
                        <template v-if="Array.isArray(column.title)">
                            {{ column.title.map((t) => $t(t)).join(' ') }}
                        </template>
                    </template>
                    <template #bodyCell="{ column: col, record: row }">
                        <template v-if="col.key === 'name'">
                            <span class="is-primary" role="button" @click="editEnum(row)">
                                {{ row.name }}
                            </span>
                            <span class="block text-green-600 italic"
                                >({{ row.enum_key }}) / {{ row.name_alias }}</span
                            >
                        </template>
                        <template v-else-if="col.key === 'type'">
                            <span class="text-gray-700 font-medium">{{ row.type }}</span>
                            <span class="block text-green-600 font-medium">{{
                                row.input_type
                            }}</span>
                        </template>
                        <template v-else>
                            <span v-if="row.id > 0" class="float-right flex items-end space-x-3">
                                <!-- View & Report -->
                                <template v-if="rule('enumerate.update')">
                                    <a-tooltip
                                        :content="$t('nav.b.edit')"
                                        effect="primary"
                                        placement="top"
                                    >
                                        <span
                                            class="text-blue-500 cursor-pointer"
                                            @click="editEnum(row)"
                                        >
                                            <i-icon class="size-4" icon="edit" />
                                        </span>
                                    </a-tooltip>
                                </template>
                                <template v-if="rule('enumerate.delete')">
                                    <a-popconfirm :title="$t('text.del-confirm')">
                                        <span class="cursor-pointer text-rose-500">
                                            <i-icon class="size-4" icon="delete" />
                                        </span>
                                    </a-popconfirm>
                                </template>
                            </span>
                        </template>
                    </template>
                </a-table>
            </div>
        </div>
    </div>
    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { computed, defineComponent, reactive, ref, watch } from 'vue'
import { debounce } from 'lodash-es'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { iAlert, rule } from '@/mixins'
import { storeEnumerate, updateEnumerate } from '@/libs'
import { useEnumerates } from '@/hooks'
import ajax from '@/ajax'

defineComponent({
    name: 'SettingEnumerate',
})

const { t } = useI18n()

const btnState = ref(false)
const filterDefaults = {
    type_id: null,
    keyword: '',
    page: 1,
    per_page: 20,
}
const filters = reactive({ ...filterDefaults })
const requestParams = reactive({})

const syncRequestParams = () => {
    requestParams.page = filters.page
    requestParams.per_page = filters.per_page
    if (filters.type_id) {
        requestParams.type_id = filters.type_id
    } else {
        delete requestParams.type_id
    }
    const keyword = (filters.keyword || '').trim()
    if (keyword) {
        requestParams.keyword = keyword
    } else {
        delete requestParams.keyword
    }
}

syncRequestParams()

const { data, isLoading, isFetching, refetch } = useEnumerates(requestParams, {
    refetchOnWindowFocus: false,
})
const columns = ref([
    {
        key: 'name',
        title: ['validation.attributes.name', 'nav.a.enumerates'],
    },
    {
        key: 'type',
        title: ['validation.attributes.type'],
    },
    { key: 'action', align: 'right' },
])
const extractItems = (payload) => {
    if (Array.isArray(payload)) {
        return payload
    }

    if (payload?.data && Array.isArray(payload.data)) {
        return payload.data
    }

    return []
}
const rows = computed(() => extractItems(data?.value || []))
const sortedRows = computed(() =>
    [...rows.value].sort((a, b) => {
        return b.id - a.id
    })
)
const pagination = computed(() => {
    const meta = data?.value || {}
    return {
        current: meta.current_page || filters.page,
        total: meta.total || rows.value.length,
        pageSize: meta.per_page || filters.per_page,
        showSizeChanger: true,
        pageSizeOptions: ['10', '20', '50', '100'],
    }
})

const handleTableChange = (pager) => {
    if (!pager) {
        return
    }
    filters.page = pager.current || filters.page
    filters.per_page = pager.pageSize || filters.per_page
}

const clearFilters = () => {
    filters.type_id = null
    filters.keyword = ''
    filters.page = 1
    syncRequestParams()
    refetch()
}

watch(
    () => filters.type_id,
    () => {
        const shouldRefetch = filters.page === 1
        filters.page = 1
        syncRequestParams()
        if (shouldRefetch) {
            refetch()
        }
    }
)

let suppressKeywordWatch = false
const applyKeywordFilter = debounce(() => {
    const trimmed = (filters.keyword || '').trim()
    if (trimmed !== filters.keyword) {
        suppressKeywordWatch = true
        filters.keyword = trimmed
        return
    }
    if (filters.page !== 1) {
        filters.page = 1
        syncRequestParams()
        return
    }
    syncRequestParams()
    refetch()
}, 300)

watch(
    () => filters.keyword,
    () => {
        if (suppressKeywordWatch) {
            suppressKeywordWatch = false
            return
        }
        applyKeywordFilter()
    }
)

watch(
    () => [filters.page, filters.per_page],
    () => {
        syncRequestParams()
        refetch()
    }
)

/** Form defaults. */
const formDefault = () => {
    return {
        id: 0,
        name: null,
        name_alias: null,
        enum_key: null,
        type: null,
        type_id: null,
        input_type: null,
        is_filter: false,
        status: false,
    }
}
const form = ref(formDefault())
/** Load the selected enum into the form. */
const editEnum = (item) => {
    form.value = Object.assign(formDefault(), item)
    form.value.type_id = parseInt(item.type_id)
}
/** Submit the create or update form. */
const submit = async () => {
    if (btnState.value) {
        return
    }
    btnState.value = true

    if (form.value.id > 0) {
        await updateEnumerate(form.value.id, form.value)
            .then((res) => {
                if (res.status === 'success') {
                    refetch()
                }
                if (res.message) {
                    iAlert(res)
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
            .finally(() => {
                btnState.value = false
            })
    } else {
        await storeEnumerate(form.value)
            .then((res) => {
                if (res.status === 'success') {
                    resetForm()
                    refetch()
                }
                if (res.message) {
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
}
/** Reset the form. */
const resetForm = () => {
    form.value = formDefault()
}

const generateLoading = ref(false)
const generateEnums = async () => {
    generateLoading.value = true
    try {
        const res = await ajax.post('enumerate/generate')
        if (res.data?.data?.success) {
            message.success(res.data?.data.message || t('text.generate.success'))
        } else {
            message.error(res.data?.data?.message || t('text.generate.failed'))
        }
    } catch (err) {
        if (err.response?.status === 429) {
            message.warning(t('text.generate.running'))
        } else {
            message.error(err.response?.data?.message || t('text.generate.failed'))
        }
    } finally {
        generateLoading.value = false
    }
}

/** Update type metadata from the selected enum type. */
const setType = (type = {}) => {
    if (type && type.id) {
        form.value.type_id = type.id
        form.value.type = type.key_name
    }
}
</script>
