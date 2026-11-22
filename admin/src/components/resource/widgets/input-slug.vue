<script setup>
/**
 * Slug input widget: an a-input that follows the source field (`field.from`, read from the form `values`) while the
 * user has not typed into it. The slug removes Vietnamese diacritics, maps đ to d, lowercases, turns every run of
 * other characters into one '-' and trims '-' at both ends. A stored slug (the field already has a value when the
 * widget mounts) is never overwritten; typing stops the following, and clearing the field starts it again.
 */
import { ref, watch } from 'vue'

const props = defineProps({
    modelValue: { type: String, default: null },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    /** Current values of the whole form; the source field is read from here. */
    values: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])

/** Turn free text into a URL-safe slug. */
function slugify(text) {
    return String(text ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
}

const manual = ref(Boolean(props.modelValue))

watch(
    () => props.values?.[props.field?.from],
    (source) => {
        if (!manual.value) emit('update:modelValue', slugify(source))
    },
)

/** Take over the typed value; an emptied field goes back to following the source. */
function onInput(value) {
    manual.value = Boolean(value)
    emit('update:modelValue', manual.value ? value : slugify(props.values?.[props.field?.from]))
}
</script>

<template>
    <a-input :value="modelValue" :disabled="disabled" :maxlength="field.rules?.max" @update:value="onInput" />
</template>
