<template>
    <a-tooltip :title="label">
        <span
            :aria-label="`${label}: ${value}`"
            :class="variantClasses"
            class="app-stat-pill inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-medium"
            role="status"
        >
            <i-icon :icon="icon" class="size-4 shrink-0" />
            <span>{{ value }}</span>
        </span>
    </a-tooltip>
</template>

<script setup>
import { computed, defineComponent } from 'vue'

defineComponent({
    name: 'AppStatPill',
})

const props = defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'muted'].includes(v),
    },
})

const variantClasses = computed(() =>
    props.variant === 'muted' ? 'bg-slate-100 text-slate-500' : 'bg-slate-100 text-slate-700'
)
</script>
