<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <a-table
            :data-source="innerData"
            :pagination="false"
            :columns="columns"
            size="small"
            :empty-text="$t('text.no-data')"
        >
            <template #headerCell="{ column }">
                <template v-if="Array.isArray(column.title)">
                    {{ column.title.map((t) => $t(t)).join(' ') }}
                </template>
            </template>
            <template #bodyCell="{ column: col, record: row, index }">
                <template v-if="col.key === 'name'">
                    <span
                        class="text-gray-700 font-medium"
                        :class="{ 'line-through text-gray-400': row._deleted }"
                    >
                        {{ row.name || $t('text.not_applicable') }}
                    </span>
                    <span v-if="row._deleted" class="text-rose-500 text-xs ml-2">
                        {{ $t('text.widget.pending_delete') }}
                    </span>
                </template>
                <template v-else-if="col.key === 'type'">
                    <span class="text-gray-700 font-medium">{{ row.type || $t('text.not_applicable') }}</span>
                    <span
                        v-if="
                            row.type === 'image' && row.options[0] && row.options[0].is_background
                        "
                        class="text-green-500 text-xs"
                    >
                        {{ $t('text.widget.background') }}
                    </span>
                </template>
                <template v-else-if="col.key === 'default_value'">
                    <span class="text-gray-700 font-medium">{{ row.default_value || $t('text.not_applicable') }}</span>
                </template>
                <template v-else-if="col.key === 'action'">
                    <div class="flex items-center gap-x-4 justify-end">
                        <template v-if="row._deleted">
                            <a-button
                                v-if="rule('widget_input.update')"
                                :icon="h(UndoOutlined)"
                                class="btn-success plain text-xs"
                                size="small"
                                :data-test="`input-undo-${row.id}`"
                                @click="undoDeleteInput(row)"
                            >
                                {{ $t('nav.b.restore') }}
                            </a-button>
                        </template>
                        <template v-else>
                            <a-button
                                v-if="rule('widget_input.update')"
                                :icon="h(EditOutlined)"
                                class="btn-success plain text-xs"
                                size="small"
                                :data-test="`input-edit-${row.id}`"
                                @click="editWidgetInput(index)"
                            >
                                {{ $t('nav.b.edit') }}
                            </a-button>
                            <a-popconfirm
                                v-if="row.id > 0 && rule('widget_input.delete')"
                                :title="$t('text.del-confirm')"
                                :ok-text="$t('nav.b.delete')"
                                :cancel-text="$t('nav.b.cancel')"
                                :icon="h(DeleteOutlined)"
                                :ok-type="'danger'"
                                @confirm="stageDeleteInput(row)"
                            >
                                <a-button
                                    :icon="h(DeleteOutlined)"
                                    class="btn-danger plain text-xs"
                                    size="small"
                                    :data-test="`input-delete-${row.id}`"
                                >
                                    {{ $t('nav.b.delete') }}
                                </a-button>
                            </a-popconfirm>
                        </template>
                    </div>
                </template>
            </template>
        </a-table>
    </div>
    <div>
        <widget-input-form
            v-model="innerForm"
            v-model:open="isFormOpen"
            @change="updateFormInputs"
        />
    </div>
</template>

<script setup>
import { defineComponent, watch, ref, h } from 'vue'
import { DeleteOutlined, EditOutlined, UndoOutlined } from '@ant-design/icons-vue'
import WidgetInputForm from './widget-input-form.vue'
import { rule } from '@/mixins'

const columns = [
    { key: 'name', title: ['text.widget.inputs'] },
    { key: 'type', title: ['validation.attributes.input_type'] },
    { key: 'default_value', title: ['text.defaultValue'] },
    { key: 'action', align: 'right' },
]

defineComponent({
    name: 'WidgetInput',
    components: {
        WidgetInputForm,
    },
})

const props = defineProps({
    modelValue: {
        type: [Array],
        default: () => [],
    },
})

const emits = defineEmits(['update:modelValue', 'change'])

const innerData = ref([])
const innerForm = ref({})
const currentIndex = ref(0)
const isFormOpen = ref(false)
const editWidgetInput = (index) => {
    currentIndex.value = index
    innerForm.value = innerData.value[index]
    isFormOpen.value = true
}

//  Stage an input row for deletion. Persisted rows (id > 0) are only marked
//  `_deleted` locally - the actual DELETE is committed by the parent widget's
//  aggregate Save (deleted_input_ids), not by an immediate call.
const stageDeleteInput = (row = {}) => {
    if (row.id > 0) {
        row._deleted = true
    }
}

//  Undo a staged deletion before Save commits it.
const undoDeleteInput = (row = {}) => {
    row._deleted = false
}

const updateFormInputs = (value = {}) => {
    const newInputs = [...innerData.value]
    //  splice newTemplates by index and value
    newInputs.splice(currentIndex.value, 1, value)

    innerData.value = newInputs
    updateValueModel()

    //  close form
    isFormOpen.value = false
}

const updateValueModel = () => {
    emits('update:modelValue', innerData.value)
    emits('change', innerData.value)
}

watch(
    () => props.modelValue,
    (val) => {
        innerData.value = val
    },
    {
        immediate: true,
    }
)
</script>
