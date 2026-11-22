<script setup>
/**
 * Text input widget. `field.type` (text, email, textarea, number) picks a-input, a-textarea or a-input-number.
 * v-model through `modelValue` / `update:modelValue`.
 */
import { computed } from 'vue'

const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const kind = computed(() => props.field.type || 'text')
const maxLength = computed(() => props.field.rules?.max)
</script>

<template>
    <a-textarea
        v-if="kind === 'textarea'"
        :value="modelValue"
        :disabled="disabled"
        :maxlength="maxLength"
        :rows="4"
        @update:value="emit('update:modelValue', $event)"
    />
    <a-input-number
        v-else-if="kind === 'number'"
        :value="modelValue"
        :disabled="disabled"
        :min="field.rules?.min"
        :max="field.rules?.max"
        style="width: 100%"
        @update:value="emit('update:modelValue', $event)"
    />
    <a-input
        v-else
        :value="modelValue"
        :type="kind === 'email' ? 'email' : 'text'"
        :disabled="disabled"
        :maxlength="maxLength"
        @update:value="emit('update:modelValue', $event)"
    />
</template>
