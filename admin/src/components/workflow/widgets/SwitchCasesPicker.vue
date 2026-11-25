<template>
    <div class="space-y-2" data-testid="switch-cases-picker">
        <div class="flex flex-wrap gap-1.5">
            <a-button
                v-for="row in chips"
                :key="row.id"
                size="small"
                :type="row.on ? 'primary' : 'default'"
                :ghost="row.on"
                :aria-pressed="row.on ? 'true' : 'false'"
                :disabled="readOnly || row.own"
                class="!h-auto !whitespace-normal !break-words !text-left"
                :data-case-value="row.id"
                @click="toggle(row)"
            >
                {{ row.on ? '✓ ' : '' }}{{ row.label }}
            </a-button>
        </div>
        <p class="mb-0 break-words text-xs text-gray-600">{{ t('text.workflow.pick.cases_hint') }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { caseFor, readCases, toggleCase } from './widgetRules.js'

/**
 * Branches of a "switch" step, picked from the values its data may hold (the verdict of a JEV step, the decision of
 * an approval). Each picked value is one way out of the step; anything else leaves by "Other". Stored as the engine
 * reads it (`cases: [{ key, match }]`); a case that was stored for another value is kept and shown.
 */
defineOptions({ name: 'SwitchCasesPicker' })

const props = defineProps({
    modelValue: { type: null, default: () => [] },
    readOnly: { type: Boolean, default: false },
    /** `[{ value, label }]` of the data the step looks at. */
    values: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const chips = computed(() => {
    const rows = props.values.map((value) => ({ id: String(value.value), value: value.value, label: value.label, on: !!caseFor(props.modelValue, value.value), own: false }))
    // A stored case for a value the list does not have (typed in advanced mode) stays visible.
    readCases(props.modelValue).forEach((row) => {
        if (!props.values.some((value) => caseFor([row], value.value))) rows.push({ id: `own:${row.key}`, value: row.match, label: String(row.key), on: true, own: true })
    })
    return rows
})

const toggle = (row) => !props.readOnly && !row.own && emit('update:modelValue', toggleCase(props.modelValue, row.value))
</script>
