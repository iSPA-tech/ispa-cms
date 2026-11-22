<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="text-xs text-gray-600 flex flex-wrap items-center gap-1">
        <span>{{ baseUrl }}</span>
        <template v-for="(path, index) in innerUrl" :key="index">
            <span v-if="index < innerUrl.length">
                <i-icon icon="chevron-right" class="size-3" />
            </span>
            <span>{{ path }}</span>
        </template>
    </div>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { url } from '@/config'

defineComponent({
    name: 'SeoUrlParser',
})

const props = defineProps({
    url: {
        type: String,
        required: true,
    },
})

const baseUrl = ref(url)
const innerUrl = ref([])

onMounted(() => {
    // if url end with slash, remove it
    if (url.endsWith('/')) {
        baseUrl.value = url.slice(0, -1)
    }
})

watch(
    () => props.url,
    (newUrl) => {
        innerUrl.value = newUrl.split('/').filter((part) => part !== '')
    },
    { deep: true, immediate: true }
)
</script>
