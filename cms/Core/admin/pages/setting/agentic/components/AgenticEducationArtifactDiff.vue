<template>
    <div v-if="isScorecardPatch" class="grid gap-2 lg:grid-cols-2">
        <div class="agentic-diff-block">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.before') }}</div>
            <div class="agentic-score-grid">
                <div>{{ t('text.agentic.diff.score') }}</div>
                <div>{{ formatScorecardValue(diffData?.before?.score) }}</div>
                <div>{{ t('text.agentic.diff.status') }}</div>
                <div>{{ diffData?.before?.status || t('text.agentic.na') }}</div>
            </div>
            <div class="agentic-diff-copy">
                {{ diffData?.before?.feedback_text || t('text.agentic.diff.no_baseline_feedback') }}
            </div>
        </div>
        <div class="agentic-diff-block agentic-diff-block--after">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.after') }}</div>
            <div class="agentic-score-grid">
                <div>{{ t('text.agentic.diff.score') }}</div>
                <div>{{ formatScorecardValue(diffData?.after?.score) }}</div>
                <div>{{ t('text.agentic.diff.status') }}</div>
                <div>{{ diffData?.after?.status || t('text.agentic.na') }}</div>
            </div>
            <div class="agentic-diff-copy">
                {{ diffData?.after?.feedback_text || t('text.agentic.diff.no_proposed_feedback') }}
            </div>
        </div>
    </div>

    <pre v-else class="agentic-json">{{ formatJson(diffData || artifact?.payload || {}) }}</pre>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    record: {
        type: Object,
        default: null,
    },
    formatJson: {
        type: Function,
        required: true,
    },
    formatScorecardValue: {
        type: Function,
        required: true,
    },
})

const artifact = computed(() => props.record?.artifact || null)
const diffData = computed(() => props.record?.diff_data || null)

const isScorecardPatch = computed(() =>
    artifact.value?.artifact_type === 'education_ai_evaluation_patch' || diffData.value?.type === 'scorecard_change'
)
</script>
