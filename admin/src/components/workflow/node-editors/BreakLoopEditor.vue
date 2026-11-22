<template>
    <div class="break-loop-editor space-y-2">
        <a-alert
            type="info"
            show-icon
            :message="$t('text.workflow.editor.break_loop.info')"
        />
    </div>
</template>

<script setup>
defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
})
defineEmits(['update:modelValue'])
</script>

<style scoped>
.break-loop-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
