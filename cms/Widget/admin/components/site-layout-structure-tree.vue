<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Area list of the "Khu vực chung" editor: every shared area of the active theme, top to bottom as on the page
    (header / page content / footer / other), each with its widget count, its own "+ Thêm" and the widgets placed in it.

    Purely presentational over props; the host owns every change:
      - `select` (placement)            open the widget's editor;
      - `add-to-slot` (area key)        open the add drawer for that area;
      - `move` (placement, direction)   reorder inside the area ('up' | 'down');
      - `focus-area` (area key)         scroll the preview to the area.

    A widget shows by name (never an id), with where it applies and "Đang bị chặn" when its widget is blocked (a
    blocked widget renders nothing on the site). `visibleAreas` is the list of areas the page in the preview has, or
    null while unknown: an area the page does not have says so plainly and stays fully usable from this list.
-->
<template>
    <div :class="flat ? 'space-y-4' : 'i-box space-y-4'" data-test="structure-tree">
        <div v-for="region in regions" :key="region.key" class="space-y-2" :data-test="`structure-region-${region.key}`">
            <p class="m-0 text-xs font-medium uppercase text-slate-500">
                {{ $t(`widget_site_layout.region.${region.key}`) }}
            </p>

            <section
                v-for="slot in region.slots"
                :key="slot.key"
                :aria-label="slotLabel(slot)"
                class="rounded-lg border border-slate-200 bg-white p-2.5"
                :data-test="`structure-slot-${slot.key}`"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <button
                        class="flex min-w-0 flex-1 items-center gap-2 text-left"
                        :data-test="`structure-focus-${slot.key}`"
                        type="button"
                        @click="$emit('focus-area', slot.key)"
                    >
                        <span class="min-w-0 break-words text-sm font-semibold text-slate-900">{{ slotLabel(slot) }}</span>
                        <span
                            class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                            :data-test="`structure-count-${slot.key}`"
                        >
                            {{ placementsForSlot(slot.key).length }}
                        </span>
                    </button>
                    <button
                        v-if="canEdit"
                        :aria-label="`${$t('widget_site_layout.area.add')}: ${slotLabel(slot)}`"
                        class="shrink-0 rounded-lg border border-cyan-600 px-2.5 py-1 text-sm font-medium text-cyan-700 hover:bg-cyan-50"
                        :data-test="`structure-add-${slot.key}`"
                        type="button"
                        @click="$emit('add-to-slot', slot.key)"
                    >
                        {{ $t('widget_site_layout.area.add') }}
                    </button>
                </div>

                <p
                    v-if="visibleAreas && !visibleAreas.includes(slot.key)"
                    class="m-0 mt-1.5 rounded bg-slate-50 px-2 py-1.5 text-xs text-slate-600"
                    :data-test="`structure-not-on-preview-${slot.key}`"
                >
                    {{ $t('widget_site_layout.area.not_on_preview') }}
                </p>

                <ul v-if="placementsForSlot(slot.key).length" class="m-0 mt-2 list-none space-y-1 p-0">
                    <li
                        v-for="placement in placementsForSlot(slot.key)"
                        :key="keyOf(placement)"
                        class="flex items-stretch gap-1"
                    >
                        <button
                            class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-1 rounded-lg px-2 py-1.5 text-left text-sm"
                            :class="
                                keyOf(placement) === selectedKey
                                    ? 'bg-cyan-50 font-medium text-cyan-900 ring-1 ring-cyan-300'
                                    : 'text-slate-700 hover:bg-slate-50'
                            "
                            :data-test="`structure-placement-${keyOf(placement)}`"
                            type="button"
                            @click="$emit('select', placement)"
                        >
                            <!-- At least 8rem for the name: the chips move to the next line before the name is cut inside a word. -->
                            <span class="min-w-32 max-w-full flex-1 break-words">{{ labelFor(placement) }}</span>
                            <span
                                v-if="isBlocked(placement)"
                                class="shrink-0 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800"
                                :data-test="`structure-blocked-${keyOf(placement)}`"
                                :title="$t('widget_site_layout.area.blocked_hint')"
                            >
                                {{ $t('widget_site_layout.area.blocked') }}
                            </span>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="scopeChipClass(placement)"
                                :data-test="`structure-scope-${keyOf(placement)}`"
                            >
                                {{ scopeBadgeLabel(t, placement) }}
                            </span>
                        </button>
                        <span v-if="canEdit && siblingCount(placement) > 1" class="flex shrink-0 flex-col justify-center">
                            <button
                                :aria-label="`${$t('widget_site_layout.area.move_up')}: ${labelFor(placement)}`"
                                class="rounded px-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30"
                                :data-test="`structure-move-up-${keyOf(placement)}`"
                                :disabled="siblingIndex(placement) === 0"
                                type="button"
                                @click="$emit('move', placement, 'up')"
                            >
                                <i-icon class="size-4" icon="chevron-up" />
                            </button>
                            <button
                                :aria-label="`${$t('widget_site_layout.area.move_down')}: ${labelFor(placement)}`"
                                class="rounded px-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30"
                                :data-test="`structure-move-down-${keyOf(placement)}`"
                                :disabled="siblingIndex(placement) === siblingCount(placement) - 1"
                                type="button"
                                @click="$emit('move', placement, 'down')"
                            >
                                <i-icon class="size-4" icon="chevron-down" />
                            </button>
                        </span>
                    </li>
                </ul>
                <p v-else class="m-0 mt-1.5 text-xs text-slate-500">
                    {{ $t('widget_site_layout.area.empty_area') }}
                </p>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { groupKey } from '../placement-canvas-utils'
import { REGION_ORDER, regionForSlotKey, resolveSlotLabel, scopeBadgeLabel } from '../slot-label'
import { widgetDisplayName } from '../area-format'

defineOptions({ name: 'SiteLayoutStructureTree' })

const props = defineProps({
    /** Shared areas of the active theme (GET /slots rows). */
    slots: {
        type: Array,
        default: () => [],
    },
    flat: {
        type: Boolean,
        default: false,
    },
    /** Placements of the draft as the user arranged them. */
    placements: {
        type: Array,
        default: () => [],
    },
    /** `(placement) => {widget_name, is_blocked, ...} | null`: what a placement shows (layout.contentFor). */
    contentFor: {
        type: Function,
        default: () => null,
    },
    selectedKey: {
        type: String,
        default: null,
    },
    /** Area keys the page in the preview has; null while unknown (no hint shown). */
    visibleAreas: {
        type: Array,
        default: null,
    },
    /** False for a read-only role: no add or reorder buttons. */
    canEdit: {
        type: Boolean,
        default: true,
    },
})

defineEmits(['select', 'add-to-slot', 'move', 'focus-area'])

const { t } = useI18n()

const slotLabel = (slot) => resolveSlotLabel(t, slot)

const keyOf = (placement) => `${groupKey(placement)}::${placement.widget_instance_uuid}`

const labelFor = (placement) => widgetDisplayName(t, props.contentFor(placement))

const isBlocked = (placement) => props.contentFor(placement)?.is_blocked === true

function placementsForSlot(slotKey) {
    return props.placements.filter((placement) => placement.slot_key === slotKey)
}

// Order is kept per area AND per "where it shows" (the server orders each such group on its own), so a widget moves
// only among the widgets of its area that show in the same place.
const siblingsOf = (placement) => props.placements.filter((item) => groupKey(item) === groupKey(placement))
const siblingCount = (placement) => siblingsOf(placement).length
const siblingIndex = (placement) => siblingsOf(placement).findIndex((item) => keyOf(item) === keyOf(placement))

function scopeChipClass(placement) {
    if (placement.scope_type === 'target') return 'bg-sky-100 text-sky-800'
    if (placement.scope_type === 'template') return 'bg-violet-100 text-violet-800'
    return 'bg-slate-100 text-slate-600'
}

const regions = computed(() =>
    REGION_ORDER.map((regionKeyName) => ({
        key: regionKeyName,
        slots: props.slots.filter((slot) => regionForSlotKey(slot.key) === regionKeyName),
    })).filter((region) => region.slots.length > 0)
)
</script>
