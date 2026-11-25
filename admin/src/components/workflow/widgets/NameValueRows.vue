<template>
    <div class="space-y-2" data-testid="name-value-rows" :data-variant="variant">
        <p v-if="!rows.length" class="mb-0 break-words rounded-lg border border-solid border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700" data-testid="name-value-empty">
            {{ emptyText || t('text.workflow.pick.rows_none') }}
        </p>
        <div v-for="(row, index) in rows" :key="index" class="space-y-1 rounded-md border border-solid border-slate-200 p-2" data-testid="name-value-row">
            <div class="flex items-center gap-1">
                <a-auto-complete
                    v-if="names.length"
                    class="min-w-0 flex-1"
                    :value="row.name"
                    :options="nameOptions"
                    :disabled="readOnly"
                    :placeholder="namePlaceholder || t('text.workflow.pick.row_name')"
                    :aria-label="namePlaceholder || t('text.workflow.pick.row_name')"
                    :filter-option="filterName"
                    data-testid="name-value-name"
                    @update:value="(name) => setRow(index, { name: name ?? '' })"
                />
                <a-input
                    v-else
                    class="min-w-0 flex-1"
                    :value="row.name"
                    :disabled="readOnly"
                    :placeholder="namePlaceholder || t('text.workflow.pick.row_name')"
                    :aria-label="namePlaceholder || t('text.workflow.pick.row_name')"
                    data-testid="name-value-name"
                    @update:value="(name) => setRow(index, { name: name ?? '' })"
                />
                <a-button
                    v-if="!readOnly"
                    size="small"
                    type="text"
                    danger
                    :aria-label="t('text.workflow.schema_form.remove_row')"
                    :title="t('text.workflow.schema_form.remove_row')"
                    data-testid="name-value-remove"
                    @click="removeRow(index)"
                >
                    <i-icon icon="trash-can-outline" class="h-4 w-4" />
                </a-button>
            </div>
            <DataTokenInput
                :model-value="variant === 'inputs' ? readInput(row.value).value : row.value"
                :disabled="readOnly"
                :aria-label="t('text.workflow.pick.row_value')"
                :placeholder="t('text.workflow.pick.row_value')"
                @update:model-value="(value) => setValue(index, value)"
            />
            <label v-if="variant === 'inputs'" class="flex items-center gap-2 text-xs text-gray-700">
                <a-switch size="small" :checked="readInput(row.value).trusted" :disabled="readOnly" @update:checked="(trusted) => setTrusted(index, trusted)" />
                <span class="break-words">{{ t('text.workflow.pick.input_trusted') }}</span>
            </label>
        </div>
        <a-button v-if="!readOnly" size="small" type="dashed" block data-testid="name-value-add" @click="addRow">
            + {{ addText || t('text.workflow.pick.row_add') }}
        </a-button>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTokenInput from '@/components/workflow/inputs/DataTokenInput.vue'
import { readInput, readPairs, writeInput, writePairs } from './widgetRules.js'

/**
 * Rows of a name and a value for a setting stored as a map (`headers`, `query`) or as the named inputs of an AI step
 * (`variant="inputs"`, each value may be marked trusted). The value takes text and data chips. Stored exactly in the
 * shape it was read in; a row without a name is kept on screen and not stored.
 */
defineOptions({ name: 'NameValueRows' })

const props = defineProps({
    modelValue: { type: null, default: null },
    readOnly: { type: Boolean, default: false },
    /** Names offered first (a header list); any other name can still be typed. */
    names: { type: Array, default: () => [] },
    variant: { type: String, default: 'map' },
    emptyText: { type: String, default: '' },
    addText: { type: String, default: '' },
    namePlaceholder: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const rows = ref([])
let written = null
watch(
    () => props.modelValue,
    (value) => {
        const stored = JSON.stringify(value ?? null)
        if (stored === written) return
        written = stored
        rows.value = readPairs(value)
    },
    { immediate: true, deep: true }
)

const nameOptions = computed(() => props.names.map((name) => ({ value: name })))
const filterName = (input, option) => String(option.value).toLowerCase().includes(String(input || '').toLowerCase())

const commit = (next) => {
    rows.value = next
    const value = writePairs(next, props.modelValue)
    written = JSON.stringify(value ?? null)
    emit('update:modelValue', value)
}
const setRow = (index, patch) => commit(rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row)))
const setValue = (index, value) =>
    setRow(index, { value: props.variant === 'inputs' ? writeInput({ ...readInput(rows.value[index].value), value }, rows.value[index].value) : value })
const setTrusted = (index, trusted) => setRow(index, { value: writeInput({ ...readInput(rows.value[index].value), trusted }, rows.value[index].value) })
const removeRow = (index) => commit(rows.value.filter((_, i) => i !== index))
// A new row is only shown; it is stored once it has a name.
const addRow = () => (rows.value = [...rows.value, { name: '', value: '' }])
</script>
