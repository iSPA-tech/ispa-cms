<template>
    <span v-if="badges.length" class="inline-flex flex-wrap items-center gap-1" data-testid="node-safety-badges">
        <a-tooltip v-for="badge in badges" :key="badge.key" :title="t(badge.hintKey)">
            <a-tag :color="badge.color" class="!m-0 inline-flex items-center gap-1" :data-safety="badge.key">
                <i-icon v-if="!compact" :icon="badge.icon" class="h-3 w-3" />
                <span>{{ t(badge.labelKey) }}</span>
            </a-tag>
        </a-tooltip>
    </span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { safetyBadges } from '@/components/workflows/nodeCatalog'

const props = defineProps({
    /** Node definition from the catalog (uses `safety`). */
    node: {
        type: Object,
        default: null,
    },
    compact: {
        type: Boolean,
        default: false,
    },
})

const { t } = useI18n()

const badges = computed(() => safetyBadges(props.node))
</script>
