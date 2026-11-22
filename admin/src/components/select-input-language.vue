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
            :disabled="disabled"
            :placeholder="$t('text.f.search')"
            class="w-full md:min-w-[150px]"
        >
            <a-select-option v-for="(lang, ind) in languages || []" :key="ind" :value="lang.id">
                <div class="flex">
                    <span class="flex-1">{{ lang.name }}</span>
                    <span v-if="lang.path" class="flex-1 text-right"
                        >({{ lang.path.toUpperCase() }})</span
                    >
                </div>
            </a-select-option>
        </a-select>
    </a-form-item>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { isEmpty } from 'lodash-es'
import { searchFilterLanguages } from '@/libs'
import { iAlert } from '@/mixins'

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
    disabled: {
        type: Boolean,
        default: false,
    },
})
const emit = defineEmits(['update:modelValue'])

defineComponent({
    name: 'SelectInputLanguage',
})

const languages = ref([])
const innerValue = ref(props.modelValue || null)
const loadingState = ref(false)
//  Process to update query and search
const fetchLanguage = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
        select: 'id,name,path',
        pageSize: 100,
    }
    loadingState.value = true
    //  Fetch user list
    await searchFilterLanguages(params)
        .then((res) => {
            languages.value = res.data || []
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const isExistInList = (value) => {
    return (languages.value || []).some((e) => e.id === value)
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
            fetchLanguage(val, 'id')
        }
        innerValue.value = val
    }
)
onMounted(async () => {
    await fetchLanguage(100, 'pageSize')
    if (!innerValue.value && languages.value.length > 0) {
        innerValue.value = languages.value[0].id
    }
})
</script>
