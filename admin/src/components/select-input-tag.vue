<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <a-form-item
            :name="name"
            :rules="[
                {
                    required: required,
                    trigger: 'blur',
                    message: $t('validation.required', {
                        a: $t('validation.attributes.tags'),
                    }),
                },
            ]"
            class="more_tools mb-0"
        >
            <template #label>
                <div class="flex items-center flex-1">
                    <span class="flex-1">{{ $t('validation.attributes.tags') }}</span>
                </div>
            </template>

            <a-cascader
                v-model:value="innerForm"
                :field-names="{ label: 'name', value: 'id' }"
                :options="tag?.data || []"
                :placeholder="$t('text.select')"
                change-on-select
                class="w-full"
                multiple
            />
        </a-form-item>
    </div>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import { useTags } from '@/hooks'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => {},
    },
    required: { type: Boolean, default: false },
    name: { type: [String, Array], default: 'tag_id' },
})

const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'SelectInputTag',
})

const { data: tag, refetch } = useTags({ type: props.type })
const tagModalState = ref(false)
const innerForm = ref(props.modelValue)
const innerName = ref(null)
//  Search item by node in reservation tag list
const searchItem = (data = [], node = 0) => {
    let item = {}
    //  Find item in data by node, if found any => return immediate
    //  if not found, find in the children list
    data.some((i) => {
        if (i.id === node) {
            item = i
            return true
        }
        if (i.children && i.children.length) {
            item = searchItem(i.children, node)
            if (item.id) {
                return true
            }
        }
    })

    return item
}

watch(
    () => tagModalState.value,
    (val) => {
        if (!val) {
            refetch()
        }
    }
)

watch(
    [() => props.modelValue, () => props.name],
    ([val, name]) => {
        if (val !== innerForm.value) {
            innerForm.value = val
        }
        if (Array.isArray(name)) {
            innerName.value = name
        } else {
            innerName.value = name
        }
    },
    { immediate: true, deep: true }
)

watch(
    () => innerForm.value,
    (val) => {
        if (val !== props.modelValue) {
            emit('update:modelValue', val)
        }
    }
)
</script>
