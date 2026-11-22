<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        :label="label"
        :name="name"
        :no-style="isEmpty(label)"
        :rules="
            required
                ? [
                      {
                          trigger: 'blur',
                          required: true,
                          message: $t('validation.required', { a: label }),
                      },
                  ]
                : undefined
        "
    >
        <a-select
            v-model:value="innerValue"
            :placeholder="$t('text.f.search')"
            class="w-full md:min-w-[150px]"
            :options="
                projectList.map((item) => ({
                    label: item,
                    value: item,
                }))
            "
            mode="multiple"
        />
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { isEmpty } from 'lodash-es'
import { getProjectList } from '@/libs/utils'

const props = defineProps({
    modelValue: {
        type: [String, Number, Array],
        default: null,
    },
    name: {
        type: [String, Array],
        default: undefined,
    },
    label: {
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
})
const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'SelectInputProject',
})

const projectList = ref([])
const innerValue = ref(props.modelValue || null)

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
    }
)

watch(
    () => props.modelValue,
    (val) => {
        innerValue.value = val
    },
    {
        immediate: true,
    }
)

onMounted(() => {
    getProjectList().then((res) => {
        projectList.value = res
    })
})
</script>
