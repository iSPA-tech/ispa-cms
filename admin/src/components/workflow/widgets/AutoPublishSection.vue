<template>
    <section
        v-if="state"
        class="flex flex-col gap-3 rounded-lg border border-solid px-3 py-3"
        :class="frameClass"
        :data-state="state"
        :aria-label="t('text.workflow.auto_publish.title')"
        data-testid="auto-publish-section"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="m-0 min-w-0 break-words text-sm font-semibold text-gray-900">{{ t('text.workflow.auto_publish.title') }}</h3>
            <a-tag :color="tagColor" class="m-0 whitespace-normal break-words" data-testid="auto-publish-status">{{ statusText }}</a-tag>
        </div>

        <!-- 1. Learning: a person decides everything while JEV is compared with them -->
        <template v-if="state === 'learning'">
            <p class="mb-0 break-words text-sm text-gray-700">{{ t('text.workflow.auto_publish.learning_intro') }}</p>
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap justify-between gap-x-2 text-xs text-gray-800">
                    <span>{{ t('text.workflow.auto_publish.decisions') }}</span>
                    <span class="font-semibold tabular-nums" data-testid="auto-publish-decided">{{ decided }} / {{ required }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-200" role="progressbar" :aria-valuenow="decided" aria-valuemin="0" :aria-valuemax="required" :aria-label="t('text.workflow.auto_publish.decisions')">
                    <div class="h-2 rounded-full bg-teal-700" :style="{ width: `${progress}%` }" />
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap justify-between gap-x-2 text-xs text-gray-800">
                    <span>{{ t('text.workflow.auto_publish.agreement') }}</span>
                    <span class="tabular-nums" data-testid="auto-publish-agreement">
                        <span class="font-semibold">{{ agreement === null ? t('text.workflow.auto_publish.agreement_none') : `${agreement}%` }}</span>
                        <span class="ml-1 text-gray-600">{{ t('text.workflow.auto_publish.agreement_needed', { percent: minimum }) }}</span>
                    </span>
                </div>
                <div class="relative h-2 overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
                    <div class="h-2 rounded-full" :class="agreementOk ? 'bg-green-600' : 'bg-amber-500'" :style="{ width: `${agreement ?? 0}%` }" />
                    <div class="absolute top-0 h-2 w-0.5 bg-gray-900" :style="{ left: `${minimum}%` }" />
                </div>
            </div>
            <ul v-if="edited || excluded" class="m-0 list-disc space-y-0.5 pl-5 text-xs text-gray-700" data-testid="auto-publish-notes">
                <li v-if="edited">{{ t('text.workflow.auto_publish.edited', { count: edited }) }}</li>
                <li v-if="excluded">{{ t('text.workflow.auto_publish.excluded', { count: excluded }) }}</li>
            </ul>
            <a-button block disabled data-testid="auto-publish-turn-on">{{ t('text.workflow.auto_publish.turn_on') }}</a-button>
            <p class="mb-0 break-words text-xs text-gray-700" data-testid="auto-publish-why-not">{{ whyNot }}</p>
        </template>

        <!-- 2. Ready: the three conditions, the level, the estimate -->
        <template v-else-if="state === 'ready'">
            <p class="mb-0 break-words text-sm text-gray-700">
                {{ t('text.workflow.auto_publish.ready_intro', { decided, percent: agreement ?? 0 }) }}
            </p>
            <ol class="m-0 list-decimal space-y-0.5 pl-5 text-sm text-gray-900">
                <li>{{ t('text.workflow.auto_publish.condition_pass') }}</li>
                <li>{{ t('text.workflow.auto_publish.condition_level') }}</li>
                <li>{{ t('text.workflow.auto_publish.condition_no_flag') }}</li>
            </ol>
            <div class="flex flex-col gap-1">
                <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.auto_publish.level') }}</p>
                <div class="flex flex-wrap gap-1" role="group" :aria-label="t('text.workflow.auto_publish.level')" data-testid="auto-publish-levels">
                    <a-button
                        v-for="row in AUTO_LEVELS"
                        :key="row.key"
                        size="small"
                        :type="level === row.key ? 'primary' : 'default'"
                        :aria-pressed="level === row.key ? 'true' : 'false'"
                        :disabled="locked"
                        :data-level="row.key"
                        @click="chooseLevel(row.value)"
                    >
                        {{ t(`text.workflow.pick.strictness_${row.key}`) }} · {{ levelText(row.value, lang) }}
                    </a-button>
                </div>
                <p v-if="estimateLine" class="mb-0 break-words text-xs text-gray-700" data-testid="auto-publish-estimate">{{ estimateLine }}</p>
            </div>
            <a-button block type="primary" :disabled="locked" data-testid="auto-publish-turn-on" @click="turnOn">{{ t('text.workflow.auto_publish.turn_on') }}</a-button>
            <p class="mb-0 break-words text-xs text-gray-700">{{ t('text.workflow.auto_publish.change_control') }}</p>
        </template>

        <!-- 3. On (or paused): the last 30 days, the level, how to take a story back -->
        <template v-else>
            <a-alert v-if="state === 'paused'" type="warning" show-icon data-testid="auto-publish-paused">
                <template #message>
                    <span class="break-words text-sm">{{ t('text.workflow.auto_publish.paused_body', { percent: minimum }) }}</span>
                </template>
            </a-alert>
            <p v-if="pending" class="mb-0 break-words rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900" data-testid="auto-publish-pending">
                {{ t('text.workflow.auto_publish.pending_publish') }}
            </p>
            <template v-if="recent">
                <dl class="m-0 grid grid-cols-3 gap-2" data-testid="auto-publish-tiles">
                    <div v-for="tile in tiles" :key="tile.key" class="min-w-0 rounded-lg bg-gray-50 px-2 py-2" :data-tile="tile.key">
                        <dd class="m-0 text-lg font-bold tabular-nums text-gray-900">{{ tile.value }}</dd>
                        <dt class="break-words text-xs font-normal text-gray-700">{{ t(`text.workflow.auto_publish.tile_${tile.key}`) }}</dt>
                    </div>
                </dl>
                <p class="mb-0 text-xs text-gray-600">{{ t('text.workflow.auto_publish.last_30_days') }}</p>
            </template>
            <div class="flex flex-col gap-1">
                <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.auto_publish.level') }}</p>
                <div class="flex flex-wrap gap-1" role="group" :aria-label="t('text.workflow.auto_publish.level')" data-testid="auto-publish-levels">
                    <a-button
                        v-for="row in AUTO_LEVELS"
                        :key="row.key"
                        size="small"
                        :type="level === row.key ? 'primary' : 'default'"
                        :aria-pressed="level === row.key ? 'true' : 'false'"
                        :disabled="locked"
                        :data-level="row.key"
                        @click="chooseLevel(row.value)"
                    >
                        {{ t(`text.workflow.pick.strictness_${row.key}`) }} · {{ levelText(row.value, lang) }}
                    </a-button>
                </div>
                <p v-if="estimateLine" class="mb-0 break-words text-xs text-gray-700" data-testid="auto-publish-estimate">{{ estimateLine }}</p>
                <p class="mb-0 break-words text-xs text-gray-700">{{ t('text.workflow.auto_publish.level_change_note') }}</p>
            </div>
            <p class="mb-0 break-words text-sm text-gray-700">{{ t('text.workflow.auto_publish.withdraw_note') }}</p>
            <p v-if="state === 'on'" class="mb-0 break-words rounded-lg border border-solid border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" data-testid="auto-publish-pause-rule">
                {{ t('text.workflow.auto_publish.pause_rule', { count: required, percent: minimum }) }}
            </p>
            <a-button block :disabled="locked" data-testid="auto-publish-turn-off" @click="turnOff">{{ t('text.workflow.auto_publish.turn_off') }}</a-button>
        </template>
    </section>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { canvasUi } from '@/components/workflows/canvasUi.js'
import { loadAutoApproval } from '@/components/workflows/autoApprovalStore.js'
import { useStepData, useStepForm } from '@/components/workflow/inputs/useStepData.js'
import {
    AUTO_LEVELS,
    agreementMet,
    autoPublishState,
    estimateFor,
    levelKey,
    levelPatch,
    levelText,
    levelValue,
    progressPercent,
    ratePercent,
    readsVerdict,
    remainingDecisions,
    turnOffPatch,
    turnOnPatch,
    waitsForPublish,
} from './autoPublishRules.js'

/**
 * "Auto-publish when JEV is sure" on a review step: where the calibration stands, and the buttons that turn it on or
 * off. The buttons only write the step's draft (`mode`, `jev.min_confidence`) through the step form; saving and
 * publishing, with their extra confirmation and second-person review, do the rest. Shown only when the server says
 * the step can publish on its own.
 */
defineOptions({ name: 'AutoPublishSection' })

const props = defineProps({
    readOnly: { type: Boolean, default: false },
})

/** `available(true|false)`: the form hides the plain "who decides" setting while this section is shown. */
const emit = defineEmits(['available'])

const { t, locale } = useI18n()
const form = useStepForm()
const stepData = useStepData()

const lang = computed(() => String(locale?.value || 'vi'))
const config = computed(() => form?.config.value || {})
const stepCode = computed(() => {
    const node = stepData.nodes.value.find((row) => row.id === stepData.currentId.value)
    return String(node?.data?.step_code || '')
})

watch(
    [() => stepData.projectId.value, stepCode, () => readsVerdict(config.value)],
    ([projectId, code, reads]) => reads && loadAutoApproval(projectId, code),
    { immediate: true }
)

const info = computed(() => (readsVerdict(config.value) ? canvasUi.autoApproval[stepCode.value] || null : null))
const state = computed(() => autoPublishState(info.value, config.value))
watch(state, (value) => emit('available', value !== ''), { immediate: true })

const locked = computed(() => props.readOnly || !form)
const number = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0)

const decided = computed(() => number(info.value?.decided))
const required = computed(() => number(info.value?.required))
const progress = computed(() => progressPercent(info.value))
const agreement = computed(() => ratePercent(info.value?.agreement_rate))
const minimum = computed(() => ratePercent(info.value?.minimum_agreement) ?? 90)
const agreementOk = computed(() => agreementMet(info.value))
const edited = computed(() => number(info.value?.approved_after_edit))
const excluded = computed(() => number(info.value?.excluded))

/** Why auto-publish cannot be turned on yet: decisions are missing, or JEV does not agree often enough. */
const whyNot = computed(() => {
    const missing = remainingDecisions(info.value)
    if (missing > 0) return t('text.workflow.auto_publish.need_more', { count: missing })
    return t('text.workflow.auto_publish.need_agreement', { percent: minimum.value })
})

const level = computed(() => levelKey(config.value, info.value))
const levelLabel = computed(() => {
    const value = levelText(levelValue(config.value, info.value), lang.value)
    return level.value ? `${t(`text.workflow.pick.strictness_${level.value}`)} ${value}` : value
})
const estimateLine = computed(() => {
    const estimate = estimateFor(info.value, levelValue(config.value, info.value))
    if (!estimate) return ''
    return t('text.workflow.auto_publish.estimate', { ...estimate, level: level.value ? t(`text.workflow.pick.strictness_${level.value}`) : levelText(levelValue(config.value, info.value), lang.value) })
})

const pending = computed(() => waitsForPublish(info.value, config.value))
const recent = computed(() => (info.value?.last_30_days && typeof info.value.last_30_days === 'object' ? info.value.last_30_days : null))
const tiles = computed(() => ['auto', 'human', 'withdrawn'].map((key) => ({ key, value: number(recent.value?.[key]) })))

const statusText = computed(() => {
    if (state.value === 'learning') return t('text.workflow.auto_publish.status_learning')
    if (state.value === 'ready') return t('text.workflow.auto_publish.status_ready')
    if (state.value === 'paused') return t('text.workflow.auto_publish.status_paused')
    return t('text.workflow.auto_publish.status_on', { level: levelLabel.value })
})
const tagColor = computed(() => ({ learning: 'default', ready: 'cyan', on: 'green', paused: 'orange' })[state.value])
const frameClass = computed(
    () => ({ learning: 'border-gray-200 bg-white', ready: 'border-teal-700 bg-white', on: 'border-green-200 bg-white', paused: 'border-amber-300 bg-white' })[state.value]
)

const turnOn = () => !locked.value && form.patch(turnOnPatch(config.value, levelValue(config.value, info.value)))
const turnOff = () => !locked.value && form.patch(turnOffPatch())
const chooseLevel = (value) => !locked.value && form.patch(levelPatch(config.value, value))
</script>
