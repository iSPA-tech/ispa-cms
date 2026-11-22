<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Chunk 3 Task 31: a generic, accessible slide-over panel used by
    pages/placements.vue for the Catalog/Inspector "explicit drawer" surfaces
    on narrow viewports (plan DoD 1541: "Narrow: Canvas primary with Catalog/
    Inspector as explicit drawers").

    Hand-rolled rather than ant-design-vue's `<a-drawer>` -- the SAME
    reasoning widget-target-picker.vue's own docblock already gives for
    hand-rolling its combobox instead of `<a-select>`: this module
    consistently prefers a directly-implemented, deterministic, testable
    control over delegating focus/dismiss behavior to a third-party
    component's internals. It also sidesteps a real environment gap:
    vitest.config.ts does not load unplugin-vue-components, so `<a-drawer>`
    never resolves to the real component under this project's OWN test
    harness anyway (see widget-placement-inspector.spec.ts's file docblock).

    `role="dialog"` + `aria-modal="true"` (the WAI-ARIA APG non-modal-element
    dialog pattern) is used instead of the native `<dialog>` element for the
    same determinism reason -- native `<dialog>`/`showModal()` focus behavior
    is not something this project's jsdom test environment is proven to
    emulate faithfully, so focus capture/return is implemented explicitly
    here instead of relied upon implicitly.

    FOCUS MANAGEMENT: the element focused just before the drawer opens (always
    the trigger button that was just activated) is captured and focus moves
    into the panel; closing returns focus to that captured element. Escape
    closes the panel (part of this module's keyboard-only add/move/reorder/
    edit/save/preview/delete/undo/publish path -- a drawer must be dismissible
    without a pointer too). A full Tab focus TRAP (containing Tab cycling
    within the panel while open) is deliberately NOT implemented -- out of
    this task's explicit DoD (focus return, not focus containment) and a
    real complexity/risk tradeoff for an MVP; every control inside the panel
    remains independently keyboard-reachable regardless.
-->
<template>
    <div v-if="open" class="fixed inset-0 z-40 flex" :class="side === 'left' ? 'justify-start' : 'justify-end'" data-test="drawer-root">
        <div class="absolute inset-0 bg-slate-900/40" data-test="drawer-backdrop" @click="close"></div>

        <div
            ref="panelRef"
            :aria-label="title"
            aria-modal="true"
            class="relative z-10 flex h-full w-full flex-col bg-white shadow-xl focus:outline-none"
            :class="wide ? 'md:w-[560px] md:max-w-[90vw]' : 'max-w-sm'"
            data-test="drawer-panel"
            role="dialog"
            tabindex="-1"
            @keydown.esc="close"
        >
            <div class="flex shrink-0 items-center justify-between gap-2 px-4 pb-3 pt-4">
                <h2 class="m-0 min-w-0 break-words text-base font-semibold text-slate-900">{{ title }}</h2>
                <button
                    :aria-label="$t('nav.b.close')"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded text-xl leading-none text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    data-test="drawer-close"
                    type="button"
                    @click="close"
                >
                    &times;
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4" data-test="drawer-body">
                <slot />
            </div>

            <!-- Actions stay reachable at the bottom whatever the content height. -->
            <div v-if="$slots.footer" class="shrink-0 border-t border-slate-200 bg-white px-4 py-3" data-test="drawer-footer">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue'

defineOptions({ name: 'WidgetLayoutDrawer' })

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        required: true,
    },
    /** Spatial hint only (Catalog slides from the left, mirroring its wide-
     * mode position left of the Canvas; Inspector from the right). */
    side: {
        type: String,
        default: 'right',
    },
    /** Wide panel for forms: 560px from 768px up, the full width below. */
    wide: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close'])

const panelRef = ref(null)
let previouslyFocused = null

function close() {
    emit('close')
}

/** Capture/restore focus around the panel's own open/close lifecycle (see
 * file docblock) -- deliberately explicit rather than relying on a native
 * `<dialog>`/third-party implementation. */
watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            previouslyFocused = document.activeElement
            await nextTick()
            panelRef.value?.focus()
        } else if (previouslyFocused instanceof HTMLElement) {
            previouslyFocused.focus()
            previouslyFocused = null
        }
    }
)

defineExpose({ close })
</script>
