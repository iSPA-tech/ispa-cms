<script setup>
/**
 * Enum/status input widget: an a-select over `field.options: [{ value, label }]`. v-model via `modelValue`.
 * A field flagged `multiple` (custom multiselect) selects several values and the model is an array.
 */
const props = defineProps({
    modelValue: { type: [String, Number, Boolean, Array], default: undefined },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <a-select
        :value="modelValue"
        :options="field.options || []"
        :mode="field.multiple ? 'multiple' : undefined"
        :allow-clear="Boolean(field.multiple)"
        :disabled="disabled"
        style="width: 100%"
        @update:value="emit('update:modelValue', $event)"
    />
</template>
