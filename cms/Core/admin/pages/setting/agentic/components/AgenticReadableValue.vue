<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <span v-if="value.kind === 'empty'" class="text-sm text-ink-faint">{{ t('text.agentic.approval.value_empty') }}</span>

    <a
        v-else-if="value.kind === 'link'"
        :href="value.url"
        target="_blank"
        rel="noopener noreferrer"
        class="break-words text-sm text-info-ink underline"
    >
        {{ value.text }}
    </a>

    <ul v-else-if="value.kind === 'list'" class="m-0 list-none space-y-1.5 p-0 text-sm text-ink">
        <li v-for="(item, index) in value.items" :key="index" class="flex min-w-0 gap-2">
            <span v-if="item.lead" class="shrink-0 font-medium tabular-nums text-ink-muted">{{ item.lead }}</span>
            <span v-else class="shrink-0 text-ink-faint" aria-hidden="true">•</span>
            <span class="min-w-0 break-words">
                <template v-if="item.url">
                    <a :href="item.url" target="_blank" rel="noopener noreferrer" class="text-info-ink underline" data-testid="readable-link">
                        {{ item.text }}
                    </a>
                    <span v-if="hostOf(item.url) && !item.text.startsWith(hostOf(item.url))" class="block text-xs text-ink-faint">
                        {{ hostOf(item.url) }}
                    </span>
                </template>
                <template v-else>{{ item.text }}</template>
            </span>
        </li>
    </ul>

    <p v-else class="m-0 whitespace-pre-line break-words text-sm leading-6 text-ink">{{ value.text }}</p>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

defineOptions({ name: 'AgenticReadableValue' })

defineProps({
    /** Display value from `displayValue()` (readable-diff.js). */
    value: { type: Object, required: true },
})

const { t } = useI18n()

/** Site name of a link, shown under its title so the reader sees where it leads. */
function hostOf(url) {
    try {
        return new URL(url).hostname.replace(/^www\./, '')
    } catch {
        return ''
    }
}
</script>
