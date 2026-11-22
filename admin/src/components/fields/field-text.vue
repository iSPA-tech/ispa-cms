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
        <a-textarea
            v-model:value="innerValue"
            :placeholder="`${$t(
                setting.input_setting?.description || setting.input_setting?.title || 'validation.attributes.content'
            )} ${setting?.language?.name || ''}`"
            :rows="rows"
        >
            <template v-if="setting.language" #addonAfter>
                <img
                    :src="`${apiUrl}images/flags/4x3/${setting.language.flag}`"
                    alt=""
                    class="w-auto h-4 -ml-1.5 max-w-none"
                />
            </template>
        </a-textarea>
    </a-form-item>
</template>

<script setup>
import { ref, watch } from 'vue'
import { apiUrl } from '@/config'

const props = defineProps({
    modelValue: { type: [String, Number, Boolean, Object, Array], default: null },
    setting: { type: Object, default: {} },
    name: { type: [String, Array], default: '' },
    noStyle: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    rows: { type: Number, default: 2 },
})
const emit = defineEmits(['update:modelValue', 'change'])

const innerValue = ref(props.modelValue)

watch(
    () => props.modelValue,
    (value) => {
        innerValue.value = value
    },
    { immediate: true, deep: true }
)

watch(
    () => innerValue.value,
    (value) => {
        emit('update:modelValue', value)
        emit('change', value)
    }
)
</script>
