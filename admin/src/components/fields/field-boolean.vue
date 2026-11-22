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
        <a-switch
            v-model:checked="innerValue"
            :checked-value="true"
            :un-checked-value="false"
            :un-checked-children="$t('text.turn-off')"
            :checked-children="$t('text.turn-on')"
            :class="{ 'text-red-500': required && !innerValue }"
            :name="name"
            @change="(value) => (innerValue = value)"
        />
    </a-form-item>
</template>

<script setup>
import { ref, watch, defineComponent } from 'vue'

defineComponent({
    name: 'FieldBoolean',
})

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    name: { type: [String, Array], default: '' },
    noStyle: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    setting: { type: Object, default: () => ({ input_setting: {} }) },
})
const emit = defineEmits(['update:modelValue', 'change'])

const innerValue = ref(props.modelValue)

watch(
    () => props.modelValue,
    (value) => {
        innerValue.value = value
    },
    { immediate: true }
)

watch(
    () => innerValue.value,
    (value) => {
        emit('update:modelValue', value)
        emit('change', value)
    }
)
</script>
