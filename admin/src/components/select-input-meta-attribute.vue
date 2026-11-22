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
            :filter-option="false"
            :mode="multiple || undefined"
            :not-found-content="loadingState ? undefined : null"
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchAttributeDebounce"
        >
            <a-select-option v-for="(attr, ind) in attributes || []" :key="ind" :value="attr.id">
                <div class="flex">
                    <span class="flex-1">{{ attr.name }}</span>
                    <span>{{ ' ' }}[{{ attr.type }}]{{ ' ' }}</span>
                    <span class="flex-1 text-right">({{ attr.translated_label }})</span>
                </div>
            </a-select-option>
        </a-select>
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterMetaAttributes } from '@/libs'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
        type: [String, Number, Array],
        default: null,
    },
    placeId: {
        type: Number,
        default: 0,
    },
    name: {
        type: [String, Array],
        default: undefined,
    },
    label: {
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
    multiple: {
        type: String,
        default: '',
        validator(value) {
            return ['multiple', 'tags'].includes(value)
        },
    },
    pageSize: {
        type: Number,
        default: 20,
    },
})
const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'SelectInputMetaAttribute',
})

const attributes = ref([])
const innerValue = ref(props.modelValue || null)
const loadingState = ref(false)
//  Process to update query and search
const fetchAttribute = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
        select: 'id,name,label',
        pageSize: props.pageSize,
    }
    loadingState.value = true
    //  Fetch user list
    await searchFilterMetaAttributes(params)
        .then((res) => {
            attributes.value = res.data || []
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const fetchAttributeDebounce = debounce(fetchAttribute, 500)
const isExistInList = (value) => {
    return (attributes.value || []).some((e) => e.id === value)
}

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
    }
)
watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue && !isExistInList(val)) {
            fetchAttribute(val, 'id')
        }
        innerValue.value = val
    }
)
onMounted(() => {
    fetchAttribute(props.pageSize, 'pageSize')
})
</script>
