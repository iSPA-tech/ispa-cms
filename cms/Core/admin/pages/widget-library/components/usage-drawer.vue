<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Where one widget is used: the pages (each with a link to its editor when there is one) and how many shared areas.
    Loaded when the drawer opens; a failed read shows a retry, not a toast.
-->
<template>
    <a-drawer
        :open="open"
        placement="right"
        :title="$t('text.widget_library.usage_title', { name: widgetLabel(widget) })"
        :width="drawerWidth"
        @close="$emit('update:open', false)"
    >
        <p v-if="loading" class="m-0 text-sm text-slate-500" data-test="usage-loading">
            {{ $t('text.widget_library.loading') }}
        </p>
        <div v-else-if="failed" class="flex flex-col items-start gap-2" data-test="usage-failed">
            <p class="m-0 text-sm text-slate-600">{{ $t('text.widget_library.usage_load_failed') }}</p>
            <i-button native-type="button" @click="load">{{ $t('text.widget_library.retry') }}</i-button>
        </div>
        <p v-else-if="pages.length === 0 && areas === 0" class="m-0 text-sm text-slate-500" data-test="usage-none">
            {{ $t('text.widget_library.usage_none') }}
        </p>
        <div v-else class="space-y-5">
            <section v-if="pages.length > 0">
                <h3 class="m-0 mb-2 text-sm font-semibold text-slate-900">
                    {{ $t('text.widget_library.usage_pages') }} ({{ pages.length }})
                </h3>
                <ul class="m-0 list-none divide-y divide-slate-100 p-0">
                    <li
                        v-for="page in pages"
                        :key="`${page.type}-${page.post_id}`"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                        data-test="usage-page"
                    >
                        <span class="min-w-0 flex-1 basis-40 break-words text-sm text-slate-800">{{ page.title }}</span>
                        <a
                            v-if="page.edit_route"
                            class="inline-flex min-h-9 items-center gap-1 text-sm text-primary hover:underline"
                            data-test="usage-page-link"
                            :href="adminHref(page.edit_route)"
                            @click.prevent="openPage(page.edit_route)"
                        >
                            {{ $t('text.widget_library.usage_open_page') }}
                            <i-icon aria-hidden="true" class="size-3.5" icon="chevron-right" />
                        </a>
                    </li>
                </ul>
            </section>
            <section v-if="areas > 0" data-test="usage-areas">
                <h3 class="m-0 mb-2 text-sm font-semibold text-slate-900">
                    {{ $t('text.widget_library.usage_areas') }}
                </h3>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm text-slate-800">
                        {{ $t('text.widget_library.usage_areas_count', { count: areas }) }}
                    </span>
                    <a
                        v-if="showArea"
                        class="inline-flex min-h-9 items-center gap-1 text-sm text-primary hover:underline"
                        data-test="usage-areas-link"
                        :href="areasHref"
                        @click.prevent="openAreas"
                    >
                        {{ $t('text.widget_library.usage_open_areas') }}
                        <i-icon aria-hidden="true" class="size-3.5" icon="chevron-right" />
                    </a>
                </div>
            </section>
        </div>
    </a-drawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { widgetLabel } from '@/libs/widget-names'
import { useRouter } from 'vue-router'
import { useWindowSize } from '@vueuse/core'
import { basePath } from '@/config/base'
import { getWidgetUsage } from '../../../widget-library-api'

defineOptions({ name: 'WidgetLibraryUsageDrawer' })

const props = defineProps({
    open: { type: Boolean, default: false },
    // Library row: {id, name}.
    widget: { type: Object, default: null },
    // Widget module on and the active theme has a shared area: the shared-area editor can be opened.
    showArea: { type: Boolean, default: false },
})

defineEmits(['update:open'])
const router = useRouter()

const { width: windowWidth } = useWindowSize()
// Full width on phones, a 480px panel from tablets up.
const drawerWidth = computed(() => Math.min(480, windowWidth.value || 480))

const pages = ref([])
const areas = ref(0)
const loading = ref(false)
const failed = ref(false)

const load = async () => {
    if (!props.widget) return
    loading.value = true
    failed.value = false
    try {
        const usage = await getWidgetUsage(props.widget.id)
        pages.value = usage.pages
        areas.value = usage.areas
    } catch {
        failed.value = true
    } finally {
        loading.value = false
    }
}

watch(
    () => [props.open, props.widget?.id],
    ([open]) => open && load(),
    { immediate: true }
)

/** Admin path of an API `edit_route` such as `/post/update/<uuid>` (the admin lives under its base path). */
const adminHref = (editRoute) => `${basePath.replace(/\/$/, '')}${editRoute}`

const openPage = (editRoute) => router.push(adminHref(editRoute))
const openAreas = () => router.push({ name: 'site-layout-placements' })
// A real address, so "open in a new tab" works; '#' only when the route is not registered (Widget module off).
const areasHref = computed(() => {
    try {
        return router.resolve({ name: 'site-layout-placements' }).href
    } catch {
        return '#'
    }
})
</script>
