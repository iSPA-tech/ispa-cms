<script setup>
/**
 * Relation display widget: shows the human-readable label of the related record. The label comes from
 * `record[<field>_label]`, else from the nested object `record[<field without _uuid>]` (read with `field.relation.label_key`),
 * else the raw value. Empty values render as an em dash.
 */
import { computed } from 'vue'

const props = defineProps({
    value: { type: [String, Number], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})

const label = computed(() => {
    const name = props.field?.field || ''
    const labelKey = props.field?.relation?.label_key || 'name'
    const direct = props.record?.[`${name}_label`]
    if (direct) return String(direct)
    const nested = props.record?.[name.replace(/_uuid$/, '')]
    if (nested && typeof nested === 'object' && nested[labelKey]) return String(nested[labelKey])
    return props.value === null || props.value === undefined || props.value === '' ? '' : String(props.value)
})
</script>

<template>
    <span class="r-widget-relation">{{ label || '—' }}</span>
</template>
