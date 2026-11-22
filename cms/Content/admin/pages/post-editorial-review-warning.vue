<template>
    <section
        v-if="review"
        class="mt-3 space-y-3 rounded-lg border p-3"
        :class="needsAttention ? 'border-red-200 bg-red-50/70' : 'border-slate-200 bg-slate-50/70'"
        data-editorial-review-warning
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h4 class="text-sm font-semibold text-slate-900">{{ $t('text.post.review.title') }}</h4>
                <p class="mt-0.5 text-xs text-slate-600">
                    {{
                        $t('text.post.review.score_line', {
                            score: review.score ?? $t('text.not_applicable'),
                            time: review.reviewed_at || $t('text.post.review.no_time'),
                        })
                    }}
                </p>
            </div>
            <span
                class="rounded-full px-2 py-1 text-[11px] font-semibold"
                :class="needsAttention ? 'bg-red-100 text-red-800' : 'bg-slate-200 text-slate-700'"
            >
                {{ $t(stateLabelKey) }}
            </span>
        </div>

        <div v-if="blockingWarnings.length" class="space-y-1.5">
            <p class="text-xs font-semibold text-red-800">
                {{ $t('text.post.review.blocking_title') }}
            </p>
            <ul class="list-disc space-y-1 pl-5 text-xs leading-5 text-red-900">
                <li v-for="warning in blockingWarnings" :key="`blocking-${warning}`">
                    {{ warning }}
                </li>
            </ul>
        </div>

        <div v-if="advisoryWarnings.length" class="space-y-1.5">
            <p class="text-xs font-semibold text-amber-800">
                {{ $t('text.post.review.advisory_title') }}
            </p>
            <ul class="list-disc space-y-1 pl-5 text-xs leading-5 text-amber-900">
                <li v-for="warning in advisoryWarnings" :key="`advisory-${warning}`">
                    {{ warning }}
                </li>
            </ul>
        </div>

        <p v-if="hasStoredPass && !advisoryWarnings.length" class="text-xs text-slate-700">
            {{ $t('text.post.review.no_warnings') }}
        </p>

        <p class="text-xs leading-5 text-slate-600">
            {{ $t('text.post.review.disclaimer') }}
        </p>

        <router-link
            v-if="postUuid"
            :to="{ name: 'pipeline-detail', params: { uuid: postUuid } }"
            class="inline-flex text-xs font-semibold text-main-600 hover:text-main-500"
        >
            {{ $t('text.post.review.open_pipeline') }}
        </router-link>
    </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    reviewPipeline: {
        type: Object,
        default: null,
    },
    postUuid: {
        type: String,
        default: '',
    },
    stale: { type: Boolean, default: false },
})

const review = computed(() => {
    const candidate = props.reviewPipeline?.editorial_review
    if (!candidate || candidate.version !== 1) return null
    if (!['blocked', 'publish_ready'].includes(candidate.status)) return null
    if (!/^[a-f0-9]{64}$/i.test(candidate.reviewed_state_sha256 || '')) return null

    return candidate
})
const strings = (value) =>
    Array.isArray(value)
        ? [...new Set(value.filter((item) => typeof item === 'string').map((item) => item.trim()).filter(Boolean))]
        : []
const blockingWarnings = computed(() => strings(review.value?.blocking_warnings))
const advisoryWarnings = computed(() => strings(review.value?.advisory_warnings))
const hasStoredPass = computed(
    () => review.value?.status === 'publish_ready'
        && Number(review.value?.score || 0) >= 80
        && blockingWarnings.value.length === 0
        && !props.stale
)
const needsAttention = computed(() => props.stale || !hasStoredPass.value)
// Translation key of the badge text; the template translates it
const stateLabelKey = computed(() => props.stale
    ? 'text.post.review.state_stale'
    : (hasStoredPass.value ? 'text.post.review.state_saved' : 'text.post.review.state_fix'))
</script>
