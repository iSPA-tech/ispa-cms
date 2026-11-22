<template>
    <div class="flex flex-col items-center justify-center py-12 text-center">
        <div class="text-4xl">{{ icon }}</div>
        <div class="mt-3 text-base font-semibold text-ink">
            {{ t(titleKey) }}
        </div>
        <div class="mt-1 max-w-md text-sm text-ink-muted">
            {{ t(descriptionKey) }}
        </div>
        <router-link
            v-if="ctaLabel && ctaRoute"
            :to="ctaRoute"
            class="mt-4"
        >
            <a-button type="primary">
                {{ ctaLabel }}
            </a-button>
        </router-link>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
    icon: {
        type: String,
        default: '\uD83D\uDCED',
    },
    titleKey: {
        type: String,
        required: true,
    },
    descriptionKey: {
        type: String,
        required: true,
    },
    ctaLabel: {
        type: String,
        default: null,
    },
    ctaRoute: {
        type: [String, Object],
        default: null,
    },
})
</script>
