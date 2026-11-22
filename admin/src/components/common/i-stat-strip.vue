<template>
    <!-- One bordered band of figures separated by hairlines (each cell draws its right and bottom edge; the outer ones are clipped). -->
    <div
        class="i-stat-strip grid overflow-hidden rounded-xl border border-border bg-surface"
        :class="colsClass"
        data-testid="stat-strip"
    >
        <slot />
    </div>
</template>

<script setup>
/**
 * IStatStrip - compact band of `i-stat-tile` cells for overview pages (home, orders): one container, hairline dividers,
 * no per-figure cards. Tiles inside drop their own border and radius.
 */
import { computed, defineComponent, provide } from 'vue'

defineComponent({ name: 'IStatStrip' })

const props = defineProps({
    /** Columns at the widest breakpoint (2-6); match the number of tiles so no empty cell shows. */
    cols: { type: Number, default: 6 },
})

provide('iStatStrip', true)

/** Static class strings so Tailwind keeps them. */
// On the two-column phone layout an odd last tile spans both columns, so no empty cell is left in the band.
const ODD_LAST = '[&>:last-child:nth-child(odd)]:col-span-2'
const COLS = {
    2: 'grid-cols-2',
    3: 'grid-cols-1 sm:grid-cols-3',
    4: `grid-cols-2 lg:grid-cols-4 ${ODD_LAST} lg:[&>:last-child:nth-child(odd)]:col-span-1`,
    5: `grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 ${ODD_LAST} sm:[&>:last-child:nth-child(odd)]:col-span-1`,
    6: `grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 ${ODD_LAST} sm:[&>:last-child:nth-child(odd)]:col-span-1`,
}
const colsClass = computed(() => COLS[props.cols] || COLS[6])
</script>
