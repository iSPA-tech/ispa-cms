<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="rule('enumerate.viewAny')" class="px-2 md:px-1 py-4 mb-12">
        <h2 class="text-xl font-bold mb-4 md:mb-6">{{ $t('nav.a.enumerates_types') }}</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="col-span-1">
                <a-form :model="form" layout="vertical" @finish="submit">
                    <a-form-item :label="$t('validation.attributes.name')" required>
                        <a-input v-model:value="form.name" :placeholder="$t('validation.attributes.name')" />
                    </a-form-item>
                    <a-form-item :label="$t('validation.attributes.key_name')" required>
                        <a-input v-model:value="form.key_name" :placeholder="$t('validation.attributes.key_name')" />
                    </a-form-item>
                    <div class="flex justify-end space-x-3">
                        <a-button @click="resetForm">
                            <span><i-icon icon="close" class="size-4" /></span>
                            <span>{{ $t('nav.b.reset') }}</span>
                        </a-button>
                        <a-button
                            :loading="isLoading || isFetching"
                            :disabled="isLoading || isFetching"
                            danger
                            type="primary"
                            html-type="submit"
                        >
                            <span><i-icon icon="content-save" /></span>
                            <span>{{ $t('nav.b.save') }}</span>
                        </a-button>
                    </div>
                </a-form>
            </div>
            <div class="col-span-1 lg:col-span-2">
                <a-table
                    :data-source="data"
                    :columns="columns"
                    :pagination="false"
                    size="small"
                    :loading="isFetching || isLoading"
                >
                    <template #headerCell="{ column }">
                        <template v-if="Array.isArray(column.title)">
                            {{ column.title.map((t) => $t(t)).join(' ') }}
                        </template>
                    </template>
                    <template #bodyCell="{ column: col, record: row }">
                        <template v-if="col.key === 'name'">
                            <span class="is-primary" role="button" @click="editType(row)">
                                {{ row.name }}
                            </span>
                            <span class="pl-3 text-green-600 italic">{{ row.key_name }}</span>
                        </template>
                        <template v-else>
                            <span v-if="row.id > 0" class="float-right flex items-center space-x-3">
                                <!-- View & Report -->

                                <template v-if="rule('enumerate.update')">
                                    <a-tooltip
                                        :content="$t('nav.b.edit')"
                                        effect="primary"
                                        placement="top"
                                    >
                                        <span
                                            class="text-blue-500 cursor-pointer"
                                            @click="editType(row)"
                                        >
                                            <i-icon icon="edit" class="size-4" />
                                        </span>
                                    </a-tooltip>
                                </template>
                                <template v-if="rule('enumerate.delete')">
                                    <a-popconfirm
                                        :title="$t('text.del-confirm')"
                                        @confirm="() => deleteType(row.id)"
                                    >
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
import { defineComponent, nextTick, ref } from 'vue'
import { iAlert, rule } from '@/mixins'
import ajax from '@/ajax'
import { useEnumerateTypes } from '@/hooks'

defineComponent({
    name: 'SettingEnumerateType',
})

//  new update
const columns = ref([
    {
        key: 'name',
        title: ['validation.attributes.name', 'nav.a.enumerates_types'],
    },
    { key: 'action', align: 'right' },
])
const { data, isFetching, isLoading, refetch } = useEnumerateTypes({})
const form = ref({
    id: 0,
    name: '',
    key_name: '',
})

const deleteType = (id) => {
    ajax.delete(`enumerate-type/${id}`)
        .then((res) => {
            if (res.data.status === 'success') {
                nextTick(() => {
                    refetch()
                })
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

const btnState = ref(false)

/** Reset the form */
const resetForm = () => {
    form.value = {
        id: 0,
        name: '',
        key_name: '',
    }
}
/** Set form to edit */
const editType = (item) => {
    form.value = { ...item }
}
/** Submit the form */
const submit = () => {
    if (btnState.value) {
        return
    }
    btnState.value = true
    const call =
        form.value.id > 0
            ? ajax.put(`enumerate-type/${form.value.id}`, form.value)
            : ajax.post('enumerate-type', form.value)
    call.then((res) => {
        if (res.data.status === 'success') {
            resetForm()
            nextTick(() => {
                refetch()
            })
        }
        if (res.data.message) {
            iAlert(res.data)
        }
        btnState.value = false
    }).catch((err) => {
        iAlert(err, true)
        btnState.value = false
    })
}
</script>
