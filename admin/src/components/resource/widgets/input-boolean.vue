<script setup>
/**
 * Boolean input widget: an a-switch. v-model via `modelValue`.
 */
defineProps({
    modelValue: { type: Boolean, default: false },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
    <a-switch :checked="modelValue" :disabled="disabled" @update:checked="emit('update:modelValue', $event)" />
</template>
