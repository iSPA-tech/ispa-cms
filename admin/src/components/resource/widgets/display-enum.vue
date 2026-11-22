<script setup>
/**
 * Enum display widget: shows the label of the option (`field.options: [{ value, label }]`) matching the value,
 * falling back to the raw value. Labels are already translated by the caller. An array value (multiselect) shows the
 * labels joined with commas.
 */
import { computed } from 'vue'

const props = defineProps({
    value: { type: [String, Number, Boolean, Array], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})

const labelOf = (value) => {
    const option = (props.field.options || []).find((o) => String(o.value) === String(value))
    return option ? option.label : value
}
const label = computed(() => (Array.isArray(props.value) ? props.value.map(labelOf).join(', ') : labelOf(props.value)))
const empty = computed(() => props.value === null || props.value === undefined || props.value === '' || (Array.isArray(props.value) && props.value.length === 0))
</script>

<template>
    <span>{{ empty ? '—' : label }}</span>
</template>
