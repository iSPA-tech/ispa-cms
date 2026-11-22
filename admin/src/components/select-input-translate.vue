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
            :placeholder="$t('text.f.search')"
            allow-clear
            class="w-full md:min-w-[150px]"
            show-search
            @search="fetchTranslateDebounce"
        >
            <a-select-option
                v-for="(trans, ind) in translates?.data || []"
                :key="ind"
                :value="
                    isAdmin
                        ? trans.name.replace(`${trans.group_name}.`, `${trans.group_name[0]}.`)
                        : trans.name
                "
            >
                <div class="flex">
                    <span class="flex-1">{{
                        isAdmin
                            ? trans.name.replace(`${trans.group_name}.`, `${trans.group_name[0]}.`)
                            : trans.name
                    }}</span>
                    <span class="text-gray-400 text-ellipsis max-w-[50%]">
                        {{ (isVi ? trans.text.vi : trans.text.en) || '' }}
                    </span>
                </div>
            </a-select-option>
        </a-select>
    </a-form-item>
</template>

<script setup>
import { computed, defineComponent, onMounted, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'
import { searchFilterTranslates } from '@/libs'
import { iAlert, translateNameHandle } from '@/mixins'
import { useAppRuntime } from '@/runtime'

const props = defineProps({
    modelValue: {
        type: [String, Number],
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
    isAdmin: {
        type: Boolean,
        default: false,
    },
})
const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'SelectInputTranslate',
})

const { locale } = useAppRuntime()
const isVi = computed(() => locale.value === 'vi')
const translates = ref([])
const innerValue = ref(props.modelValue || null)
const loadingState = ref(false)
//  Process to update query and search
const fetchTranslate = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
    }
    loadingState.value = true
    //  Fetch user list
    await searchFilterTranslates(params)
        .then((res) => {
            translates.value = res.data
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const fetchTranslateDebounce = debounce(fetchTranslate, 500)
const isExistInList = (value) => {
    return (translates.value.data || []).some((e) => e.id === value)
}

watch(
    () => innerValue.value,
    (val) => {
        if (props.isAdmin) {
            emit('update:modelValue', translateNameHandle(val))
        } else {
            emit('update:modelValue', val)
        }
    }
)
watch(
    () => props.modelValue,
    (val) => {
        if (val > 0 && val !== innerValue && !isExistInList(val)) {
            fetchTranslate(val, 'id')
        }
        innerValue.value = val
    }
)
onMounted(() => {
    fetchTranslate(10, 'pageSize')
})
</script>
