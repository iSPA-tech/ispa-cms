<template>
    <div class="space-y-4">
        <div v-if="summaryText" class="agentic-note">
            {{ summaryText }}
        </div>

        <AgenticProductArtifactDiff
            v-if="isProductArtifact"
            :record="record"
            :format-json="formatJson"
        />

        <AgenticEducationArtifactDiff
            v-else-if="isEducationArtifact"
            :record="record"
            :format-json="formatJson"
            :format-scorecard-value="formatScorecardValue"
        />

        <component
            :is="moduleDiff"
            v-else-if="moduleDiff"
            :record="record"
            :format-json="formatJson"
        />

        <div v-else-if="isDescriptionPatch" class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.before') }}</div>
                <div class="agentic-diff-copy">
                    {{ descriptionBefore }}
                </div>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.after') }}</div>
                <div class="agentic-diff-copy">
                    {{ descriptionAfter }}
                </div>
            </div>
        </div>

        <div v-else-if="isJsonPatch" class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.before') }}</div>
                <pre class="agentic-json">{{ formatJson(diffData?.before || {}) }}</pre>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.after') }}</div>
                <pre class="agentic-json">{{ formatJson(diffData?.after || artifact?.payload?.proposed_change || {}) }}</pre>
            </div>
        </div>

        <pre v-else class="agentic-json">{{ formatJson(diffData || artifact?.payload || {}) }}</pre>

        <AgenticArtifactEvidence
            :title="t('text.agentic.diff.evidence')"
            kind="evidence"
            :items="evidenceItems"
            :format-json="formatJson"
        />

        <AgenticArtifactEvidence
            :title="t('text.agentic.diff.citations')"
            kind="citation"
            :items="citationItems"
            :format-json="formatJson"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { agenticDiffComponent } from '../registry'
import AgenticArtifactEvidence from './AgenticArtifactEvidence.vue'
import AgenticEducationArtifactDiff from './AgenticEducationArtifactDiff.vue'
import AgenticProductArtifactDiff from './AgenticProductArtifactDiff.vue'

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

const isProductArtifact = computed(() => {
    const artifactType = artifact.value?.artifact_type || ''

    return artifactType.startsWith('product_') || diffData.value?.type === 'product_catalog_patch' || diffData.value?.type === 'product_flags_patch'
})

const isDescriptionPatch = computed(() =>
    artifact.value?.artifact_type === 'content_post_description_patch'
    || diffData.value?.type === 'text_diff'
)

const isEducationArtifact = computed(() =>
    artifact.value?.artifact_type === 'education_ai_evaluation_patch' || diffData.value?.type === 'scorecard_change'
)

/** The approval diff a module registered for this artifact (or diff) type, else null. */
const moduleDiff = computed(() => agenticDiffComponent(artifact.value?.artifact_type || '', diffData.value?.type || ''))

const isJsonPatch = computed(() => diffData.value?.type === 'json_patch')

const descriptionBefore = computed(() =>
    diffData.value?.before || artifact.value?.original_data_snapshot?.description || t('text.agentic.diff.no_baseline')
)

const descriptionAfter = computed(() =>
    diffData.value?.after
    || artifact.value?.payload?.description
    || artifact.value?.payload?.proposed_change?.description
    || t('text.agentic.diff.no_proposal')
)

const summaryText = computed(() =>
    diffData.value?.summary || artifact.value?.payload?.summary || ''
)

const evidenceItems = computed(() => {
    const evidence = diffData.value?.evidence ?? artifact.value?.payload?.evidence ?? []

    return Array.isArray(evidence) ? evidence : []
})

const citationItems = computed(() => {
    const citations = diffData.value?.citations ?? artifact.value?.payload?.citations ?? []

    return Array.isArray(citations) ? citations : []
})
</script>
