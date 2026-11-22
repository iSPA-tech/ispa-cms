<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        :label="$t(setting.input_setting.title)"
        :name="name"
        :no-style="noStyle"
        :required="required"
    >
        <a-input-password
            v-if="setting.type === 'password'"
            v-model:value="innerValue"
            :placeholder="`${$t(setting.input_setting?.title || 'validation.attributes.content')} ${
                setting?.language?.name || ''
            }`"
        />
        <a-input
            v-else
            v-model:value="innerValue"
            :placeholder="`${$t(
                setting.input_setting?.description || setting.input_setting?.title || 'validation.attributes.content'
            )} ${setting?.language?.name || ''}`"
            :type="setting.type"
            @change="(value) => debounceChange(value)"
        >
            <template v-if="setting.language" #prefix>
                <img
                    :src="`${apiUrl}images/flags/4x3/${setting.language.flag}`"
                    alt=""
                    class="w-auto h-4 -ml-1.5 max-w-none"
                />
            </template>
        </a-input>
    </a-form-item>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import { apiUrl } from '@/config'
import { debounce } from 'lodash-es'

const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    setting: { type: Object, default: {} },
    name: { type: [String, Array], default: '' },
    noStyle: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'change'])
defineComponent({
    name: 'FieldInput',
})
const innerValue = ref(props.modelValue)
const debounceChange = debounce((value) => {
    const newValue = value?.target ? value.target.value : value
    emit('update:modelValue', newValue)
    emit('change', newValue)
}, 300)

watch(
    () => props.modelValue,
    (newVal) => {
        innerValue.value = newVal
    },
    { deep: true }
)

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
    },
    { deep: true }
)
</script>
