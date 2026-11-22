<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <label v-if="!noLabel" class="text-sm text-blk-950 mt-3 mb-2">
        <span v-if="required" class="text-red-500">* </span>
        <span v-if="label" class="pr-3">{{ label }}</span>
        <span>{{ getTypeLabel(type) }}</span>
        <span v-if="limit > 0" class="pl-2.5"> ({{ innerValue.length }}/{{ limit }}) </span>
    </label>
    <div class="border border-dashed border-gray-400 bg-white text-center p-2 rounded-lg mt-1.5">
        <modal-media-center
            :id="`modal-type-${type}`"
            v-model="innerValue"
            v-model:show="mediaShowState"
            :folder="folder"
            :limit="limit"
            :type="type"
            :category="category"
            :list-endpoint="listEndpoint"
            :upload-endpoint="uploadEndpoint"
            :upload-hint="uploadHint"
        >
            <template #children>
                <div
                    :class="['image', 'avatar'].includes(type) ? 'flex-wrap' : 'flex-col'"
                    class="w-full flex gap-3"
                >
                    <figure
                        v-for="(file, ind) in innerValue"
                        :key="ind"
                        :class="['image', 'avatar'].includes(type) ? 'w-20 h-20' : 'w-full p-3'"
                        class="relative border rounded-sm bg-gray-100 inline-flex justify-start shadow-xs m-0"
                    >
                        <template v-if="file">
                            <span
                                class="transition-all absolute bg-white rounded-full -top-1.5 -right-1.5 text-red-400 w-6 h-6 text-center text-sm border hover:shadow-xs hover:text-red-600"
                                role="button"
                                @click="removeMedia(ind)"
                            >
                                <i-icon class="size-4" icon="delete" />
                            </span>

                            <i-image
                                v-if="['image', 'avatar'].includes(type)"
                                :src="file"
                                class="w-full h-full object-contain rounded-sm overflow-hidden"
                            />
                            <div v-else class="w-full">
                                <p class="text-left">
                                    {{ file.alias_name || file.name }}
                                </p>
                                <audio
                                    v-if="type === 'audio'"
                                    :src="`${apiUrl}${removeLeftTrailingSlash(file.url)}`"
                                    class="w-full shadow-xs"
                                    controls
                                />
                                <p
                                    v-if="!['image', 'audio', 'avatar'].includes(type)"
                                    class="text-left m-0 text-gray-500 text-sm"
                                >
                                    {{ file.size }}
                                </p>
                            </div>
                        </template>
                    </figure>
                    <label
                        class="rounded-sm transition-all w-20 h-20 items-center p-3 shadow-xs text-cyan-600 bg-slate-50 hover:bg-cyan-100 hover:text-slate-500 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 border border-indigo-100"
                        role="button"
                        @click="openMediaCenter"
                    >
                        <span
                            class="text-xl mx-auto text-center leading-6 flex flex-col items-center"
                        >
                            <i-icon class="size-6" icon="cloud-upload" />
                            <b class="text-xs font-normal">{{ $t('text.select') }}</b>
                        </span>
                    </label>
                </div>
            </template>
        </modal-media-center>
    </div>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import { apiUrl } from '@/config'
import { removeLeftTrailingSlash } from '@/mixins'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    limit: { type: Number, default: 8 },
    folder: { type: String, default: 'User' },
    category: { type: String, default: 'images' },
    type: {
        type: String,
        default: 'image',
        validator: (val) =>
            ['image', 'avatar', 'video', 'audio', 'archive', 'document', 'all'].includes(val),
    },
    required: { type: Boolean, default: false },
    noLabel: { type: Boolean, default: false },
    label: { type: String, default: null },
    // Optional module-owned list/upload routes, forwarded to the media center (defaults keep the general store).
    listEndpoint: { type: String, default: null },
    uploadEndpoint: { type: String, default: null },
    uploadHint: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change'])
defineComponent({
    name: 'MediaSelection',
})

const innerValue = ref(props.modelValue)
const mediaShowState = ref(false)
//  Delete image from innerValue (new array so the media center re-maps its selected ids)
const removeMedia = (index) => {
    innerValue.value = innerValue.value.filter((_, i) => i !== index)
}
//  Open media center
const openMediaCenter = () => {
    mediaShowState.value = true
}

const getTypeLabel = (type = '') => {
    switch (type) {
        case 'all':
            return t('text.files')
        case 'image':
            return t('text.tab.image')
        case 'avatar':
            return t('text.tab.avatar')
        case 'audio':
            return t('text.audio')
        case 'video':
            return t('web.media.video')
        case 'document':
            return t('web.document.title')
        case 'archive':
            return t('text.upload.archive')
        default:
            return ''
    }
}

watch(
    () => props.modelValue,
    (val) => {
        innerValue.value = val
    }
)

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
        emit('change', val)
    }
)
</script>
