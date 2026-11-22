<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    "Thêm vào…": where a library widget goes. "Trang" lets the operator pick a page by name and opens that page's
    editor with the widget ready to place (`?add_widget=<id>&theme=<id>`, read by the page editor). "Khu vực chung"
    opens the shared-area editor with the widget preselected; it is offered only when the Widget module is on and the
    active theme has at least one shared area (`showArea`), and only for a widget with at least one design a shared
    area accepts (the server's `placeable=1` list: since 2026-11-21 the same designs pages accept, Default included).
    A widget without one (only designs of another project) gets a sentence instead of the button; a chosen design that
    is not accepted is said here, and the area editor then asks for another design.
-->
<template>
    <a-modal
        :footer="null"
        :open="open"
        :title="$t('text.widget_library.add_title', { name: widgetLabel(widget) })"
        :width="520"
        @cancel="close"
    >
        <div class="space-y-4">
            <div v-if="designs.length > 1">
                <label class="mb-1 block text-sm font-medium text-slate-700" for="widget-add-design">
                    {{ $t('text.widget_library.add_design') }}
                </label>
                <a-select
                    id="widget-add-design"
                    v-model:value="themeId"
                    class="w-full"
                    data-test="add-to-design"
                    :options="designs.map((design) => ({ value: design.id, label: designLabel(design) }))"
                />
            </div>

            <section class="rounded-lg border border-slate-200 p-3" data-test="add-to-page">
                <h3 class="m-0 text-sm font-semibold text-slate-900">{{ $t('text.widget_library.add_page') }}</h3>
                <p class="m-0 mb-2 text-xs text-slate-500">{{ $t('text.widget_library.add_page_hint') }}</p>
                <div class="flex flex-wrap gap-2">
                    <a-select
                        v-model:value="pageUuid"
                        allow-clear
                        class="min-w-0 flex-1 basis-48"
                        data-test="add-to-page-select"
                        :filter-option="false"
                        :loading="searching"
                        :not-found-content="searching ? undefined : $t('text.widget_library.add_page_none')"
                        :options="pages.map((page) => ({ value: page.uuid, label: page.name }))"
                        :placeholder="$t('text.widget_library.add_page_search')"
                        show-search
                        @search="searchDebounced"
                    />
                    <i-button
                        class="min-h-9"
                        data-test="add-to-page-go"
                        :disabled="!pageUuid"
                        native-type="button"
                        variant="primary"
                        @click="goToPage"
                    >
                        {{ $t('text.widget_library.add_page_go') }}
                    </i-button>
                </div>
            </section>

            <p
                v-if="showArea && areaState === 'none'"
                class="m-0 rounded-lg border border-dashed border-slate-300 p-3 text-xs text-slate-600"
                data-test="add-to-area-none"
            >
                {{ $t('text.widget_library.add_area_none') }}
            </p>
            <button
                v-else-if="showArea && areaState !== 'loading'"
                class="flex w-full items-center gap-3 rounded-lg border border-slate-200 p-3 text-left hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                data-test="add-to-area"
                type="button"
                @click="goToArea"
            >
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-slate-900">
                        {{ $t('text.widget_library.add_area') }}
                    </span>
                    <span class="block text-xs text-slate-500">{{ $t('text.widget_library.add_area_hint') }}</span>
                    <span
                        v-if="areaState === 'other-design'"
                        class="mt-1 block text-xs text-amber-800"
                        data-test="add-to-area-other-design"
                    >
                        {{ $t('text.widget_library.add_area_other_design') }}
                    </span>
                </span>
                <i-icon aria-hidden="true" class="size-5 shrink-0 text-slate-400" icon="chevron-right" />
            </button>
        </div>
    </a-modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { designLabel, widgetLabel } from '@/libs/widget-names'
import { useRouter } from 'vue-router'
import { debounce } from 'lodash-es'
import { listPlaceableDesigns, searchPages, usableDesigns } from '../../../widget-library-api'

defineOptions({ name: 'WidgetLibraryAddToDialog' })

const props = defineProps({
    open: { type: Boolean, default: false },
    // Library row to add: {id, name, template: [{id, name, usable_on_site}]}.
    widget: { type: Object, default: null },
    // Widget module on and the active theme has a shared area.
    showArea: { type: Boolean, default: false },
})

const emit = defineEmits(['update:open'])
const router = useRouter()

// Only designs this site can use; the library lists the others too.
const designs = computed(() => usableDesigns(props.widget))
const themeId = ref(null)
const pages = ref([])
const pageUuid = ref(undefined)
const searching = ref(false)
let searchSequence = 0

const search = async (keywords = '') => {
    const sequence = ++searchSequence
    searching.value = true
    const rows = await searchPages(keywords)
    if (sequence !== searchSequence) return
    pages.value = rows
    searching.value = false
}
const searchDebounced = debounce(search, 300)

// Designs of every widget that a shared area accepts; null until the server has answered.
const placeableDesigns = ref(null)
// The read failed: the button stays, and the area editor explains if the design cannot be used.
const placeableUnknown = ref(false)

const loadPlaceableDesigns = async () => {
    placeableUnknown.value = false
    try {
        placeableDesigns.value = await listPlaceableDesigns()
    } catch {
        placeableDesigns.value = null
        placeableUnknown.value = true
    }
}

/**
 * What the shared-area choice shows: 'loading' (nothing yet), 'ok', 'other-design' (the widget fits, the chosen design
 * does not) or 'none' (no design of this widget fits a shared area on this site).
 */
const areaState = computed(() => {
    if (placeableUnknown.value) return 'ok'
    if (!placeableDesigns.value) return 'loading'
    const usable = placeableDesigns.value.get(Number(props.widget?.id))
    if (!usable?.size) return 'none'
    return usable.has(Number(themeId.value)) ? 'ok' : 'other-design'
})

watch(
    () => [props.open, props.showArea, props.widget?.id],
    ([open, showArea, widgetId], previous) => {
        if (!open) return
        if (showArea) loadPlaceableDesigns()
        // Only the area count arrived while the dialog was open: keep what the operator chose.
        if (previous?.[0] && previous[2] === widgetId) return
        themeId.value = designs.value[0]?.id ?? null
        pageUuid.value = undefined
        search('')
    },
    { immediate: true }
)

const close = () => emit('update:open', false)

// The page editor and the shared-area editor read these two values once and place the widget.
const handOff = () => ({ add_widget: props.widget.id, theme: themeId.value })

const goToPage = () => {
    if (!pageUuid.value) return
    router.push({ name: 'post-update', params: { uuid: pageUuid.value }, query: handOff() })
    close()
}

const goToArea = () => {
    router.push({ name: 'site-layout-placements', query: handOff() })
    close()
}
</script>
