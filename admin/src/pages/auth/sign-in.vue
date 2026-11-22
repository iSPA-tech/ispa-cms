<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-md w-full space-y-8 mx-auto mt-8 px-4 sm:px-0">
        <div class="flex flex-col">
            <div class="pb-6">
                <img :alt="cmsTitle" :src="logoAuthUrl" class="mx-auto h-12 w-auto" />
            </div>
            <h1 class="mt-6 text-center text-2xl sm:text-3xl font-extrabold text-ink">
                {{ projectTitle }}
            </h1>
            <h2 class="mt-4 text-center text-xl sm:text-2xl font-extrabold text-ink">
                {{ $t('text.auth.sign-in') }}
            </h2>
        </div>

        <a-form
            v-if="!challengeActive"
            ref="formRef"
            :model="form"
            layout="vertical"
            @finish="submitForm"
        >
            <template v-if="remainingResponse">
                <div class="rounded-md bg-warning-soft p-4 mb-4">
                    <div class="flex">
                        <div class="shrink-0">
                            <svg
                                aria-hidden="true"
                                class="h-5 w-5 text-warning"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                                xmlns="http://www.w3.org/2000/svg"
                            >
                                <path
                                    clip-rule="evenodd"
                                    d="M8.485 3.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 3.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                    fill-rule="evenodd"
                                />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-warning-ink">
                                {{ remainingResponse }}
                            </h3>
                        </div>
                    </div>
                </div>
            </template>

            <a-form-item
                :label="$t('validation.attributes.username')"
                :rules="[
                    {
                        required: true,
                        trigger: 'blur',
                        message: $t('validation.required', {
                            a: $t('validation.attributes.username'),
                        }),
                    },
                ]"
                name="username"
            >
                <a-input
                    v-model:value="form.username"
                    :disabled="!allowed || loading"
                    :placeholder="$t('validation.attributes.username')"
                    autocomplete="username"
                />
            </a-form-item>
            <a-form-item
                :label="$t('validation.attributes.password')"
                :rules="[
                    {
                        required: true,
                        trigger: 'blur',
                        message: $t('validation.required', {
                            a: $t('validation.attributes.password'),
                        }),
                    },
                ]"
                name="password"
            >
                <a-input
                    v-model:value="form.password"
                    :disabled="!allowed || loading"
                    :placeholder="$t('validation.attributes.password')"
                    autocomplete="current-password"
                    show-password
                    type="password"
                />
            </a-form-item>
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input
                        id="remember-me"
                        v-model="form.remember"
                        class="h-4 w-4 accent-primary border-border-strong rounded-sm"
                        name="remember-me"
                        type="checkbox"
                    />
                    <label class="ml-2 block text-sm text-ink" for="remember-me">
                        {{ $t('text.auth.remember') }}
                    </label>
                </div>

                <div class="text-sm">
                    <router-link
                        :to="{ name: 'auth-forgot' }"
                        class="font-medium text-primary-ink hover:text-primary-hover"
                    >
                        {{ $t('text.auth.forget') }}
                    </router-link>
                </div>
            </div>
            <div class="pt-4">
                <button
                    :disabled="!allowed || loading"
                    class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md bg-primary text-on-primary hover:bg-primary-hover disabled:opacity-75 disabled:cursor-not-allowed"
                    type="submit"
                >
                    <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                        <svg
                            aria-hidden="true"
                            class="h-5 w-5 text-on-primary/70 group-hover:text-on-primary"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                clip-rule="evenodd"
                                d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z"
                                fill-rule="evenodd"
                            />
                        </svg>
                    </span>
                    {{ loading ? $t('text.auth.signing-in') : $t('nav.b.sign-in') }}
                </button>
            </div>
            <div v-if="passkeySupported" class="mt-6 border-t border-border pt-5">
                <button
                    :disabled="!allowed || loading"
                    class="group flex w-full items-center gap-3 rounded-lg border border-border bg-surface p-3 text-left shadow-xs transition hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-75"
                    type="button"
                    @click="submitPasskey"
                >
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-ink">
                        <FingerPrintIcon aria-hidden="true" class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-ink">
                            {{
                                loading
                                    ? t('text.passkey.signing-in')
                                    : t('text.passkey.sign-in')
                            }}
                        </span>
                        <span class="mt-0.5 block text-xs leading-5 text-ink-muted">
                            {{ t('text.passkey.sign-in-desc') }}
                        </span>
                    </span>
                </button>
            </div>
        </a-form>

        <div v-else class="space-y-5">
            <section class="rounded-lg border border-border bg-surface p-5 shadow-xs">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-primary-ink">
                            {{ $t('text.mfa.verification-kicker') }}
                        </p>
                        <h3 class="mt-2 text-lg font-semibold text-ink">
                            {{ challengeTitle }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-ink-muted">
                            {{ challengeDescription }}
                        </p>
                    </div>
                    <span
                        class="inline-flex rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-primary-ink"
                    >
                        {{ challengeStepCount }}
                    </span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <span
                        v-for="step in challengeSteps"
                        :key="step.key"
                        :class="[
                            'inline-flex rounded-full px-3 py-1 text-xs font-medium',
                            step.completed
                                ? 'bg-success-soft text-success-ink'
                                : step.current
                                ? 'bg-primary-soft text-primary-ink'
                                : 'bg-surface-sunken text-ink-muted',
                        ]"
                    >
                        {{ step.label }}
                    </span>
                </div>
            </section>

            <a-form ref="challengeFormRef" :model="challengeForm" layout="vertical" @finish="submitChallenge">
                <a-form-item
                    :label="challengeInputLabel"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: t('validation.required', { a: challengeInputLabel }),
                        },
                    ]"
                    name="code"
                >
                    <a-input
                        v-model:value="challengeForm.code"
                        :maxlength="recoveryMode ? 32 : 16"
                        :placeholder="challengeInputPlaceholder"
                        autocomplete="one-time-code"
                    />
                </a-form-item>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button
                        class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-ink hover:bg-surface-hover"
                        type="button"
                        @click="resetChallenge"
                    >
                        {{ $t('text.mfa.back-to-sign-in') }}
                    </button>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="challenge.current_method === METHOD_EMAIL_CODE && challenge.can_resend"
                            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-primary-ink hover:bg-surface-hover"
                            type="button"
                            @click="resendChallenge"
                        >
                            {{ $t('text.mfa.resend-code') }}
                        </button>
                        <button
                            v-if="challenge.current_method === METHOD_TOTP && challenge.can_use_recovery_code"
                            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-ink hover:bg-surface-hover"
                            type="button"
                            @click="toggleRecoveryMode"
                        >
                            {{
                                recoveryMode
                                    ? $t('text.mfa.use-authenticator-code')
                                    : $t('text.mfa.use-recovery-code')
                            }}
                        </button>
                        <button
                            :disabled="challengeLoading"
                            class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-70"
                            type="submit"
                        >
                            {{
                                challengeLoading
                                    ? $t('text.mfa.verifying')
                                    : $t('text.mfa.submit-verification')
                            }}
                        </button>
                    </div>
                </div>

                <p
                    v-if="recoveryMode && challenge.recovery_codes_remaining"
                    class="mt-3 text-xs text-ink-muted"
                >
                    {{
                        $t('text.mfa.recovery-codes-remaining', {
                            count: challenge.recovery_codes_remaining,
                        })
                    }}
                </p>
            </a-form>
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, onBeforeMount, onMounted, ref, watch } from 'vue'
import ajax from '@/ajax'
import { useRouter } from 'vue-router'
import { iAlert } from '@/mixins'
import {
    CACHE_KEY_ACCOUNT_DATA,
    cookie,
    logoAuthUrl,
    cmsTitle,
    projectTitle,
    COOKIE_KEY_LOCALE,
    COOKIE_KEY_TOKEN,
} from '@/config'
import { useI18n } from 'vue-i18n'
import { isPasskeySupported, loginWithPasskey } from '@/libs/passkey'
import { FingerPrintIcon } from '@heroicons/vue/24/outline'

defineComponent({
    name: 'AuthSignIn',
})

const METHOD_EMAIL_CODE = 'email_code'
const METHOD_TOTP = 'totp'

const allowed = ref(true)
const remainingResponse = ref(null)
const token = cookie.get(COOKIE_KEY_TOKEN)
const { t } = useI18n()

const emptyForm = () => ({
    username: '',
    password: '',
    remember: false,
})

const emptyChallenge = () => ({
    challenge_id: null,
    email: '',
    required_methods: [],
    completed_methods: [],
    current_method: null,
    can_resend: false,
    can_use_recovery_code: false,
    recovery_codes_remaining: 0,
})

const form = ref(emptyForm())
const formRef = ref(null)
const loading = ref(false)
const router = useRouter()

const challenge = ref(emptyChallenge())
const challengeForm = ref({ code: '' })
const challengeFormRef = ref(null)
const challengeLoading = ref(false)
const recoveryMode = ref(false)
const passkeySupported = ref(false)

const challengeActive = computed(() => Boolean(challenge.value.challenge_id))

const challengeTitle = computed(() => {
    if (recoveryMode.value) {
        return t('text.mfa.recovery-code-title')
    }

    if (challenge.value.current_method === METHOD_EMAIL_CODE) {
        return t('text.mfa.email-code-title')
    }

    if (challenge.value.current_method === METHOD_TOTP) {
        return t('text.mfa.authenticator-code-title')
    }

    return t('text.mfa.complete-verification-title')
})

const challengeDescription = computed(() => {
    if (recoveryMode.value) {
        return t('text.mfa.recovery-code-desc')
    }

    if (challenge.value.current_method === METHOD_EMAIL_CODE) {
        return challenge.value.email
            ? t('text.mfa.email-code-desc-to', { email: challenge.value.email })
            : t('text.mfa.email-code-desc')
    }

    if (challenge.value.current_method === METHOD_TOTP) {
        return t('text.mfa.authenticator-code-desc')
    }

    return t('text.mfa.complete-verification-desc')
})

const challengeInputLabel = computed(() => {
    if (recoveryMode.value) {
        return t('text.mfa.recovery-code')
    }

    if (challenge.value.current_method === METHOD_TOTP) {
        return t('text.mfa.authenticator-code')
    }

    return t('validation.attributes.verify-code')
})

const challengeInputPlaceholder = computed(() => {
    if (recoveryMode.value) {
        return t('text.mfa.recovery-code-placeholder')
    }

    if (challenge.value.current_method === METHOD_TOTP) {
        return t('text.mfa.authenticator-code-placeholder')
    }

    return t('validation.attributes.verify-code')
})

const challengeSteps = computed(() => {
    return (challenge.value.required_methods || []).map((method) => ({
        key: method,
        label: getMethodLabel(method),
        completed: (challenge.value.completed_methods || []).includes(method),
        current: challenge.value.current_method === method,
    }))
})

const challengeStepCount = computed(() => {
    const total = challenge.value.required_methods?.length || 0
    const done = challenge.value.completed_methods?.length || 0

    if (!total) {
        return t('text.mfa.step-empty')
    }

    return t('text.mfa.step-count', {
        current: Math.min(done + 1, total),
        total,
    })
})

function getMethodLabel(method) {
    if (method === METHOD_EMAIL_CODE) {
        return t('text.mfa.email-code')
    }

    if (method === METHOD_TOTP) {
        return t('text.mfa.authenticator-app')
    }

    return t('text.mfa.verification')
}

function normalizeChallengePayload(payload = {}) {
    if (payload?.type === 'two_fa') {
        return {
            challenge_id: payload.challenge_id || null,
            email: payload.email || '',
            required_methods: [METHOD_EMAIL_CODE],
            completed_methods: [],
            current_method: METHOD_EMAIL_CODE,
            can_resend: true,
            can_use_recovery_code: false,
            recovery_codes_remaining: 0,
        }
    }

    return {
        ...emptyChallenge(),
        ...payload,
    }
}

function applyChallengePayload(payload) {
    challenge.value = normalizeChallengePayload(payload)
    challengeForm.value.code = ''
    recoveryMode.value = false
}

function handleAuthResponse(response) {
    if (!response?.data) {
        return
    }

    const payload = response.data.data || {}

    if (payload.type === 'auth') {
        setUserCookie(response.data)
        return
    }

    if (payload.type === 'mfa_challenge' || payload.type === 'two_fa') {
        applyChallengePayload(payload)
    }
}

function submitForm() {
    if (!form.value.username || !form.value.password) {
        iAlert([t('mgs.error.fields-empty')], true)
        return
    }

    loading.value = true
    ajax.post('auth/sign-in', form.value).then((res) => {
        if (res.data?.status === 'success') {
            handleAuthResponse(res)
        } else {
            handleFailedLoginResponse(res)
        }
    }).finally(() => {
        loading.value = false
    })
}

async function submitPasskey() {
    if (!allowed.value) {
        return
    }

    loading.value = true

    try {
        const response = await loginWithPasskey()

        if (response.data?.status === 'success') {
            handleAuthResponse(response)
        } else {
            handleFailedLoginResponse(response)
        }
    } catch (error) {
        if (error?.name === 'AbortError' || error?.name === 'NotAllowedError') {
            iAlert({
                type: 'warning',
                title: t('text.passkey.cancelled-title'),
                message: t('text.passkey.cancelled'),
            })
        }
    } finally {
        loading.value = false
    }
}

function submitChallenge() {
    if (!challenge.value.challenge_id || !challengeForm.value.code) {
        iAlert([{ message: t('validation.required', { a: challengeInputLabel.value }) }], true)
        return
    }

    challengeLoading.value = true

    const endpoint = recoveryMode.value ? 'auth/challenge/recovery-code' : 'auth/challenge'

    ajax.post(endpoint, {
        challenge_id: challenge.value.challenge_id,
        code: challengeForm.value.code,
    }).then((res) => {
        if (res.data?.status === 'success') {
            handleAuthResponse(res)
        } else if (res.data?.message) {
            iAlert(res.data, true)
        }
    }).finally(() => {
        challengeLoading.value = false
    })
}

function resendChallenge() {
    if (!challenge.value.challenge_id) {
        return
    }

    challengeLoading.value = true
    ajax.post('auth/challenge/resend', {
        challenge_id: challenge.value.challenge_id,
    }).then((res) => {
        if (res.data?.status === 'success') {
            applyChallengePayload(res.data.data)
            iAlert({
                type: 'success',
                message: t('text.mfa.resend-success'),
            })
        }
    }).finally(() => {
        challengeLoading.value = false
    })
}

function toggleRecoveryMode() {
    recoveryMode.value = !recoveryMode.value
    challengeForm.value.code = ''
}

function resetChallenge() {
    challenge.value = emptyChallenge()
    challengeForm.value.code = ''
    recoveryMode.value = false
}

function handleFailedLoginResponse(res) {
    if (res.headers) {
        if (res.headers['iSpa-auth-failed-remaining-count']) {
            remainingResponse.value = t('mgs.auth.failed-remaining').replace(
                ':n',
                res.headers['iSpa-auth-failed-remaining-count']
            )
            setTimeout(() => {
                remainingResponse.value = null
            }, 5000)
        }

        if (res.headers['iSpa-auth-failed-block'] && res.headers['iSpa-auth-failed-block'] > 0) {
            allowed.value = false

            cookie.set('limited-request', true)
            cookie.set('limited-time', res.headers['iSpa-auth-failed-remaining-time'])
            cookie.set('limited-count', res.headers['iSpa-auth-failed-remaining-count'])

            remainingResponse.value = t('mgs.auth.login-limited').replace(
                ':time',
                res.headers['iSpa-auth-failed-remaining-time']
            )
        }
    }

    if (res.data?.message) {
        iAlert(res.data, true)
    }
}

const checkAllowed = () => {
    if (cookie.get('limited-request')) {
        remainingResponse.value = t('mgs.auth.failed-remaining').replace(
            ':n',
            cookie.get('limited-count', 5)
        )

        const nowadays = new Date()
        allowed.value = false

        if (cookie.get('limited-time')) {
            if (new Date(cookie.get('limited-time')).getTime() < nowadays.getTime()) {
                allowed.value = true
                remainingResponse.value = null
            } else {
                remainingResponse.value = t('mgs.auth.login-limited').replace(
                    ':time',
                    cookie.get('limited-time')
                )
            }
        }
    }
}

const setUserCookie = (data) => {
    cookie.set(COOKIE_KEY_TOKEN, data.data.token)
    localStorage.removeItem(CACHE_KEY_ACCOUNT_DATA)

    cookie.set('limited-request', false)
    cookie.set('limited-time', null)

    if (data.data.locale) {
        cookie.set(COOKIE_KEY_LOCALE, data.data.locale || 'en')
    }

    if (data.data.languages) {
        cookie.set('languages', data.data.languages || [])
    }

    setTimeout(() => {
        router.go(0)
    }, 1500)

    if (data.message) {
        iAlert(data.message)
    }
}

onBeforeMount(() => {
    if (token !== null && token !== undefined) {
        router.push({ name: 'dashboard' })
    }
})

onMounted(() => {
    checkAllowed()
    passkeySupported.value = isPasskeySupported()
})

watch(
    allowed,
    (val) => {
        if (val === false) {
            setInterval(() => {
                checkAllowed()
            }, 1000)
        }
    },
    { immediate: true }
)
</script>
