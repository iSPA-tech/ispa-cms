<template>
    <a-modal
        :open="open"
        :title="t('text.security_tokens.stepup.title')"
        :footer="null"
        :mask-closable="false"
        :destroy-on-close="true"
        width="420px"
        data-testid="stepup-dialog"
        @cancel="cancel"
    >
        <div v-if="statusLoading" class="py-4" role="status" data-testid="stepup-loading">
            <a-skeleton active :paragraph="{ rows: 2 }" />
        </div>
        <div v-else class="space-y-3">
            <p class="m-0 text-sm text-ink-muted" data-testid="stepup-intro">{{ intro || t('text.security_tokens.stepup.intro') }}</p>

            <a-alert
                v-if="lockSeconds > 0"
                type="warning"
                show-icon
                :message="t('text.security_tokens.stepup.locked', { seconds: lockSeconds })"
                data-testid="stepup-locked"
            />
            <a-alert
                v-else-if="errorKey"
                type="error"
                show-icon
                :message="t(errorKey)"
                data-testid="stepup-error"
            />

            <!-- Step 1: the password (accounts that have one). -->
            <form
                v-if="stage === 'password'"
                class="space-y-3"
                autocomplete="off"
                data-testid="stepup-password-form"
                @submit.prevent="submitPassword"
            >
                <label class="block text-sm font-medium" for="stepup-password">{{ t('text.security_tokens.stepup.password') }}</label>
                <input
                    id="stepup-password"
                    v-model="password"
                    type="password"
                    autocomplete="off"
                    class="ant-input w-full"
                    :disabled="busy || lockSeconds > 0"
                    data-testid="stepup-password"
                />
                <div class="flex justify-end">
                    <i-button
                        variant="primary"
                        size="md"
                        native-type="submit"
                        :loading="busy"
                        :disabled="busy || lockSeconds > 0 || !password"
                        data-testid="stepup-password-submit"
                    >
                        {{ t('text.security_tokens.stepup.continue') }}
                    </i-button>
                </div>
            </form>

            <!-- Account without a password: the code goes to the e-mail address. -->
            <div v-else-if="stage === 'email-only'" class="space-y-3" data-testid="stepup-email-only">
                <p class="m-0 text-sm">{{ t('text.security_tokens.stepup.no_password') }}</p>
                <div class="flex justify-end">
                    <i-button
                        variant="primary"
                        size="md"
                        :loading="busy"
                        :disabled="busy || lockSeconds > 0"
                        data-testid="stepup-send-first"
                        @click="sendEmailCode"
                    >
                        {{ t('text.security_tokens.stepup.send_code') }}
                    </i-button>
                </div>
            </div>

            <!-- Step 2: the second factor. -->
            <form
                v-else-if="stage === 'code'"
                class="space-y-3"
                autocomplete="off"
                data-testid="stepup-code-form"
                @submit.prevent="submitCode"
            >
                <label class="block text-sm font-medium" for="stepup-code">{{ codeLabel }}</label>
                <input
                    id="stepup-code"
                    v-model="code"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    class="ant-input w-full"
                    :disabled="busy || lockSeconds > 0"
                    data-testid="stepup-code"
                />
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap gap-2">
                        <i-button
                            v-if="method === 'email_code'"
                            size="sm"
                            :disabled="busy || lockSeconds > 0"
                            data-testid="stepup-resend"
                            @click="sendEmailCode"
                        >
                            {{ t('text.security_tokens.stepup.send_code_again') }}
                        </i-button>
                        <i-button
                            v-if="canUseRecovery"
                            size="sm"
                            :disabled="busy"
                            data-testid="stepup-toggle-recovery"
                            @click="toggleRecovery"
                        >
                            {{ useRecovery ? t('text.security_tokens.stepup.use_authenticator') : t('text.security_tokens.stepup.use_recovery') }}
                        </i-button>
                    </div>
                    <i-button
                        variant="primary"
                        size="md"
                        native-type="submit"
                        :loading="busy"
                        :disabled="busy || lockSeconds > 0 || !code"
                        data-testid="stepup-code-submit"
                    >
                        {{ t('text.security_tokens.stepup.confirm') }}
                    </i-button>
                </div>
            </form>

            <div v-else-if="stage === 'unavailable'" data-testid="stepup-unavailable">
                <p class="m-0 text-sm">{{ t('text.security_tokens.stepup.not_possible') }}</p>
            </div>
        </div>
    </a-modal>
</template>

<script setup>
/**
 * The one confirmation form every token revoke, workflow credential change and sensitive workflow publish uses: the account password, then the second factor the account
 * has (authenticator code, e-mail code with a "send code" button, or a recovery code), or only an e-mail code for an
 * account without a password. Wrong password and wrong code share one generic text; the fields are cleared after every
 * answer; a lock shows a countdown from `Retry-After`. The grant is handed to the parent through `confirmed` and never
 * kept here; the typed password, the code and the confirmation id are wiped as soon as a request settles and on close.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
    DEFAULT_PURPOSE,
    errorCode,
    fetchStepUpStatus,
    retryAfterSeconds,
    startStepUp,
    stepUpEmailCode,
    stepUpSecondFactor,
} from '@/libs/securityTokens'

const props = defineProps({
    /** Whether the dialog is open. */
    open: { type: Boolean, default: false },
    /** What the grant is for (`revoke_tokens`, `workflow_credential`, `workflow_publish`); a grant only works for its purpose. */
    purpose: { type: String, default: DEFAULT_PURPOSE },
    /** Sentence above the form that says why the confirmation is asked; empty keeps the one about ending sessions. */
    intro: { type: String, default: '' },
})
const emit = defineEmits(['confirmed', 'cancel'])

const { t } = useI18n()

const statusLoading = ref(false)
const busy = ref(false)
/** password | email-only | code | unavailable */
const stage = ref('password')
const password = ref('')
const code = ref('')
const challengeId = ref('')
const method = ref('')
const canUseRecovery = ref(false)
const useRecovery = ref(false)
const errorKey = ref('')
const lockSeconds = ref(0)
let timer = null
let disposed = false

const codeLabel = computed(() => {
    if (useRecovery.value) return t('text.security_tokens.stepup.recovery_code')
    return method.value === 'email_code'
        ? t('text.security_tokens.stepup.email_code')
        : t('text.security_tokens.stepup.authenticator_code')
})

/** Stop the countdown. */
const stopTimer = () => {
    if (timer) clearInterval(timer)
    timer = null
}

/** Start (or restart) the lock countdown. */
const startLock = (seconds) => {
    stopTimer()
    lockSeconds.value = Math.max(0, Math.ceil(seconds))
    if (lockSeconds.value <= 0) return
    timer = setInterval(() => {
        lockSeconds.value -= 1
        if (lockSeconds.value <= 0) {
            lockSeconds.value = 0
            stopTimer()
        }
    }, 1000)
}

/** Drop everything typed. */
const wipe = () => {
    password.value = ''
    code.value = ''
}

/** Back to the start state. */
const reset = () => {
    stopTimer()
    wipe()
    challengeId.value = ''
    method.value = ''
    canUseRecovery.value = false
    useRecovery.value = false
    errorKey.value = ''
    lockSeconds.value = 0
    busy.value = false
    stage.value = 'password'
}

/** Translate a failed request into the generic text or the lock countdown. */
const showError = (err) => {
    const codeName = errorCode(err)
    errorKey.value = ''
    if (codeName === 'step_up_locked' || codeName === 'step_up_email_limit') {
        startLock(retryAfterSeconds(err))
        return
    }
    if (codeName === 'step_up_unavailable') errorKey.value = 'text.security_tokens.stepup.unavailable'
    else if (codeName === 'step_up_not_possible') errorKey.value = 'text.security_tokens.stepup.not_possible'
    else errorKey.value = 'text.security_tokens.stepup.failed'
}

/** Apply an answer: the grant (done) or the second-factor step. */
const handleAnswer = (body) => {
    const data = body?.data || {}
    if (data.status === 'confirmed' && data.grant) {
        const payload = { grant: data.grant, expires_in: data.expires_in }
        wipe()
        emit('confirmed', payload)
        return
    }
    challengeId.value = data.challenge_id || ''
    method.value = data.method || ''
    canUseRecovery.value = Boolean(data.can_use_recovery_code)
    useRecovery.value = false
    wipe()
    stage.value = challengeId.value ? 'code' : 'unavailable'
}

/** Read what the account needs and pick the first step. */
const loadStatus = async () => {
    statusLoading.value = true
    try {
        const body = (await fetchStepUpStatus())?.data || {}
        if (disposed || !props.open) return
        if (body.possible === false) stage.value = 'unavailable'
        else stage.value = body.password_required ? 'password' : 'email-only'
        if (body.locked) startLock(Number(body.retry_after || 0))
    } catch {
        if (!disposed) errorKey.value = 'text.security_tokens.stepup.unavailable'
    } finally {
        if (!disposed) statusLoading.value = false
    }
}

/** Run one request with the busy guard; the typed values are wiped when it settles. */
const guarded = async (fn) => {
    if (busy.value) return
    busy.value = true
    errorKey.value = ''
    try {
        handleAnswer(await fn())
    } catch (err) {
        if (!disposed) {
            wipe()
            showError(err)
        }
    } finally {
        if (!disposed) busy.value = false
    }
}

const submitPassword = () => password.value && guarded(() => startStepUp(password.value, props.purpose))
const submitCode = () =>
    code.value && guarded(() => stepUpSecondFactor(challengeId.value, code.value, useRecovery.value ? 'recovery_code' : null))
const sendEmailCode = () => guarded(() => stepUpEmailCode(stage.value === 'code' ? challengeId.value : null, props.purpose))

/** Switch between the authenticator code and a recovery code. */
const toggleRecovery = () => {
    useRecovery.value = !useRecovery.value
    code.value = ''
}

const cancel = () => {
    reset()
    emit('cancel')
}

watch(
    () => props.open,
    (value) => {
        reset()
        if (value) loadStatus()
    },
    { immediate: true }
)

onBeforeUnmount(() => {
    disposed = true
    stopTimer()
    wipe()
})
</script>
