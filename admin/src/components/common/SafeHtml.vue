<template>
    <component :is="tag" v-html="sanitizedHtml" />
</template>

<script setup>
import { computed } from 'vue'
import { sanitizeHtml } from '@/utils/safeHtml'

const props = defineProps({
    html: { type: [String, Number], default: '' },
    tag: { type: String, default: 'div' },
})

const sanitizedHtml = computed(() => sanitizeHtml(props.html))
</script>
