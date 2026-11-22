<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Small picture of one widget design for the add drawer: the design rendered with its default content, drawn at
    desktop width and scaled down to the tile. Loaded only when the tile scrolls into view; a blocked widget never
    asks for a picture (the server refuses it). Shows a neutral tile while loading or when there is no picture.
-->
<template>
    <div ref="boxRef" aria-hidden="true" class="relative aspect-[16/10] w-full overflow-hidden rounded-md bg-slate-100">
        <iframe
            v-if="html"
            class="pointer-events-none absolute left-0 top-0 origin-top-left border-0 bg-white"
            data-test="area-thumb-frame"
            referrerpolicy="no-referrer"
            sandbox=""
            :srcdoc="html"
            :style="frameStyle"
            tabindex="-1"
        />
        <span v-else class="absolute inset-0 flex items-center justify-center text-slate-300">
            <i-icon class="size-7" icon="widgets-outline" />
        </span>
    </div>
</template>

<script>
// One request per widget design, shared by every tile of this admin session.
const thumbnailCache = new Map()
</script>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useElementSize } from '@vueuse/core'
import * as api from '../api'

defineOptions({ name: 'AreaWidgetThumbnail' })

const props = defineProps({
    widgetId: { type: Number, required: true },
    themeId: { type: Number, default: null },
    /** Widget version from the list row: a saved design gets a new picture. */
    version: { type: Number, default: 0 },
    /** A blocked widget shows the neutral tile and asks for nothing. */
    disabled: { type: Boolean, default: false },
})

const FRAME_WIDTH = 1200
const FRAME_HEIGHT = 750

const boxRef = ref(null)
const html = ref('')
const { width } = useElementSize(boxRef)

const frameStyle = computed(() => ({
    width: `${FRAME_WIDTH}px`,
    height: `${FRAME_HEIGHT}px`,
    transform: `scale(${(width.value || 0) / FRAME_WIDTH})`,
}))

let observer = null

/** The frame runs no script (`sandbox=""`), so scripts are taken out instead of being refused one by one in the
 * console; a render with nothing to show keeps the neutral tile. */
function pictureHtml(rendered) {
    const clean = String(rendered ?? '').replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    const body = clean.replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '')
    const hasMedia = /<(img|picture|video|audio|iframe|svg|canvas|input|button|select|textarea)\b/i.test(body)
    const hasText = body.replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').trim() !== ''
    return hasMedia || hasText ? clean : ''
}

async function load() {
    if (props.disabled || !props.themeId) return
    const key = `${props.widgetId}-${props.themeId}-${props.version}`
    if (!thumbnailCache.has(key)) {
        const request = api.getWidgetThumbnail(props.widgetId, props.themeId)
        request.catch(() => thumbnailCache.delete(key))
        thumbnailCache.set(key, request)
    }
    try {
        html.value = pictureHtml(await thumbnailCache.get(key))
    } catch {
        html.value = ''
    }
}

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined' || !boxRef.value) {
        load()
        return
    }
    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                observer?.disconnect()
                observer = null
                load()
            }
        },
        { rootMargin: '200px' }
    )
    observer.observe(boxRef.value)
})

onBeforeUnmount(() => observer?.disconnect())
</script>
