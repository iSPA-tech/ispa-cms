<script setup>
/**
 * Status display widget: an a-tag coloured from `field.colors[value]` or the matching `field.options[].color`.
 * The label comes from the option with the same value, else the raw value.
 */
import { computed } from 'vue'

const props = defineProps({
    value: { type: [String, Number, Boolean], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})

/** Booleans map to '1'/'0' (value, option value and colour key alike) so true/false cells match options keyed 1/0. */
const keyOf = (v) => (typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
const key = computed(() => keyOf(props.value))
const option = computed(() => (props.field.options || []).find((o) => keyOf(o.value) === key.value))
const color = computed(() => props.field.colors?.[key.value] || option.value?.color || undefined)
const label = computed(() => option.value?.label ?? props.value)
</script>

<template>
    <span v-if="value === null || value === undefined || value === ''">—</span>
    <a-tag v-else :color="color">{{ label }}</a-tag>
</template>
