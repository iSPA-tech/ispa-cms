<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_360px]">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">
                    {{ $t('nav.a.account-password') }}
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $t('text.account-password') }}
                </p>
            </div>

            <a-alert
                class="mt-5"
                :message="$t('text.acc.password-session-signout')"
                show-icon
                type="info"
            />

            <a-form
                id="passwordForm"
                ref="refPws"
                :model="form"
                class="mt-6"
                layout="vertical"
            >
                <a-form-item
                    :label="$t('text.acc.current-password')"
                    name="current_password"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: $t('validation.required', { a: $t('text.acc.current-password') }),
                        },
                    ]"
                >
                    <a-input-password
                        v-model:value="form.current_password"
                        :placeholder="$t('text.acc.current-password-placeholder')"
                    />
                </a-form-item>

                <a-form-item
                    :label="$t('validation.attributes.new_password')"
                    name="password"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: $t('validation.required', { a: $t('validation.attributes.new_password') }),
                        },
                    ]"
                >
                    <a-input-password
                        v-model:value="form.password"
                        :placeholder="$t('validation.attributes.new_password')"
                    />
                </a-form-item>

                <a-form-item
                    :label="$t('validation.attributes.password_confirmation')"
                    name="password_confirmation"
                    :rules="[
                        {
                            required: true,
                            trigger: 'blur',
                            message: $t('validation.required', { a: $t('validation.attributes.password_confirmation') }),
                        },
                        {
                            validator: validatePasswordConfirmation,
                            trigger: 'blur',
                        },
                    ]"
                >
                    <a-input-password
                        v-model:value="form.password_confirmation"
                        :placeholder="$t('validation.attributes.password_confirmation')"
                    />
                </a-form-item>

                <div class="flex justify-end gap-2 pt-2">
                    <router-link :to="{ name: 'account-dashboard' }">
                        <a-button>
                            <span class="inline-flex items-center gap-2">
                                <i-icon icon="close" class="size-4" />
                                <span>{{ $t('nav.b.cancel') }}</span>
                            </span>
                        </a-button>
                    </router-link>
                    <a-button
                        :loading="statePws"
                        danger
                        type="primary"
                        @click="submitForm"
                    >
                        <span class="inline-flex items-center gap-2">
                            <i-icon icon="content-save" class="size-4" />
                            <span>{{ $t('nav.b.save') }}</span>
                        </span>
                    </a-button>
                </div>
            </a-form>
        </section>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">{{ t('text.acc.password-strength') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ t('text.acc.password-strength-desc') }}
                        </p>
                    </div>
                    <span
                        :style="{ color: strength.color }"
                        class="text-sm font-semibold"
                    >
                        {{ strength.label }}
                    </span>
                </div>

                <a-progress
                    :percent="strength.percent"
                    :show-info="false"
                    class="mt-5"
                    :stroke-color="strength.color"
                />

                <div class="mt-5 space-y-3">
                    <div
                        v-for="item in passwordRequirements"
                        :key="item.key"
                        class="flex items-start gap-3 rounded-2xl border px-4 py-3"
                        :class="item.met ? 'border-emerald-100 bg-emerald-50/50' : 'border-slate-200 bg-slate-50'"
                    >
                        <span
                            :class="item.met ? 'bg-emerald-500' : 'bg-slate-300'"
                            class="mt-1 inline-flex size-2.5 rounded-full"
                        />
                        <span class="text-sm text-slate-700">{{ item.label }}</span>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">
                            {{ t('text.acc.security-preferences') }}
                        </h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ t('text.acc.security-preferences-desc') }}
                        </p>
                    </div>
                    <router-link :to="{ name: 'account-setting' }">
                        <a-button size="small">{{ t('text.acc.preferences') }}</a-button>
                    </router-link>
                </div>

                <div class="mt-6 space-y-4">
                    <div class="rounded-2xl bg-slate-50 px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {{ t('text.acc.last-password-change') }}
                        </p>
                        <p class="mt-2 text-sm text-slate-700">
                            {{ lastPasswordChangeLabel }}
                        </p>
                    </div>

                    <div class="rounded-2xl bg-slate-50 px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {{ t('text.acc.sign-in-policy') }}
                        </p>
                        <p class="mt-2 text-sm text-slate-700">
                            {{
                                getSettingValue(settings, 'security', 'two_fa', false)
                                    ? t('text.acc.sign-in-verification-enabled')
                                    : t('text.acc.sign-in-verification-disabled')
                            }}
                        </p>
                    </div>

                    <div class="rounded-2xl bg-slate-50 px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                            {{ t('text.acc.security-email_change_password') }}
                        </p>
                        <p class="mt-2 text-sm text-slate-700">
                            {{
                                getSettingValue(settings, 'security', 'email_change_password', false)
                                    ? t('text.acc.password-alert-enabled')
                                    : t('text.acc.password-alert-disabled')
                            }}
                        </p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import { useStorage } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { can } from '@/hooks/useCan'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { dateFormat, iAlert } from '@/mixins'
import { useAppRuntime } from '@/runtime'
import {
    buildPasswordRequirementItems,
    getSettingValue,
    resolvePasswordStrength,
} from './account-helpers'

defineComponent({
    name: 'AccountChangePassword',
})

const { t } = useI18n()
const { signOut } = useAppRuntime()
const account = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const settings = ref({})
const form = ref({
    password: '',
    current_password: '',
    password_confirmation: '',
})
const refPws = ref(null)
const statePws = ref(false)

const passwordRequirements = computed(() =>
    buildPasswordRequirementItems({
        currentPassword: form.value.current_password,
        password: form.value.password,
        passwordConfirmation: form.value.password_confirmation,
    }, t)
)
const strength = computed(() => resolvePasswordStrength(passwordRequirements.value, t))
const lastPasswordChangeLabel = computed(() => {
    if (account.value?.lastChangePassword?.createdAt) {
        return t('text.acc.password-changed-on', {
            date: dateFormat(account.value.lastChangePassword.createdAt, 'YYYY-MM-DD HH:mm'),
        })
    }

    if (account.value?.createdAt) {
        const since = dateFormat(account.value.createdAt, 'YYYY-MM-DD')
        // Stored messages use {d}; {date} is kept for older packs.
        return t('text.acc.password-not-changed-since', { date: since, d: since })
    }

    return t('text.acc.password-not-changed-yet')
})

function validatePasswordConfirmation() {
    if (!form.value.password_confirmation) {
        return Promise.reject(
            new Error(t('validation.required', { a: t('validation.attributes.password_confirmation') }))
        )
    }

    if (form.value.password_confirmation !== form.value.password) {
        return Promise.reject(
            new Error(
                t('validation.confirmed', {
                    attribute: t('validation.attributes.password_confirmation'),
                })
            )
        )
    }

    return Promise.resolve()
}

function loadSettings(uuid) {
    // The settings endpoint needs the user update permission; a read-only role skips the request instead of failing it.
    if (!uuid || !can('user', 'update')) {
        return
    }

    ajax.get(`user/${uuid}/settings`)
        .then((res) => {
            if (res.data.status === 'success') {
                settings.value = res.data.data || {}
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

function submitForm() {
    refPws.value.validate()
        .then(() => {
            statePws.value = true

            ajax.post('account/change-password', form.value)
                .then((res) => {
                    if (res?.data?.status === 'success') {
                        form.value = {
                            password: '',
                            current_password: '',
                            password_confirmation: '',
                        }
                        iAlert(res.data)
                        signOut()
                        return
                    }

                    if (res?.data?.message) {
                        iAlert(res.data)
                    }
                })
                .catch((err) => {
                    iAlert(err, true)
                })
                .finally(() => {
                    statePws.value = false
                })
        })
        .catch(() => undefined)
}

watch(
    () => account.value?.uuid,
    (uuid) => {
        if (uuid) {
            loadSettings(uuid)
        }
    },
    { immediate: true }
)
</script>
