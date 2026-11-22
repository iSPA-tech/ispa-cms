<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="px-2 md:px-1 pb-4 min-h-full">
        <div class="flex items-center justify-end">
            <a-button
                v-if="rule('widget_theme.create')"
                :icon="h(PlusOutlined)"
                class="btn-success plain text-xs"
                size="small"
                data-test="template-add"
                @click="addTemplate"
                :disabled="isFormOpen"
            >
                {{ $t('nav.b.add') }} {{ $t('validation.attributes.widget_template') }}
            </a-button>
        </div>
        <div>
            <a-table
                :data-source="form"
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
                            {{ localizedName(row.name_translations, row.name, locale) || $t('text.not_applicable') }}
                        </span>
                        <span v-if="row._deleted" class="text-rose-500 text-xs ml-2">
                            {{ $t('text.widget.pending_delete') }}
                        </span>
                    </template>
                    <template v-else-if="col.key === 'query'">
                        <template v-for="(item, ind) in Object.keys(row.query || {})" :key="ind">
                            <span class="text-gray-700 font-medium">{{ item }}: </span>
                            <span class="text-gray-700 font-medium">{{ row.query[item] }}</span>
                        </template>
                    </template>
                    <template v-else-if="col.key === 'project_name'">
                        {{ row.project_name.join(', ') || $t('text.not_applicable') }}
                    </template>
                    <template v-else-if="col.key === 'action'">
                        <div class="flex items-center gap-x-4 justify-end">
                            <template v-if="row._deleted">
                                <a-button
                                    v-if="rule('widget_theme.update')"
                                    :icon="h(UndoOutlined)"
                                    class="btn-success plain text-xs"
                                    size="small"
                                    :data-test="`template-undo-${row.id}`"
                                    @click="undoRemoveTemplate(row)"
                                >
                                    {{ $t('nav.b.restore') }}
                                </a-button>
                            </template>
                            <template v-else>
                                <a-button
                                    v-if="rule('widget_theme.update')"
                                    :icon="h(EditOutlined)"
                                    class="btn-success plain text-xs"
                                    size="small"
                                    :data-test="`template-edit-${row.id}`"
                                    @click="editWidgetTemplate(index)"
                                >
                                    {{ $t('nav.b.edit') }}
                                </a-button>
                                <a-popconfirm
                                    v-if="rule('widget_theme.delete')"
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.delete')"
                                    :title="$t('text.del-confirm')"
                                    ok-type="danger"
                                    @confirm="removeTemplate(row)"
                                >
                                    <a-button
                                        :icon="h(DeleteOutlined)"
                                        class="btn-danger plain text-xs"
                                        size="small"
                                        :data-test="`template-delete-${row.id}`"
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
            <widget-template-form
                v-model="templateForm"
                v-model:open="isFormOpen"
                @change="updateFormTemplate"
            />
        </div>
    </div>
</template>

<script setup>
import { defineComponent, h, ref, watch } from 'vue'
import { DeleteOutlined, EditOutlined, PlusOutlined, UndoOutlined } from '@ant-design/icons-vue'
import { widgetTemplateDefault } from '@/hooks'
import { rule } from '@/mixins'
import { useI18n } from 'vue-i18n'
import { localizedName } from '@/libs/widget-names'
import WidgetTemplateForm from './widget-template-form.vue'

const columns = [
    { key: 'name', title: ['validation.attributes.widget_template'] },
    { key: 'query', title: ['text.widget.query'] },
    { key: 'project_name', title: ['text.template_id'] },
    { key: 'action', align: 'right' },
]

defineComponent({
    name: 'WidgetTemplate',
})
const props = defineProps({
    modelValue: {
        type: [Array],
        default: () => [],
    },
})
const emits = defineEmits(['update:modelValue', 'change'])

// The table names each design in the admin language, from the texts being edited (not saved yet).
const { locale } = useI18n()

const form = ref(props.modelValue)
const templateForm = ref(widgetTemplateDefault())
const currentIndex = ref(0)
const isFormOpen = ref(false)

const editWidgetTemplate = (index) => {
    currentIndex.value = index
    templateForm.value = {
        ...widgetTemplateDefault(),
        ...form.value[index],
    }
    isFormOpen.value = true
}
const addTemplate = () => {
    const newTemplate = widgetTemplateDefault()
    form.value.push(newTemplate)
    currentIndex.value = form.value.length - 1
    templateForm.value = { ...newTemplate } // <- fresh copy
    isFormOpen.value = true
}

const updateFormTemplate = (value = {}) => {
    if (!value.name || value.name.trim() === '') {
        // remove blank template immediately
        form.value.splice(currentIndex.value, 1)
    } else {
        const newTemplates = [...form.value]
        newTemplates.splice(currentIndex.value, 1, value)
        form.value = newTemplates
    }

    updateValueModel()
    isFormOpen.value = false
}

//  Stage a template row for deletion. Persisted rows (id > 0) are only
//  marked `_deleted` locally - the actual DELETE is committed by the parent
//  widget's aggregate Save (deleted_template_ids), not by an immediate call.
//  Unsaved rows (id === 0) have nothing to stage server-side, so they are
//  dropped from the working list right away.
const removeTemplate = (row) => {
    if (row.id > 0) {
        row._deleted = true
    } else {
        const index = form.value.indexOf(row)
        if (index > -1) {
            form.value.splice(index, 1)
        }
    }

    currentIndex.value = 0
}

//  Undo a staged deletion before Save commits it.
const undoRemoveTemplate = (row) => {
    row._deleted = false
}

const updateValueModel = () => {
    const cleaned = form.value.filter((t) => t.name && t.name.trim() !== '')
    emits('update:modelValue', cleaned)
    emits('change', cleaned)
}

watch(
    () => props.modelValue,
    (val) => {
        form.value = val
    }
)
</script>
