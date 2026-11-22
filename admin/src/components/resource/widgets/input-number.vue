<script setup>
/**
 * Number input widget (a-input-number). `min`, `max` and an optional `step` come from `field.rules`; missing
 * rules leave the input unbounded. v-model through `modelValue` / `update:modelValue`.
 */
defineProps({
    modelValue: { type: [Number, String], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <a-input-number
        :value="modelValue"
        :disabled="disabled"
        :min="field.rules?.min"
        :max="field.rules?.max"
        :step="field.rules?.step ?? 1"
        style="width: 100%"
        @update:value="emit('update:modelValue', $event)"
    />
</template>
