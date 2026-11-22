<template>
    <a-tooltip v-if="health" placement="bottom">
        <template #title>
            <div class="space-y-1 text-xs">
                <div>
                    <span class="font-semibold">{{ t('text.agentic.health.provider') }}:</span>
                    {{ health.provider || t('text.not_applicable') }}
                </div>
                <div>
                    <span class="font-semibold">{{ t('text.agentic.health.scheduler') }}:</span>
                    {{ health.scheduler || t('text.not_applicable') }}
                </div>
                <div>
                    <span class="font-semibold">{{ t('text.agentic.health.api_key') }}:</span>
                    {{ health.api_key_status || t('text.not_applicable') }}
                </div>
                <div v-if="health.message" class="pt-1 text-ink-faint">
                    {{ health.message }}
                </div>
            </div>
        </template>
        <span class="agentic-pill" :class="statusClass">
            {{ health.status || t('text.agentic.health.unknown') }}
        </span>
    </a-tooltip>
    <span v-else class="agentic-pill agentic-pill--muted">
        {{ t('text.agentic.health.unavailable') }}
    </span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    health: {
        type: Object,
        default: null,
    },
})

const statusClass = computed(() => {
    const status = props.health?.status
    if (status === 'ok') return 'agentic-pill--ok'
    if (status === 'warn') return 'agentic-pill--warn'
    if (status === 'error') return 'agentic-pill--danger'
    return 'agentic-pill--muted'
})
</script>
