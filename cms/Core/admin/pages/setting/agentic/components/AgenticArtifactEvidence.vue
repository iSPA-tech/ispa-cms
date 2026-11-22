<template>
    <div v-if="items.length" class="agentic-meta-strip">
        <div class="agentic-diff-label">{{ title }}</div>
        <div class="mt-2 space-y-2">
            <div
                v-for="(item, index) in items"
                :key="`${kind}-${index}`"
                class="agentic-evidence-row"
            >
                <div class="agentic-evidence-title">
                    {{ item.label || item.type || item.url || `${kind}_${index + 1}` }}
                </div>
                <div v-if="item.note" class="agentic-evidence-copy">
                    {{ item.note }}
                </div>
                <div v-else-if="item.url" class="agentic-evidence-copy break-all">
                    {{ item.url }}
                </div>
                <pre v-else class="agentic-json agentic-json--compact">{{ formatJson(item) }}</pre>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    items: {
        type: Array,
        default: () => [],
    },
    kind: {
        type: String,
        default: 'item',
    },
    formatJson: {
        type: Function,
        required: true,
    },
})
</script>
