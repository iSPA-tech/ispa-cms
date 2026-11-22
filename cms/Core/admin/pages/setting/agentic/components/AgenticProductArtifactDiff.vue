<template>
    <div v-if="isProductCatalogPatch" class="space-y-3">
        <div class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.description_before') }}</div>
                <div class="agentic-diff-copy">
                    {{ productCatalogBefore.description }}
                </div>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.description_after') }}</div>
                <div class="agentic-diff-copy">
                    {{ productCatalogAfter.description }}
                </div>
            </div>
        </div>

        <div class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.seo_before') }}</div>
                <div class="agentic-score-grid">
                    <div>{{ t('text.agentic.diff.language') }}</div>
                    <div>{{ formatFieldValue(productCatalogBefore.seo.language_id) }}</div>
                    <div>{{ t('text.agentic.diff.title') }}</div>
                    <div>{{ formatFieldValue(productCatalogBefore.seo.title) }}</div>
                    <div>{{ t('text.agentic.diff.description') }}</div>
                    <div>{{ formatFieldValue(productCatalogBefore.seo.description) }}</div>
                    <div>{{ t('text.agentic.diff.hashtag') }}</div>
                    <div>{{ formatFieldValue(productCatalogBefore.seo.hashtag) }}</div>
                </div>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.seo_after') }}</div>
                <div class="agentic-score-grid">
                    <div>{{ t('text.agentic.diff.language') }}</div>
                    <div>{{ formatFieldValue(productCatalogAfter.seo.language_id) }}</div>
                    <div>{{ t('text.agentic.diff.title') }}</div>
                    <div>{{ formatFieldValue(productCatalogAfter.seo.title) }}</div>
                    <div>{{ t('text.agentic.diff.description') }}</div>
                    <div>{{ formatFieldValue(productCatalogAfter.seo.description) }}</div>
                    <div>{{ t('text.agentic.diff.hashtag') }}</div>
                    <div>{{ formatFieldValue(productCatalogAfter.seo.hashtag) }}</div>
                </div>
            </div>
        </div>

        <div v-if="issueKeys.length" class="agentic-meta-strip">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.normalization_issues') }}</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <span
                    v-for="issueKey in issueKeys"
                    :key="issueKey"
                    class="agentic-chip"
                >
                    {{ issueKey }}
                </span>
            </div>
        </div>
    </div>

    <div v-else-if="isProductDescriptionPatch" class="grid gap-2 lg:grid-cols-2">
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

    <div v-else-if="isProductContentPatch" class="space-y-3">
        <div class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.preview_before') }}</div>
                <div class="agentic-diff-copy">
                    {{ contentBeforePreview }}
                </div>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.preview_after') }}</div>
                <div class="agentic-diff-copy">
                    {{ contentAfterPreview }}
                </div>
            </div>
        </div>

        <div class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.content_before') }}</div>
                <pre class="agentic-json">{{ formatJson(contentBeforeBlocks) }}</pre>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.content_after') }}</div>
                <pre class="agentic-json">{{ formatJson(contentAfterBlocks) }}</pre>
            </div>
        </div>

        <div v-if="issueKeys.length" class="agentic-meta-strip">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.content_issues') }}</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <span
                    v-for="issueKey in issueKeys"
                    :key="issueKey"
                    class="agentic-chip"
                >
                    {{ issueKey }}
                </span>
            </div>
        </div>
    </div>

    <div v-else-if="isProductSeoPatch" class="grid gap-2 lg:grid-cols-2">
        <div class="agentic-diff-block">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.seo_before') }}</div>
            <div class="agentic-score-grid">
                <div>{{ t('text.agentic.diff.language') }}</div>
                <div>{{ formatFieldValue(productSeoBefore.language_id) }}</div>
                <div>{{ t('text.agentic.diff.title') }}</div>
                <div>{{ formatFieldValue(productSeoBefore.title) }}</div>
                <div>{{ t('text.agentic.diff.description') }}</div>
                <div>{{ formatFieldValue(productSeoBefore.description) }}</div>
                <div>{{ t('text.agentic.diff.hashtag') }}</div>
                <div>{{ formatFieldValue(productSeoBefore.hashtag) }}</div>
            </div>
        </div>
        <div class="agentic-diff-block agentic-diff-block--after">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.seo_after') }}</div>
            <div class="agentic-score-grid">
                <div>{{ t('text.agentic.diff.language') }}</div>
                <div>{{ formatFieldValue(productSeoAfter.language_id) }}</div>
                <div>{{ t('text.agentic.diff.title') }}</div>
                <div>{{ formatFieldValue(productSeoAfter.title) }}</div>
                <div>{{ t('text.agentic.diff.description') }}</div>
                <div>{{ formatFieldValue(productSeoAfter.description) }}</div>
                <div>{{ t('text.agentic.diff.hashtag') }}</div>
                <div>{{ formatFieldValue(productSeoAfter.hashtag) }}</div>
            </div>
        </div>
    </div>

    <div v-else-if="isProductFlagsPatch" class="space-y-3">
        <div class="grid gap-2 lg:grid-cols-2">
            <div class="agentic-diff-block">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.flags_before') }}</div>
                <div class="agentic-score-grid">
                    <div v-for="flag in flagKeys" :key="`before-${flag}`">{{ flag }}</div>
                    <div v-for="flag in flagKeys" :key="`before-value-${flag}`">{{ formatBoolean(productFlagsBefore[flag]) }}</div>
                </div>
            </div>
            <div class="agentic-diff-block agentic-diff-block--after">
                <div class="agentic-diff-label">{{ t('text.agentic.diff.flags_after') }}</div>
                <div class="agentic-score-grid">
                    <div v-for="flag in flagKeys" :key="`after-${flag}`">{{ flag }}</div>
                    <div v-for="flag in flagKeys" :key="`after-value-${flag}`">{{ formatBoolean(productFlagsAfter[flag]) }}</div>
                </div>
            </div>
        </div>

        <div v-if="changedKeys.length" class="agentic-meta-strip">
            <div class="agentic-diff-label">{{ t('text.agentic.diff.changed_flags') }}</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <span
                    v-for="changedKey in changedKeys"
                    :key="changedKey"
                    class="agentic-chip"
                >
                    {{ changedKey }}
                </span>
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
})

const artifact = computed(() => props.record?.artifact || null)
const diffData = computed(() => props.record?.diff_data || null)

const isProductCatalogPatch = computed(() =>
    artifact.value?.artifact_type === 'product_catalog_normalization_patch' || diffData.value?.type === 'product_catalog_patch'
)

const isProductDescriptionPatch = computed(() =>
    artifact.value?.artifact_type === 'product_description_patch'
)

const isProductContentPatch = computed(() =>
    artifact.value?.artifact_type === 'product_content_patch' || diffData.value?.type === 'content_patch'
)

const isProductSeoPatch = computed(() =>
    artifact.value?.artifact_type === 'product_seo_patch'
)

const isProductFlagsPatch = computed(() =>
    artifact.value?.artifact_type === 'product_custom_flags_patch' || diffData.value?.type === 'product_flags_patch'
)

const issueKeys = computed(() => {
    const issues = diffData.value?.issue_keys ?? artifact.value?.payload?.proposed_change?.issue_keys ?? []

    return Array.isArray(issues) ? issues : []
})

const descriptionBefore = computed(() =>
    diffData.value?.before || artifact.value?.original_data_snapshot?.description || t('text.agentic.diff.no_baseline')
)

const descriptionAfter = computed(() =>
    diffData.value?.after
    || artifact.value?.payload?.description
    || artifact.value?.payload?.proposed_change?.description
    || t('text.agentic.diff.no_proposal')
)

const contentBeforeBlocks = computed(() => {
    const blocks = diffData.value?.before ?? artifact.value?.original_data_snapshot?.content ?? []

    return Array.isArray(blocks) ? blocks : []
})

const contentAfterBlocks = computed(() => {
    const blocks = diffData.value?.after ?? artifact.value?.payload?.proposed_change?.content ?? []

    return Array.isArray(blocks) ? blocks : []
})

const contentBeforePreview = computed(() =>
    diffData.value?.before_preview || contentPreview(contentBeforeBlocks.value) || t('text.agentic.diff.no_baseline')
)

const contentAfterPreview = computed(() =>
    diffData.value?.after_preview || contentPreview(contentAfterBlocks.value) || t('text.agentic.diff.no_proposal')
)

const productCatalogBefore = computed(() => ({
    description: diffData.value?.before?.description || artifact.value?.original_data_snapshot?.description || t('text.agentic.diff.no_baseline'),
    seo: {
        language_id: diffData.value?.before?.seo?.language_id ?? artifact.value?.original_data_snapshot?.seo?.language_id ?? null,
        title: diffData.value?.before?.seo?.title ?? artifact.value?.original_data_snapshot?.seo?.title ?? '',
        description: diffData.value?.before?.seo?.description ?? artifact.value?.original_data_snapshot?.seo?.description ?? '',
        hashtag: diffData.value?.before?.seo?.hashtag ?? artifact.value?.original_data_snapshot?.seo?.hashtag ?? '',
    },
}))

const productCatalogAfter = computed(() => ({
    description: diffData.value?.after?.description || artifact.value?.payload?.proposed_change?.description || t('text.agentic.diff.no_proposal'),
    seo: {
        language_id: diffData.value?.after?.seo?.language_id ?? artifact.value?.payload?.proposed_change?.seo?.language_id ?? null,
        title: diffData.value?.after?.seo?.title ?? artifact.value?.payload?.proposed_change?.seo?.title ?? '',
        description: diffData.value?.after?.seo?.description ?? artifact.value?.payload?.proposed_change?.seo?.description ?? '',
        hashtag: diffData.value?.after?.seo?.hashtag ?? artifact.value?.payload?.proposed_change?.seo?.hashtag ?? '',
    },
}))

const productSeoBefore = computed(() => ({
    language_id: diffData.value?.before?.language_id ?? artifact.value?.original_data_snapshot?.seo?.language_id ?? null,
    title: diffData.value?.before?.title ?? artifact.value?.original_data_snapshot?.seo?.title ?? '',
    description: diffData.value?.before?.description ?? artifact.value?.original_data_snapshot?.seo?.description ?? '',
    hashtag: diffData.value?.before?.hashtag ?? artifact.value?.original_data_snapshot?.seo?.hashtag ?? '',
}))

const productSeoAfter = computed(() => ({
    language_id: diffData.value?.after?.language_id ?? artifact.value?.payload?.proposed_change?.language_id ?? null,
    title: diffData.value?.after?.title ?? artifact.value?.payload?.proposed_change?.title ?? '',
    description: diffData.value?.after?.description ?? artifact.value?.payload?.proposed_change?.description ?? '',
    hashtag: diffData.value?.after?.hashtag ?? artifact.value?.payload?.proposed_change?.hashtag ?? '',
}))

const flagKeys = ['newProduct', 'selling', 'goodPrice', 'favourite', 'featured']

const productFlagsBefore = computed(() => normalizeFlags(
    diffData.value?.before ?? artifact.value?.original_data_snapshot?.custom_flags ?? {}
))

const productFlagsAfter = computed(() => normalizeFlags(
    diffData.value?.after ?? artifact.value?.payload?.proposed_change?.custom_flags ?? {}
))

const changedKeys = computed(() => {
    const keys = diffData.value?.changed_keys ?? artifact.value?.payload?.proposed_change?.changed_keys ?? []

    return Array.isArray(keys) ? keys : []
})

const formatFieldValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return t('text.agentic.na')
    }

    return value
}

const formatBoolean = (value) => (value ? 'on' : 'off')

const normalizeFlags = (flags) => ({
    newProduct: Boolean(flags?.newProduct),
    selling: Boolean(flags?.selling),
    goodPrice: Boolean(flags?.goodPrice),
    favourite: Boolean(flags?.favourite),
    featured: Boolean(flags?.featured),
})

const contentPreview = (blocks) => {
    if (!Array.isArray(blocks)) {
        return ''
    }

    return blocks
        .map((block) => {
            if (!block || typeof block !== 'object') {
                return null
            }

            const value = block.value
            if (typeof value !== 'string') {
                return null
            }

            return value.replace(/<[^>]*>/g, '').trim()
        })
        .filter(Boolean)
        .join('\n\n')
}
</script>
