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
            :options="
                components.map((e) => {
                    return {
                        label: e.name,
                        value: e.name,
                    }
                })
            "
            :placeholder="$t('text.f.search')"
            class="w-full md:min-w-[150px]"
            @change="updateModelValue"
            show-search
        />
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { isEmpty } from 'lodash-es'
import { getUtilComponents } from '@/libs'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: [String, Array],
        default: 'component',
    },
    label: {
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
})
const emits = defineEmits(['update:modelValue'])
defineComponent({
    name: 'SelectInputComponents',
})

const components = ref([])
const innerValue = ref(props.modelValue || null)
//  Process to update query and search
const fetchWidget = async () => {
    await getUtilComponents().then((res) => {
        if (res?.data) {
            components.value = res.data
        }
    })
}

const updateModelValue = (val) => {
    const component = components.value.find((e) => e.name === val)
    innerValue.value = val
    emits('update:modelValue', val, component)
    emits('change', val, component)
}

watch(
    () => innerValue.value,
    (val) => {
        emits('update:modelValue', val)
    }
)

onMounted(() => {
    fetchWidget(10, 'pageSize')
})
</script>
