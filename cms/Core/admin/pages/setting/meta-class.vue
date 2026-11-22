<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <template v-if="rule('meta_class.viewAny')" class="px-2 md:px-1 py-4 mb-12">
        <div class="flex items-center">
            <h2 class="text-xl font-bold mb-4 md:mb-6 flex-1">{{ $t('nav.a.meta_classes') }}</h2>
            <div>
                <a-button :icon="h(PlusOutlined)" class="btn-success plain" @click="openModal">
                    {{ $t('nav.b.create') }} {{ $t('nav.a.meta_classes') }}
                </a-button>
            </div>
        </div>

        <a-table :columns="columns" :data-source="dataRef || []" size="small">
            <template #headerCell="{ column }">
                <template v-if="Array.isArray(column.title)">
                    {{ column.title.map((t) => $t(t)).join(' ') }}
                </template>
            </template>
            <template #bodyCell="{ column: col, record: row }">
                <template v-if="col.key === 'name'">
                    <p class="m-0">{{ row.name }}</p>
                    <p class="text-gray-400 text-sm m-0">
                        {{ row.options.length }}
                        {{ $t('nav.a.meta_attributes') }}
                    </p>
                </template>
                <template v-if="col.key === 'target'">
                    <span class="font-semibold hover:text-gray-900" role="button">
                        {{ metaTargetLabel(row.target) }}
                    </span>
                </template>
                <template v-if="col.key === 'enumerate'">
                    <span class="font-semibold hover:text-gray-900" role="button">
                        {{ metaTypeLabel(row.type) }}
                    </span>
                </template>
                <template v-if="col.key === 'action'">
                    <a-tooltip v-if="rule('meta_class.edit')" :title="$t('nav.b.edit')">
                        <a-button
                            :icon="h(EditOutlined)"
                            class="btn-info text"
                            type="text"
                            @click="editClass(row.id)"
                        />
                    </a-tooltip>
                </template>
            </template>
        </a-table>

        <a-modal
            v-model:open="modalState"
            :title="`${$t(form.id > 0 ? 'nav.b.update' : 'nav.b.create')} ${$t('nav.a.meta_classes')}`"
            :width="$isMobile() ? '100%' : '50%'"
            @close="resetForm"
        >
            <a-form
                id="metaClassForm"
                :model="form"
                :rules="formRule"
                layout="vertical"
                @finish="submitForm"
                @finish-failed="finishFailed"
            >
                <select-input-translate
                    v-model="form.name"
                    :label="`${$t('validation.attributes.name')} (${$t('nav.a.meta_classes')})`"
                    name="name"
                    required
                />

                <div class="grid gap-x-3 grid-cols-2 pick-color">
                    <a-form-item :label="$t('validation.attributes.target')" name="target">
                        <a-select
                            v-model:value="form.target"
                            :placeholder="$t('text.select')"
                            class="w-full"
                        >
                            <a-select-option value="User">{{ $t('text.setting.meta_class.target_user') }}</a-select-option>
                            <a-select-option value="Product">{{ $t('text.setting.meta_class.target_product') }}</a-select-option>
                            <a-select-option value="Place">{{ $t('text.setting.meta_class.target_place') }}</a-select-option>
                            <a-select-option value="Item">{{ $t('text.setting.meta_class.target_item') }}</a-select-option>
                            <a-select-option value="Service">{{ $t('text.setting.meta_class.target_service') }}</a-select-option>
                            <a-select-option value="Order">{{ $t('text.setting.meta_class.target_order') }}</a-select-option>
                        </a-select>
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.type')" name="type">
                        <a-select v-model:value="form.type" :placeholder="$t('text.select')">
                            <a-select-option value="class">{{ $t('text.setting.meta_class.type_class') }}</a-select-option>
                            <a-select-option value="group">{{ $t('text.setting.meta_class.type_group') }}</a-select-option>
                        </a-select>
                    </a-form-item>

                    <a-form-item
                        v-if="form.type === 'group'"
                        :label="$t('validation.attributes.parent_id')"
                        name="parent_id"
                    >
                        <a-select v-model:value="form.parent_id" :placeholder="$t('text.select')">
                            <a-select-option
                                :label="$t('text.select')"
                                :value="0"
                                disabled
                            ></a-select-option>
                        </a-select>
                    </a-form-item>
                </div>

                <select-input-meta-attribute
                    v-model="form.options"
                    :label="$t('nav.a.meta_attributes')"
                    multiple="multiple"
                    name="options"
                    required
                />
            </a-form>

            <template #footer>
                <div class="flex flex-nowrap items-center justify-end gap-2">
                    <a-button
                        :disabled="!rule(['meta_class.create', 'meta_class.update'])"
                        :icon="h(StopOutlined)"
                        @click="closeModal"
                    >
                        {{ $t('nav.b.cancel') }}
                    </a-button>
                    <a-button
                        :disabled="!rule(['meta_class.create', 'meta_class.update']) || btnState"
                        :icon="h(SaveOutlined)"
                        :loading="btnState"
                        danger
                        form="metaClassForm"
                        html-type="submit"
                        type="primary"
                    >
                        {{ $t('nav.b.save') }}
                    </a-button>
                </div>
            </template>
        </a-modal>
    </template>
    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { defineComponent, h, onMounted, ref, watch } from 'vue'
import { finishFailed, iAlert, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import ajax from '@/ajax'
import { EditOutlined, PlusOutlined, SaveOutlined, StopOutlined } from '@ant-design/icons-vue'
import { useMetaClasses } from '@/hooks'
import { getMetaClass } from '@/libs'
import { isEmpty } from 'lodash-es'
import { Form } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'

const useForm = Form.useForm
const { t } = useI18n()

const metaTargetKeys = {
    User: 'text.setting.meta_class.target_user',
    Product: 'text.setting.meta_class.target_product',
    Place: 'text.setting.meta_class.target_place',
    Item: 'text.setting.meta_class.target_item',
    Service: 'text.setting.meta_class.target_service',
    Order: 'text.setting.meta_class.target_order',
}
const metaTypeKeys = {
    class: 'text.setting.meta_class.type_class',
    group: 'text.setting.meta_class.type_group',
}
const metaTargetLabel = (target) => (metaTargetKeys[target] ? t(metaTargetKeys[target]) : target)
const metaTypeLabel = (type) => (metaTypeKeys[type] ? t(metaTypeKeys[type]) : type)

defineComponent({
    name: 'SettingMetaClass',
})

const dataRef = ref([])
const { data: dataResponse, refetch } = useMetaClasses({})
const columns = [
    {
        key: 'name',
        title: ['nav.a.meta_attributes'],
    },
    {
        key: 'target',
        title: ['validation.attributes.target'],
    },
    {
        key: 'enumerate',
        title: ['validation.attributes.enumerate_id'],
    },
    {
        key: 'action',
        align: 'right',
    },
]
const modalState = ref(false)
const editClass = async (id) => {
    const data = await getMetaClass(id, true)
    setForm(data)
    openModal()
}
const setForm = (data) => {
    form.value = data
}
const closeModal = () => {
    modalState.value = false
}
const openModal = () => {
    modalState.value = true
}

const dialogState = ref(false)
const formDefault = () => {
    return {
        id: 0,
        name: null,
        target: 'User',
        type: 'class',
        parent_id: null,
        user_id: null,
        options: [],
    }
}
const form = ref(formDefault())
const formRule = ref({})
const { resetFields, clearValidate } = useForm(form, formRule)
const selectedList = ref([])
const btnState = ref(false)
const resetForm = () => {
    form.value = formDefault()

    resetFields()
    clearValidate()
}
const submitForm = (data = {}) => {
    if (btnState.value) {
        return
    }

    const value = objectAssignOnlyByKeyHasValue(form.value, data)
    btnState.value = true
    const call =
        form.value.id > 0
            ? ajax.put('meta-class/' + value.id, value)
            : ajax.post('meta-class', value)

    call.then((res) => {
        if (res?.status === 'success') {
            if (value.id < 1) {
                resetForm()
                //  Re-call the list
                // refetch()
            }
        }
        if (res?.message) {
            iAlert(res.data)
        }
    }).finally(() => {
        btnState.value = false
    })
}

const openDialog = () => {
    dialogState.value = true
}

onMounted(() => {
    //  No need to get options of attributes
})

watch(
    () => dataResponse.value,
    (val) => {
        dataRef.value = val
    }
)
</script>
