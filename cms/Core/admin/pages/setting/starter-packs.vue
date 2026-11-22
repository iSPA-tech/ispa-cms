<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-5xl mx-auto space-y-6" data-testid="starter-packs-page">
        <section class="i-box space-y-1">
            <h3 class="m-0 text-sm font-semibold">
                {{ t('text.starter_pack.title') }}
            </h3>
            <p class="m-0 text-sm text-ink-muted">
                {{ t('text.starter_pack.intro') }}
            </p>
        </section>

        <section
            v-if="run"
            class="i-box space-y-3"
            aria-live="polite"
            data-testid="apply-run"
        >
            <a-alert
                v-if="runRunning"
                type="info"
                show-icon
                :message="t('text.starter_pack.started', { name: run.name })"
                :description="t('text.starter_pack.started_hint')"
                data-testid="apply-started"
            />
            <a-alert
                v-else-if="run.row?.status === 'succeeded'"
                type="success"
                show-icon
                :message="t('text.starter_pack.result_succeeded', { name: run.name })"
                :description="t('text.starter_pack.result_backup_taken')"
                data-testid="apply-result-ok"
            />
            <a-alert
                v-else-if="run.row?.status === 'failed'"
                type="error"
                show-icon
                :message="t('text.starter_pack.result_failed', { name: run.name })"
                :description="failedDescription"
                data-testid="apply-result-failed"
            />
            <a-alert
                v-else-if="run.watchFailed"
                type="warning"
                show-icon
                :message="t('text.starter_pack.result_unknown')"
                data-testid="apply-result-unknown"
            />
            <ul
                v-if="runSteps.length"
                class="m-0 list-none space-y-1 p-0"
                data-testid="apply-steps"
            >
                <li
                    v-for="step in runSteps"
                    :key="step.name"
                    class="flex flex-wrap items-start justify-between gap-2 text-sm"
                    :data-testid="`apply-step-${step.name}`"
                >
                    <span class="min-w-0 break-words">
                        {{ stepLabel(step.name) }}
                        <span
                            v-if="stepCounts(step)"
                            class="text-ink-muted"
                            >({{ stepCounts(step) }})</span
                        >
                        <span
                            v-if="step.explain"
                            class="block text-danger"
                            >{{ step.explain }}</span
                        >
                        <span
                            v-if="stepBlocked(step)"
                            class="block text-ink-muted"
                            :data-testid="`apply-step-${step.name}-blocked`"
                            >{{ t('text.starter_pack.step_blocked_hint') }}</span
                        >
                    </span>
                    <a-tag
                        class="m-0"
                        :color="stepBlocked(step) ? 'orange' : stepColor(step.status)"
                        >{{
                        stepStatusLabel(step.status)
                    }}</a-tag>
                </li>
            </ul>
        </section>

        <div
            v-if="loading"
            class="i-box space-y-2"
            role="status"
            data-testid="starter-packs-loading"
        >
            <a-skeleton active :paragraph="{ rows: 4 }" />
            <p class="m-0 text-sm text-ink-muted">
                {{ t('text.starter_pack.loading') }}
            </p>
        </div>
        <a-alert
            v-else-if="loadError"
            type="error"
            show-icon
            :message="t('text.starter_pack.load_failed')"
            :description="t('text.starter_pack.load_failed_hint')"
            data-testid="starter-packs-error"
        >
            <template #action>
                <a-button
                    size="small"
                    data-testid="starter-packs-retry"
                    @click="loadPacks"
                >
                    {{ t('text.starter_pack.retry') }}
                </a-button>
            </template>
        </a-alert>
        <section
            v-else-if="!packs.length"
            class="i-box"
            data-testid="starter-packs-empty"
        >
            <a-empty :description="t('text.starter_pack.empty')" />
        </section>
        <template v-else>
            <div>
                <ul
                    class="m-0 grid list-none grid-cols-1 gap-3 p-0 md:grid-cols-2"
                >
                    <li v-for="pack in packs" :key="pack.key" class="min-w-0">
                        <button
                            type="button"
                            class="i-box block w-full cursor-pointer space-y-2 text-left"
                            :class="
                                pack.key === selected ? 'border-primary' : ''
                            "
                            :aria-pressed="pack.key === selected"
                            :data-testid="`pack-card-${pack.key}`"
                            @click="select(pack.key)"
                        >
                            <span
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span
                                    class="min-w-0 break-words text-sm font-semibold"
                                    >{{ pack.name }}</span
                                >
                                <a-tag
                                    v-if="pack.applied"
                                    class="m-0"
                                    color="green"
                                    :data-testid="`pack-applied-${pack.key}`"
                                >
                                    {{ t('text.starter_pack.applied') }}
                                </a-tag>
                            </span>
                            <span
                                class="block break-words text-sm text-ink-muted"
                                >{{ pack.description }}</span
                            >
                            <span class="block text-xs text-ink-muted">
                                {{
                                    t('text.starter_pack.version', {
                                        version: pack.version,
                                    })
                                }}
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <section
                v-if="selected"
                class="i-box space-y-4"
                :data-testid="`pack-detail`"
                aria-live="polite"
            >
                <div
                    v-if="detailLoading"
                    role="status"
                    data-testid="pack-detail-loading"
                >
                    <a-skeleton active :paragraph="{ rows: 5 }" />
                </div>
                <a-alert
                    v-else-if="detailError"
                    type="error"
                    show-icon
                    :message="t('text.starter_pack.detail_failed')"
                    :description="t('text.starter_pack.load_failed_hint')"
                    data-testid="pack-detail-error"
                >
                    <template #action>
                        <a-button
                            size="small"
                            data-testid="pack-detail-retry"
                            @click="select(selected, true)"
                        >
                            {{ t('text.starter_pack.retry') }}
                        </a-button>
                    </template>
                </a-alert>
                <template v-else-if="plan">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h4 class="m-0 min-w-0 break-words text-sm font-semibold">
                            {{ detail.name }}
                        </h4>
                        <i-button
                            v-if="canApply"
                            variant="primary"
                            size="sm"
                            data-testid="pack-apply"
                            :disabled="!!applyBlockedReason"
                            @click="openConfirm"
                        >
                            {{ t('text.starter_pack.apply') }}
                        </i-button>
                    </div>
                    <p
                        v-if="canApply && applyBlockedReason"
                        class="m-0 text-sm text-ink-muted"
                        data-testid="pack-apply-reason"
                    >
                        {{ applyBlockedReason }}
                    </p>

                    <div
                        v-if="blockers.length"
                        class="space-y-2"
                        data-testid="pack-blockers"
                    >
                        <a-alert
                            v-for="(text, index) in blockers"
                            :key="`b${index}`"
                            type="error"
                            show-icon
                            :message="
                                index === 0
                                    ? t('text.starter_pack.blocked')
                                    : ''
                            "
                            :description="text"
                        />
                    </div>

                    <a-alert
                        v-else-if="nothingToDo"
                        type="success"
                        show-icon
                        :message="t('text.starter_pack.nothing_to_do')"
                        data-testid="pack-nothing-to-do"
                    />

                    <a-alert
                        v-for="(text, index) in warnings"
                        :key="`w${index}`"
                        type="warning"
                        show-icon
                        :message="text"
                        data-testid="pack-warning"
                    />

                    <div
                        v-if="irreversible.length"
                        class="space-y-1"
                        data-testid="pack-irreversible"
                    >
                        <a-alert
                            type="warning"
                            show-icon
                            :message="
                                t('text.starter_pack.irreversible_warning')
                            "
                            :description="
                                irreversible.map((m) => m.name).join(', ')
                            "
                        />
                    </div>

                    <a-alert
                        v-if="partnerModules.length"
                        type="warning"
                        show-icon
                        :message="t('text.starter_pack.third_party_warning')"
                        :description="partnerModules.map((m) => m.name).join(', ')"
                        data-testid="pack-third-party"
                    />

                    <div class="space-y-2">
                        <h5 class="m-0 text-sm font-semibold">
                            {{ t('text.starter_pack.modules') }}
                        </h5>
                        <p
                            v-if="!plan.modules?.length"
                            class="m-0 text-sm text-ink-muted"
                        >
                            {{ t('text.starter_pack.none') }}
                        </p>
                        <ul
                            v-else
                            class="m-0 list-none space-y-1 p-0"
                            data-testid="pack-modules"
                        >
                            <li
                                v-for="mod in plan.modules"
                                :key="mod.name"
                                class="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <span class="min-w-0 break-words">
                                    {{ mod.name }}
                                    <span
                                        v-if="mod.version"
                                        class="text-ink-muted"
                                        >{{ mod.version }}</span
                                    >
                                </span>
                                <a-tag
                                    class="m-0"
                                    :color="stateColor(mod.state)"
                                    >{{ stateLabel(mod.state) }}</a-tag
                                >
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="sections.length"
                        class="space-y-2"
                        data-testid="pack-config"
                    >
                        <h5 class="m-0 text-sm font-semibold">
                            {{ t('text.starter_pack.config') }}
                        </h5>
                        <ul class="m-0 list-none space-y-1 p-0">
                            <li
                                v-for="row in sections"
                                :key="row.key"
                                class="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <span class="min-w-0 break-words">{{
                                    sectionLabel(row.key)
                                }}</span>
                                <span class="text-ink-muted">{{
                                    row.update > 0
                                        ? `${t('text.starter_pack.will_create', { count: row.create })}, ${t('text.starter_pack.will_keep', { count: row.update })}`
                                        : t('text.starter_pack.will_create', { count: row.create })
                                }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="roleNames.length || workflowNames.length"
                        class="space-y-2"
                        data-testid="pack-roles"
                    >
                        <h5 class="m-0 text-sm font-semibold">
                            {{ t('text.starter_pack.roles_workflows') }}
                        </h5>
                        <p
                            v-if="roleNames.length"
                            class="m-0 break-words text-sm"
                        >
                            {{
                                t('text.starter_pack.roles_create', {
                                    names: roleNames.join(', '),
                                })
                            }}
                        </p>
                        <p
                            v-if="workflowNames.length"
                            class="m-0 break-words text-sm"
                        >
                            {{
                                t('text.starter_pack.workflows_create', {
                                    names: workflowNames.join(', '),
                                })
                            }}
                        </p>
                    </div>

                    <div
                        v-if="plan.content?.length"
                        class="space-y-2"
                        data-testid="pack-content"
                    >
                        <h5 class="m-0 text-sm font-semibold">
                            {{ t('text.starter_pack.content') }}
                        </h5>
                        <ul class="m-0 list-none space-y-1 p-0">
                            <li
                                v-for="(row, index) in plan.content"
                                :key="index"
                                class="text-sm"
                            >
                                <span class="font-medium">{{ row.module }}</span
                                ><span class="text-ink-muted"
                                    >:
                                    {{
                                        row.counts
                                            ? countsText(row.counts)
                                            : t(
                                                  'text.starter_pack.counted_later'
                                              )
                                    }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </template>
            </section>
        </template>

        <a-drawer
            :open="confirm.open"
            width="min(480px, 100vw)"
            :title="t('text.starter_pack.confirm_title', { name: detail?.name || '' })"
            @close="closeConfirm"
        >
            <form
                class="flex flex-col gap-4"
                data-testid="apply-confirm"
                novalidate
                @submit.prevent="submitApply"
            >
                <a-alert
                    v-if="confirm.error"
                    type="error"
                    show-icon
                    :message="confirm.error"
                    data-testid="apply-error"
                >
                    <template v-if="confirm.errorList.length" #description>
                        <ul class="m-0 list-disc space-y-1 pl-5">
                            <li v-for="(line, i) in confirm.errorList" :key="i">
                                {{ line }}
                            </li>
                        </ul>
                    </template>
                </a-alert>
                <section class="space-y-1">
                    <h4 class="m-0 text-sm font-semibold">
                        {{ t('text.starter_pack.confirm_steps_title') }}
                    </h4>
                    <ol
                        class="m-0 list-decimal space-y-1 pl-5 text-sm"
                        data-testid="apply-confirm-steps"
                    >
                        <li v-for="step in confirmSteps" :key="step">
                            {{ t(`text.starter_pack.confirm_step_${step}`) }}
                        </li>
                    </ol>
                    <p
                        class="m-0 pt-2 text-xs text-ink-muted"
                        data-testid="apply-confirm-backup"
                    >
                        {{ t('text.starter_pack.confirm_backup') }}
                    </p>
                </section>
                <section
                    v-if="needConfirmation.length"
                    class="space-y-2"
                    data-testid="apply-confirm-irreversible"
                >
                    <a-alert
                        type="warning"
                        show-icon
                        :message="t('text.starter_pack.confirm_irreversible_title')"
                        :description="t('text.starter_pack.confirm_irreversible_text')"
                    />
                    <ul class="m-0 list-none space-y-2 p-0 text-sm">
                        <li v-for="mod in needConfirmation" :key="mod.name">
                            <span class="font-medium">{{ mod.name }}</span>
                            <ul
                                class="m-0 list-disc pl-5 text-xs text-ink-muted"
                            >
                                <li
                                    v-for="migration in mod.migrations"
                                    :key="migration"
                                    class="break-all"
                                >
                                    {{ migration }}
                                </li>
                            </ul>
                        </li>
                    </ul>
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="confirm.understood"
                            type="checkbox"
                            class="mt-1"
                            data-testid="apply-confirm-understood"
                        />
                        <span>{{ t('text.starter_pack.confirm_understood') }}</span>
                    </label>
                </section>
                <section
                    v-if="partnerModules.length"
                    class="space-y-3"
                    data-testid="apply-confirm-third-party"
                >
                    <h4 class="m-0 text-sm font-semibold">
                        {{ t('text.starter_pack.confirm_third_party_title') }}
                    </h4>
                    <div v-for="mod in partnerModules" :key="mod.name" class="space-y-1">
                        <p class="m-0 text-sm font-medium">
                            {{ t('text.starter_pack.confirm_third_party_module', { name: mod.name }) }}
                        </p>
                        <third-party-confirm v-model="confirm.thirdParty[mod.name]" :details="mod.details" />
                    </div>
                </section>
                <div class="flex flex-wrap justify-end gap-2">
                    <i-button
                        size="sm"
                        data-testid="apply-cancel"
                        @click="closeConfirm"
                    >
                        {{ t('text.starter_pack.confirm_cancel') }}
                    </i-button>
                    <i-button
                        variant="primary"
                        size="sm"
                        native-type="submit"
                        data-testid="apply-start"
                        :loading="confirm.busy"
                        :disabled="!canStart"
                    >
                        {{ t('text.starter_pack.confirm_start') }}
                    </i-button>
                </div>
            </form>
        </a-drawer>
    </div>
</template>

<script setup>
import { computed, defineComponent, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { iAlert } from '@/mixins'
import { isWebmaster } from '@/hooks/useCan'
import {
    applyErrorKind,
    applyStarterPack,
    fetchStarterPack,
    fetchStarterPackHistory,
    fetchStarterPacks,
    hasConfiguration,
    hasNothingToDo,
    irreversibleModules,
    isApplicationRunning,
    matchMessage,
    matchStepMessage,
    modulesNeedingConfirmation,
    modulesNeedingThirdParty,
} from '@/libs/starter-packs'
import ThirdPartyConfirm from './modules/third-party-confirm.vue'

defineComponent({ name: 'SettingStarterPacks' })

const { t, te, locale } = useI18n()

/** Milliseconds between two reads of a running apply; the timer only lives while the apply runs. */
const RUN_POLL_MS = 4000
/** Failed reads in a row after which the page stops watching and points at the task bar. */
const RUN_POLL_MAX_FAILURES = 3

const packs = ref([])
const loading = ref(false)
const loadError = ref(false)
/** Key of the pack whose plan is open; empty when none. */
const selected = ref('')
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref(false)
let disposed = false
/** Counter that drops the answer of a request that was overtaken by a newer click. */
let detailSeq = 0

const plan = computed(() => detail.value?.plan || null)
const nothingToDo = computed(() => hasNothingToDo(plan.value))
const irreversible = computed(() => irreversibleModules(plan.value))

/** Translate a planner message; an unknown one is shown as sent. */
const say = (text) => {
    const hit = matchMessage(text)
    if (!hit) return String(text)
    const path = `text.starter_pack.${hit.key}`
    return te(path) ? t(path, hit.params) : String(text)
}
const blockers = computed(() => (plan.value?.blockers || []).map(say))
const warnings = computed(() => (plan.value?.warnings || []).map(say))

/** Configuration sections as rows with create and update counts. */
const sections = computed(() => {
    const raw = plan.value?.config?.sections || {}
    return Object.entries(raw).map(([key, value]) => ({
        key,
        create: Number(value?.counts?.create) || 0,
        update: Number(value?.counts?.update) || 0,
    }))
})
const roleNames = computed(() => plan.value?.roles?.created || [])
const workflowNames = computed(() => plan.value?.workflows?.created || [])

/** Operator label of a configuration section; an unknown key becomes a humanised name. */
const sectionLabel = (key) => {
    const path = `text.config_package.section_${key}`
    if (te(path)) return t(path)
    const words = String(key).replace(/[_-]+/g, ' ').trim()
    return words.charAt(0).toUpperCase() + words.slice(1)
}

const stateLabel = (state) =>
    te(`text.starter_pack.state_${state}`)
        ? t(`text.starter_pack.state_${state}`)
        : state
const stateColor = (state) =>
    state === 'enabled' ? 'green' : state === 'installed' ? 'blue' : 'orange'

/** "3 articles, 2 categories" from an importer's counts. */
const countsText = (counts) =>
    Object.entries(counts)
        .filter(([, value]) => Number(value) > 0)
        .map(([key, value]) =>
            te(`text.starter_pack.count_${key}`)
                ? t(`text.starter_pack.count_${key}`, { count: value })
                : `${key}: ${value}`
        )
        .join(', ') || t('text.starter_pack.nothing_new')

/** Load the pack list. */
const loadPacks = async () => {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        const rows = await fetchStarterPacks(locale.value)
        if (disposed) return
        packs.value = rows
    } catch {
        if (!disposed) loadError.value = true
    } finally {
        if (!disposed) loading.value = false
    }
}

/** Open the plan of a pack (or reload it). */
const select = async (key, force = false) => {
    if (!force && key === selected.value && detail.value) return
    selected.value = key
    const seq = ++detailSeq
    detail.value = null
    detailError.value = false
    detailLoading.value = true
    try {
        const body = await fetchStarterPack(key, locale.value)
        if (disposed || seq !== detailSeq) return
        detail.value = body
    } catch {
        if (!disposed && seq === detailSeq) detailError.value = true
    } finally {
        if (!disposed && seq === detailSeq) detailLoading.value = false
    }
}

// ---- Apply ----------------------------------------------------------------------------------------------------

const webmaster = computed(() => isWebmaster())
/** The API tells whether the viewer holds `starter_pack.apply`; without it no apply control is rendered. */
const canApply = computed(() => detail.value?.can_apply === true)
const needConfirmation = computed(() => modulesNeedingConfirmation(plan.value))
/** Modules to install that a partner publishes: each gets the confirmation box of the single install. */
const partnerModules = computed(() => modulesNeedingThirdParty(plan.value))

/** Why the apply button is off, in plain words; empty when it can be used. */
const applyBlockedReason = computed(() => {
    if (runRunning.value) return t('text.starter_pack.reason_running')
    if (blockers.value.length) return t('text.starter_pack.reason_blocked')
    if (nothingToDo.value) return t('text.starter_pack.reason_nothing')
    if (hasConfiguration(plan.value) && !webmaster.value)
        return t('text.starter_pack.reason_webmaster')
    return ''
})

/** Steps listed in the confirmation: only those the plan has work for. */
const confirmSteps = computed(() => {
    const p = plan.value
    if (!p) return []
    const steps = ['backup']
    if ((p.modules || []).some((m) => m.state !== 'enabled')) steps.push('modules')
    if (hasConfiguration(p)) steps.push('config')
    if (p.roles?.created?.length) steps.push('roles')
    if (p.workflows?.created?.length) steps.push('workflows')
    if (p.content?.length) steps.push('content')
    return steps
})

const confirm = reactive({
    open: false,
    busy: false,
    understood: false,
    /** Module name => its partner box is ticked. */
    thirdParty: {},
    error: '',
    errorList: [],
})

/** The apply button of the dialog: off while a request runs and until the irreversible changes are confirmed. */
const canStart = computed(
    () =>
        !confirm.busy &&
        !applyBlockedReason.value &&
        (needConfirmation.value.length === 0 || confirm.understood) &&
        partnerModules.value.every((m) => confirm.thirdParty[m.name] === true)
)

const openConfirm = () => {
    if (applyBlockedReason.value) return
    Object.assign(confirm, {
        open: true,
        busy: false,
        understood: false,
        thirdParty: {},
        error: '',
        errorList: [],
    })
}
const closeConfirm = () => {
    if (confirm.busy) return
    confirm.open = false
}

/** Plain wording of an apply error; a 422 lists the blockers. */
const showApplyError = (error) => {
    const { kind, blockers: lines } = applyErrorKind(error)
    confirm.error = t(`text.starter_pack.error_${kind}`)
    confirm.errorList = kind === 'blocked' ? lines.map(say) : []
    // A stale or changed plan: show the current one again so the next click uses it.
    if (['stale', 'blocked', 'irreversible', 'third_party'].includes(kind) && selected.value) {
        select(selected.value, true)
    }
}

/**
 * Start the apply. A second click while the request runs sends nothing; the flag is only released on an error,
 * because on success the dialog closes and the task bar and the report take over.
 */
const submitApply = async () => {
    if (confirm.busy || !canStart.value || !detail.value) return
    confirm.busy = true
    confirm.error = ''
    confirm.errorList = []
    const payload = { version: detail.value.version || plan.value?.pack_version }
    if (needConfirmation.value.length) payload.confirm_irreversible = true
    if (partnerModules.value.length) payload.confirm_third_party = true
    try {
        const res = await applyStarterPack(selected.value, payload)
        if (disposed) return
        iAlert({
            message: t('text.starter_pack.started', { name: detail.value.name }),
            type: 'success',
        })
        confirm.open = false
        confirm.busy = false
        startRun(selected.value, detail.value.name, res.application_uuid)
    } catch (error) {
        if (disposed) return
        showApplyError(error)
        confirm.busy = false
    }
}

// ---- Progress and result --------------------------------------------------------------------------------------

/** The apply being watched: pack key and name, ledger row uuid, last row read, poll failures. */
const run = ref(null)
const runRunning = computed(
    () =>
        !!run.value &&
        !run.value.watchFailed &&
        (!run.value.row || isApplicationRunning(run.value.row))
)
let runTimer = null

const stopRunTimer = () => {
    clearTimeout(runTimer)
    runTimer = null
}

/** Read the ledger row of the watched apply once, then plan the next read only while it still runs. */
const pollRun = async () => {
    stopRunTimer()
    const current = run.value
    if (disposed || !current) return
    try {
        const rows = await fetchStarterPackHistory(current.key)
        if (disposed || run.value !== current) return
        const row = rows.find((r) => r.uuid === current.uuid) || (current.uuid ? null : rows[0])
        if (row) current.row = row
        current.failures = 0
    } catch {
        if (disposed || run.value !== current) return
        current.failures += 1
        if (current.failures >= RUN_POLL_MAX_FAILURES) current.watchFailed = true
    }
    if (current.watchFailed) return
    if (!current.row || isApplicationRunning(current.row)) {
        runTimer = setTimeout(pollRun, RUN_POLL_MS)
        return
    }
    // Finished: the list shows the applied badge and the plan shows what is left to do.
    loadPacks()
    if (selected.value) select(selected.value, true)
}

const startRun = (key, name, uuid) => {
    stopRunTimer()
    run.value = reactive({ key, name, uuid, row: null, failures: 0, watchFailed: false })
    runTimer = setTimeout(pollRun, 1500)
}

const stepLabel = (name) =>
    te(`text.starter_pack.step_${name}`) ? t(`text.starter_pack.step_${name}`) : name
const stepStatusLabel = (status) => t(`text.starter_pack.step_status_${status}`)
const stepColor = (status) =>
    ({ succeeded: 'green', skipped: 'blue', failed: 'red', not_run: 'default' })[status] ||
    'default'

/** Step failure in plain words; a message the page does not know gets a generic sentence, never the English text. */
const explainStep = (step) => {
    if (step.status !== 'failed') return ''
    const hit = matchStepMessage(step.message)
    return hit
        ? t(`text.starter_pack.${hit.key}`, hit.params)
        : t('text.starter_pack.step_failed_generic', { step: stepLabel(step.name) })
}
const runSteps = computed(() =>
    (run.value?.row?.steps || [])
        .filter((step) => step.status !== 'pending')
        .map((step) => ({ ...step, explain: explainStep(step) }))
)
/** A configuration step that finished but left some settings out (their rows do not exist on this site yet). */
const stepBlocked = (step) =>
    step.name === 'config' && step.status === 'succeeded' && Number(step.counts?.blocked) > 0
const stepCounts = (step) => {
    if (step.status !== 'succeeded') return ''
    return Object.entries(step.counts || {})
        .filter(([, value]) => Number(value) > 0)
        .map(([key, value]) =>
            te(`text.starter_pack.count_${key}`)
                ? t(`text.starter_pack.count_${key}`, { count: value })
                : ''
        )
        .filter(Boolean)
        .join(', ')
}
/** After a failure: say plainly whether anything was changed. A failed or missing backup step means nothing was. */
const failedDescription = computed(() => {
    const backup = (run.value?.row?.steps || []).find((s) => s.name === 'backup')
    return backup?.status === 'succeeded'
        ? t('text.starter_pack.result_failed_backup')
        : t('text.starter_pack.result_nothing_changed')
})

onMounted(loadPacks)
onBeforeUnmount(() => {
    disposed = true
    stopRunTimer()
})
</script>
