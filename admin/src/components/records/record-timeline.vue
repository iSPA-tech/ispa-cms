<template>
    <section class="space-y-3" data-testid="record-timeline">
        <a-tabs v-model:active-key="active" size="small">
            <a-tab-pane key="notes" :tab="t('text.timeline.notes_tab')" />
            <a-tab-pane key="history" :tab="t('text.timeline.history_tab')" />
        </a-tabs>
        <record-notes v-if="active === 'notes'" :type="type" :uuid="uuid" :can-note="canNote" />
        <record-history v-else :type="type" :uuid="uuid" />
    </section>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import RecordHistory from './record-history.vue'
import RecordNotes from './record-notes.vue'

/**
 * Timeline of one record: internal notes and change history in two tabs. The tab panes are empty headers and the content
 * is rendered with `v-if`, so only the active tab is mounted and the other one releases its requests and timers.
 */
defineProps({
    type: { type: String, required: true },
    uuid: { type: String, required: true },
    canNote: { type: Boolean, default: true },
})

const { t } = useI18n()
const active = ref('notes')
</script>
