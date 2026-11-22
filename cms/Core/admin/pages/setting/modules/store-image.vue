<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <span class="inline-flex items-center justify-center overflow-hidden" data-testid="store-image">
        <img v-if="src" :src="src" :alt="alt" class="size-full object-cover" />
        <slot v-else />
    </span>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getModuleMedia } from '@/libs/module'

/**
 * One store image. The site serves it from its local cache behind the admin token, so the bytes are fetched as a
 * Blob and shown through an object URL (released when the image goes away). Without a hash, or when the fetch
 * fails, the default slot (a placeholder) is shown instead.
 */
const props = defineProps({
    hash: { type: String, default: null },
    alt: { type: String, default: '' },
})

const src = ref('')
let token = 0

/** Free the current object URL, if any. */
const release = () => {
    if (src.value) URL.revokeObjectURL(src.value)
    src.value = ''
}

/** Fetch the image for the current hash; a stale answer is dropped. */
const load = async () => {
    const mine = ++token
    release()
    if (!props.hash) return
    try {
        const blob = await getModuleMedia(props.hash)
        if (mine !== token) return
        src.value = URL.createObjectURL(blob)
    } catch {
        // The placeholder stays: a missing image never blocks the store.
    }
}

onMounted(load)
watch(() => props.hash, load)
onBeforeUnmount(() => {
    token++
    release()
})
</script>
