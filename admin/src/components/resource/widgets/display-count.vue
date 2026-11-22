<script setup>
/**
 * Count display widget: a number, rendered as a router-link when `field.link` (route location or path string) is set
 * only in the detail view (`field.context === 'detail'`; list tables show a plain number) and when the count is above zero. Missing values render as 0.
 */
import { computed } from 'vue'

const props = defineProps({
    value: { type: [String, Number], default: 0 },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})

const count = computed(() => props.value ?? 0)
const link = computed(() => (props.field.context === 'detail' && Number(count.value) > 0 ? props.field.link || null : null))
</script>

<template>
    <router-link v-if="link" :to="link">{{ count }}</router-link>
    <span v-else>{{ count }}</span>
</template>
