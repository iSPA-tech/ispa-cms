<script setup>
/**
 * Boolean display widget: an a-tag reading "Yes" or "No" (`text.yes` / `text.no`), never the raw true/false.
 * 1, '1' and true count as yes; 0, '0' and false as no; null, undefined and '' render as an em dash like the other
 * display widgets. Props: `value`, `field`, `record` - the shared widget contract.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    value: { type: [String, Number, Boolean], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})
const { t } = useI18n()

const empty = computed(() => props.value === null || props.value === undefined || props.value === '')
const yes = computed(() => props.value === true || props.value === 1 || props.value === '1')
</script>

<template>
    <span v-if="empty">—</span>
    <a-tag v-else :color="yes ? 'success' : undefined">{{ t(yes ? 'text.yes' : 'text.no') }}</a-tag>
</template>
