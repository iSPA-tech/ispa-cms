<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="relative">
        <div class="flex flex-col">
            <label for="" class="pb-1.5">{{ label || $t('validation.attributes.content') }}</label>
            <iframe
                :srcdoc="innerForm.value || defaultContent"
                class="border p-2 shadow-sm rounded-sm w-full h-[106px]"
            />
        </div>
        <div class="absolute z-10 top-0 right-0">
            <a-button
                :icon="h(EditOutlined)"
                class="btn-info plain text-xs"
                size="small"
                @click="openModal"
            >
                {{ $t('nav.b.edit') }}
            </a-button>
        </div>
    </div>

    <a-modal
        :open="modalState"
        :title="`${$t('nav.b.edit')} ${label || $t('validation.attributes.content')}`"
        class="w-full lg:w-3/4"
        width="75%"
        :onCancel="closeModal"
    >
        <tip-tap-editor v-model="innerForm.value" :original="innerForm" />

        <template #footer>
            <div class="flex flex-nowrap items-center justify-end space-x-3 pt-4">
                <a-button @click="closeModal" :icon="h(CloseCircleOutlined)">
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    danger
                    type="primary"
                    class="btn-danger plain"
                    :icon="h(SaveOutlined)"
                    @click="submitForm"
                >
                    {{ $t('nav.b.save') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { defineComponent, h, ref } from 'vue'
import { CloseCircleOutlined, EditOutlined, SaveOutlined } from '@ant-design/icons-vue'
import TipTapEditor from '@/components/tiptap-editor.vue'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'IContentTextareaEditor',
})

const { t } = useI18n()

const defaultContent = `<p style="color: #888">${t('text.form.click-button-to-edit')}</p>`

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
})
const emits = defineEmits(['update:modelValue', 'change'])
const modalState = ref(false)

const innerForm = ref({
    value: props.modelValue,
})

const openModal = () => {
    modalState.value = true
}

const closeModal = () => {
    modalState.value = false
}

const submitForm = () => {
    modalState.value = false
    emits('update:modelValue', innerForm.value.value)
    emits('change', innerForm.value.value)
}
</script>
