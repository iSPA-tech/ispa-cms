<template>
    <div class="space-y-1" data-testid="sla-input">
        <div class="flex flex-wrap items-center gap-2">
            <a-input-number
                class="w-36"
                :value="parts.amount"
                :min="1"
                :precision="0"
                :disabled="readOnly"
                :placeholder="emptyLabel || t('text.workflow.field.sla_none')"
                :aria-label="label || t('text.workflow.field.sla')"
                data-testid="sla-amount"
                @update:value="setAmount"
            />
            <a-select
                class="w-28"
                :value="unit"
                :options="unitOptions"
                :disabled="readOnly"
                :aria-label="t('text.workflow.field.sla_unit')"
                data-testid="sla-unit"
                @update:value="setUnit"
            />
        </div>
        <p v-if="outOfRange" class="mb-0 break-words text-xs text-red-600" role="alert" data-testid="sla-out-of-range">
            {{ t('text.workflow.field.sla_out_of_range') }}
        </p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatSla, parseSla, slaInRange } from '@/components/workflow/node-editors/schemaForm.js'

/**
 * Review deadline (SLA) of a workflow step: a number and a unit, stored as `30m` / `4h` / `2d`.
 * An empty number stores nothing: the step is then not measured.
 */
defineOptions({ name: 'SlaDurationInput' })

const props = defineProps({
    modelValue: { type: null, default: null },
    readOnly: { type: Boolean, default: false },
    label: { type: String, default: '' },
    /** Wording of the empty number; the SLA wording ("not measured") when left out. */
    emptyLabel: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const parts = computed(() => parseSla(props.modelValue))
// The unit is kept while the number is empty, so picking "ngày" first and typing after works.
const unit = ref(parts.value.unit)
watch(parts, (value) => {
    if (value.amount !== null) unit.value = value.unit
})

const unitOptions = computed(() => [
    { value: 'm', label: t('text.workflow.field.sla_unit_minutes') },
    { value: 'h', label: t('text.workflow.field.sla_unit_hours') },
    { value: 'd', label: t('text.workflow.field.sla_unit_days') },
])

const outOfRange = computed(() => !slaInRange(props.modelValue))

const setAmount = (amount) => emit('update:modelValue', formatSla(amount, unit.value))
const setUnit = (value) => {
    unit.value = value
    if (parts.value.amount !== null) emit('update:modelValue', formatSla(parts.value.amount, value))
}
</script>
