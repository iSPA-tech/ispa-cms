<script setup>
/**
 * Multi-line text input widget (a-textarea). `field.rules.max` limits the length.
 * v-model through `modelValue` / `update:modelValue`.
 */
defineProps({
    modelValue: { type: String, default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <a-textarea
        :value="modelValue"
        :disabled="disabled"
        :maxlength="field.rules?.max"
        :rows="4"
        @update:value="emit('update:modelValue', $event)"
    />
</template>
