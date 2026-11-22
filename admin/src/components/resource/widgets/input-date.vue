<script setup>
/**
 * Date input widget (a-date-picker). The model value is a `YYYY-MM-DD` string, or null when cleared.
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
    <a-date-picker
        :value="modelValue || null"
        :disabled="disabled"
        value-format="YYYY-MM-DD"
        format="DD/MM/YYYY"
        style="width: 100%"
        @update:value="emit('update:modelValue', $event || null)"
    />
</template>
