<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup="">
import { defineComponent, ref, watch } from 'vue'
import TipTapEditor from '@/components/tiptap-editor.vue'
import IContentVideo from '@/components/i-content-video.vue'

defineComponent({
    name: 'IContentsView',
})

const emit = defineEmits(['update:modelValue', 'change'])

const { modelValue, translated, original } = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    translated: {
        type: Object,
        default: () => {
            return { contents: [] }
        },
    },
    original: {
        type: Object,
        default: () => {},
    },
})

//  Parse new translated data to model
const parseData = () => {
    const newTranslated = {
        ...translated,
    }

    newTranslated.contents.forEach((content, index) => {
        if (content.type === 'video' && content.value.includes('embed')) {
            // change from url `.../embed/videoId?any-params` into .../watch?v=videoId
            const videoId = content.value.split('/').pop().split('?')[0]
            content.value = `https://www.youtube.com/watch?v=${videoId}`
        }

        newTranslated.contents[index] = content
    })

    return newTranslated
}

const innerTranslated = ref(translated || { contents: [] })
const innerValue = ref(modelValue || [])

watch(
    () => translated.value,
    () => {
        innerTranslated.value = parseData()
        innerTranslated.value.contents.forEach((content, index) => {
            innerValue.value[index] = content
        })
    },
    { immediate: true, deep: true }
)

watch(
    () => innerValue.value,
    (val) => {
        //  Update model
        emit('update:modelValue', val)
        emit('change', val)
    },
    { deep: true }
)
</script>

<template>
    <div>
        <div v-for="(content, ind) in innerTranslated.contents" :key="ind" class="relative">
            <template v-if="innerValue[ind] && innerValue[ind].type">
                <div v-if="content.type === 'image'" class="my-4 p-4 bg-blue-50 rounded-sm">
                    <div
                        v-for="(img, i) in content.value"
                        :key="i"
                        class="flex items-start space-x-4"
                    >
                        <div>
                            <img :src="img" alt="" class="max-w-[150px] not-prose" />
                            <a :href="img" class="text-xs m-0" target="_blank">URL</a>
                        </div>

                        <div
                            v-if="content.value && content.value[0]"
                            class="grid grid-cols-2 gap-y-4 md:gap-y-0 md:gap-x-4"
                        >
                            <div class="col-span-2 md:col-span-1">
                                <a-form-item
                                    :label="$t('text.image.caption')"
                                    :name="['content', `${ind}`, 'options', 'cation']"
                                >
                                    <a-input
                                        v-model:value="innerValue[ind].options.caption"
                                        :placeholder="$t('text.editor.caption_placeholder')"
                                        type="text"
                                    ></a-input>
                                    <p class="text-xs m-0 text-gray-500">
                                        {{ $t('text.image.caption-desc') }}
                                    </p>
                                </a-form-item>
                            </div>

                            <div class="col-span-2 md:col-span-1">
                                <a-form-item
                                    :label="$t('text.image.alt')"
                                    :name="['content', `${ind}`, 'options', 'alt']"
                                >
                                    <a-input
                                        v-model:value="innerValue[ind].options.alt"
                                        :placeholder="$t('text.editor.alt_placeholder')"
                                        type="text"
                                    ></a-input>
                                    <p class="text-xs m-0 text-gray-500">
                                        {{ $t('text.image.alt-desc') }}
                                    </p>
                                </a-form-item>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else-if="content.type === 'text'">
                    <tip-tap-editor v-model="innerValue[ind].value" />
                    <div
                        class="bg-gray-50 p-4 text-xs *:mt-0 *:mb-2 [&_td]:py-1"
                        v-html="original.contents[ind].value"
                    />
                </div>
                <div v-else-if="content.type === 'video'" class="my-4 bg-rose-50 p-4 rounded-sm">
                    <i-content-video v-model="innerValue" :index="ind" />
                    <p class="text-xs m-0">{{ original.contents[ind].value ?? '' }}</p>
                </div>
            </template>
        </div>
    </div>
</template>
