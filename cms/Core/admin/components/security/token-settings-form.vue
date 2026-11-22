<template>
    <div class="space-y-4" :data-testid="`token-settings-${section}`">
        <div v-if="loading" role="status" data-testid="settings-loading">
            <a-skeleton active :paragraph="{ rows: 5 }" />
        </div>
        <a-alert
            v-else-if="loadError"
            type="error"
            show-icon
            :message="t('text.security_tokens.settings.load_error')"
            :description="t('text.security_tokens.settings.load_error_hint')"
            data-testid="settings-error"
        >
            <template #action>
                <i-button size="sm" data-testid="settings-retry" @click="load">{{ t('text.security_tokens.list.retry') }}</i-button>
            </template>
        </a-alert>
        <form v-else-if="draft" class="space-y-4" autocomplete="off" @submit.prevent="save">
            <a-alert v-if="notice" type="success" show-icon closable :message="notice" data-testid="settings-saved" @close="notice = ''" />
            <a-alert v-if="saveError" type="error" show-icon :message="t(saveError)" data-testid="settings-save-error" />

            <template v-if="section === 'lifetime'">
                <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.settings.lifetime_intro') }}</p>

                <div v-for="audience in audiences" :key="audience" class="space-y-2 border-t border-gray-200 pt-3 first:border-t-0 first:pt-0" :data-testid="`lifetime-row-${audience}`">
                    <div class="font-medium text-sm">{{ t(`text.security_tokens.audience.${audience}`) }}</div>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="block text-sm">
                            <span class="block mb-1">{{ t('text.security_tokens.settings.lifetime') }}</span>
                            <select
                                v-model="draft.lifetime[audience]"
                                class="ant-input w-auto"
                                :data-testid="`lifetime-${audience}`"
                                @change="onLifetimeChange(audience)"
                            >
                                <option v-for="opt in LIFETIME_OPTIONS" :key="opt" :value="opt">{{ t(`text.security_tokens.lifetime.${opt}`) }}</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="block mb-1">{{ t('text.security_tokens.settings.idle') }}</span>
                            <select v-model.number="draft.idle_days[audience]" class="ant-input w-auto" :data-testid="`idle-${audience}`">
                                <option v-for="d in options.idle_days" :key="d" :value="d">{{ idleLabel(d) }}</option>
                            </select>
                        </label>
                        <i-button
                            size="sm"
                            :disabled="applyDisabled(audience)"
                            :data-testid="`apply-${audience}`"
                            @click="openApply(audience)"
                        >
                            {{ t('text.security_tokens.apply.button') }}
                        </i-button>
                    </div>
                    <a-alert
                        v-if="draft.lifetime[audience] === 'never'"
                        type="warning"
                        show-icon
                        :message="t('text.security_tokens.settings.never_warning')"
                        :data-testid="`never-warning-${audience}`"
                    />
                    <p v-else-if="recommended[audience]" class="m-0 text-xs text-ink-muted" :data-testid="`recommended-${audience}`">
                        {{ t('text.security_tokens.settings.recommended', { value: t(`text.security_tokens.lifetime.${recommended[audience]}`) }) }}
                    </p>
                    <p v-if="draft.lifetime[audience] === 'never'" class="m-0 text-xs text-ink-muted" :data-testid="`apply-never-hint-${audience}`">
                        {{ t('text.security_tokens.apply.never_hint') }}
                    </p>
                    <a-alert
                        v-if="applyResult[audience]"
                        :type="applyResult[audience].already_past > 0 && !applyResult[audience].revoked ? 'warning' : 'success'"
                        show-icon
                        closable
                        :message="applyResultText(applyResult[audience])"
                        :data-testid="`apply-result-${audience}`"
                        @close="applyResult[audience] = null"
                    />
                </div>

                <div class="space-y-2 border-t border-gray-200 pt-3">
                    <label class="block text-sm font-medium" for="token-cap">{{ t('text.security_tokens.settings.cap') }}</label>
                    <input
                        id="token-cap"
                        v-model.number="draft.max_live_tokens"
                        type="number"
                        class="ant-input w-28"
                        :min="options.max_live_tokens.min"
                        :max="options.max_live_tokens.max"
                        data-testid="token-cap"
                    />
                    <p class="m-0 text-xs text-ink-muted">{{ t('text.security_tokens.settings.cap_hint') }}</p>
                </div>

                <fieldset class="space-y-2 border-t border-gray-200 pt-3">
                    <legend class="text-sm font-medium">{{ t('text.security_tokens.settings.mode') }}</legend>
                    <label v-for="m in options.modes" :key="m" class="flex items-start gap-2 text-sm">
                        <input v-model="draft.mode" type="radio" name="token-mode" :value="m" class="mt-1" :data-testid="`mode-${m}`" />
                        <span>
                            <span class="font-medium">{{ t(`text.security_tokens.mode.${m}`) }}</span>
                            <span class="block text-xs text-ink-muted">{{ t(`text.security_tokens.mode.${m}_hint`) }}</span>
                        </span>
                    </label>
                </fieldset>

                <div class="space-y-2 border-t border-gray-200 pt-3" data-testid="notify-section">
                    <div class="text-sm font-medium">{{ t('text.security_tokens.settings.notify_title') }}</div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="draft.notify_new_device" type="checkbox" class="mt-1" data-testid="notify-new-device" />
                        <span>
                            <span class="font-medium">{{ t('text.security_tokens.settings.notify_label') }}</span>
                            <span class="block text-xs text-ink-muted">{{ t('text.security_tokens.settings.notify_hint') }}</span>
                        </span>
                    </label>
                </div>
            </template>

            <template v-else>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.settings.cleanup_intro') }}</p>
                <label class="block text-sm">
                    <span class="block mb-1 font-medium">{{ t('text.security_tokens.settings.unused') }}</span>
                    <select v-model.number="draft.unused_days" class="ant-input w-auto" data-testid="unused-days">
                        <option v-for="d in options.unused_days" :key="d" :value="d">{{ unusedLabel(d) }}</option>
                    </select>
                </label>
            </template>

            <div class="flex justify-end">
                <i-button
                    variant="primary"
                    size="md"
                    native-type="submit"
                    :loading="saving"
                    :disabled="saving || !dirty"
                    data-testid="settings-save"
                >
                    {{ t('text.security_tokens.settings.save') }}
                </i-button>
            </div>
        </form>

        <!-- "Never expires" asks for an explicit confirmation before it is kept. -->
        <a-modal
            :open="Boolean(pendingNever)"
            :title="t('text.security_tokens.settings.never_title')"
            :footer="null"
            :mask-closable="false"
            data-testid="never-dialog"
            @cancel="cancelNever"
        >
            <div class="space-y-3">
                <a-alert type="warning" show-icon :message="t('text.security_tokens.settings.never_warning')" />
                <p class="m-0 text-sm">{{ t('text.security_tokens.settings.never_confirm_text') }}</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <i-button size="md" data-testid="never-cancel" @click="cancelNever">{{ t('text.security_tokens.revoke_all.cancel') }}</i-button>
                    <i-button variant="warning" size="md" data-testid="never-confirm" @click="confirmNever">
                        {{ t('text.security_tokens.settings.never_confirm') }}
                    </i-button>
                </div>
            </div>
        </a-modal>

        <!-- Apply to existing tokens: counts first, then the confirmation. -->
        <a-modal
            :open="Boolean(applyFor)"
            :title="t('text.security_tokens.apply.title', { api: applyFor ? t(`text.security_tokens.audience.${applyFor}`) : '' })"
            :footer="null"
            :mask-closable="false"
            data-testid="apply-dialog"
            @cancel="closeApply"
        >
            <div class="space-y-3">
                <div v-if="previewLoading" role="status"><a-skeleton active :paragraph="{ rows: 2 }" /></div>
                <a-alert v-else-if="previewError" type="error" show-icon :message="t('text.security_tokens.apply.preview_error')" data-testid="apply-preview-error" />
                <template v-else-if="applyCounts">
                    <ul class="m-0 list-disc pl-5 text-sm" data-testid="apply-counts">
                        <li>{{ t('text.security_tokens.apply.count_live', { count: applyCounts.live }) }}</li>
                        <li>{{ t('text.security_tokens.apply.count_never', { count: applyCounts.never_expiring }) }}</li>
                        <li>{{ t('text.security_tokens.apply.count_past', { count: applyCounts.would_expire_now }) }}</li>
                    </ul>
                    <p class="m-0 text-sm" data-testid="apply-stay-alive">{{ t('text.security_tokens.apply.stay_alive') }}</p>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="revokePast" type="checkbox" class="mt-1" data-testid="apply-revoke-past" />
                        <span>{{ t('text.security_tokens.apply.revoke_past') }}</span>
                    </label>
                </template>
                <a-alert v-if="applyError" type="error" show-icon :message="t(applyError)" data-testid="apply-error" />
                <div class="flex flex-wrap justify-end gap-2">
                    <i-button size="md" :disabled="applying" data-testid="apply-cancel" @click="closeApply">{{ t('text.security_tokens.revoke_all.cancel') }}</i-button>
                    <i-button
                        variant="primary"
                        size="md"
                        :loading="applying"
                        :disabled="applying || previewLoading || !applyCounts"
                        data-testid="apply-confirm"
                        @click="confirmApply"
                    >
                        {{ t('text.security_tokens.apply.confirm') }}
                    </i-button>
                </div>
            </div>
        </a-modal>

        <step-up-dialog :open="stepUp.open.value" @confirmed="stepUp.confirmed" @cancel="stepUp.cancelled" />
    </div>
</template>

<script setup>
/**
 * Token settings of the Webmaster, in two sections: `lifetime` (per API: lifetime and idle timeout; the cap per account;
 * the enforcement mode with a plain explanation of off, log and enforce; the new-device sign-in notice switch (off by default); "apply to existing tokens") and `cleanup` (the
 * days after which an unused token is removed). "Never expires" asks for a confirmation when it is chosen and a warning
 * stays visible wherever it is selected. A change applies to new tokens only; the apply action shows the counts first,
 * says that tokens older than the new limit stay alive unless "also end them now" is ticked, is disabled for "never"
 * (nothing to apply) and needs the step-up confirmation.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
    applyToExisting,
    errorCode,
    fetchTokenSettings,
    LIFETIME_OPTIONS,
    previewTokenSettings,
    saveTokenSettings,
    useStepUpRunner,
} from '@/libs/securityTokens'
import StepUpDialog from './step-up-dialog.vue'

const props = defineProps({
    /** `lifetime` or `cleanup`. */
    section: { type: String, default: 'lifetime', validator: (v) => ['lifetime', 'cleanup'].includes(v) },
})
const emit = defineEmits(['saved'])

const { t } = useI18n()
const stepUp = useStepUpRunner()

const loading = ref(false)
const loadError = ref(false)
const saving = ref(false)
const notice = ref('')
const saveError = ref('')
const saved = ref(null)
const draft = ref(null)
const audiences = ref([])
const recommended = ref({})
const options = ref({ idle_days: [], unused_days: [], modes: [], max_live_tokens: { min: 1, max: 100 } })
/** Audiences whose "never" was confirmed in this session. */
const confirmedNever = reactive(new Set())
const pendingNever = ref('')
let previousValue = ''
const applyFor = ref('')
const previewLoading = ref(false)
const previewError = ref(false)
const applyCounts = ref(null)
const revokePast = ref(false)
const applying = ref(false)
const applyError = ref('')
const applyResult = reactive({})
let disposed = false

const copy = (settings) => JSON.parse(JSON.stringify(settings))
/** Copy of the settings with the new-device notice switch always a boolean (off when the API omits it). */
const withNotify = (settings) => ({ ...copy(settings), notify_new_device: Boolean(settings?.notify_new_device) })

/** Fields this section edits. */
const fields = computed(() => (props.section === 'lifetime' ? ['lifetime', 'idle_days', 'max_live_tokens', 'mode', 'notify_new_device'] : ['unused_days']))

/** Changed fields only (per-audience maps carry only the audiences that changed). */
const changes = computed(() => {
    const out = {}
    if (!draft.value || !saved.value) return out
    for (const field of fields.value) {
        const now = draft.value[field]
        const before = saved.value[field]
        if (typeof now === 'object' && now !== null) {
            const diff = {}
            for (const key of Object.keys(now)) if (now[key] !== before[key]) diff[key] = now[key]
            if (Object.keys(diff).length) out[field] = diff
        } else if (now !== before) {
            out[field] = now
        }
    }
    return out
})
const dirty = computed(() => Object.keys(changes.value).length > 0)

const idleLabel = (days) => (days === 0 ? t('text.security_tokens.settings.idle_off') : t('text.security_tokens.settings.idle_days', { days }))
const unusedLabel = (days) => (days === 0 ? t('text.security_tokens.settings.unused_off') : t('text.security_tokens.settings.unused_days', { days }))

/** The apply button is off for "never" (nothing to apply) and while the form holds unsaved changes. */
const applyDisabled = (audience) => saved.value?.lifetime?.[audience] === 'never' || draft.value?.lifetime?.[audience] === 'never' || dirty.value

/** Load the settings. */
const load = async () => {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        const body = (await fetchTokenSettings())?.data || {}
        if (disposed) return
        saved.value = withNotify(body.settings)
        draft.value = withNotify(body.settings)
        audiences.value = body.audiences || []
        recommended.value = body.recommended || {}
        options.value = body.options
    } catch {
        if (!disposed) loadError.value = true
    } finally {
        if (!disposed) loading.value = false
    }
}

/** Choosing "never" opens the confirmation (unless it was saved that way); the other choices apply at once. */
const onLifetimeChange = (audience) => {
    if (draft.value.lifetime[audience] === 'never' && saved.value.lifetime[audience] !== 'never' && !confirmedNever.has(audience)) {
        previousValue = saved.value.lifetime[audience]
        pendingNever.value = audience
    }
}
const confirmNever = () => {
    confirmedNever.add(pendingNever.value)
    pendingNever.value = ''
}
const cancelNever = () => {
    if (pendingNever.value) draft.value.lifetime[pendingNever.value] = previousValue
    pendingNever.value = ''
}

/** Save the changed fields. "Never" travels with the confirmation flag. */
const save = async () => {
    if (saving.value || !dirty.value) return
    saving.value = true
    saveError.value = ''
    notice.value = ''
    const body = { ...changes.value }
    if (Object.values(body.lifetime || {}).includes('never')) body.confirm_never_expire = true
    try {
        const res = (await saveTokenSettings(body))?.data || {}
        if (disposed) return
        saved.value = withNotify(res.settings)
        draft.value = withNotify(res.settings)
        confirmedNever.clear()
        notice.value = t('text.security_tokens.settings.saved')
        emit('saved')
    } catch (err) {
        if (!disposed) saveError.value = errorCode(err) === 'NEVER_EXPIRE_CONFIRMATION_REQUIRED' ? 'text.security_tokens.settings.never_required' : 'text.security_tokens.settings.save_error'
    } finally {
        if (!disposed) saving.value = false
    }
}

/** Open the apply dialog and load the counts of the saved settings. */
const openApply = async (audience) => {
    applyFor.value = audience
    applyCounts.value = null
    revokePast.value = false
    applyError.value = ''
    previewError.value = false
    previewLoading.value = true
    try {
        const body = (await previewTokenSettings({}))?.data || {}
        if (!disposed) applyCounts.value = body.audiences?.[audience] || { live: 0, never_expiring: 0, would_expire_now: 0 }
    } catch {
        if (!disposed) previewError.value = true
    } finally {
        if (!disposed) previewLoading.value = false
    }
}
const closeApply = () => {
    if (applying.value) return
    applyFor.value = ''
    stepUp.cancelled()
}

/** Apply after the step-up confirmation (the grant is used up). */
const confirmApply = () => {
    if (applying.value || stepUp.open.value || !applyFor.value) return undefined
    const audience = applyFor.value
    const action = async (grant) => {
        applying.value = true
        applyError.value = ''
        try {
            const res = (await applyToExisting({ audience, revoke_past: revokePast.value }, grant))?.data || {}
            if (disposed) return
            applyResult[audience] = res
            applyFor.value = ''
        } catch (err) {
            const code = errorCode(err)
            if (code === 'step_up_required') throw err
            if (!disposed) applyError.value = code === 'NOTHING_TO_APPLY' ? 'text.security_tokens.apply.never_hint' : 'text.security_tokens.apply.error'
        } finally {
            if (!disposed) applying.value = false
        }
    }
    return stepUp.run(action, { consume: true })
}

/** Plain result sentence; tokens past the limit are said to stay alive unless they were ended. */
const applyResultText = (res) => {
    const base = t('text.security_tokens.apply.result', { set: res.set ?? 0 })
    if ((res.already_past ?? 0) > 0 && !(res.revoked > 0)) return `${base} ${t('text.security_tokens.apply.result_past_alive', { count: res.already_past })}`
    if ((res.revoked ?? 0) > 0) return `${base} ${t('text.security_tokens.apply.result_revoked', { count: res.revoked })}`
    return base
}

onMounted(load)
onBeforeUnmount(() => {
    disposed = true
    stepUp.cancelled()
})
</script>
