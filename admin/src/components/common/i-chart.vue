<template>
    <div ref="host" class="i-chart min-w-0" :style="{ minHeight: resolvedHeight + 'px' }" data-testid="i-chart"></div>
</template>

<script setup>
/**
 * IChart - design-system wrapper around ApexCharts.
 *
 * Drives the `apexcharts` instance directly (not vue3-apexcharts, whose option watcher destroys a chart that has not
 * finished its async init when a parent re-renders early). Palette rules: categorical series take the fixed chart
 * colour slots in order (chart-1 … chart-5); use `palette="status"` only for series that are real statuses and pass
 * `statusKeys` (success / warning / danger / info) aligned with `series`. Colours are read from CSS custom properties
 * and re-read when the `class` attribute of `<html>` changes, so dark mode re-colours the chart.
 */
import { computed, defineComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ApexCharts from 'apexcharts'
import { useAppRuntime } from '@/runtime'
import { buildChartOptions, resolveChartColors } from '@/libs/chart-options'

defineComponent({ name: 'IChart' })

const props = defineProps({
    /** Chart kind. */
    type: {
        type: String,
        default: 'trend',
        validator: (v) => ['trend', 'bars', 'donut', 'spark'].includes(v),
    },
    /** ApexCharts series (for donut: a flat number array). */
    series: { type: Array, required: true },
    /** X axis categories. */
    categories: { type: Array, default: () => [] },
    /** Colour palette: categorical slots or status colours. */
    palette: {
        type: String,
        default: 'categorical',
        validator: (v) => ['categorical', 'status'].includes(v),
    },
    /** Status keys aligned with `series` when palette is `status`. */
    statusKeys: { type: Array, default: () => [] },
    /** Height override in px; defaults per type. */
    height: { type: Number, default: null },
    /** ApexCharts options merged over the built ones (deep for plain objects; arrays replace). */
    options: { type: Object, default: () => ({}) },
    /** Bars only: draw horizontal bars so long category names stay readable. */
    horizontal: { type: Boolean, default: false },
    /** Categorical slot (1-5) the first series takes, e.g. a one-series spark in another colour. */
    firstSlot: { type: Number, default: 1, validator: (v) => Number.isInteger(v) && v >= 1 && v <= 5 },
})

const { locale } = useAppRuntime()
const host = ref(null)
const colors = ref(null)
let chart = null
let observer = null

/** Re-read the design-token colours from the document root. */
const refreshColors = () => {
    colors.value = resolveChartColors(getComputedStyle(document.documentElement))
}

/** Number of series (donut counts parts, which never show a legend anyway). */
const seriesCount = computed(() => (Array.isArray(props.series) ? props.series.length : 0))

const builtOptions = computed(() => {
    if (!colors.value) return null

    return buildChartOptions({
        type: props.type,
        palette: props.palette,
        colors: colors.value,
        statusKeys: props.statusKeys,
        categories: props.categories,
        height: props.height ?? undefined,
        seriesCount: seriesCount.value,
        locale: locale.value,
        horizontal: props.horizontal,
        firstSlot: props.firstSlot,
    })
})

/** Deep merge of plain objects (arrays and other values replace), so page options keep the built theme colours. */
const mergeDeep = (base, extra) => {
    const out = { ...base }
    for (const [key, value] of Object.entries(extra || {})) {
        const isPlain = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
        out[key] = isPlain(value) && isPlain(base?.[key]) ? mergeDeep(base[key], value) : value
    }
    return out
}
const mergedOptions = computed(() => (builtOptions.value ? mergeDeep(builtOptions.value, props.options) : null))
const resolvedHeight = computed(() => mergedOptions.value?.chart?.height ?? props.height ?? 0)

/** Create the chart once the host element and the token colours exist. */
const render = async () => {
    if (!host.value || !mergedOptions.value) return
    await nextTick()
    chart?.destroy()
    chart = new ApexCharts(host.value, { ...mergedOptions.value, series: props.series })
    await chart.render()
}

onMounted(() => {
    refreshColors()
    if (typeof MutationObserver !== 'undefined') {
        observer = new MutationObserver(refreshColors)
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    }
    render()
})

// Option changes (theme colours, categories, type) rebuild the chart; series changes update in place.
watch(mergedOptions, (next, prev) => {
    if (!chart || !next) return
    if (prev && next.chart?.type === prev.chart?.type) {
        chart.updateOptions({ ...next, series: props.series }, false, true)
    } else {
        render()
    }
})
watch(
    () => props.series,
    (next) => {
        if (chart) chart.updateSeries(next, true)
    },
    { deep: true }
)

onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
    chart?.destroy()
    chart = null
})
</script>
