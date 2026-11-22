<template>
    <component
        :is="to ? 'router-link' : isButton ? 'button' : 'div'"
        v-bind="to ? { to } : isButton ? { type: 'button' } : {}"
        class="i-stat-tile flex min-w-0 flex-col gap-1.5 bg-surface p-3.5 no-underline"
        :class="[
            inStrip ? 'rounded-none shadow-[1px_0_0_0_var(--color-border),0_1px_0_0_var(--color-border)]' : 'rounded-lg border border-border',
            to || isButton ? 'hover:bg-surface-hover transition-colors' : '',
            isButton ? 'w-full text-left cursor-pointer' : '',
        ]"
        :data-tone="tone"
        data-testid="stat-tile"
    >
        <!-- Label: sentence case, up to two lines; a dot only when the value needs attention (warning/danger). -->
        <div class="flex min-w-0 items-start gap-1.5">
            <span v-if="dotClass" class="mt-[7px] size-1.5 shrink-0 rounded-full" :class="dotClass" data-testid="stat-tile-dot"></span>
            <span class="line-clamp-2 text-[13px] leading-5 text-ink-muted" :title="label">{{ label }}</span>
        </div>
        <div v-if="loading" data-testid="stat-tile-skeleton" aria-busy="true">
            <div class="h-7 w-20 rounded-sm bg-surface-sunken animate-pulse"></div>
        </div>
        <template v-else>
            <div class="flex flex-wrap items-baseline gap-x-1.5 gap-y-1">
                <span class="tabular-nums" :title="fullValue" data-testid="stat-tile-value"
                    ><span class="text-2xl font-semibold leading-tight" :class="valueToneClass || 'text-ink'">{{ valueParts.main }}</span
                    ><span v-if="valueParts.unit" class="ml-0.5 text-sm font-medium text-ink-muted">{{ valueParts.unit }}</span></span
                >
                <span v-if="suffix" class="text-sm font-medium text-ink-faint tabular-nums" data-testid="stat-tile-suffix">{{ suffix }}</span>
                <span
                    v-if="hasDelta"
                    class="inline-flex items-center gap-0.5 rounded-sm px-1 py-px text-xs font-medium tabular-nums"
                    :class="deltaClass"
                    data-testid="stat-tile-delta"
                >
                    <i-icon v-if="deltaIcon" :icon="deltaIcon" class="size-3 shrink-0" />
                    {{ deltaText }}
                </span>
            </div>
            <!-- Small chart of the period, only when a point is above zero (an all-zero line says nothing). -->
            <i-chart
                v-if="hasSpark"
                type="spark"
                data-testid="stat-tile-spark"
                :series="[{ name: label, data: spark }]"
                :first-slot="sparkSlot"
            />
            <div v-if="meterPercent !== null" class="h-1 overflow-hidden rounded-full bg-border" data-testid="stat-tile-meter">
                <span class="block h-full rounded-full" :class="meterClass" :style="{ width: meterPercent + '%' }"></span>
            </div>
            <span v-if="badge" class="self-start rounded-full px-2 py-px text-xs font-medium" :class="badgeClass" data-testid="stat-tile-badge">{{ badge }}</span>
        </template>
        <div
            v-if="description || $slots.description"
            class="text-xs leading-5 text-ink-faint"
            data-testid="stat-tile-description"
        >
            <slot name="description">{{ description }}</slot>
        </div>
    </component>
</template>

<script setup>
/**
 * IStatTile - dashboard figure: sentence-case label, value with a muted unit, optional meter, trend pill and badge.
 * Neutral by default; colour appears only when the figure needs attention (a dot for warning/danger, the badge chip).
 * Inside `i-stat-strip` it drops its own border and radius. Colours come from design tokens only.
 */
import { computed, defineComponent, inject, useAttrs } from 'vue'
import { useAppRuntime } from '@/runtime'

defineComponent({ name: 'IStatTile' })

const props = defineProps({
    /** Tile caption, sentence case, one line (truncated with a tooltip). */
    label: { type: String, default: '' },
    /** Small text under the value; the `description` slot overrides it. */
    description: { type: String, default: '' },
    /** Force the root element, e.g. `button`. */
    as: { type: String, default: '' },
    /** Main figure. Non-numeric strings render as-is. */
    value: { type: [Number, String], default: 0 },
    /** Explicit change in percent; wins over `previous`. */
    delta: { type: Number, default: null },
    /** Previous-period value; when `delta` is null and this is > 0, the change is derived from it. */
    previous: { type: Number, default: null },
    /** "Lower is better": an increase gets the bad pill, a decrease the good pill. */
    reverse: { type: Boolean, default: false },
    /** How `value` is formatted. */
    format: {
        type: String,
        default: 'number',
        validator: (v) => ['number', 'currency', 'percent'].includes(v),
    },
    /** Most decimals shown for a plain number (e.g. `1` for an average like 2.4); counts keep the default 0. */
    fractionDigits: { type: Number, default: 0 },
    /** ISO 4217 currency code used when `format` is `currency`. */
    currency: { type: String, default: 'VND' },
    /** Compact values from one million up (`192,9 Tr ₫`); the full value stays in the title attribute. */
    compact: { type: Boolean, default: true },
    /** Router target; renders a router-link. */
    to: { type: [Object, String], default: null },
    /** Small muted text after the value, e.g. `/ 1.000`. */
    suffix: { type: String, default: '' },
    /** Fill of a thin meter under the value, 0-100; null hides it. */
    meter: { type: Number, default: null },
    /** Short status chip under the value, coloured by `tone` (e.g. "Cần nạp"). */
    badge: { type: String, default: '' },
    /** Meaning of the figure: warning/danger add a dot and colour the badge and meter; others stay neutral. */
    tone: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'success', 'warning', 'danger', 'info'].includes(v),
    },
    /** Show a skeleton instead of the value. */
    loading: { type: Boolean, default: false },
    /** Daily values of the period for a small chart under the value; drawn only when a point is above zero. */
    spark: { type: Array, default: null },
    /** Chart colour slot (1 to 5, `--color-chart-N`) of the small chart. */
    sparkSlot: { type: Number, default: 1, validator: (v) => Number.isInteger(v) && v >= 1 && v <= 5 },
    /** Status colour of the value itself (published, pending, rejected...); a zero keeps the ink colour. */
    valueTone: { type: String, default: '', validator: (v) => ['', 'success', 'warning', 'danger'].includes(v) },
})

const { locale } = useAppRuntime()
const attrs = useAttrs()
// Clickable without a route: native button so keyboard users can activate it.
const isButton = computed(() => !props.to && (props.as === 'button' || typeof attrs.onClick === 'function' || Array.isArray(attrs.onClick)))

/** True when rendered inside `i-stat-strip` (borderless cell). */
const inStrip = inject('iStatStrip', false)

/** A spark only when the series has a value above zero; i-chart colours it from the slot and follows theme switches. */
const hasSpark = computed(() => Array.isArray(props.spark) && props.spark.some((v) => Number(v) > 0))

const valueToneClass = computed(() => {
    if (!props.valueTone || props.value === 0 || props.value === '0') return ''
    return { success: 'text-success-ink', warning: 'text-warning-ink', danger: 'text-danger-ink' }[props.valueTone] || ''
})

/**
 * Attention dot beside the label: only for figures that need someone. A zero count is never a warning: pages often
 * derive the tone from a threshold that a 0 also matches.
 */
const dotClass = computed(() =>
    props.value === 0 || props.value === '0' ? '' : ({ warning: 'bg-warning', danger: 'bg-danger' })[props.tone] || ''
)
/** Badge chip colours by tone. */
const badgeClass = computed(
    () =>
        ({
            success: 'bg-success-soft text-success-ink',
            warning: 'bg-warning-soft text-warning-ink',
            danger: 'bg-danger-soft text-danger-ink',
            info: 'bg-info-soft text-info-ink',
        })[props.tone] || 'bg-surface-sunken text-ink-muted'
)
/** Meter fill clamped to 0-100, or null when no meter is requested. */
const meterPercent = computed(() =>
    typeof props.meter === 'number' && Number.isFinite(props.meter) ? Math.min(100, Math.max(0, props.meter)) : null
)
const meterClass = computed(() => ({ warning: 'bg-warning', danger: 'bg-danger' })[props.tone] || 'bg-primary')

/** Intl locale tag for the active admin locale. */
const localeTag = computed(() => (locale.value === 'vi' ? 'vi-VN' : 'en-US'))

/**
 * Format the tile value for the active locale.
 * @param {boolean} compact Use compact notation (`Tr`, `T` in Vietnamese) for values from one million up.
 */
const formatValue = (compact) => {
    const n = Number(props.value)
    if (props.value === null || props.value === '' || Number.isNaN(n)) {
        return props.value ?? '-'
    }
    if (props.format === 'percent') {
        return new Intl.NumberFormat(localeTag.value, { maximumFractionDigits: 1 }).format(n) + '%'
    }
    const useCompact = compact && Math.abs(n) >= 1_000_000
    const options = useCompact
        ? { notation: 'compact', maximumFractionDigits: 1 }
        : { maximumFractionDigits: props.format === 'number' ? props.fractionDigits : 0 }
    if (props.format === 'currency') {
        return new Intl.NumberFormat(localeTag.value, { style: 'currency', currency: props.currency, ...options }).format(n)
    }

    return new Intl.NumberFormat(localeTag.value, options).format(n)
}

/** Value as shown in the tile (compact when enabled). */
const displayValue = computed(() => formatValue(props.compact))

/** Split the shown value into the figure and a muted unit (currency symbol or %). */
const valueParts = computed(() => {
    const text = String(displayValue.value ?? '')
    const match = text.match(/^(.*?)\s*(₫|%|VND|đ)$/u) || text.match(/^(₫|\$|€)\s*(.*)$/u)
    if (!match) return { main: text, unit: '' }
    return /^(₫|\$|€)$/u.test(match[1]) ? { main: match[2], unit: match[1] } : { main: match[1], unit: match[2] }
})
/** Full, never-compacted value for the title attribute. */
const fullValue = computed(() => (displayValue.value === formatValue(false) ? undefined : formatValue(false)))

/** Effective change in percent: the `delta` prop, else derived from `previous`, else null. */
const effectiveDelta = computed(() => {
    if (typeof props.delta === 'number' && Number.isFinite(props.delta)) return props.delta
    const current = Number(props.value)
    if (typeof props.previous === 'number' && Number.isFinite(props.previous) && props.previous > 0 && Number.isFinite(current)) {
        return Math.round(((current - props.previous) / props.previous) * 1000) / 10
    }

    return null
})

const hasDelta = computed(() => effectiveDelta.value !== null)
const deltaIcon = computed(() => (effectiveDelta.value > 0 ? 'arrow-up' : effectiveDelta.value < 0 ? 'arrow-down' : ''))
const deltaClass = computed(() => {
    const d = effectiveDelta.value
    if (d === 0) return 'bg-surface-sunken text-ink-muted delta-flat'
    const good = props.reverse ? d < 0 : d > 0

    return good ? 'bg-success-soft text-success-ink delta-up' : 'bg-danger-soft text-danger-ink delta-down'
})
const deltaText = computed(() => {
    const d = effectiveDelta.value
    const sign = d > 0 ? '+' : d < 0 ? '-' : ''

    return `${sign}${new Intl.NumberFormat(localeTag.value, { maximumFractionDigits: 1 }).format(Math.abs(d))}%`
})
</script>
