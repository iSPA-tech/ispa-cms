<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    "Cập nhật" of a downloaded hub widget. Before anything changes the dialog shows the picture of the design in use
    next to the picture the hub published for the new version (one design at a time when there are several), what is
    new in the versions since the installed one, how many pages and shared areas get the new look and which new input
    boxes arrive. When the new version drops a design that is in use the update is not offered. Nothing is applied
    until the operator confirms. Pictures and notes scroll inside the dialog; the impact line and the buttons stay in
    view.
-->
<template>
    <a-modal :footer="null" :open="open" width="min(640px, calc(100vw - 32px))" @cancel="close">
        <template #title>
            <span class="block break-words pr-8">
                {{ $t('text.widget_library.hub_update_title', { name: widgetLabel(widget) }) }}
            </span>
        </template>
        <div class="flex flex-col gap-3">
            <p v-if="fromVersion && toVersion" class="m-0 break-words text-sm font-semibold text-slate-900" data-test="update-versions">
                {{ $t('text.widget_library.hub_update_versions', { from: formatVersion(fromVersion), to: formatVersion(toVersion) }) }}
            </p>

            <div class="-mr-2 flex max-h-[max(10rem,min(45vh,calc(100vh_-_27rem)))] flex-col gap-4 overflow-y-auto border-0 border-b border-solid border-slate-100 pb-2 pr-2 sm:max-h-[max(10rem,min(55vh,calc(100vh_-_24rem)))]" data-test="update-body">
                <section v-if="shownPair" class="flex flex-col gap-2" data-test="update-previews">
                    <div
                        v-if="pairs.length > 1"
                        :aria-label="$t('text.widget_library.add_design')"
                        class="flex flex-wrap gap-1.5"
                        role="group"
                    >
                        <button
                            v-for="(pair, index) in pairs"
                            :key="pair.key"
                            :aria-pressed="shownPair.key === pair.key"
                            class="min-h-8 max-w-full break-words rounded-full border px-3 text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                            :class="
                                shownPair.key === pair.key
                                    ? 'border-primary bg-primary text-on-primary'
                                    : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'
                            "
                            :data-test="`update-design-${index}`"
                            type="button"
                            @click="selectedKey = pair.key"
                        >
                            {{ pair.label || $t('text.widget_library.hub_update_design_n', { n: index + 1 }) }}
                        </button>
                    </div>

                    <div class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
                        <figure class="m-0 flex min-w-0 flex-1 flex-col gap-1">
                            <div
                                class="relative flex aspect-[16/10] w-full items-center justify-center overflow-hidden rounded-lg border border-solid border-slate-200 bg-slate-50 text-slate-400"
                            >
                                <img
                                    v-if="currentSrc"
                                    alt=""
                                    class="absolute inset-0 size-full object-cover object-top"
                                    data-test="update-picture-current"
                                    decoding="async"
                                    loading="lazy"
                                    :src="currentSrc"
                                    @error="currentFailed = true"
                                />
                                <span v-else class="flex flex-col items-center gap-1 px-3 text-center" data-test="update-no-picture-current">
                                    <i-icon aria-hidden="true" class="size-8" icon="puzzle-outline" />
                                    <span class="text-xs">{{ $t('text.widget_library.hub_update_no_picture') }}</span>
                                </span>
                            </div>
                            <figcaption class="text-center text-xs font-medium text-slate-600">
                                {{ $t('text.widget_library.hub_update_current') }}
                            </figcaption>
                        </figure>

                        <span aria-hidden="true" class="flex shrink-0 justify-center text-slate-400 sm:pb-5">
                            <i-icon class="size-5 sm:hidden" icon="chevron-down" />
                            <i-icon class="hidden size-5 sm:block" icon="chevron-right" />
                        </span>

                        <figure class="m-0 flex min-w-0 flex-1 flex-col gap-1">
                            <div
                                class="relative flex aspect-[16/10] w-full items-center justify-center overflow-hidden rounded-lg border border-solid border-emerald-300 bg-slate-50 text-slate-400"
                            >
                                <img
                                    v-if="nextSrc"
                                    alt=""
                                    class="absolute inset-0 size-full object-cover object-top"
                                    data-test="update-picture-new"
                                    decoding="async"
                                    :src="nextSrc"
                                    @error="nextFailed = true"
                                />
                                <span
                                    v-else-if="!nextAddress || nextFailed"
                                    class="flex flex-col items-center gap-1 px-3 text-center"
                                    data-test="update-no-picture-new"
                                >
                                    <i-icon aria-hidden="true" class="size-8" icon="puzzle-outline" />
                                    <span class="text-xs">{{ $t('text.widget_library.hub_update_no_picture') }}</span>
                                </span>
                            </div>
                            <figcaption class="text-center text-xs font-medium text-emerald-800">
                                {{ $t('text.widget_library.hub_update_new') }}
                            </figcaption>
                        </figure>
                    </div>
                </section>

                <section class="flex flex-col gap-2" data-test="update-notes">
                    <h3 class="m-0 text-sm font-semibold text-slate-900">{{ $t('text.widget_library.hub_whats_new') }}</h3>
                    <p v-if="notes.length === 0" class="m-0 text-sm text-slate-500" data-test="update-notes-empty">
                        {{ $t('text.widget_library.hub_no_notes') }}
                    </p>
                    <div
                        v-for="group in notes"
                        v-else
                        :key="group.version"
                        class="flex flex-col gap-1.5"
                        :data-test="`update-notes-${group.version}`"
                    >
                        <h4 v-if="notes.length > 1" class="m-0 text-xs font-semibold text-slate-600">
                            {{ $t('text.widget_library.hub_whats_new_version', { version: formatVersion(group.version) }) }}
                        </h4>
                        <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
                            <li
                                v-for="(item, index) in group.items"
                                :key="index"
                                class="flex flex-wrap items-start gap-x-2 gap-y-1"
                                :data-change="changeType(item.type).type"
                            >
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="changeType(item.type).tone"
                                >
                                    <i-icon aria-hidden="true" class="size-3.5" :icon="changeType(item.type).icon" />
                                    {{ $t(changeType(item.type).label) }}
                                </span>
                                <span class="min-w-0 flex-1 basis-40 break-words text-sm text-slate-700">{{ item.text }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

            </div>

            <p v-if="state === 'loading'" class="m-0 text-sm text-slate-500" data-test="update-loading">
                {{ $t('text.widget_library.loading') }}
            </p>
            <a-alert v-else-if="state === 'failed'" data-test="update-failed" :message="$t(errorKey)" show-icon type="warning" />
            <template v-else>
                <div v-if="blocked" class="flex flex-col gap-1" data-test="update-blocked" role="alert">
                    <p class="m-0 text-sm font-medium text-amber-800">{{ $t('text.widget_library.hub_design_in_use') }}</p>
                    <p class="m-0 break-words text-sm text-slate-600">
                        {{ $t('text.widget_library.hub_removed_designs', { names: impact.removed_designs_in_use.join(', ') }) }}
                    </p>
                </div>
                <template v-else>
                    <p class="m-0 text-sm text-slate-700" data-test="update-impact">
                        {{ $t('text.widget_library.hub_update_impact', { pages: impact.pages, areas: impact.areas }) }}
                    </p>
                    <p v-if="impact.new_input_labels.length" class="m-0 break-words text-sm text-slate-600">
                        {{ $t('text.widget_library.hub_new_inputs', { labels: impact.new_input_labels.join(', ') }) }}
                    </p>
                </template>
            </template>

            <div class="flex flex-wrap justify-end gap-2 pt-1">
                <i-button class="min-h-9" data-test="update-cancel" native-type="button" @click="close">
                    {{ $t('nav.b.cancel') }}
                </i-button>
                <i-button
                    v-if="state === 'ready' && !blocked"
                    class="min-h-9"
                    data-test="update-confirm"
                    :disabled="loading"
                    :loading="loading"
                    native-type="button"
                    variant="primary"
                    @click="submit"
                >
                    {{ $t('text.widget_library.hub_update') }}
                </i-button>
            </div>
        </div>
    </a-modal>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { designLabel, widgetLabel } from '@/libs/widget-names'
import { getHubUpdateImpact, hubErrorCopy, loadHubPreview, updateHubWidget, usableDesigns } from '../../../widget-library-api'
import { formatVersion } from '../format-version'

defineOptions({ name: 'WidgetLibraryUpdateDialog' })

const props = defineProps({
    open: { type: Boolean, default: false },
    // Library row being updated.
    widget: { type: Object, default: null },
    // Hub version the update leads to; 0 when not known.
    toVersion: { type: Number, default: 0 },
    // Hub list entry of this widget: {preview_theme_keys, preview_urls, changes}. Null while the hub list is not read;
    // the dialog then shows the picture in use, a plain tile for the new version and no notes.
    hubEntry: { type: Object, default: null },
})

// `done` carries the server answer of a finished update ({id, sync_status}).
const emit = defineEmits(['update:open', 'done'])

// 'loading' | 'ready' | 'failed'
const state = ref('loading')
const errorKey = ref('')
const impact = ref({ pages: 0, areas: 0, removed_designs_in_use: [], new_input_labels: [] })
const blocked = computed(() => impact.value.removed_designs_in_use.length > 0)
const loading = ref(false)

// Installed version, stated with the new one ("Bản 2 → bản 3") when both are known.
const fromVersion = computed(() => Number(props.widget?.version) || 0)

const close = () => emit('update:open', false)

const fail = (error) => {
    errorKey.value = hubErrorCopy(error)
    state.value = 'failed'
}

/**
 * Pictures to compare, one pair per design: `{key, label, current, next}` (`current` the site's stored picture,
 * `next` the address of the hub picture of the new version; '' when there is none).
 *
 * Designs the site has and the new version keeps are paired by design key. When none can be paired (the new version
 * only has other designs, or the server does not send design keys) the designs of the new version are listed next to
 * the first design of the site; those carry no name and are numbered on screen.
 */
const pairs = computed(() => {
    const designs = usableDesigns(props.widget)
    const urls = props.hubEntry?.preview_urls ?? {}
    const keys = [...new Set([...(props.hubEntry?.preview_theme_keys ?? []), ...Object.keys(urls)])]
    const pair = (key, design, label) => ({
        key,
        label,
        current: String(design?.preview_image_url || ''),
        next: String(urls[key] ?? ''),
    })

    const kept = designs.filter((design) => design.theme_key && keys.includes(design.theme_key))
    if (kept.length > 0) return kept.map((design) => pair(design.theme_key, design, designLabel(design)))
    if (keys.length <= 1) return props.widget ? [pair(keys[0] ?? '', designs[0], '')] : []
    return keys.map((key) => pair(key, designs[0], ''))
})

const selectedKey = ref('')
const shownPair = computed(() => pairs.value.find((pair) => pair.key === selectedKey.value) ?? pairs.value[0] ?? null)

// A hidden widget shows nothing on the site, so there is no picture "in use"; one that fails to load is a plain tile.
const currentFailed = ref(false)
const currentAddress = computed(() => (props.widget?.is_blocked ? '' : (shownPair.value?.current ?? '')))
const currentSrc = computed(() => (currentFailed.value ? '' : currentAddress.value))
watch(currentAddress, () => {
    currentFailed.value = false
})

// The hub picture is read through the API client (see `loadHubPreview`), only while the dialog is open and only for
// the design shown.
const nextAddress = computed(() => (props.open ? (shownPair.value?.next ?? '') : ''))
const nextSrc = ref('')
const nextFailed = ref(false)
let alive = true

const dropNext = () => {
    if (nextSrc.value) URL.revokeObjectURL(nextSrc.value)
    nextSrc.value = ''
}

watch(
    nextAddress,
    async (address) => {
        dropNext()
        nextFailed.value = false
        if (!address) return
        try {
            const blob = await loadHubPreview(address)
            // A late answer for a design that is no longer shown keeps nothing.
            if (!alive || nextAddress.value !== address) return
            nextSrc.value = URL.createObjectURL(blob)
        } catch {
            if (alive && nextAddress.value === address) nextFailed.value = true
        }
    },
    { immediate: true }
)
watch(nextFailed, (failed) => failed && dropNext())

onBeforeUnmount(() => {
    alive = false
    dropNext()
})

const CHANGE_TYPES = {
    added: { type: 'added', label: 'text.widget_library.hub_change_added', icon: 'plus-circle-outline', tone: 'bg-emerald-100 text-emerald-800' },
    fixed: { type: 'fixed', label: 'text.widget_library.hub_change_fixed', icon: 'bug', tone: 'bg-amber-100 text-amber-800' },
    changed: { type: 'changed', label: 'text.widget_library.hub_change_changed', icon: 'sync', tone: 'bg-sky-100 text-sky-800' },
}
// A type this screen does not know reads as a plain change.
const changeType = (type) => CHANGE_TYPES[type] ?? CHANGE_TYPES.changed

/**
 * "Có gì mới": the hub's notes of every version newer than the installed one, newest first. The server sends the text
 * in the reader's language. An older server sends no `changes`; that reads the same as a version without notes.
 */
const notes = computed(() =>
    (Array.isArray(props.hubEntry?.changes) ? props.hubEntry.changes : [])
        .map((group) => ({
            version: Number(group?.version) || 0,
            items: (Array.isArray(group?.items) ? group.items : []).filter((item) => String(item?.text ?? '').trim() !== ''),
        }))
        .filter((group) => group.version > fromVersion.value && group.items.length > 0)
        .sort((a, b) => b.version - a.version)
)

watch(
    () => [props.open, props.widget?.id],
    async ([open, id]) => {
        if (!open || !id) return
        state.value = 'loading'
        loading.value = false
        selectedKey.value = ''
        try {
            const read = await getHubUpdateImpact(id)
            // A late answer for a widget that is no longer the one shown is dropped.
            if (!props.open || props.widget?.id !== id) return
            impact.value = read
            state.value = 'ready'
        } catch (error) {
            if (props.open && props.widget?.id === id) fail(error)
        }
    },
    { immediate: true }
)

const submit = async () => {
    if (loading.value || !props.widget) return
    loading.value = true
    try {
        const result = await updateHubWidget(props.widget.id)
        emit('done', result)
        close()
    } catch (error) {
        fail(error)
        loading.value = false
    }
}
</script>
