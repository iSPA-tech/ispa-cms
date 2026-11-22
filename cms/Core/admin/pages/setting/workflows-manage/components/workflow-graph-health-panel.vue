<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-2">
            <div>
                <p class="panel-title mb-1">{{ t('text.workflow.builder.graph_health') }}</p>
                <p class="panel-subtitle">
                    {{ t('text.workflow.builder.save_blocked') }}
                </p>
            </div>
            <a-tag :color="tone">{{ summary || t('text.workflow.validation.state_not_designed') }}</a-tag>
        </div>
        <div class="space-y-2">
            <button
                v-for="check in checks"
                :key="check.key"
                class="health-check"
                type="button"
                @click="$emit('focus-issue', check)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 text-left">
                        <div class="text-sm font-medium text-gray-700">
                            {{ check.label }}
                        </div>
                        <div class="text-xs text-gray-500">
                            {{ check.message }}
                        </div>
                    </div>
                    <a-tag :color="check.ok ? 'green' : 'orange'">
                        {{ check.ok ? t('text.workflow.builder.check_ok') : t('text.workflow.builder.check_fix') }}
                    </a-tag>
                </div>
            </button>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
    checks: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: String,
        default: '',
    },
    tone: {
        type: String,
        default: 'default',
    },
})

defineEmits(['focus-issue'])
</script>

<style scoped>
.panel-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
}

.panel-subtitle {
    margin: 0;
    font-size: 0.75rem;
    color: #6b7280;
}

.health-check {
    width: 100%;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #ffffff;
    padding: 12px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.health-check:hover {
    border-color: #22c55e;
    box-shadow: 0 8px 24px -18px rgba(15, 23, 42, 0.25);
}
</style>
