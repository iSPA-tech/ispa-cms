<script setup>
/**
 * Plain text display widget; also the fallback for unknown widgets. Empty values render as an em dash.
 * When `field.secondary` names a non-empty record attribute it renders as a muted second line.
 * Props: `value` (cell value), `field` (schema field), `record` (row) - the shared widget contract.
 */
import { computed } from 'vue'

const props = defineProps({
    value: { type: [String, Number, Boolean], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})

const secondary = computed(() => {
    const raw = props.field?.secondary ? props.record?.[props.field.secondary] : null
    return raw === null || raw === undefined || raw === '' ? '' : String(raw)
})
</script>

<template>
    <span class="r-widget-text">
        {{ value === null || value === undefined || value === '' ? '—' : value }}
        <span v-if="secondary" class="r-widget-secondary">{{ secondary }}</span>
    </span>
</template>

<style scoped>
.r-widget-secondary {
    display: block;
    color: var(--ant-color-text-secondary, rgba(0, 0, 0, 0.45));
    font-size: 12px;
    line-height: 1.4;
}
</style>
