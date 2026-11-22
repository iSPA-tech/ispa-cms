<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    One widget of the library: the stored picture of its first design, its name, where it comes from, whether it is
    hidden on the site or has a newer version on the hub, where it is used, and the actions. The picture is a still
    image kept with the design (no live frame on cards); a design without one shows a plain tile.

    Actions: one main button ("Thêm vào…") and one menu with the rest ("Xem trước", "Cập nhật lên bản N", "Sửa thiết
    kế", then "Tạm ẩn trên site" last). The picture opens the preview and the "Có bản mới" chip starts the update, so
    the two common side actions need no menu. A widget with no design this site can use is dimmed and offers only
    "Sửa thiết kế".
-->
<template>
    <article
        class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white"
        :data-test="`library-card-${widget.id}`"
    >
        <component
            :is="themeId ? 'button' : 'div'"
            :aria-label="themeId ? $t('text.widget_library.preview_open', { name: widgetLabel(widget) }) : undefined"
            class="group relative block aspect-[16/10] w-full overflow-hidden border-0 border-b border-solid border-slate-100 bg-slate-50 p-0"
            :class="
                themeId
                    ? 'cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary'
                    : 'opacity-60'
            "
            :data-test="`library-picture-${widget.id}`"
            :type="themeId ? 'button' : undefined"
            @click="themeId && $emit('preview')"
        >
            <img
                v-if="pictureUrl"
                alt=""
                class="absolute inset-0 size-full object-cover object-top"
                decoding="async"
                loading="lazy"
                :src="pictureUrl"
                @error="pictureFailed = true"
            />
            <span v-else class="flex h-full flex-col items-center justify-center gap-1 px-3 text-center text-slate-400">
                <i-icon aria-hidden="true" class="size-8" :icon="widget.is_blocked ? 'eye-off-outline' : 'puzzle-outline'" />
                <span v-if="widget.is_blocked" class="text-xs">{{ $t('text.widget_library.blocked') }}</span>
            </span>
            <span
                v-if="themeId"
                aria-hidden="true"
                class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 bg-slate-900/70 px-2 py-1 text-xs font-medium text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"
            >
                <i-icon class="size-3.5" icon="eye-outline" />
                {{ $t('text.widget_library.action_preview') }}
            </span>
        </component>

        <div class="flex flex-1 flex-col gap-2 p-3">
            <div class="min-w-0" :class="{ 'opacity-60': !themeId }">
                <h3 class="m-0 break-words text-sm font-semibold text-slate-900">{{ widgetLabel(widget) }}</h3>
                <p v-if="designCount > 1" class="m-0 text-xs text-slate-500">
                    {{ $t('text.widget_library.designs_count', { count: designCount }) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <span
                    class="max-w-full break-words rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="isHub ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800'"
                    :data-test="`library-origin-${widget.id}`"
                >
                    <template v-if="isHub && installedVersion">
                        {{ $t('text.widget_library.origin_hub_version', { version: formatVersion(installedVersion) }) }}
                    </template>
                    <template v-else>
                        {{ $t(isHub ? 'text.widget_library.origin_hub' : 'text.widget_library.origin_site') }}
                    </template>
                </span>
                <span
                    v-if="widget.is_blocked"
                    class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800"
                    :data-test="`library-blocked-${widget.id}`"
                >
                    <i-icon aria-hidden="true" class="size-3.5" icon="eye-off-outline" />
                    {{ $t('text.widget_library.blocked') }}
                </span>
                <!-- "Có bản mới" starts the update for those who may update; others only read it. -->
                <button
                    v-if="hasUpdate && canHub"
                    class="inline-flex min-h-6 cursor-pointer items-center gap-1 rounded-full border-0 bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 hover:bg-emerald-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                    :data-test="`library-has-update-${widget.id}`"
                    type="button"
                    @click="$emit('update')"
                >
                    <i-icon aria-hidden="true" class="size-3.5" icon="cloud-download" />
                    {{ $t('text.widget_library.hub_has_update') }}
                </button>
                <span
                    v-else-if="hasUpdate"
                    class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
                    :data-test="`library-has-update-${widget.id}`"
                >{{ $t('text.widget_library.hub_has_update') }}</span>
                <span
                    v-if="pictureStale"
                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700"
                    :data-test="`library-stale-${widget.id}`"
                >{{ $t('text.widget_library.preview_stale') }}</span>
                <span
                    v-if="unexported"
                    class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                    :data-test="`library-unexported-${widget.id}`"
                >{{ $t('text.widget_library.unexported') }}</span>
            </div>

            <p
                v-if="!themeId"
                class="m-0 break-words text-xs text-amber-800"
                :data-test="`library-no-design-${widget.id}`"
            >
                {{ $t('text.widget_library.no_usable_design') }}
            </p>

            <p v-if="widget.is_blocked && widget.blocked_reason" class="m-0 break-words text-xs text-red-700">
                {{ $t('text.widget_library.block_reason') }}: {{ widget.blocked_reason }}
            </p>

            <button
                v-if="used"
                class="self-start rounded text-left text-xs text-slate-600 underline decoration-dotted underline-offset-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                :data-test="`library-usage-${widget.id}`"
                type="button"
                @click="$emit('usage')"
            >
                {{ $t('text.widget_library.usage', { pages: usage.pages, areas: usage.areas }) }}
            </button>
            <p v-else class="m-0 text-xs text-slate-400">{{ $t('text.widget_library.unused') }}</p>

            <!-- Nothing to add or preview without a usable design: the only way forward is the design editor. -->
            <div v-if="!themeId" class="mt-auto flex pt-1">
                <i-button
                    v-if="canEdit"
                    class="min-h-9 flex-1"
                    :data-test="`library-edit-${widget.id}`"
                    native-type="button"
                    @click="$emit('edit')"
                >
                    <template #icon><i-icon class="size-3.5" icon="pencil-outline" /></template>
                    <span>{{ $t('text.widget_library.action_edit') }}</span>
                </i-button>
            </div>
            <div v-else class="mt-auto flex gap-2 pt-1">
                <i-button
                    class="min-h-9 min-w-0 flex-1"
                    :data-test="`library-add-${widget.id}`"
                    :disabled="widget.is_blocked"
                    native-type="button"
                    variant="primary"
                    @click="$emit('add')"
                >
                    <template #icon><i-icon class="size-3.5" icon="plus-thick" /></template>
                    <span>{{ $t('text.widget_library.action_add') }}</span>
                </i-button>
                <a-dropdown placement="bottomRight" :trigger="['click']">
                    <i-button
                        :aria-label="$t('text.widget_library.more_actions', { name: widgetLabel(widget) })"
                        class="min-h-9 w-9 shrink-0 !px-0"
                        :data-test="`library-menu-${widget.id}`"
                        native-type="button"
                    >
                        <template #icon><i-icon aria-hidden="true" class="size-4" icon="dots-vertical" /></template>
                    </i-button>
                    <template #overlay>
                        <a-menu :data-test="`library-menu-items-${widget.id}`">
                            <a-menu-item key="preview" :data-test="`library-preview-${widget.id}`" @click="$emit('preview')">
                                <template #icon><i-icon aria-hidden="true" class="size-4" icon="eye-outline" /></template>
                                {{ $t('text.widget_library.action_preview') }}
                            </a-menu-item>
                            <a-menu-item
                                v-if="hasUpdate && canHub"
                                key="update"
                                :data-test="`library-update-${widget.id}`"
                                @click="$emit('update')"
                            >
                                <template #icon><i-icon aria-hidden="true" class="size-4" icon="cloud-download" /></template>
                                {{
                                    updateVersion
                                        ? $t('text.widget_library.hub_update_to', { version: formatVersion(updateVersion) })
                                        : $t('text.widget_library.hub_update')
                                }}
                            </a-menu-item>
                            <a-menu-item
                                v-if="canEdit"
                                key="edit"
                                :data-test="`library-edit-${widget.id}`"
                                @click="$emit('edit')"
                            >
                                <template #icon><i-icon aria-hidden="true" class="size-4" icon="pencil-outline" /></template>
                                {{ $t('text.widget_library.action_edit') }}
                            </a-menu-item>
                            <template v-if="canBlock">
                                <a-menu-divider />
                                <!-- Hiding is the risky one (last, red); showing again is not. Both still confirm. -->
                                <a-menu-item
                                    key="block"
                                    :danger="!widget.is_blocked"
                                    :data-test="`library-block-${widget.id}`"
                                    @click="$emit('block')"
                                >
                                    <template #icon>
                                        <i-icon
                                            aria-hidden="true"
                                            class="size-4"
                                            :icon="widget.is_blocked ? 'eye-outline' : 'eye-off-outline'"
                                        />
                                    </template>
                                    {{
                                        $t(
                                            widget.is_blocked
                                                ? 'text.widget_library.action_unblock'
                                                : 'text.widget_library.action_block'
                                        )
                                    }}
                                </a-menu-item>
                            </template>
                        </a-menu>
                    </template>
                </a-dropdown>
            </div>
        </div>
    </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { widgetLabel } from '@/libs/widget-names'
import { usableDesigns } from '../../../widget-library-api'
import { formatVersion } from '../format-version'

defineOptions({ name: 'WidgetLibraryCard' })

const props = defineProps({
    // Library row: {id, name, origin, is_blocked, blocked_reason, usage: {pages, areas},
    // template: [{id, name, usable_on_site, preview_image_url, preview_stale}]}.
    widget: { type: Object, required: true },
    // True where hub widgets are authored and this one has changes not exported yet.
    unexported: { type: Boolean, default: false },
    // The hub has a newer version of this downloaded widget.
    hasUpdate: { type: Boolean, default: false },
    // That newer version, named in the menu ("Cập nhật lên bản N"); 0 when not known.
    updateVersion: { type: Number, default: 0 },
    canEdit: { type: Boolean, default: false },
    canBlock: { type: Boolean, default: false },
    // May download and update widgets from the hub.
    canHub: { type: Boolean, default: false },
})

defineEmits(['preview', 'edit', 'block', 'add', 'usage', 'update'])

const isHub = computed(() => props.widget.origin === 'hub')
// Hub version installed on this site, named in the origin chip; 0 when not known. A site copy shows none.
const installedVersion = computed(() => Number(props.widget.version) || 0)
// The library lists every design; only the ones this site can use are counted, shown and offered.
const designs = computed(() => usableDesigns(props.widget))
const designCount = computed(() => designs.value.length)
const themeId = computed(() => designs.value[0]?.id ?? null)
const usage = computed(() => ({
    pages: Number(props.widget.usage?.pages ?? 0),
    areas: Number(props.widget.usage?.areas ?? 0),
}))
const used = computed(() => usage.value.pages + usage.value.areas > 0)

// A hidden widget shows nothing on the site, so its tile says so instead of a picture. A picture that fails
// to load falls back to the plain tile.
const pictureFailed = ref(false)
// The cover is the first usable design that has a picture, so a widget whose first design has none (e.g. an
// older theme-drawn design) still shows the picture of a newer one.
const coverDesign = computed(() => designs.value.find((d) => d?.preview_image_url) ?? designs.value[0])
const storedPicture = computed(() => coverDesign.value?.preview_image_url || '')
const pictureUrl = computed(() => (props.widget.is_blocked || pictureFailed.value ? '' : storedPicture.value))
const pictureStale = computed(() => coverDesign.value?.preview_stale === true)

watch(storedPicture, () => {
    pictureFailed.value = false
})
</script>
