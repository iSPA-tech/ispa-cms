<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <div class="min-w-0 space-y-4" data-testid="readable-diff">
        <p v-if="!view.rows.length && !view.supporting.length" class="m-0 text-sm text-ink-muted" data-testid="readable-diff-empty">
            {{ t('text.agentic.approval.no_readable_change') }}
        </p>

        <!-- One block per field: current and proposed side by side on wide screens, stacked on phones. -->
        <section v-for="row in view.rows" :key="row.field" class="min-w-0" data-testid="readable-diff-row">
            <h4 class="m-0 mb-1.5 text-sm font-semibold text-ink">{{ row.label }}</h4>
            <div class="grid min-w-0 grid-cols-1 gap-2" :class="view.hasBefore ? 'lg:grid-cols-2' : ''">
                <div v-if="view.hasBefore" class="min-w-0 rounded-lg border border-border bg-surface-sunken p-3" data-testid="readable-diff-before">
                    <div class="mb-1 text-xs font-medium text-ink-faint">{{ t('text.agentic.approval.current_value') }}</div>
                    <AgenticReadableValue :value="row.before" />
                </div>
                <div
                    class="min-w-0 rounded-lg border p-3"
                    :class="row.changed ? 'border-success-soft bg-success-soft/30' : 'border-border bg-surface'"
                    data-testid="readable-diff-after"
                >
                    <div class="mb-1 text-xs font-medium text-ink-faint">
                        {{ row.changed || !view.hasBefore ? t('text.agentic.approval.proposed_value') : t('text.agentic.approval.unchanged_value') }}
                    </div>
                    <AgenticReadableValue :value="row.after" />
                </div>
            </div>
        </section>

        <!-- Sources, photos and notes that back the proposal. -->
        <section v-for="entry in view.supporting" :key="entry.field" class="min-w-0" :data-testid="`readable-diff-${entry.field}`">
            <h4 class="m-0 mb-1.5 text-sm font-semibold text-ink">{{ entry.label }}</h4>
            <AgenticReadableValue :value="entry.value" />
        </section>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { buildReadableDiff } from '../readable-diff'
import AgenticReadableValue from './AgenticReadableValue.vue'

defineOptions({ name: 'AgenticReadableDiff' })

const props = defineProps({
    /** Approval record from the API. */
    record: { type: Object, default: null },
})

const { t, te } = useI18n()
const view = computed(() => buildReadableDiff(props.record, t, te))
</script>
