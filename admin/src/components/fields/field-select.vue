<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item :label="$t(setting.input_setting.title)" :name="name" :no-style="noStyle">
        <a-select
            v-model:value="innerValue"
            :options="options"
            :placeholder="`${$t(setting.input_setting?.title || 'validation.attributes.content')} ${
                setting?.language?.name || ''
            }`"
            class="w-full"
            :mode="setting.input_setting?.multiple === true ? 'multiple' : 'default'"
        />
    </a-form-item>
</template>

<script>
import { defineComponent, ref, watch } from 'vue'

export default defineComponent({
    name: 'FieldSelect',
    props: {
        modelValue: { type: [String, Number, Boolean, Object, Array], default: null },
        setting: { type: Object, default: {} },
        name: { type: [String, Array], default: '' },
        options: { type: Array, default: () => [] },
        noStyle: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'change'],
    setup(props, { emit }) {
        const innerValue = ref(props.modelValue)

        watch(
            () => props.modelValue,
            (value) => {
                innerValue.value = value
            },
            { immediate: true, deep: true }
        )

        watch(innerValue, (value) => {
            emit('update:modelValue', value)
            emit('change', value)
        })

        return {
            innerValue,
        }
    },
})
</script>
