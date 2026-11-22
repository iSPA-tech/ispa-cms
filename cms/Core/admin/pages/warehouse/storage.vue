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
                name: 'product-dashboard',
                icon: 'product',
                title: $t('nav.a.products'),
            },
            {
                text: $t('nav.a.storages'),
                title: $t('nav.a.storages'),
            },
        ]"
    >
        <i-button v-if="rule('storage.create')" variant="primary" @click="modalState = true">
            <template #icon>
                <i-icon class="size-3.5" icon="plus-thick" />
            </template>
            <span>{{ $t('nav.b.create') }}</span>
        </i-button>
    </breadcrumbs>

    <div class="i-box">
        <a-table :data-source="(storage || []).filter((e) => e.id > 0)" :columns="columns">
            <template #headerCell="{ column }">
                <template v-if="Array.isArray(column.title)">
                    {{ column.title.map((t) => $t(t)).join(' ') }}
                </template>
            </template>

            <template #bodyCell="{ column: col, record: row }">
                <template v-if="col.key === 'name'">
                    <a-tooltip
                        v-if="rule('storage.update')"
                        :content="$t('nav.b.edit')"
                        effect="primary"
                        placement="top"
                    >
                        <span class="text-blue-500 cursor-pointer" @click="editStorage(row.id)">
                            {{ row.mapped_name || row.name }}
                        </span>
                    </a-tooltip>
                    <span v-else class="text-gray-700 font-medium">{{
                        row.mapped_name || row.name
                    }}</span>
                    <span v-if="row.code" class="block text-green-600 italic">{{ row.code }}</span>
                </template>
                <template v-else-if="col.key === 'type'">
                    <span class="text-gray-700 font-medium">
                        {{ row.storage_type ? row.storage_type.name : '-' }}
                    </span>
                </template>
                <template v-else-if="col.key === 'status'">
                    <span class="text-gray-700 font-medium">
                        {{ row.status ?? '-' }}
                    </span>
                </template>
                <template v-else>
                    <div v-if="row.id > 0" class="flex items-center justify-end space-x-3">
                        <template v-if="rule('storage.update')">
                            <a-tooltip :content="$t('nav.b.edit')" placement="top">
                                <span
                                    class="text-blue-500 cursor-pointer"
                                    @click="editStorage(row.id)"
                                >
                                    <i-icon class="size-4" icon="edit" />
                                </span>
                            </a-tooltip>
                        </template>
                        <template v-if="rule('storage.delete')">
                            <a-popconfirm :title="$t('text.del-confirm')" @confirm="() => {}">
                                <span class="cursor-pointer text-rose-500">
                                    <i-icon class="size-4" icon="delete" />
                                </span>
                            </a-popconfirm>
                        </template>
                    </div>
                </template>
            </template>
        </a-table>
    </div>

    <a-drawer
        v-model:open="modalState"
        :title="`${$t(form.id > 0 ? 'nav.b.edit' : 'nav.b.create')} ${$t('nav.a.storages')}`"
        :width="$isMobile() ? '100%' : '50%'"
        @close="resetForm"
    >
        <a-form :model="form" layout="vertical">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                <a-form-item
                    :label="`${$t('validation.attributes.code')} (${$t('nav.a.storages')})`"
                    :rules="formRule.code"
                    name="code"
                >
                    <a-input
                        v-model:value="form.code"
                        :placeholder="`${$t('validation.attributes.code')} (${$t('nav.a.storages')})`"
                    />
                </a-form-item>

                <a-form-item
                    :label="`${$t('validation.attributes.name')} (${$t('nav.a.storages')})`"
                    :rules="formRule.name"
                    name="name"
                >
                    <a-input
                        v-model:value="form.name"
                        :placeholder="`${$t('validation.attributes.name')} (${$t('nav.a.storages')})`"
                    />
                </a-form-item>

                <a-form-item :label="$t('validation.attributes.enumerate_id')" name="enumerate_id">
                    <a-select
                        v-model:value="form.enumerate_id"
                        :options="
                            (statuses || [])
                                .filter((e) => e.type === 'storage')
                                .map((e) => {
                                    return {
                                        value: e.id,
                                        label: locale === 'vi' ? e.name : e.name_alias,
                                    }
                                })
                        "
                    />
                </a-form-item>

                <a-form-item :label="$t('nav.a.storages')" name="parent_id">
                    <a-select
                        v-model:value="form.parent_id"
                        :options="
                            (storage.data || []).map((e) => {
                                return {
                                    value: e.id,
                                    label: e.mapped_name || e.name,
                                    disabled: e.disabled,
                                }
                            })
                        "
                        :placeholder="$t('text.select')"
                    />
                </a-form-item>

                <a-form-item
                    :label="$t('validation.attributes.established_at')"
                    name="established_at"
                >
                    <a-date-picker
                        v-model:value="form.established_at"
                        class="w-full"
                        type="date"
                    ></a-date-picker>
                </a-form-item>

                <select-input-place
                    v-if="rule('place.create')"
                    v-model="form.place_id"
                    :label="$t('nav.a.places')"
                    name="place_id"
                    required
                />
            </div>
        </a-form>

        <template #footer>
            <span class="dialog-footer flex flex-nowrap items-center justify-end space-x-3">
                <a-button @click="modalState = false">
                    <span><i-icon icon="close" /></span>
                    <span>{{ $t('nav.b.cancel') }}</span>
                </a-button>
                <a-button danger type="primary" @click="submitForm">
                    <span><i-icon icon="content-save" class="size-4" /></span>
                    <span>{{ $t('nav.b.save') }}</span>
                </a-button>
            </span>
        </template>
    </a-drawer>
</template>

<script setup>
import { defineComponent, ref, nextTick } from 'vue'
import { iAlert, rule } from '@/mixins'
import ajax from '@/ajax'
import { useI18n } from 'vue-i18n'
import { useStorages, useEnumerates } from '@/hooks'
import { cookie, COOKIE_KEY_LOCALE, defaultLocale } from '@/config'
import { getStorage } from '@/libs'

defineComponent({
    name: 'Storage',
})

const columns = [
    {
        key: 'name',
        title: ['validation.attributes.name'],
    },
    {
        key: 'status',
        title: ['validation.attributes.status'],
    },
    {
        key: 'type',
        title: ['validation.attributes.enumerate_id'],
    },
    { key: 'action', align: 'right' },
]

const modalState = ref(false)
const formDefault = () => {
    return {
        id: 0,
        uid: null,
        name: null,
        code: null,
        established_at: null,
        established_by: null,
        parent_id: null,
        enumerate_id: null,
        place_id: null,
        user_id: null,
        status: 'status_published',
    }
}
const form = ref(formDefault())
const { data: statuses } = useEnumerates()
const { data: storage, refetch: storageRefetch } = useStorages({
    placeId: form.value.place_id || null,
})
const locale = cookie.get(COOKIE_KEY_LOCALE, defaultLocale)

const { t } = useI18n()

const btnState = ref(false)
const formRule = {
    name: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', {
                a: `${t('validation.attributes.name')} ${t('nav.a.storages')}`,
            }),
        },
    ],
    code: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.code') }),
        },
    ],
}
/** Reset form */
const resetForm = () => {
    parseData(0)
}
/** Submit form */
const submitForm = () => {
    if (btnState.value) {
        return
    }
    btnState.value = true
    const call =
        form.value.id > 0
            ? ajax.put(`storage/${form.value.id}`, form.value)
            : ajax.post(`storage`, form.value)
    call.then((res) => {
        if (res.data.status === 'success') {
            storageRefetch()
            modalState.value = false
        }
        if (res.data.message) {
            iAlert(res.data)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}
/** Close modal */
const editStorage = async (id) => {
    if (id > 0) {
        await getStorage(id, true).then(async (res) => {
            form.value = Object.assign(formDefault(), res)
            await nextTick(() => {
                modalState.value = true
            })
            await nextTick(() => {
                parseData(id)
            })
        })
    }
}
/** Parse data to disable selection */
const parseData = (id) => {
    const disabled = []

    if (storage.value.data && storage.value.data.length > 0) {
        storage.value.data.forEach((value) => {
            if (value.id > 0) {
                value['disabled'] = false
                if (
                    id > 0 &&
                    (value.id === id ||
                        (value.parent_id !== null &&
                            value.parent_id > 0 &&
                            disabled.includes(value.parent_id) >= 0))
                ) {
                    value.disabled = true
                    disabled.push(value.id)
                }
            }
        })
    }
}
</script>
