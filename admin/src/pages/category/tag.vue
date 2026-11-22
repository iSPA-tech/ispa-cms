<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <div class="flex place-content-end">
            <a-button v-if="rule('tag.create')" :icon="h(PlusOutlined)" class="btn-success" @click="openForm">
                {{ $t('nav.b.create') }} {{ $t('validation.attributes.tags') }}
            </a-button>
        </div>

        <div class="mt-4">
            <a-spin :indicator="loadingSpinner" :spinning="isLoading">
                <a-table :columns="columns" :data-source="tags || []" row-key="uuid" size="small">
                    <template #headerCell="{ column }">
                        <template v-if="column.key === 'name'">
                            {{ $t('validation.attributes.name') }} {{ $t('validation.attributes.tags') }}
                        </template>
                    </template>
                    <template #bodyCell="{ column: col, record: row }">
                        <template v-if="row && row.uuid">
                            <template v-if="col.key === 'name'">
                                <div class="flex items-center space-x-3">
                                    <div class="flex-1">
                                        <p class="mb-0">
                                            <span
                                                :class="`${
                                                    row.isDeleted
                                                        ? 'line-through text-gray-500'
                                                        : 'font-medium text-cyan-500 hover:text-cyan-700'
                                                }`"
                                                :role="rule('tag.update') ? 'button' : undefined"
                                                @click="rule('tag.update') && editCate(row.uuid)"
                                            >
                                                {{ row.name }}
                                            </span>
                                        </p>
                                        <p v-if="row.slug" class="text-green-600 text-sm mb-0">
                                            {{ row.slug }}
                                        </p>
                                    </div>
                                </div>
                            </template>
                            <template v-else-if="col.key === 'action'">
                                <div class="inline-flex items-center justify-end">
                                    <span
                                        v-if="rule('tag.update')"
                                        class="font-medium text-cyan-500 hover:text-cyan-700"
                                        role="button"
                                        @click="editCate(row.uuid)"
                                    >
                                        <span class="text-blue-500" role="button">
                                            <i-icon class="size-4" icon="edit" />
                                        </span>
                                    </span>
                                    <span
                                        v-if="rule('tag.update') && rule('tag.delete')"
                                        class="mx-2.5 text-gray-300 bg-gray-300 w-px h-1/2"
                                    ></span>
                                    <a-popconfirm
                                        v-if="rule('tag.delete')"
                                        :cancel-text="$t('nav.b.cancel')"
                                        :ok-text="$t('nav.b.delete')"
                                        :title="$t('text.del-confirm')"
                                        ok-type="danger"
                                        @confirm="() => delTag(row.uuid)"
                                    >
                                        <span class="text-rose-500" role="button">
                                            <i-icon class="size-4" icon="delete" />
                                        </span>
                                    </a-popconfirm>
                                </div>
                            </template>
                        </template>
                    </template>
                </a-table>
            </a-spin>
        </div>
    </div>

    <a-drawer
        v-model:open="formState"
        :title="`${$t(form.uuid ? 'nav.b.edit' : 'nav.b.create')} ${$t('validation.attributes.tags')}`"
        :width="$isMobile() ? '100%' : '50%'"
        @close="resetForm"
    >
        <a-form
            :model="form"
            class="cms-form"
            layout="vertical"
            @finish="submit"
            @finishFailed="onFinishFailed"
        >
            <div>
                <a-form-item :label="$t('validation.attributes.name')" name="name">
                    <a-input
                        v-model:value="form.name"
                        :maxlength="255"
                        :placeholder="$t('validation.attributes.name')"
                        show-count
                        type="text"
                    />
                </a-form-item>

                <a-form-item :label="$t('validation.attributes.description')" name="description">
                    <a-textarea
                        v-model:value="form.description"
                        :maxlength="500"
                        :placeholder="$t('validation.attributes.description')"
                        :rows="5"
                        show-count
                    />
                </a-form-item>

            </div>

            <div class="flex items-center space-x-2 pt-4 md:pt-6">
                <div class="flex-1" />
                <a-button :icon="h(StopOutlined)" @click="closeForm">
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    :icon="h(SaveOutlined)"
                    :loading="btnState"
                    :disabled="btnState"
                    danger
                    html-type="submit"
                    type="primary"
                >
                    {{ $t(form.uuid ? 'nav.b.save' : 'nav.b.create') }}
                </a-button>
            </div>
        </a-form>
    </a-drawer>
</template>

<script setup>
import { defineComponent, nextTick, ref, h } from 'vue'
import { useRoute } from 'vue-router'
import { iAlert, loadingSpinner, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { useTags } from '@/hooks'
import { deleteTag, getTag, storeTag, updateTag } from '@/libs'
import { PlusOutlined, SaveOutlined, StopOutlined } from '@ant-design/icons-vue'

const props = defineProps({
    visible: { type: Boolean, default: false },
})
defineComponent({
    name: 'TagManage',
})
const columns = [
    { key: 'name', title: ['validation.attributes.name', 'validation.attributes.tags'] },
    { key: 'action', align: 'right' },
]

const formState = ref(false)
const { isLoading, data: tags, refetch } = useTags()

/** Form */
const formDefault = () => {
    return {
        uuid: '',
        name: null,
        description: null,
    }
}
const btnState = ref(false)
const form = ref(formDefault())
/** Reset form */
const resetForm = () => {
    form.value = formDefault()
}
const closeForm = () => {
    formState.value = false
    resetForm()
}
const onFinishFailed = () => {}
/** Submit the form */
const submit = (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    const call = value.uuid ? updateTag(value.uuid, value) : storeTag(value)

    call.then((res) => {
        if (res.status === 'success') {
            refetch()
            //  Clear form
            if (!value.uuid) {
                resetForm()
            }
        }
        if (res.message) {
            iAlert(res.message)
        }
    })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

const delTag = async (uuid) => {
    await deleteTag(uuid)
        .then((res) => {
            if (res.status === 'success') {
                refetch()
            }
            if (res.message) {
                iAlert(res.message)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}
/** Edit Tag */
const editCate = async (uuid) => {
    await getTag(uuid, true).then((res) => {
        if (res.data) {
            const { uuid: key, name, description } = res.data
            form.value = { ...formDefault(), uuid: key, name, description }
        }
    })

    await nextTick(() => {
        formState.value = true
    })
}

const openForm = async () => {
    formState.value = true
    resetForm()
}
</script>
