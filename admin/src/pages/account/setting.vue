<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-6">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900">{{ $t('text.acc.preferences') }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">
                        {{ $t('text.acc.preferences-desc') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
                    >
                        {{ $t('text.acc.localization-language') }}: {{ currentLanguageLabel }}
                    </span>
                    <span
                        class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
                    >
                        {{ $t('text.acc.localization-timezone') }}: {{ currentTimezoneLabel }}
                    </span>
                </div>
            </div>
        </article>

        <a-spin :spinning="loadingSettings || loadingMfa || loadingPasskeys" class="block">
            <a-form layout="vertical">
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(320px,0.95fr)]">
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">
                                {{ $t('text.acc.security-preferences') }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $t('text.acc.security-preferences-desc') }}
                            </p>
                        </div>

                        <div class="mt-6 space-y-4">
                            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                                <a-form-item class="mb-0" :label="$t('text.acc.sign-in-policy')">
                                    <a-select
                                        v-model:value="form.security.mfa_mode.value"
                                        :options="mfaModeOptions"
                                        option-filter-prop="label"
                                        :placeholder="$t('text.acc.select-sign-in-policy')"
                                        show-search
                                    />
                                </a-form-item>
                                <p class="mt-2 text-sm text-slate-500">
                                    {{ $t('text.acc.sign-in-policy-desc') }}
                                </p>
                                <a-alert
                                    v-if="requiresAuthenticatorMode && !mfaStatus.authenticator?.enabled"
                                    class="mt-4"
                                    :message="$t('text.acc.warn-authenticator-required')"
                                    show-icon
                                    type="warning"
                                />
                            </div>

                            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-900">
                                            {{ $t('text.mfa.authenticator-app') }}
                                        </h3>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ $t('text.mfa.authenticator-app-desc') }}
                                        </p>
                                    </div>
                                    <span
                                        :class="[
                                            'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                                            authenticatorStatusClass,
                                        ]"
                                    >
                                        {{ authenticatorStatusLabel }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                            {{ $t('text.acc.policy') }}
                                        </p>
                                        <p class="mt-2 text-sm font-medium text-slate-900">
                                            {{ selectedMfaModeLabel }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{
                                                $t('text.mfa.recovery-codes-remaining', {
                                                    count: mfaStatus.recovery_codes_remaining || 0,
                                                })
                                            }}
                                        </p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                            {{ $t('text.acc.last-activity') }}
                                        </p>
                                        <p class="mt-2 text-sm font-medium text-slate-900">
                                            {{ authenticatorLastUsedLabel }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $t('text.acc.label') }}:
                                            {{
                                                mfaStatus.authenticator?.label ||
                                                $t('text.acc.not-configured')
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <div v-if="authenticatorSetup" class="mt-4 rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-4">
                                        <div>
                                            <h4 class="text-sm font-semibold text-slate-900">
                                                {{ $t('text.mfa.finish-setup') }}
                                            </h4>
                                            <p class="mt-1 text-sm text-slate-500">
                                                {{ $t('text.mfa.finish-setup-desc') }}
                                            </p>
                                        </div>
                                        <button
                                            class="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                                            type="button"
                                            @click="cancelAuthenticatorSetup"
                                        >
                                            {{ $t('text.mfa.cancel-setup') }}
                                        </button>
                                    </div>
                                    <div class="mt-4 grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)]">
                                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                                            <img v-if="authenticatorQrUrl" :src="authenticatorQrUrl" :alt="$t('text.mfa.qr-code')" class="mx-auto h-52 w-52 rounded-lg object-contain" />
                                            <p v-else class="flex h-52 items-center text-center text-sm text-slate-500">
                                                {{ authenticatorQrError ? $t('text.profile.passkey.qr_error') : $t('text.profile.passkey.qr_loading') }}
                                            </p>
                                        </div>
                                        <div class="space-y-4">
                                            <div class="rounded-xl border border-slate-200 bg-white p-4">
                                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                                    {{ $t('text.mfa.manual-key') }}
                                                </p>
                                                <p class="mt-2 break-all font-mono text-sm text-slate-900">
                                                    {{ authenticatorSetup.secret }}
                                                </p>
                                                <div class="mt-3 flex flex-wrap gap-2">
                                                    <button
                                                        class="inline-flex items-center rounded-md border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                                                        type="button"
                                                        @click="copyValue(authenticatorSetup.secret)"
                                                    >
                                                        {{ $t('text.mfa.copy-secret') }}
                                                    </button>
                                                    <button
                                                        class="inline-flex items-center rounded-md border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                                                        type="button"
                                                        @click="copyValue(authenticatorSetup.otpauth_uri)"
                                                    >
                                                        {{ $t('text.mfa.copy-otpauth-uri') }}
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="rounded-xl border border-slate-200 bg-white p-4">
                                                <a-form-item class="mb-0" :label="$t('text.mfa.authenticator-code')">
                                                    <a-input
                                                        v-model:value="authenticatorCode"
                                                        :maxlength="6"
                                                        :placeholder="$t('text.mfa.authenticator-code-placeholder')"
                                                    />
                                                </a-form-item>
                                                <div class="mt-3 flex justify-end">
                                                    <a-button
                                                        :loading="confirmingAuthenticator"
                                                        danger
                                                        type="primary"
                                                        @click="confirmAuthenticatorSetup"
                                                    >
                                                        {{ $t('text.mfa.confirm-authenticator') }}
                                                    </a-button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="mt-4 flex flex-wrap gap-2">
                                    <a-button
                                        v-if="!mfaStatus.authenticator?.enabled"
                                        :loading="startingAuthenticator"
                                        type="primary"
                                        @click="beginAuthenticatorSetup"
                                    >
                                        {{ $t('text.mfa.set-up-authenticator') }}
                                    </a-button>
                                    <a-button
                                        v-if="mfaStatus.authenticator?.enabled"
                                        @click="toggleRecoveryRegeneration"
                                    >
                                        {{ $t('text.mfa.regenerate-recovery-codes') }}
                                    </a-button>
                                    <a-button
                                        v-if="mfaStatus.authenticator?.enabled"
                                        danger
                                        @click="toggleAuthenticatorDisable"
                                    >
                                        {{ $t('text.mfa.disable-authenticator') }}
                                    </a-button>
                                </div>

                                <div
                                    v-if="showRecoveryRegeneration || showAuthenticatorDisable"
                                    class="mt-4 rounded-2xl border border-slate-200 bg-white p-4"
                                >
                                    <a-form-item class="mb-0" :label="$t('text.acc.current-password')">
                                        <a-input
                                            v-model:value="securityActionPassword"
                                            :placeholder="$t('text.acc.current-password-placeholder')"
                                            show-password
                                            type="password"
                                        />
                                    </a-form-item>

                                    <div class="mt-3 flex flex-wrap justify-end gap-2">
                                        <a-button @click="resetSecurityActionState">
                                            {{ $t('nav.b.cancel') }}
                                        </a-button>
                                        <a-button
                                            v-if="showRecoveryRegeneration"
                                            :loading="regeneratingCodes"
                                            type="primary"
                                            @click="regenerateRecoveryCodes"
                                        >
                                            {{ $t('text.mfa.generate-new-recovery-codes') }}
                                        </a-button>
                                        <a-button
                                            v-if="showAuthenticatorDisable"
                                            :loading="disablingAuthenticator"
                                            danger
                                            type="primary"
                                            @click="disableAuthenticator"
                                        >
                                            {{ $t('text.mfa.disable-authenticator') }}
                                        </a-button>
                                    </div>
                                </div>

                                <div
                                    v-if="recoveryCodeList.length"
                                    class="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50 p-4"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h4 class="text-sm font-semibold text-slate-900">
                                                {{ $t('text.mfa.recovery-codes-title') }}
                                            </h4>
                                            <p class="mt-1 text-sm text-slate-500">
                                                {{ $t('text.mfa.recovery-codes-desc') }}
                                            </p>
                                        </div>
                                        <button
                                            class="inline-flex items-center rounded-md border border-emerald-200 bg-white px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100"
                                            type="button"
                                            @click="copyValue(recoveryCodeList.join('\n'))"
                                        >
                                            {{ $t('text.mfa.copy-all-codes') }}
                                        </button>
                                    </div>

                                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                        <div
                                            v-for="code in recoveryCodeList"
                                            :key="code"
                                            class="rounded-xl border border-emerald-200 bg-white px-4 py-3 font-mono text-sm font-semibold text-slate-900"
                                        >
                                            {{ code }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-cyan-50 text-cyan-700">
                                            <i-icon icon="fingerprint" class="size-5" />
                                        </span>
                                        <div>
                                        <h3 class="text-sm font-semibold text-slate-900">
                                            {{ $t('text.passkey.sign-in') }}
                                        </h3>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ $t('text.profile.passkey.desc') }}
                                        </p>
                                        </div>
                                    </div>
                                    <span
                                        :class="[
                                            'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                                            passkeys.length > 0
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-slate-100 text-slate-500',
                                        ]"
                                    >
                                        {{
                                            passkeys.length > 0
                                                ? $t('text.profile.passkey.in_use', { count: passkeys.length })
                                                : $t('text.profile.passkey.not_set_up')
                                        }}
                                    </span>
                                </div>

                                <a-alert
                                    v-if="!passkeySupported"
                                    class="mt-4"
                                    :message="$t('text.profile.passkey.unsupported')"
                                    show-icon
                                    type="warning"
                                />

                                <template v-else>
                                    <div class="mt-4 flex flex-wrap items-center gap-3">
                                        <a-button :loading="registeringPasskey" type="primary" @click="beginPasskeyRegistration">
                                            <span class="inline-flex items-center gap-2">
                                                <i-icon icon="fingerprint" class="size-4" />
                                                <span>{{ $t('text.profile.passkey.add') }}</span>
                                            </span>
                                        </a-button>
                                        <span class="text-xs text-slate-500">
                                            {{ $t('text.profile.passkey.add_hint') }}
                                        </span>
                                    </div>

                                    <div v-if="passkeys.length" class="mt-4 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                                        <div
                                            v-for="passkey in passkeys"
                                            :key="passkey.id"
                                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-slate-900">
                                                    {{ passkey.name }}
                                                </p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ passkey.authenticator || $t('text.profile.passkey.default_authenticator') }}
                                                    ·
                                                    {{
                                                        passkey.last_used_at
                                                            ? $t('text.profile.passkey.last_used', { time: formatPasskeyTimestamp(passkey.last_used_at) })
                                                            : $t('text.profile.passkey.never_used')
                                                    }}
                                                </p>
                                            </div>
                                            <a-popconfirm
                                                :cancel-text="$t('nav.b.cancel')"
                                                :ok-text="$t('nav.b.delete')"
                                                :title="$t('text.profile.passkey.remove_confirm', { name: passkey.name })"
                                                @confirm="removePasskey(passkey.id)"
                                            >
                                                <a-button
                                                    :loading="removingPasskeyId === passkey.id"
                                                    danger
                                                    size="small"
                                                >
                                                    {{ $t('nav.b.delete') }}
                                                </a-button>
                                            </a-popconfirm>
                                        </div>
                                    </div>

                                    <p v-else class="mt-4 text-sm text-slate-500">
                                        {{ $t('text.profile.passkey.empty') }}
                                    </p>
                                </template>
                            </div>

                            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-900">
                                            {{ $t('text.acc.security-email_change_password') }}
                                        </h3>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ $t('text.acc.email-change-password-desc') }}
                                        </p>
                                    </div>
                                    <a-switch
                                        v-model:checked="form.security.email_change_password.value"
                                        :checked-children="$t('text.turn-on')"
                                        :un-checked-children="$t('text.turn-off')"
                                    />
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">
                                {{ $t('text.acc.localization-preferences') }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $t('text.acc.localization-preferences-desc') }}
                            </p>
                        </div>

                        <div class="mt-6 space-y-5">
                            <a-form-item :label="$t('text.acc.localization-language')">
                                <a-select
                                    v-model:value="form.localization.language.value"
                                    :options="languageOptions"
                                    :virtual="false"
                                    option-filter-prop="label"
                                    :placeholder="$t('text.acc.select-language')"
                                    show-search
                                />
                            </a-form-item>

                            <a-form-item :label="$t('text.acc.localization-timezone')">
                                <a-select
                                    v-model:value="form.localization.timezone.value"
                                    :options="timezoneOptions"
                                    option-filter-prop="label"
                                    :placeholder="$t('text.acc.select-timezone')"
                                    show-search
                                />
                            </a-form-item>

                            <a-alert
                                :message="$t('text.acc.locale-save-info')"
                                show-icon
                                type="info"
                            />
                        </div>
                    </section>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <router-link :to="{ name: 'account-dashboard' }">
                        <a-button>
                            <span class="inline-flex items-center gap-2">
                                <i-icon icon="close" class="size-4" />
                                <span>{{ $t('nav.b.cancel') }}</span>
                            </span>
                        </a-button>
                    </router-link>
                    <a-button :loading="savingSettings" danger type="primary" @click="submitForm">
                        <span class="inline-flex items-center gap-2">
                            <i-icon icon="content-save" class="size-4" />
                            <span>{{ $t('nav.b.save') }}</span>
                        </span>
                    </a-button>
                </div>
            </a-form>
        </a-spin>

    </div>
</template>

<script setup>
import { computed, defineComponent, onMounted, ref, watch } from 'vue'
import { useStorage } from '@vueuse/core'
import ajax from '@/ajax'
import { can } from '@/hooks/useCan'
import { iAlert, iCopy } from '@/mixins'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { useAppRuntime } from '@/runtime'
import { useI18n } from 'vue-i18n'
import { getSettingOptionLabel } from './account-helpers'
import { useAuthenticatorQr } from './use-authenticator-qr'
import {
    deletePasskey,
    getPasskeys,
    isPasskeySupported,
    registerPasskey,
    suggestPasskeyName,
} from '@/libs/passkey'
defineComponent({
    name: 'AccountSetting',
})
const account = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const { locale, setLocale } = useAppRuntime()
const { t } = useI18n()
const loadingSettings = ref(false)
const savingSettings = ref(false)
const loadingMfa = ref(false)
const startingAuthenticator = ref(false)
const confirmingAuthenticator = ref(false)
const disablingAuthenticator = ref(false)
const regeneratingCodes = ref(false)
const loadingPasskeys = ref(false)
const registeringPasskey = ref(false)
const removingPasskeyId = ref(null)
const authenticatorSetup = ref(null)
const authenticatorCode = ref('')
const { authenticatorQrUrl, authenticatorQrError, clearAuthenticatorQr, renderAuthenticatorQr } = useAuthenticatorQr()
const recoveryCodeList = ref([])
const securityActionPassword = ref('')
const showAuthenticatorDisable = ref(false)
const showRecoveryRegeneration = ref(false)
const passkeys = ref([])
const passkeySupported = ref(false)

const emptyMfaStatus = () => ({
    mode: 'off',
    recovery_codes_remaining: 0,
    authenticator: {
        enabled: false,
        pending: false,
        label: null,
        last_used_at: null,
    },
})

const mfaStatus = ref(emptyMfaStatus())

function createFormState(payload = {}) {
    return {
        security: {
            mfa_mode: {
                value: payload?.security?.mfa_mode?.value ?? 'off',
                type: 'select',
                options: payload?.security?.mfa_mode?.options || [],
            },
            email_change_password: {
                value: payload?.security?.email_change_password?.value ?? false,
                type: 'boolean',
            },
        },
        localization: {
            language: {
                value: payload?.localization?.language?.value ?? 'en',
                type: 'select',
                options: payload?.localization?.language?.options || [],
            },
            timezone: {
                value: payload?.localization?.timezone?.value ?? 'Asia/Ho_Chi_Minh',
                type: 'select',
                options: payload?.localization?.timezone?.options || [],
            },
        },
    }
}

const form = ref(createFormState())

const languageOptions = computed(() => {
    return (form.value.localization.language.options || []).map((item) => ({
        label: item.name || item.label || item.path || item.value,
        value: item.path || item.value,
    }))
})

const timezoneOptions = computed(() => {
    return (form.value.localization.timezone.options || []).map((item) => {
        if (typeof item === 'string') {
            return {
                label: item,
                value: item,
            }
        }

        return {
            label: item.name || item.label || item.path || item.value,
            value: item.path || item.value,
        }
    })
})

const mfaModeOptions = computed(() => {
    return (form.value.security.mfa_mode.options || []).map((item) => ({
        label: item.name || item.label || item.value,
        value: item.value,
    }))
})

const currentLanguageLabel = computed(() =>
    getSettingOptionLabel(form.value, 'localization', 'language', t('text.acc.not-configured'))
)

const currentTimezoneLabel = computed(() =>
    getSettingOptionLabel(form.value, 'localization', 'timezone', t('text.acc.not-configured'))
)

const requiresAuthenticatorMode = computed(() =>
    ['totp_only', 'email_plus_totp'].includes(form.value.security.mfa_mode.value)
)

const selectedMfaModeLabel = computed(() => {
    const option = mfaModeOptions.value.find((item) => item.value === form.value.security.mfa_mode.value)

    return option?.label || t('text.acc.not-configured')
})

const authenticatorStatusLabel = computed(() => {
    if (authenticatorSetup.value || mfaStatus.value.authenticator?.pending) {
        return t('text.acc.pending-setup')
    }

    if (mfaStatus.value.authenticator?.enabled) {
        return t('text.acc.status-active')
    }

    return t('text.acc.not-configured')
})

const authenticatorStatusClass = computed(() => {
    if (authenticatorSetup.value || mfaStatus.value.authenticator?.pending) {
        return 'bg-amber-50 text-amber-700'
    }

    if (mfaStatus.value.authenticator?.enabled) {
        return 'bg-emerald-50 text-emerald-700'
    }

    return 'bg-slate-100 text-slate-500'
})

const authenticatorLastUsedLabel = computed(() => {
    return mfaStatus.value.authenticator?.last_used_at || t('text.acc.no-authenticator-signin-yet')
})

function getSettings(uuid) {
    // The settings endpoint needs the user update permission; a read-only role skips the request instead of failing it.
    if (!uuid || !can('user', 'update')) {
        return
    }

    loadingSettings.value = true
    ajax.get(`user/${uuid}/settings`)
        .then((res) => {
            if (res.data.status === 'success') {
                form.value = createFormState(res.data.data || {})
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingSettings.value = false
        })
}

function getMfaStatus() {
    loadingMfa.value = true
    ajax.get('account/mfa')
        .then((res) => {
            if (res.data.status === 'success') {
                mfaStatus.value = {
                    ...emptyMfaStatus(),
                    ...(res.data.data || {}),
                }

                if (mfaStatus.value.mode) {
                    form.value.security.mfa_mode.value = mfaStatus.value.mode
                }
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingMfa.value = false
        })
}

function submitForm() {
    if (!account.value?.uuid) {
        return
    }

    if (requiresAuthenticatorMode.value && !mfaStatus.value.authenticator?.enabled) {
        iAlert({
            type: 'warning',
            message: t('text.acc.warn-authenticator-setup-before-policy'),
        })
        return
    }

    savingSettings.value = true
    ajax.put(`user/${account.value.uuid}/settings`, form.value)
        .then(async (res) => {
            if (res.data.status === 'success') {
                const selectedLocale = form.value.localization.language.value

                if (selectedLocale && selectedLocale !== locale.value) {
                    await setLocale(selectedLocale)
                }
            }

            iAlert(res.data.message)
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            savingSettings.value = false
        })
}

function beginAuthenticatorSetup() {
    startingAuthenticator.value = true
    ajax.post('account/mfa/authenticator/start')
        .then((res) => {
            if (res.data.status === 'success') {
                authenticatorSetup.value = res.data.data
                authenticatorCode.value = ''
                void renderAuthenticatorQr(authenticatorSetup.value.otpauth_uri)
                recoveryCodeList.value = []
                resetSecurityActionState()
            } else {
                iAlert(res.data, true)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            startingAuthenticator.value = false
        })
}

function confirmAuthenticatorSetup() {
    if (!authenticatorCode.value) {
        iAlert({
            type: 'warning',
            message: t('text.acc.warn-confirm-authenticator-code'),
        })
        return
    }

    confirmingAuthenticator.value = true
    ajax.post('account/mfa/authenticator/confirm', {
        code: authenticatorCode.value,
    }).then((res) => {
        if (res.data.status === 'success') {
            authenticatorSetup.value = null
            authenticatorCode.value = ''
            clearAuthenticatorQr()
            recoveryCodeList.value = res.data.data?.recovery_codes || []
            if (res.data.data?.summary) {
                mfaStatus.value = {
                    ...emptyMfaStatus(),
                    ...res.data.data.summary,
                }
            }
            iAlert(res.data.message)
            getMfaStatus()
        } else {
            iAlert(res.data, true)
        }
    }).catch((err) => {
        iAlert(err, true)
    }).finally(() => {
        confirmingAuthenticator.value = false
    })
}

function cancelAuthenticatorSetup() {
    authenticatorSetup.value = null
    authenticatorCode.value = ''
    clearAuthenticatorQr()
}

function toggleAuthenticatorDisable() {
    showAuthenticatorDisable.value = !showAuthenticatorDisable.value
    showRecoveryRegeneration.value = false
    securityActionPassword.value = ''
}

function toggleRecoveryRegeneration() {
    showRecoveryRegeneration.value = !showRecoveryRegeneration.value
    showAuthenticatorDisable.value = false
    securityActionPassword.value = ''
}

function resetSecurityActionState() {
    showAuthenticatorDisable.value = false
    showRecoveryRegeneration.value = false
    securityActionPassword.value = ''
}

function disableAuthenticator() {
    if (!securityActionPassword.value) {
        iAlert({
            type: 'warning',
            message: t('text.acc.warn-current-password-disable-authenticator'),
        })
        return
    }

    disablingAuthenticator.value = true
    ajax.delete('account/mfa/authenticator', {
        data: {
            current_password: securityActionPassword.value,
        },
    }).then((res) => {
        if (res.data.status === 'success') {
            recoveryCodeList.value = []
            resetSecurityActionState()
            authenticatorSetup.value = null
            clearAuthenticatorQr()
            iAlert(res.data.message)
            getMfaStatus()
        } else {
            iAlert(res.data, true)
        }
    }).catch((err) => {
        iAlert(err, true)
    }).finally(() => {
        disablingAuthenticator.value = false
    })
}

function regenerateRecoveryCodes() {
    if (!securityActionPassword.value) {
        iAlert({
            type: 'warning',
            message: t('text.acc.warn-current-password-regenerate-recovery'),
        })
        return
    }

    regeneratingCodes.value = true
    ajax.post('account/mfa/recovery-codes/regenerate', {
        current_password: securityActionPassword.value,
    }).then((res) => {
        if (res.data.status === 'success') {
            recoveryCodeList.value = res.data.data?.recovery_codes || []
            resetSecurityActionState()
            iAlert(res.data.message)
            getMfaStatus()
        } else {
            iAlert(res.data, true)
        }
    }).catch((err) => {
        iAlert(err, true)
    }).finally(() => {
        regeneratingCodes.value = false
    })
}

function formatPasskeyTimestamp(value) {
    const date = new Date(value)

    if (Number.isNaN(date.getTime())) {
        return t('text.profile.passkey.time_unknown')
    }

    return new Intl.DateTimeFormat(locale.value === 'vi' ? 'vi-VN' : 'en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date)
}

function getPasskeyList() {
    loadingPasskeys.value = true

    return getPasskeys()
        .then((items) => {
            passkeys.value = items
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingPasskeys.value = false
        })
}

async function beginPasskeyRegistration() {
    registeringPasskey.value = true

    try {
        const response = await registerPasskey(suggestPasskeyName(undefined, t))

        if (response.data?.status !== 'success') {
            iAlert(response.data, true)
            return
        }

        iAlert(response.data.message || t('text.profile.passkey.added'))
        await getPasskeyList()
    } catch (err) {
        if (err?.name === 'AbortError' || err?.name === 'NotAllowedError') {
            iAlert({
                type: 'warning',
                message: t('text.passkey.cancelled'),
            })
        } else if (err?.key) {
            // PasskeyError carries an i18n key instead of display text.
            iAlert({ type: 'error', message: t(err.key) })
        } else {
            iAlert(err, true)
        }
    } finally {
        registeringPasskey.value = false
    }
}

async function removePasskey(passkeyId) {
    removingPasskeyId.value = passkeyId

    try {
        const response = await deletePasskey(passkeyId)

        if (response.data?.status !== 'success') {
            iAlert(response.data, true)
            return
        }

        iAlert(response.data.message || t('text.profile.passkey.removed'))
        await getPasskeyList()
    } catch (err) {
        iAlert(err, true)
    } finally {
        removingPasskeyId.value = null
    }
}

async function copyValue(value) {
    if (!value) {
        return
    }

    await iCopy(value)
}

watch(
    () => account.value?.uuid,
    (uuid) => {
        if (uuid) {
            getSettings(uuid)
            getMfaStatus()
            getPasskeyList()
        }
    },
    { immediate: true }
)

onMounted(() => {
    passkeySupported.value = isPasskeySupported()
})
</script>
