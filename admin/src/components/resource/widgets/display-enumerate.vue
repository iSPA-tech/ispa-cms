<script setup>
/**
 * Enumerate display widget: an a-tag with the entry name in the current language (name, or name_alias outside
 * Vietnamese). The entry is found, in order, from the value itself when it is an object, from a nested object on the
 * record (parent of a dotted path such as `status.code`, or the plural `statuses`) whose key matches, and finally from
 * the cached enumerates of `field.enumerate.group`. Raw keys are never shown: an unresolved or empty value renders an
 * em dash.
 */
import { computed } from 'vue'
import { useEnumerates } from '@/hooks'
import { useAppRuntime } from '@/runtime'

const props = defineProps({
    value: { type: [String, Number, Object], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
})
const { locale } = useAppRuntime()

const config = computed(() => props.field?.enumerate || {})
const isObject = (v) => v !== null && typeof v === 'object'
const isEmpty = (v) => v === null || v === undefined || v === ''

/** Entry key of the value: the value itself, or the key read from an object value. */
const key = computed(() => {
    const raw = props.value
    if (!isObject(raw)) return isEmpty(raw) ? '' : String(raw)
    const found = raw[config.value.value_key || 'enum_key'] ?? raw.code ?? raw.value
    return isEmpty(found) ? '' : String(found)
})

/** Entry already present on the value or on the record, matching the key. */
const embedded = computed(() => {
    if (isObject(props.value) && (props.value.name || props.value.name_alias)) return props.value
    const name = props.field?.field || ''
    const parent = name.includes('.') ? name.slice(0, name.lastIndexOf('.')) : ''
    const record = props.record || {}
    const candidates = [parent && record[parent], record[`${name}es`], record[`${name}s`]]
    return candidates.find((c) => isObject(c) && (c.name || c.name_alias) && (key.value === '' || String(c.enum_key ?? key.value) === key.value)) || null
})

const needsLookup = computed(() => key.value !== '' && !embedded.value && !!config.value.group)
const { data: entries } = useEnumerates({ type: config.value.group }, { enabled: needsLookup })

const entry = computed(() => {
    if (embedded.value) return embedded.value
    const list = Array.isArray(entries?.value) ? entries.value : []
    return list.find((e) => e.enum_key === key.value && (!config.value.group || e.type === config.value.group)) || null
})

const label = computed(() => {
    const e = entry.value
    if (!e) return ''
    return String(locale.value === 'vi' ? e.name || e.name_alias || '' : e.name_alias || e.name || '')
})
</script>

<template>
    <a-tag v-if="label">{{ label }}</a-tag>
    <span v-else>—</span>
</template>
