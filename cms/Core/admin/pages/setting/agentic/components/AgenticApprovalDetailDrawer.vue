<template>
    <a-drawer
        v-model:open="drawerOpen"
        :title="t('text.agentic.approval_detail.title')"
        :width="$isMobile() ? '100%' : 720"
        destroy-on-close
    >
        <a-skeleton v-if="isFetching && !record" active :rows="8" />
        <template v-else-if="record">
            <div class="space-y-4">
                <div class="agentic-panel">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-ink">
                                {{ record.artifact?.artifact_type ? sl(record.artifact.artifact_type) : t('text.agentic.approval_detail.unknown_artifact') }}
                            </div>
                            <div class="mt-1 text-xs text-ink-muted">
                                {{ t('text.agentic.approval_detail.approval_id', { id: record.id }) }} · {{ t('text.agentic.approval_detail.run_id', { id: record.run?.id || t('text.agentic.na') }) }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <span class="agentic-pill" :class="runtimeStatusClass(record.status)">
                                {{ sl(record.status) }}
                            </span>
                            <span class="agentic-pill" :class="runtimeStatusClass(record.apply_outcome)">
                                {{ record.apply_outcome ? sl(record.apply_outcome) : sl('not_applied') }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 text-xs text-ink-muted sm:grid-cols-2">
                        <div>{{ t('text.agentic.approval_detail.workflow') }}: #{{ record.workflow?.id || t('text.agentic.na') }} · {{ (record.workflow?.status ? sl(record.workflow.status) : t('text.agentic.run.detached')) }}</div>
                        <div>{{ t('text.agentic.approval_detail.resume_step') }}: {{ record.workflow?.resume_step_id || t('text.agentic.none') }}</div>
                        <div>{{ t('text.agentic.approval_detail.reviewer') }}: {{ record.reviewer?.name || t('text.agentic.na') }}</div>
                        <div>{{ t('text.agentic.approval_detail.reviewed_at') }}: {{ record.reviewed_at || t('text.agentic.na') }}</div>
                        <div>{{ t('text.agentic.approval_detail.apply_started') }}: {{ record.apply_started_at || t('text.agentic.na') }}</div>
                        <div>{{ t('text.agentic.approval_detail.apply_finished') }}: {{ record.apply_finished_at || t('text.agentic.na') }}</div>
                        <div>{{ t('text.agentic.approval_detail.render_hint') }}: {{ record.artifact?.render_hint ? sl(record.artifact.render_hint) : t('text.agentic.na') }}</div>
                        <div>{{ t('text.agentic.approval_detail.contract') }}: {{ record.artifact?.apply_contract_version || t('text.agentic.na') }}</div>
                    </div>
                    <div class="mt-3 text-xs text-ink-muted">
                        {{ t('text.agentic.approval_detail.reviewer_note') }}: {{ record.reviewer_note || t('text.agentic.none') }}
                    </div>
                    <div v-if="record.apply_error" class="mt-2 text-xs text-danger-ink">
                        {{ t('text.agentic.approval_detail.apply_error') }}: {{ record.apply_error }}
                    </div>
                </div>

                <div class="agentic-panel">
                    <div class="agentic-panel-title">{{ t('text.agentic.approval_detail.diff') }}</div>
                    <AgenticArtifactDiff
                        :record="record"
                        :format-json="formatJson"
                        :format-scorecard-value="formatScorecardValue"
                    />
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="agentic-panel">
                        <div class="agentic-panel-title">{{ t('text.agentic.approval_detail.artifact_payload') }}</div>
                        <pre class="agentic-json">{{ formatJson(record.artifact?.payload || {}) }}</pre>
                    </div>
                    <div class="agentic-panel">
                        <div class="agentic-panel-title">{{ t('text.agentic.approval_detail.original_snapshot') }}</div>
                        <pre class="agentic-json">{{ formatJson(record.artifact?.original_data_snapshot || {}) }}</pre>
                    </div>
                </div>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AgenticArtifactDiff from './AgenticArtifactDiff.vue'
import { useAgenticMeta } from '../composables/useAgenticMeta'

const { t } = useI18n()
const { sl } = useAgenticMeta()

const props = defineProps({
    open: { type: Boolean, default: false },
    record: { type: Object, default: null },
    isFetching: { type: Boolean, default: false },
    runtimeStatusClass: { type: Function, required: true },
    formatJson: { type: Function, required: true },
    formatScorecardValue: { type: Function, required: true },
})

const emit = defineEmits(['update:open'])

const drawerOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
})
</script>
