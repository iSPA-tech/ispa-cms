<template>
    <span class="dialog-footer flex items-center justify-end space-x-3">
        <a-button @click="$emit('cancel')">
            <span><i-icon :icon="cancelIcon" class="size-4" /></span>
            <span>{{ $t(cancelTextKey) }}</span>
        </a-button>
        <a-button
            key="submit"
            class="m-0"
            :loading="loading"
            :danger="dangerSubmit"
            type="primary"
            :form="form || undefined"
            @click="$emit('submit')"
        >
            <span><i-icon :icon="submitIcon" class="size-4" /></span>
            <span>{{ $t(submitTextKey) }}</span>
        </a-button>
    </span>
</template>

<script setup>
import { defineComponent } from 'vue'

defineComponent({
    name: 'AppModalFooter',
})

defineProps({
    cancelTextKey: { type: String, default: 'nav.b.cancel' },
    submitTextKey: { type: String, default: 'nav.b.save' },
    loading: { type: Boolean, default: false },
    dangerSubmit: { type: Boolean, default: true },
    submitIcon: { type: String, default: 'content-save' },
    cancelIcon: { type: String, default: 'close' },
    form: { type: String, default: null },
})

defineEmits(['cancel', 'submit'])
</script>
