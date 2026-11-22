<template>
    <span class="inline-flex items-center gap-1">
        <slot />
        <a-popover v-if="explanation" placement="top">
            <template #content>
                <div class="max-w-xs text-sm text-ink">
                    {{ explanation }}
                </div>
            </template>
            <span
                class="cursor-help text-ink-faint transition-colors hover:text-ink-muted"
                :aria-label="t('text.agentic.help_label')"
            >
                &#9432;
            </span>
        </a-popover>
    </span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticMeta } from '../composables/useAgenticMeta.js'

const { t } = useI18n()
const { helpTerms } = useAgenticMeta()

const props = defineProps({
    term: {
        type: String,
        required: true,
    },
})

const explanation = computed(() => {
    const key = helpTerms[props.term]
    if (!key) return null
    return t(key)
})
</script>
