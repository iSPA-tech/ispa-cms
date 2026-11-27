<!--
  Why a workflow is paused and what to do about it. A pause by a safety limit is lifted with "turn back on" by an
  account that may publish. A pause after an integrity check means the stored version was changed outside the admin:
  the workflow must be reviewed in the designer and published again.
-->
<template>
    <a-alert
        v-if="info"
        :type="info.kind === 'integrity' ? 'error' : 'warning'"
        show-icon
        :message="t(info.kind === 'integrity' ? 'text.workflow.guard.paused.title_integrity' : 'text.workflow.guard.paused.title')"
        :data-kind="info.kind"
        data-testid="workflow-paused"
    >
        <template #description>
            <div class="space-y-2">
                <p class="m-0 text-sm" data-testid="workflow-paused-reason">{{ t(info.reasonKey) }}</p>
                <p v-if="pausedAt" class="m-0 text-xs text-gray-500">{{ t('text.workflow.guard.paused.since', { time: pausedAt }) }}</p>
                <p v-if="errorKey" class="m-0 text-sm text-red-600" data-testid="workflow-paused-error">{{ t(errorKey) }}</p>
                <div class="flex flex-wrap gap-2">
                    <a-button
                        v-if="info.canResume && canPublish"
                        type="primary"
                        size="small"
                        :loading="busy"
                        data-testid="workflow-resume"
                        @click="resume"
                    >
                        {{ t('text.workflow.guard.paused.resume') }}
                    </a-button>
                    <a-button v-if="info.kind === 'integrity'" size="small" data-testid="workflow-paused-open" @click="$emit('open-builder', workflow)">
                        {{ t('text.workflow.guard.paused.open_designer') }}
                    </a-button>
                </div>
                <p v-if="info.canResume && !canPublish" class="m-0 text-xs text-gray-500" data-testid="workflow-resume-denied">
                    {{ t('text.workflow.guard.paused.resume_needs_publisher') }}
                </p>
            </div>
        </template>
    </a-alert>
</template>

<script setup>
import dayjs from 'dayjs'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { NOT_PUBLISHED_CODES, guardErrorCode, resumeWorkflow } from '../../../../workflow-guard-api'
import { pausedInfo } from '../../../../workflow-guard-report'

defineOptions({ name: 'WorkflowPausedAlert' })

const props = defineProps({
    workflow: { type: Object, default: null },
    canPublish: { type: Boolean, default: false },
})

const emit = defineEmits(['resumed', 'open-builder'])

const { t } = useI18n()
const busy = ref(false)
const errorKey = ref('')

const info = computed(() => pausedInfo(props.workflow))
const pausedAt = computed(() => (props.workflow?.paused_at ? dayjs(props.workflow.paused_at).format('HH:mm DD/MM/YYYY') : ''))

async function resume() {
    if (busy.value || !props.workflow?.id) return
    busy.value = true
    errorKey.value = ''
    try {
        await resumeWorkflow(props.workflow.uuid)
        emit('resumed', props.workflow)
    } catch (error) {
        const code = guardErrorCode(error)
        if (NOT_PUBLISHED_CODES.includes(code)) errorKey.value = 'text.workflow.guard.activate.not_published_body'
        else errorKey.value = code === 'forbidden' ? 'text.workflow.guard.error.forbidden' : 'text.workflow.guard.paused.resume_failed'
    } finally {
        busy.value = false
    }
}
</script>
