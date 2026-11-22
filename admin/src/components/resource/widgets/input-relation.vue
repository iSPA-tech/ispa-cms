<script setup>
/**
 * Relation input widget: an a-select with server-side search over `field.relation.endpoint`. The first page loads when
 * the dropdown opens, later pages are searched with a 300 ms debounce. Shows loading, empty and error (with retry)
 * states; stale responses are ignored and timers/requests are cleared on unmount. Emits the `value_key` value.
 *
 * The current value shows its label without a lookup: from `field.from_record.label`, `<field>_label`,
 * `<relation>_<label_key>` or the nested related object. `field.from_record` (`{ value, label }`) names the record keys
 * whose API payload uses other names than the form field; the value is prefilled from `from_record.value` while the
 * field is still empty.
 *
 * `relation.multiple` (or `field.multiple`) turns it into a tag picker: the value is an array of keys and the labels of
 * the current values come from the record (`from_record.label`, `<field>_labels`, or the nested list of related objects).
 * `relation.depends_on` (`{ field, param }`) scopes the lookup by another form field read from `values`: its value is
 * sent as query param `param`, the picker is disabled (and sends no request) while it is empty, and a change of the
 * parent clears the value and reloads the options.
 * `relation.endpoints_by` (`{ field, map }`) picks the lookup endpoint by another form field: the endpoint is
 * `map[values[field]]`; while the parent has no mapped value the picker is disabled (sends no request) and shows a hint
 * (placeholder `text.resource.pick_parent_first`, naming the parent field by `field.parent_label`, the translated label
 * the form adds; without it the generic `text.resource.empty` is shown), and a change of the parent clears the value
 * and reloads from the new endpoint.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { toAjaxPath } from '@/utils/resource/endpoints'

const SEARCH_DEBOUNCE_MS = 300

const props = defineProps({
    modelValue: { type: [String, Number, Array], default: undefined },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    /** Current values of the whole form; the parent field of `depends_on` is read from here. */
    values: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const relation = computed(() => props.field?.relation || {})
const labelKey = computed(() => relation.value.label_key || 'name')
const valueKey = computed(() => relation.value.value_key || 'uuid')

const multiple = computed(() => Boolean(relation.value.multiple || props.field?.multiple))
const dependsOn = computed(() => relation.value.depends_on || null)
/** Placeholder while the parent has no mapped value: tells which field to choose first. */
const parentHint = computed(() => (props.field?.parent_label ? t('text.resource.pick_parent_first', { field: props.field.parent_label }) : t('text.resource.empty')))
const isEmpty = (v) => v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0)
const parentValue = computed(() => (dependsOn.value ? props.values?.[dependsOn.value.field] : undefined))
const endpointsBy = computed(() => relation.value.endpoints_by || null)
const endpointParent = computed(() => (endpointsBy.value ? props.values?.[endpointsBy.value.field] : undefined))
/** Lookup endpoint: the mapped one when `endpoints_by` is set (undefined while unmapped), else `relation.endpoint`. */
const endpoint = computed(() => {
    if (!endpointsBy.value) return relation.value.endpoint
    const key = endpointParent.value
    const map = endpointsBy.value.map || {}
    return typeof key === 'string' && Object.hasOwn(map, key) ? map[key] : undefined
})
const parentMissing = computed(() => (Boolean(dependsOn.value) && isEmpty(parentValue.value)) || (Boolean(endpointsBy.value) && !endpoint.value))

const items = ref([])
const loading = ref(false)
const failed = ref(false)
const loaded = ref(false)
const picked = ref(null)
const pickedLabels = ref({})

let timer = null
let controller = null
let sequence = 0
let lastSearch = ''

/** Label of the current value known from the record (`<field>_label`, `<relation>_<label_key>` or the nested related object). */
function knownLabel() {
    const name = props.field?.field || ''
    const fromRecord = props.record?.[props.field?.from_record?.label]
    if (fromRecord) return String(fromRecord)
    const direct = props.record?.[`${name}_label`]
    if (direct) return String(direct)
    const base = name.replace(/_uuid$/, '')
    const flat = props.record?.[`${base}_${labelKey.value}`]
    if (flat) return String(flat)
    const nested = props.record?.[base]
    return nested && typeof nested === 'object' && nested[labelKey.value] ? String(nested[labelKey.value]) : null
}

/** Labels of the current values of a multiple picker, by value, known from the record. */
function knownLabels() {
    const name = props.field?.field || ''
    const base = name.replace(/_uuids?$/, '')
    const current = Array.isArray(props.modelValue) ? props.modelValue : []
    const labels = {}
    for (const key of [props.field?.from_record?.label, `${name}_labels`, name, base, `${base}s`]) {
        const list = key ? props.record?.[key] : null
        if (!Array.isArray(list)) continue
        list.forEach((entry, index) => {
            if (entry && typeof entry === 'object') {
                if (entry[valueKey.value] !== undefined && entry[labelKey.value]) labels[entry[valueKey.value]] ??= String(entry[labelKey.value])
            } else if (typeof entry === 'string' && current[index] !== undefined && key !== name) {
                labels[current[index]] ??= entry
            }
        })
    }
    return labels
}

/** Prefill an empty field from the record key named by `from_record.value` (API names differing from the form field). */
watch(
    () => props.record?.[props.field?.from_record?.value],
    (value) => {
        if (!isEmpty(value) && isEmpty(props.modelValue)) {
            emit('update:modelValue', value)
        }
    },
    { immediate: true },
)

const options = computed(() => {
    const list = items.value.map((item) => ({ value: item[valueKey.value], label: String(item[labelKey.value] ?? item[valueKey.value]) }))
    if (multiple.value) {
        const known = knownLabels()
        const missing = (Array.isArray(props.modelValue) ? props.modelValue : [])
            .filter((v) => !list.some((o) => o.value === v))
            .map((v) => ({ value: v, label: pickedLabels.value[v] ?? known[v] ?? String(v) }))
        return [...missing, ...list]
    }
    const current = props.modelValue
    if (current === undefined || current === null || current === '' || list.some((o) => o.value === current)) return list
    const label = picked.value?.value === current ? picked.value.label : knownLabel()
    return label ? [{ value: current, label }, ...list] : list
})

/** Abort the in-flight request and invalidate its response. */
function cancelInFlight() {
    sequence += 1
    controller?.abort()
    controller = null
}

/** Query params for one lookup: the term goes under the schema's `search_param` and is omitted when empty. */
function buildParams(search) {
    const params = { per_page: relation.value.page_size || 20 }
    if (search) params[relation.value.search_param || 'search'] = search
    if (dependsOn.value && !isEmpty(parentValue.value)) params[dependsOn.value.param] = parentValue.value
    return params
}

/** Request one page of the lookup endpoint; responses older than the latest request are ignored. */
async function fetchItems(search) {
    if (parentMissing.value) return
    cancelInFlight()
    const current = sequence
    controller = new AbortController()
    lastSearch = search
    loading.value = true
    failed.value = false
    try {
        const res = await ajax.get(toAjaxPath(endpoint.value), {
            params: buildParams(search),
            signal: controller.signal,
            silent: true,
        })
        if (current !== sequence) return
        // Rows sit under `data` ({ data: [...] }) or, for endpoints that wrap a Laravel paginator, under `data.data`.
        const body = res?.data?.data
        items.value = Array.isArray(body) ? body : Array.isArray(body?.data) ? body.data : []
    } catch {
        if (current !== sequence) return
        failed.value = true
        items.value = []
    }
    loaded.value = true
    loading.value = false
}

/** Debounce typing into a single request. */
function onSearch(term) {
    clearTimeout(timer)
    timer = setTimeout(() => {
        timer = null
        fetchItems(String(term ?? '').trim())
    }, SEARCH_DEBOUNCE_MS)
}

/** Load the first page when the dropdown opens and nothing was loaded yet. */
function onOpen(open) {
    if (open && !parentMissing.value && !loaded.value && !loading.value) fetchItems('')
}

/** Remember the picked label and emit the value. */
function onChange(value) {
    if (multiple.value) {
        const list = Array.isArray(value) ? value : []
        const next = {}
        for (const v of list) next[v] = options.value.find((o) => o.value === v)?.label ?? pickedLabels.value[v] ?? String(v)
        pickedLabels.value = next
        emit('update:modelValue', list)
        return
    }
    picked.value = value === undefined || value === null ? null : { value, label: options.value.find((o) => o.value === value)?.label ?? String(value) }
    emit('update:modelValue', value ?? null)
}

/** A changed parent invalidates the picked value and the loaded options: clear, then reload for the new parent. */
watch([parentValue, endpointParent], (next, previous) => {
    if (JSON.stringify(next) === JSON.stringify(previous)) return
    cancelInFlight()
    clearTimeout(timer)
    timer = null
    items.value = []
    loaded.value = false
    loading.value = false
    failed.value = false
    picked.value = null
    pickedLabels.value = {}
    emit('update:modelValue', multiple.value ? [] : null)
    if (!parentMissing.value) fetchItems('')
})

onBeforeUnmount(() => {
    clearTimeout(timer)
    timer = null
    cancelInFlight()
})
</script>

<template>
    <a-select
        :value="multiple ? (Array.isArray(modelValue) ? modelValue : []) : modelValue"
        :mode="multiple ? 'multiple' : undefined"
        :options="options"
        :disabled="disabled || parentMissing"
        :loading="loading"
        :placeholder="parentMissing && endpointsBy ? parentHint : undefined"
        :filter-option="false"
        show-search
        allow-clear
        style="width: 100%"
        @search="onSearch"
        @dropdown-visible-change="onOpen"
        @update:value="onChange"
    >
        <template #notFoundContent>
            <div v-if="loading" class="r-relation-state r-relation-loading"><a-spin size="small" /></div>
            <div v-else-if="failed" class="r-relation-state r-relation-error">
                <span>{{ t('text.resource.load_failed') }}</span>
                <i-button size="sm" @click="fetchItems(lastSearch)">{{ t('text.resource.retry') }}</i-button>
            </div>
            <div v-else class="r-relation-state r-relation-empty">{{ t('text.resource.empty') }}</div>
        </template>
    </a-select>
</template>
