<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup>
import { defineComponent, onMounted, ref, watch, computed } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: String,
        default: 'id',
    },
    label: {
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    size: {
        type: String,
        default: 'default',
    },
    apiFunction: {
        type: Function,
        required: true,
    },
    searchParams: {
        type: Object,
        default: () => ({
            id: 'id',
            keyword: 'keywords',
            pageSize: 'pageSize',
        }),
    },
    optionLabelKey: {
        type: String,
        default: 'name',
    },
    optionValueKey: {
        type: String,
        default: 'id',
    },
    initialPageSize: {
        type: Number,
        default: 10,
    },
})
const emit = defineEmits(['update:modelValue'])
defineComponent({
    name: 'AbstractSelector',
})

const items = ref([])
const innerValue = ref(props.modelValue || null)
const loadingState = ref(false)

const fetchItems = async (value, typeKey) => {
    const params = {
        [typeKey]: value,
    }
    loadingState.value = true
    try {
        const res = await props.apiFunction(params)
        items.value = res
    } catch (err) {
        iAlert(err, true)
    } finally {
        loadingState.value = false
    }
}

const fetchListDebounce = debounce((value) => fetchItems(value, props.searchParams.keyword), 500)

const isExistInList = (value) => {
    return items.value.some((e) => e[props.optionValueKey] === value)
}

const options = computed(() => {
    return (items.value || []).map((e) => ({
        label: e[props.optionLabelKey],
        value: parseInt(e[props.optionValueKey]),
    }))
})

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
    }
)

watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue.value && !isExistInList(val)) {
            fetchItems(val, props.searchParams.id)
        }
        innerValue.value = val
    }
)

onMounted(() => {
    if (props.modelValue > 0) {
        fetchItems(props.modelValue, props.searchParams.id)
    } else {
        fetchItems(props.initialPageSize, props.searchParams.pageSize)
    }
})
</script>

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
            :not-found-content="loadingState ? undefined : null"
            :options="options"
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchListDebounce"
            :size="size"
        />
    </a-form-item>
</template>
