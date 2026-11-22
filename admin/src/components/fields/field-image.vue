<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <media-selection v-model="innerValue" :limit="1" folder="Setting" no-label type="image" />
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'

const props = defineProps(['modelValue'])
const emit = defineEmits(['update:modelValue', 'change'])
defineComponent({
    name: 'FieldImage',
})

const innerValue = ref(props.modelValue)

watch(innerValue, (value) => {
    emit('update:modelValue', value)
    emit('change', value)
})

watch(
    () => props.modelValue,
    (value) => {
        innerValue.value = value
    }
)
</script>
