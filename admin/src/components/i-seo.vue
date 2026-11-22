<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div
        v-for="(field, ind) in fields"
        :key="field"
        class="flex flex-col relative col-span-2 md:col-span-1"
        :class="[ind < fields.length - 1 ? 'mb-4' : '']"
    >
        <div class="w-full pb-1">
            {{ $t('validation.attributes.seo-' + field) }}
        </div>
        <div class="flex-1">
            <div v-for="(item, ind) in innerForm" :key="ind" :class="{ 'mt-1': ind > 0 }">
                <a-form-item
                    v-if="props.languageId === null || props.languageId === item.language_id"
                    :name="['seos', ind, field]"
                    class="mb-0!"
                >
                    <a-input
                        v-if="field !== 'description'"
                        v-model:value="innerForm[ind][field]"
                        :maxlength="field === 'title' ? 60 : 500"
                        :placeholder="`${$t('validation.attributes.seo-' + field)} ${item.lang_name}`"
                        show-count
                    >
                        <template v-if="item.lang_flag" #prefix>
                            <img
                                :src="`${apiUrl}images/flags/4x3/${item.lang_flag}`"
                                alt=""
                                class="w-auto h-4 -ml-1.5 max-w-none"
                            />
                        </template>
                    </a-input>
                    <a-textarea
                        v-else
                        v-model:value="innerForm[ind][field]"
                        :maxlength="160"
                        :placeholder="`${$t('validation.attributes.seo-' + field)} ${item.lang_name}`"
                        show-count
                    />
                </a-form-item>
            </div>

            <p class="text-xs text-gray-500 m-0">{{ $t('text.e.seo-' + field) }}</p>
        </div>
    </div>
</template>

<script setup>
import { defineComponent, ref, watch, onMounted } from 'vue'
import { apiUrl } from '@/config'
import { useAppRuntime } from '@/runtime'

const fields = ['title', 'hashtag', 'description']
const emit = defineEmits(['update:modelValue'])
const props = defineProps({
    modelValue: { type: Array, default: [] },
    languageId: { type: [Number, String], default: null },
})
defineComponent({
    name: 'ISeo',
})
const { languages } = useAppRuntime()
//  Init inner form
const innerForm = ref(props.modelValue || [])
//  Init form
const initForm = () => {
    const list =
        (languages.value?.data || []).filter(
            (item) => item.for_web === true && item.is_locked === false
        ) || []

    list.forEach((lang) => {
        //    Check if lang exist in inner form
        const langExist = innerForm.value.find((item) => item.language_id === lang.id)
        if (!langExist) {
            const newSeo = {
                ...defaultSeoObject(),
                language_id: lang.id,
                lang_path: lang.path,
                lang_name: lang.name,
                lang_flag: lang.flag,
            }

            innerForm.value.push(newSeo)
        } else {
            langExist.lang_path = lang.path
            langExist.lang_name = lang.name
            langExist.lang_flag = lang.flag
        }
    })
}

//	Default SEO object
const defaultSeoObject = () => {
    return {
        id: 0,
        target_type: null,
        target_id: 0,
        language_id: null,
        title: '',
        description: '',
        hashtag: '',
        facebook: {},
        twitter: {},
        custom: {},
        config: {},
    }
}

watch(
    () => props.modelValue,
    (val) => {
        innerForm.value = val
        initForm()
    }
)

watch(
    () => innerForm.value,
    (val) => {
        emit('update:modelValue', val)
    }
)

watch(
    () => languages.value,
    () => {
        initForm()
    }
)
onMounted(() => {
    initForm()
})
</script>
