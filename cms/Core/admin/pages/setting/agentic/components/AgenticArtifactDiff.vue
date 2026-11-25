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

        <!-- Every other proposal: one readable block per field, never raw JSON. -->
        <AgenticReadableDiff v-else :record="record" />

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
import AgenticReadableDiff from './AgenticReadableDiff.vue'

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

const isEducationArtifact = computed(() =>
    artifact.value?.artifact_type === 'education_ai_evaluation_patch' || diffData.value?.type === 'scorecard_change'
)

/** The approval diff a module registered for this artifact (or diff) type, else null. */
const moduleDiff = computed(() => agenticDiffComponent(artifact.value?.artifact_type || '', diffData.value?.type || ''))

/** The proposal's one-line summary; left out when the readable diff below shows a summary field of its own. */
const summaryText = computed(() => {
    const summary = diffData.value?.summary || artifact.value?.payload?.summary || ''
    const after = diffData.value?.after ?? artifact.value?.payload?.proposed_change
    const shownAsField = !usesModuleDiff.value && after && typeof after === 'object' && typeof after.summary === 'string' && after.summary !== ''
    return shownAsField ? '' : summary
})

const usesModuleDiff = computed(() => isProductArtifact.value || isEducationArtifact.value || Boolean(moduleDiff.value))

const evidenceItems = computed(() => {
    const evidence = diffData.value?.evidence ?? artifact.value?.payload?.evidence ?? []

    return Array.isArray(evidence) ? evidence : []
})

const citationItems = computed(() => {
    const citations = diffData.value?.citations ?? artifact.value?.payload?.citations ?? []

    return Array.isArray(citations) ? citations : []
})
</script>
