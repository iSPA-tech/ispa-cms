<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-6 mb-4">
        <div v-for="(i, ind) in innerList" :key="ind" class="sm:flex">
            <template v-if="i && i.id">
                <div class="mb-4 shrink-0 sm:mb-0 sm:mr-4">
                    <i-image
                        :src="i.thumbnail_lg"
                        class="h-32 w-full sm:w-32 border border-gray-300 bg-white text-gray-300"
                    ></i-image>
                </div>
                <div>
                    <h4 class="text-lg break-all line-clamp-1">{{ i.name }}</h4>
                    <p class="mt-1 text-sm text-gray-400 break-all line-clamp-2">
                        {{ i.url }}
                    </p>
                    <div class="flex mt-3">
                        <template v-if="innerList.length > 1">
                            <span v-if="i.main" class="text-yellow-600 pr-2 text-xs leading-8"
                                ><i-icon class="size-3" icon="star" />
                                {{ $t('text.image.default') }}</span
                            >
                            <a-button
                                v-else
                                :loading="upLoading"
                                class="btn-info plain"
                                size="small"
                                @click="setMain(i.id)"
                            >
                                <span><i-icon class="size-3" icon="star" /></span>
                                <span>{{ $t('nav.b.default') }}</span>
                            </a-button>
                        </template>
                        <a-popconfirm
                            :title="$t('text.del-confirm')"
                            @confirm="upDel(i.id, innerList, updateModel)"
                        >
                            <a-button :loading="upLoading" class="btn-danger plain" size="small">
                                <span><i-icon class="size-4" icon="delete" /></span>
                                <span>{{ $t('nav.b.delete') }}</span>
                            </a-button>
                        </a-popconfirm>
                    </div>
                </div>
            </template>
        </div>

        <i-upload
            v-if="isUpload || innerList.length >= max"
            v-model="innerList"
            :accepted="accepted"
            :category="category"
            :max="max"
            :target="target"
            :target-id="targetId"
            @change="updateModel"
        />
    </div>

    <div v-if="target === 'Post' && rule('post.attach.document')" class="pt-4">
        <h3 class="text-gray-700 text-medium text-lg">{{ $t('text.media.attach_document') }}</h3>

        <div v-for="(i, ind) in attachList" :key="ind" class="sm:flex">
            <template v-if="i && i.id">
                <div class="mb-4 shrink-0 sm:mb-0 sm:mr-4">
                    <i-image
                        :src="i.thumbnail_lg"
                        class="h-32 w-full sm:w-32 border border-gray-300 bg-white text-gray-300"
                    />
                </div>
                <div>
                    <h4 class="text-lg break-all line-clamp-1">{{ i.name }}</h4>
                    <p class="mt-1 text-sm text-gray-400 break-all line-clamp-2">
                        {{ i.url }}
                    </p>
                    <div class="flex mt-3">
                        <template v-if="attachList.length > 1">
                            <span v-if="i.main" class="text-yellow-600 pr-2 text-xs leading-8"
                                ><i-icon class="size-3" icon="star" />
                                {{ $t('text.image.default') }}</span
                            >
                            <a-button
                                v-else
                                :loading="upLoading"
                                class="btn-info plain"
                                size="small"
                                @click="setMain(i.id)"
                            >
                                <span><i-icon class="size-3" icon="star" /></span>
                                <span>{{ $t('nav.b.default') }}</span>
                            </a-button>
                        </template>
                        <a-popconfirm
                            :title="$t('text.del-confirm')"
                            @confirm="upDel(i.id, attachList, updateAttach)"
                        >
                            <a-button :loading="upLoading" class="btn-danger plain" size="small">
                                <span><i-icon class="size-4" icon="delete" /></span>
                                <span>{{ $t('nav.b.delete') }}</span>
                            </a-button>
                        </a-popconfirm>
                    </div>
                </div>
            </template>
        </div>

        <i-upload
            v-if="attachList.length < 1"
            v-model="attachList"
            :target="target"
            :target-id="targetId"
            accepted="document"
            category="attach"
            @change="updateAttach"
        />
        <p class="text-sm text-gray-400">{{ $t('text.media.attach_allowed_types') }}</p>
    </div>
</template>
<script setup>
import { defineComponent, ref, watch } from 'vue'
import useUploadMixins from '@/mixins/upload'
import ajax from '@/ajax'
import { rule } from '@/mixins'

const props = defineProps({
    modelValue: { type: [Object, Array], default: null },
    attachs: { type: [Object, Array], default: () => [] },
    target: { type: String, default: null },
    max: { type: Number, default: 8 },
    category: { type: String, default: 'images' },
    targetId: { type: Number, default: (e) => e >>> 0 },
    isUpload: { type: Boolean, default: true },
    accepted: { type: String, default: 'image' },
})

const emits = defineEmits(['update:modelValue', 'update:attachs'])
defineComponent({
    name: 'IImages',
})

const { upDel, upLoading } = useUploadMixins()
const innerList = ref(undefined)
const attachList = ref([])

/** Update form */
const updateModel = () => {
    emit('update:modelValue', innerList.value)
}

const updateAttach = () => {
    emit('update:attachs', attachList.value)
}

/** Set main image */
const setMain = (id) => {
    //  Get list id from innerList
    const listId = innerList.value.map((i) => i.id)
    //  Prepare data to send
    const data = {
        main: id,
        list: listId,
    }
    ajax.post(`upload/main`, data).then((res) => {
        if (res.data.status === 'success') {
            innerList.value.forEach((e) => {
                e.main = e.id === id
            })
        }
    })
}

watch(
    () => props.attachs,
    (val) => {
        attachList.value = val
    }
)

watch(
    () => props.modelValue,
    (val) => {
        innerList.value = val
    }
)

watch(
    () => innerList.value,
    () => {
        updateModel()
    }
)
</script>
