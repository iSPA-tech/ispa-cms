<script setup>
/**
 * Date/time display widget: `DD/MM/YYYY HH:mm` in Asia/Ho_Chi_Minh (same format as the Helpdesk organizations page).
 * Empty or unparsable values render as an em dash.
 *
 * Schema options: `date_only` drops the time part. `expiry` (`{ warn_days, expired_label, warning_label }`) highlights
 * the value as an expiry date: a date whose day has ended is shown with the danger tokens, one that ends within
 * `warn_days` days with the warning tokens. The state is also spelled out (label under the date and tooltip), so the
 * meaning never relies on colour alone.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { ADMIN_TIMEZONE } from '@/config/constants'

dayjs.extend(utc)
dayjs.extend(timezone)

const TIMEZONE = ADMIN_TIMEZONE

const props = defineProps({
    value: { type: [String, Number, Date], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})
const { t } = useI18n()

const date = computed(() => {
    if (!props.value) return null
    // A bare date (custom date field) has no time zone: read it as that day in the admin zone so it never shifts.
    const parsed = typeof props.value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(props.value) ? dayjs.tz(props.value, TIMEZONE) : dayjs(props.value)
    return parsed.isValid() ? parsed.tz(TIMEZONE) : null
})

const text = computed(() => (date.value ? date.value.format(props.field?.date_only ? 'DD/MM/YYYY' : 'DD/MM/YYYY HH:mm') : '—'))

/** `expired`, `soon` or null for the schema `expiry` option. */
const state = computed(() => {
    const option = props.field?.expiry
    if (!option || !date.value) return null
    const end = date.value.endOf('day')
    if (end.isBefore(dayjs())) return 'expired'
    return end.isBefore(dayjs().add(Number(option.warn_days ?? 30), 'day')) ? 'soon' : null
})

const stateLabel = computed(() => {
    if (!state.value) return ''
    const key = state.value === 'expired' ? props.field.expiry.expired_label : props.field.expiry.warning_label
    return key ? t(key) : ''
})
const stateClass = computed(() => (state.value === 'expired' ? 'bg-danger-soft text-danger-ink' : 'bg-warning-soft text-warning-ink'))
</script>

<template>
    <span v-if="state" class="inline-flex flex-col rounded px-1.5 py-0.5 font-medium leading-tight" :class="stateClass" :title="stateLabel || undefined">
        <span>{{ text }}</span>
        <span v-if="stateLabel" class="text-xs font-normal">{{ stateLabel }}</span>
    </span>
    <span v-else>{{ text }}</span>
</template>
