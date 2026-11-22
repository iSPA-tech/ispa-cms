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
            :not-found-content="loadingState ? undefined : null"
            :options="
                widgetOptions.map((e) => {
                    return {
                        label: widgetLabel(e),
                        value: parseInt(e.id),
                    }
                })
            "
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            :show-search="searchable"
            @search="fetchWidgetDebounce"
            @change="updateModelValue"
        />
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterWidgets } from '@/libs'
import { widgetLabel } from '@/libs/widget-names'
import { iAlert } from '@/mixins'
import { useWidgets } from '@/hooks'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: null,
    },
    name: {
        type: [String, Array],
        default: 'widget_id',
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
    isAdmin: {
        type: Boolean,
        default: false,
    },
})
const emits = defineEmits(['update:modelValue', 'change'])
defineComponent({
    name: 'SelectInputWidget',
})

const { data: widgets } = useWidgets({
    isAdmin: props.isAdmin,
})
const innerValue = ref(props.modelValue || null)
const widgetOptions = ref([])
const loadingState = ref(false)

watch(
    () => widgets.value?.data,
    (value) => {
        widgetOptions.value = Array.isArray(value) ? value : []
    },
    { immediate: true }
)
//  Process to update query and search
const fetchWidget = async (value, type = 'keywords') => {
    const keywords = String(value ?? '').trim()
    if (type !== 'id' && !keywords) return

    loadingState.value = true
    try {
        const result = await searchFilterWidgets(
            type === 'id'
                ? { id: Number(value), limit: 1 }
                : { keywords, limit: 50 }
        )
        const found = Array.isArray(result) ? result : result?.data || []
        const current = widgetOptions.value
        const merged = [...current, ...found].filter(
            (item, index, items) =>
                items.findIndex((candidate) => Number(candidate.id) === Number(item.id)) === index
        )
        widgetOptions.value = merged
    } catch (err) {
        iAlert(err, true)
    } finally {
        loadingState.value = false
    }
}
const fetchWidgetDebounce = debounce(fetchWidget, 500)
const isExistInList = (value) => {
    return (widgets.value?.data || []).some((e) => Number(e.id) === Number(value))
}

const updateModelValue = (val) => {
    innerValue.value = val
    emits('update:modelValue', val)
    emits('change', val)
}

watch(
    () => innerValue.value,
    (val) => {
        emits('update:modelValue', val)
    }
)
watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue.value && !isExistInList(val)) {
            fetchWidget(val, 'id')
        }
        innerValue.value = val
    }
)
</script>
