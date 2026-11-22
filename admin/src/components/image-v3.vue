<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <img
            v-if="src"
            :alt="altText"
            :class="{ 'cursor-pointer hover:shadow-xs': original }"
            :src="url"
            class="shrink-0 border rounded-sm w-full"
            @click="open = true"
        />
        <span v-else class="w-10 h-10 border rounded-sm flex items-center text-gray-300 text-xl">
            <i-icon icon="images" class="size-4 mx-auto" />
        </span>
        <TransitionRoot v-if="original" :show="open" as="template">
            <Dialog as="div" class="fixed z-999 inset-0 overflow-y-auto" @close="open = false">
                <div
                    class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0"
                >
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0"
                        enter-to="opacity-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100"
                        leave-to="opacity-0"
                    >
                        <DialogOverlay class="fixed inset-0 bg-gray-500/75 transition-opacity" />
                    </TransitionChild>
                    <span
                        aria-hidden="true"
                        class="hidden sm:inline-block sm:align-middle sm:h-screen"
                        >&#8203;</span
                    >
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <div
                            class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-[86%] sm:w-auto sm:p-6 relative"
                        >
                            <div>
                                <img
                                    :src="original"
                                    alt=" "
                                    class="rounded-sm object-contain mx-auto max-h-[80vh]"
                                />
                                <p v-if="altText" class="text-gray-500 italic text-sm leading-6">
                                    {{ altText }}
                                </p>
                            </div>
                            <span
                                class="absolute cursor-pointer z-10 w-8 h-8 -top-4 shadow-xs border -right-4 rounded-full bg-white text-center leading-7 text-2xl"
                                @click="open = false"
                                >&times;</span
                            >
                        </div>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>
    </div>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { Dialog, DialogOverlay, TransitionRoot, TransitionChild } from '@headlessui/vue'
import { apiUrl } from '@/config'

const props = defineProps({
    src: { type: [String, Object], default: null },
    type: { type: String, default: 'thumbnail_lg' },
    //  Use for quick upload
    targetId: {
        type: Number,
        default: (val) => {
            return val >>> 0
        },
    },
    target: { type: String, default: null },
    alt: { type: String, default: null },
})

defineComponent({
    name: 'ImageV3',
})

const open = ref(false)

const altText = ref(props.alt || null)
const original = ref(null)
const url = ref(null)

/** Show image by input
 * @param {Object} obj
 * @return {String}
 * @last_updated 6.19.6 - 2022-05-05, 08:34 ICT - by malayvuong
 * - detected is deleted and add the params
 */
const showUrl = (obj) => {
    let url
    let baseUrl = apiUrl.trim()
    //  remove last slash of apiUrl
    if (baseUrl[baseUrl.length - 1] === '/') {
        baseUrl = baseUrl.slice(0, -1)
    }

    if (obj) {
        if (props.type === 'cover') {
            url = obj.cover + (obj.deleted_at ? '&isDelete=true' : '') || null
        } else if (props.type === 'url') {
            url = obj.url + (obj.deleted_at ? '&isDelete=true' : '') || null
        } else {
            url = obj.thumbnail_sm + (obj.deleted_at ? '&isDelete=true' : '') || null
        }
        altText.value = props.alt || obj.name || null
        original.value = baseUrl + (obj.url || null)
    }

    //  Detect if url has not http, then set with apiUrl
    if (url && url.indexOf('http') === -1) {
        url = baseUrl + url
    }

    return url
}

onMounted(() => {
    if (typeof props.src === 'object') {
        url.value = showUrl(props.src)
    } else {
        url.value = props.src
    }
})

watch(
    () => props.src,
    (val) => {
        if (val) {
            if (typeof props.src === 'object') {
                url.value = showUrl(val)
            } else {
                url.value = val
            }
        }
    }
)
</script>
