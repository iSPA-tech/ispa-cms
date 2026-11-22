<template>
    <a-tooltip :title="label">
        <i-button
            :aria-label="label"
            :disabled="disabled"
            :loading="loading"
            :size="size"
            :variant="danger ? 'danger' : variant ? variant : 'default'"
            @click="handleClick"
        >
            <template #icon>
                <i-icon :icon="icon" class="size-4" />
            </template>
        </i-button>
    </a-tooltip>
</template>

<script setup>
import { defineComponent } from 'vue'
import IButton from '@/components/i-button.vue'

defineComponent({
    name: 'AppIconAction',
})

defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    size: { type: String, default: 'sm' },
    variant: { type: String, default: 'default' },
})

const emit = defineEmits(['click'])

const handleClick = (event) => {
    emit('click', event)
}
</script>
