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
                          required: true,
                          message: $t('validation.required', { a: label }),
                      },
                  ]
                : undefined
        "
    >
        <a-select
            v-model:value="innerValue"
            :filter-option="false"
            :not-found-content="loadingState ? undefined : null"
            :options="
                (enumerateTypes || []).map((e) => {
                    return {
                        label: e.name,
                        value: parseInt(e.id),
                    }
                })
            "
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
        />
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { isEmpty } from 'lodash-es'
import { getEnumerateTypes } from '@/libs'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: String,
        default: 'type_id',
    },
    label: {
        type: String,
        default: '',
    },
    searchable: {
        type: Boolean,
        default: true,
    },
    required: {
        type: Boolean,
        default: false,
    },
})
const emit = defineEmits(['update:modelValue', 'change'])
defineComponent({
    name: 'SelectInputEnumerateType',
})

const enumerateTypes = ref([])
const innerValue = ref(props.modelValue || null)
const loadingState = ref(false)
//  Process to update query and search
const fetchEnumerateType = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
    }
    loadingState.value = true
    //  Fetch user list
    await getEnumerateTypes(params)
        .then((res) => {
            //  Sort the list by id desc
            res.sort((a, b) => {
                return b.id - a.id
            })

            enumerateTypes.value = res
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const isExistInList = (value) => {
    return enumerateTypes.value.some((e) => e.id === value)
}

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
        const currentObject = enumerateTypes.value.find((e) => e.id === val)
        if (currentObject) {
            emit('change', currentObject)
        }
    },
    { immediate: true }
)
watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue && !isExistInList(val)) {
            fetchEnumerateType(val, 'id')
        }
        innerValue.value = val ? parseInt(val) : null
    },
    { immediate: true }
)

onMounted(() => {
    fetchEnumerateType(10, 'pageSize')
})
</script>
