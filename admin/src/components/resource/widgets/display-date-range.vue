<script setup>
/**
 * Date range display widget: "start – end" (`DD/MM/YYYY`) from `record[field.start]` and `record[field.end]`.
 * Shows one side when only one is present and an em dash when both are empty or unparsable.
 */
import { computed } from 'vue'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { ADMIN_TIMEZONE } from '@/config/constants'

dayjs.extend(utc)
dayjs.extend(timezone)

const props = defineProps({
    value: { type: [String, Number, Date], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})

/** Format one date; date-only strings skip the timezone shift. */
function format(raw) {
    if (!raw) return ''
    const date = dayjs(raw)
    if (!date.isValid()) return ''
    const dateOnly = typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(raw)
    return (dateOnly ? date : date.tz(ADMIN_TIMEZONE)).format('DD/MM/YYYY')
}

const text = computed(() => {
    const parts = [format(props.record?.[props.field.start]), format(props.record?.[props.field.end])]
    const filled = parts.filter(Boolean)
    if (!filled.length) return '—'
    return filled.length === 2 ? `${parts[0]} – ${parts[1]}` : filled[0]
})
</script>

<template>
    <span>{{ text }}</span>
</template>
