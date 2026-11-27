<!--
  Publish dialog of the workflow designer. It first asks the server what a publish of the saved draft would do and says
  it in plain words: the sensitive changes, and whether the version goes live now, waits for a second person or goes
  live after a waiting time, which the only publisher of a site may skip ("go live now"). A sensitive publish asks for
  the account confirmation (step-up) and is then sent once more.
  A refused publish lists its problems the way a refused save does.
-->
<template>
    <a-modal
        :open="open"
        :title="t('text.workflow.guard.publish.title', { name: workflow?.name || '' })"
        :mask-closable="false"
        :footer="null"
        :destroy-on-close="true"
        width="560px"
        data-testid="publish-dialog"
        @cancel="close"
    >
        <div v-if="stage === 'loading'" class="py-2" role="status" data-testid="publish-loading">
            <a-skeleton active :paragraph="{ rows: 3 }" />
        </div>

        <div v-else-if="stage === 'error'" class="space-y-3" data-testid="publish-load-error">
            <a-alert type="error" show-icon :message="t(loadErrorKey)" />
            <div class="flex flex-wrap justify-end gap-2">
                <a-button @click="close">{{ t('text.workflow.guard.close') }}</a-button>
                <a-button type="primary" data-testid="publish-retry" @click="load">{{ t('text.workflow.guard.retry') }}</a-button>
            </div>
        </div>

        <!-- The publish has ended: say what happened. -->
        <div v-else-if="stage === 'done'" class="space-y-3" data-testid="publish-done">
            <a-alert :type="result.tone" show-icon :message="result.text" :data-path="result.path" data-testid="publish-result" />
            <p v-if="result.path === 'pending_review' || result.path === 'cooling_off'" class="m-0 text-sm text-gray-600">
                {{ t('text.workflow.guard.publish.still_running_old') }}
            </p>
            <div class="flex justify-end">
                <a-button type="primary" data-testid="publish-close" @click="close">{{ t('text.workflow.guard.close') }}</a-button>
            </div>
        </div>

        <div v-else class="space-y-4" data-testid="publish-ready">
            <a-alert
                v-if="outcome.path === 'unchanged'"
                type="info"
                show-icon
                :message="outcome.text"
                data-testid="publish-unchanged"
            />
            <template v-else>
                <section v-if="changes.length" class="space-y-2" data-testid="publish-changes">
                    <h4 class="m-0 text-sm font-semibold text-gray-800">{{ t('text.workflow.guard.publish.sensitive_title') }}</h4>
                    <p class="m-0 text-sm text-gray-600">{{ t('text.workflow.guard.publish.sensitive_hint') }}</p>
                    <ul class="m-0 list-disc space-y-1 pl-5 text-sm text-gray-800">
                        <li v-for="(row, index) in changes" :key="index" class="break-words" data-testid="publish-change">
                            {{ row.text }}
                            <span v-if="row.detail" class="font-medium">{{ row.detail }}</span>
                            <span v-if="row.step" class="text-gray-500">{{ t('text.workflow.guard.at_step', { step: row.step }) }}</span>
                        </li>
                    </ul>
                </section>

                <section class="space-y-2">
                    <h4 class="m-0 text-sm font-semibold text-gray-800">{{ t('text.workflow.guard.publish.what_happens') }}</h4>
                    <a-alert :type="outcome.tone" show-icon :message="outcome.text" :data-path="outcome.path" data-testid="publish-outcome" />
                    <div v-if="outcome.path === 'cooling_off'" class="space-y-1" data-testid="publish-release-now">
                        <a-checkbox v-model:checked="releaseNow" :disabled="busy">
                            {{ t('text.workflow.guard.publish.release_now', { minutes: waitMinutes }) }}
                        </a-checkbox>
                        <p class="m-0 text-xs text-gray-500">{{ t('text.workflow.guard.publish.release_now_hint') }}</p>
                    </div>
                    <p v-if="preview.step_up_required" class="m-0 text-sm text-gray-600" data-testid="publish-stepup-note">
                        {{ t('text.workflow.guard.publish.stepup_note') }}
                    </p>
                </section>

                <section v-if="problems.length" class="space-y-2" data-testid="publish-problems">
                    <a-alert type="error" show-icon :message="t('text.workflow.guard.publish.refused_title')" />
                    <ul class="m-0 list-disc space-y-1 pl-5 text-sm text-gray-800">
                        <li v-for="(row, index) in problems" :key="index" class="break-words" data-testid="publish-problem">
                            {{ row.text }}
                            <span v-if="row.steps.length" class="text-gray-500">{{ t('text.workflow.guard.at_step', { step: row.steps.join(', ') }) }}</span>
                        </li>
                    </ul>
                </section>
                <a-alert v-else-if="actionErrorKey" type="error" show-icon :message="t(actionErrorKey)" data-testid="publish-error" />

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-gray-700">{{ t('text.workflow.guard.publish.note_label') }}</span>
                    <a-textarea
                        v-model:value="note"
                        :auto-size="{ minRows: 2, maxRows: 4 }"
                        :disabled="busy"
                        :maxlength="200"
                        :placeholder="t('text.workflow.guard.publish.note_placeholder')"
                        data-testid="publish-note"
                    />
                </label>
            </template>

            <div class="flex flex-wrap justify-end gap-2">
                <a-button :disabled="busy" data-testid="publish-cancel" @click="close">
                    {{ outcome.path === 'unchanged' ? t('text.workflow.guard.close') : $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    v-if="outcome.path !== 'unchanged'"
                    type="primary"
                    :loading="busy"
                    :disabled="busy || stepUp.open.value"
                    data-testid="publish-confirm"
                    @click="confirm"
                >
                    {{ t(publishButtonKey(skipsWait ? 'published' : outcome.path)) }}
                </a-button>
            </div>
        </div>

        <StepUpDialog
            :open="stepUp.open.value"
            :purpose="PUBLISH_PURPOSE"
            :intro="t('text.workflow.guard.stepup.publish_intro')"
            @confirmed="stepUp.confirmed"
            @cancel="stepUp.cancelled"
        />
    </a-modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStepUpRunner } from '@/libs/securityTokens'
import { recordSaveErrors } from '@/components/workflows/nodeCatalog.js'
import StepUpDialog from '../../../../components/security/step-up-dialog.vue'
import { PUBLISH_PURPOSE, guardErrorCode, previewPublish, publishDraft, refusalProblems } from '../../../../workflow-guard-api'
import { describeChanges, problemsForNodes, publishButtonKey, publishOutcome, publishProblems } from '../../../../workflow-guard-report'

defineOptions({ name: 'WorkflowPublishDialog' })

const props = defineProps({
    open: { type: Boolean, default: false },
    workflow: { type: Object, default: null },
    /** step_code => step name, so sentences name steps the way the canvas does. */
    names: { type: Object, default: () => ({}) },
})

/** `done` carries the publish answer; `refused` the raw problems (the designer shows the first step at fault). */
const emit = defineEmits(['close', 'done', 'refused'])

const { t } = useI18n()
const stepUp = useStepUpRunner({ purpose: PUBLISH_PURPOSE, lazy: true })

/** loading | error | ready | done */
const stage = ref('loading')
const preview = ref({})
const note = ref('')
/** The only publisher of the site skips the waiting time (offered only when the publish would wait for it). */
const releaseNow = ref(false)
const busy = ref(false)
const loadErrorKey = ref('')
const actionErrorKey = ref('')
const problems = ref([])
const result = ref({ path: 'published', tone: 'success', text: '' })

const ctx = computed(() => ({ t, names: props.names }))
const changes = computed(() => describeChanges(preview.value?.changes, ctx.value))
const outcome = computed(() => publishOutcome(preview.value, { t }))
const skipsWait = computed(() => releaseNow.value && outcome.value.path === 'cooling_off')
const waitMinutes = computed(() => (Number(preview.value?.cooling_off_minutes) > 0 ? Number(preview.value.cooling_off_minutes) : 60))

const errorKey = (error, fallback) =>
    ({
        forbidden: 'text.workflow.guard.error.forbidden',
        step_up_required: 'text.workflow.guard.error.stepup_needed',
        // Auto-publish turned on before JEV agreed with the reviewers often enough.
        auto_approval_not_ready: 'text.workflow.issue.auto_approval_not_ready',
    })[guardErrorCode(error)] || fallback

let token = 0
async function load() {
    const id = props.workflow?.uuid
    if (!id) return
    const mine = ++token
    stage.value = 'loading'
    problems.value = []
    actionErrorKey.value = ''
    try {
        const body = await previewPublish(id)
        if (mine !== token) return
        preview.value = body || {}
        stage.value = 'ready'
    } catch (error) {
        if (mine !== token) return
        loadErrorKey.value = errorKey(error, 'text.workflow.guard.publish.preview_failed')
        stage.value = 'error'
    }
}

async function confirm() {
    if (busy.value || stepUp.open.value || !props.workflow?.id) return
    busy.value = true
    problems.value = []
    actionErrorKey.value = ''
    try {
        // The preview already knows a confirmation is needed: ask first instead of sending a publish that will be refused.
        const answer = await stepUp.run((grant) => publishDraft(props.workflow.uuid, note.value.trim(), grant, skipsWait.value), {
            askFirst: Boolean(preview.value?.step_up_required),
        })
        // The operator closed the confirmation: nothing was published.
        if (answer === undefined) return
        result.value = publishOutcome(answer, { t, done: true })
        stage.value = 'done'
        emit('done', answer)
    } catch (error) {
        const rows = refusalProblems(error)
        if (rows.length) {
            problems.value = publishProblems(rows, ctx.value)
            recordSaveErrors({ data: { errors: problemsForNodes(rows, ctx.value) } })
            emit('refused', rows)
        } else {
            actionErrorKey.value = errorKey(error, 'text.workflow.guard.publish.failed')
        }
    } finally {
        busy.value = false
    }
}

function close() {
    if (busy.value) return
    stepUp.cancelled()
    emit('close')
}

watch(
    () => props.open,
    (value) => {
        if (!value) return
        note.value = ''
        releaseNow.value = false
        load()
    },
    { immediate: true }
)
</script>
