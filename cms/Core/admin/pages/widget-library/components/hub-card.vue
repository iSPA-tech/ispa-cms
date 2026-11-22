<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    One widget of the hub this site does not have yet ("Trên hub"): the picture published with its first design, its
    name, what it is, who published it, and "Tải về". The picture is read through the API client only once the card is
    in view; with no picture (or one that cannot be read) the top is a short plain strip. Never a live frame. When the
    widget cannot be downloaded the card says why in plain words and the button cannot be pressed.
-->
<template>
    <article
        ref="cardRef"
        class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white"
        :data-test="`hub-card-${entry.widget_key}`"
    >
        <div
            aria-hidden="true"
            class="relative flex w-full items-center justify-center overflow-hidden border-b border-slate-100 bg-slate-50 text-slate-300"
            :class="pictureExpected ? 'aspect-[16/10]' : 'h-24'"
        >
            <img
                v-if="pictureSrc"
                alt=""
                class="absolute inset-0 size-full object-cover object-top"
                decoding="async"
                :src="pictureSrc"
                @error="pictureFailed = true"
            />
            <i-icon v-else class="size-10" icon="puzzle-outline" />
        </div>

        <div class="flex flex-1 flex-col gap-2 p-3">
            <div class="min-w-0">
                <h3 class="m-0 break-words text-sm font-semibold text-slate-900">{{ widgetLabel(entry) }}</h3>
                <p v-if="entry.publisher" class="m-0 break-words text-xs text-slate-500">
                    {{ $t('text.widget_library.hub_publisher', { publisher: entry.publisher }) }}
                </p>
            </div>

            <p v-if="widgetDescription(entry)" class="m-0 line-clamp-3 break-words text-xs text-slate-600">
                {{ widgetDescription(entry) }}
            </p>

            <p
                v-if="reason"
                class="m-0 break-words text-xs text-amber-800"
                :data-test="`hub-reason-${entry.widget_key}`"
                role="status"
            >{{ $t(reason) }}</p>

            <div class="mt-auto flex flex-wrap gap-2 pt-1">
                <i-button
                    class="min-h-9 flex-1"
                    :data-test="`hub-download-${entry.widget_key}`"
                    :disabled="loading || blocked"
                    :loading="loading"
                    native-type="button"
                    variant="primary"
                    @click="download"
                >
                    <template #icon><i-icon class="size-3.5" icon="cloud-download" /></template>
                    <span>{{ $t('text.widget_library.hub_download') }}</span>
                </i-button>
            </div>
        </div>
    </article>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { widgetDescription, widgetLabel } from '@/libs/widget-names'
import { downloadHubWidget, hubErrorCopy, isFinalHubRefusal, loadHubPreview } from '../../../widget-library-api'

defineOptions({ name: 'WidgetLibraryHubCard' })

const props = defineProps({
    // Hub list entry: {widget_key, name, display_name, description, display_description, publisher, missing_resolvers, preview_theme_keys, preview_urls}.
    entry: { type: Object, required: true },
    // Translation key of why a download of this widget was refused for good (kept by the page until "Tải mới").
    finalReason: { type: String, default: '' },
})

// `done` carries the server answer of a finished download ({id, sync_status}); `refused` the translation key of a
// refusal that pressing again cannot change.
const emit = defineEmits(['done', 'refused'])

// The site lacks something this widget needs: known before pressing.
const needsNewerSystem = computed(() => (props.entry.missing_resolvers ?? []).length > 0)
const blocked = computed(() => needsNewerSystem.value || props.finalReason !== '')

const loading = ref(false)
// Translation key of why the last download did not go through and may be tried again (the hub did not answer).
const retryReason = ref('')
const reason = computed(() =>
    needsNewerSystem.value ? 'text.widget_library.hub_resolver_missing' : props.finalReason || retryReason.value
)

const download = async () => {
    if (loading.value || blocked.value) return
    loading.value = true
    retryReason.value = ''
    try {
        emit('done', await downloadHubWidget(props.entry.widget_key))
    } catch (error) {
        if (isFinalHubRefusal(error)) emit('refused', hubErrorCopy(error))
        else retryReason.value = hubErrorCopy(error)
        loading.value = false
    }
}

// Picture of the first design the hub published one for.
const pictureAddress = computed(() => {
    const urls = props.entry.preview_urls ?? {}
    const first = (props.entry.preview_theme_keys ?? []).find((key) => urls[key]) ?? Object.keys(urls)[0]
    return first ? String(urls[first] ?? '') : ''
})
const pictureSrc = ref('')
const pictureFailed = ref(false)
// The tile keeps the picture's shape while one is expected, so the card does not jump when it arrives.
const pictureExpected = computed(() => pictureAddress.value !== '' && !pictureFailed.value)

const cardRef = ref(null)
const visible = ref(false)
let observer = null
let alive = true

const dropPicture = () => {
    if (pictureSrc.value) URL.revokeObjectURL(pictureSrc.value)
    pictureSrc.value = ''
}

/** Read the picture once the card is in view; any failure leaves the plain strip, without a message. */
const loadPicture = async () => {
    const address = pictureAddress.value
    if (!visible.value || !address) return
    try {
        const blob = await loadHubPreview(address)
        // A card that went away or changed its picture meanwhile keeps nothing.
        if (!alive || pictureAddress.value !== address) return
        dropPicture()
        pictureSrc.value = URL.createObjectURL(blob)
    } catch {
        if (alive && pictureAddress.value === address) pictureFailed.value = true
    }
}

watch(pictureAddress, () => {
    dropPicture()
    pictureFailed.value = false
    loadPicture()
})
watch(visible, loadPicture)
watch(pictureFailed, (failed) => failed && dropPicture())

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') {
        visible.value = true
        return
    }
    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                visible.value = true
                observer?.disconnect()
            }
        },
        { rootMargin: '200px' }
    )
    observer.observe(cardRef.value)
})

onBeforeUnmount(() => {
    alive = false
    observer?.disconnect()
    dropPicture()
})
</script>
