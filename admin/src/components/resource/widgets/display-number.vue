<script setup>
/**
 * Number display widget: the value followed by an optional unit (`field.unit`, a translation key). Empty values render
 * as an em dash.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    value: { type: [String, Number], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})
const { t } = useI18n()

const empty = computed(() => props.value === null || props.value === undefined || props.value === '')
const unit = computed(() => (props.field?.unit ? t(props.field.unit) : ''))
</script>

<template>
    <span class="r-widget-number">{{ empty ? '—' : unit ? `${value} ${unit}` : value }}</span>
</template>
