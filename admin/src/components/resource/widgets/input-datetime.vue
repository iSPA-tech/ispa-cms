<script setup>
/**
 * Date-time input widget (a-date-picker with time). The model value is a `YYYY-MM-DD HH:mm:ss` string (or the
 * schema's `value_format`), or null when cleared. v-model through `modelValue` / `update:modelValue`.
 *
 * A record value that carries a zone (an API `toISOString()` such as `2026-12-01T01:00:00.000000Z`) is shown as
 * Asia/Ho_Chi_Minh wall time, the zone the server reads the submitted value in and the one `display-datetime` uses;
 * otherwise an untouched edit form would show (and could re-save) the UTC clock time.
 */
import { computed } from 'vue'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { ADMIN_TIMEZONE } from '@/config/constants'

dayjs.extend(utc)
dayjs.extend(timezone)

const TIMEZONE = ADMIN_TIMEZONE
/** Trailing `Z` or `+07:00` / `+0700`: the string names its own zone. */
const ZONED = /(Z|[+-]\d{2}:?\d{2})$/

const props = defineProps({
    modelValue: { type: String, default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const valueFormat = computed(() => props.field?.value_format || 'YYYY-MM-DD HH:mm:ss')

/** Picker value: zoned strings converted to Asia/Ho_Chi_Minh wall time in the value format, others unchanged. */
const pickerValue = computed(() => {
    const value = props.modelValue
    if (!value || !ZONED.test(value)) return value || null
    const parsed = dayjs(value)
    return parsed.isValid() ? parsed.tz(TIMEZONE).format(valueFormat.value) : null
})
</script>

<template>
    <a-date-picker
        :value="pickerValue"
        :disabled="disabled"
        :value-format="valueFormat"
        :show-time="true"
        format="DD/MM/YYYY HH:mm"
        style="width: 100%"
        @update:value="emit('update:modelValue', $event || null)"
    />
</template>
