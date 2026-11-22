<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex flex-col">
        <div class="pb-6">
            <img :alt="cmsTitle" :src="logoAuthUrl" class="mx-auto h-12 w-auto" />
        </div>
        <h1 class="mt-6 text-center text-2xl sm:text-3xl font-extrabold text-ink">
            {{ projectTitle }}
        </h1>
        <h2 class="mt-4 text-center text-xl sm:text-2xl font-extrabold text-ink">
            {{ $t('text.auth.recover') }}
        </h2>
    </div>
    <a-form
        ref="formRef"
        :model="form"
        class="mt-8 space-y-6"
        layout="vertical"
        @finish="submitForm"
    >
        <a-form-item
            v-if="form.step === 1"
            :label="$t('validation.attributes.email')"
            :rules="[
                {
                    required: true,
                    trigger: 'blur',
                    message: $t('validation.required', { a: $t('validation.attributes.email') }),
                },
            ]"
            name="email"
        >
            <a-input v-model:value="form.email" :placeholder="$t('validation.attributes.email')" type="email">
                <template #suffix>
                    <a-tooltip :title="$t('text.auth.e-email')">
                        <span class="relative">
                            <i-icon icon="help-circle-outline" />
                        </span>
                    </a-tooltip>
                </template>
            </a-input>
        </a-form-item>

        <a-form-item v-if="form.step === 2" :label="$t('validation.attributes.verify-code')" name="code">
            <a-input v-model:value="form.code" :placeholder="$t('validation.attributes.verify-code')">
                <template #suffix>
                    <a-tooltip :title="$t('text.e.verify-code')">
                        <span class="relative">
                            <i-icon icon="help-circle-outline" />
                        </span>
                    </a-tooltip>
                </template>
            </a-input>
        </a-form-item>

        <template v-if="form.step === 3">
            <a-form-item
                :label="$t('validation.attributes.new_password')"
                :rules="[{ trigger: 'blur', validator: validatePassword }]"
                name="password"
            >
                <a-input
                    v-model:value="form.password"
                    :placeholder="$t('validation.attributes.new_password')"
                    show-password
                    type="password"
                />
            </a-form-item>

            <a-form-item
                :label="$t('validation.attributes.password_confirmation')"
                :rules="[{ trigger: 'blur', validator: validatePassword }]"
                name="password_confirmation"
            >
                <a-input
                    v-model:value="form.password_confirmation"
                    :placeholder="$t('validation.attributes.password_confirmation')"
                    show-password
                    type="password"
                />
            </a-form-item>
        </template>

        <i-alert v-if="form.step === 4" type="success">
            {{ form.message }}
        </i-alert>

        <div class="flex items-center justify-between">
            <div class="flex-1" />

            <div class="text-sm">
                <span
                    class="font-medium text-primary-ink hover:text-primary-hover"
                    role="button"
                    @click="goBack"
                >
                    {{ $t('nav.b.sign-in') }}
                </span>
            </div>
        </div>

        <div v-if="form.step < 4">
            <button
                v-loading="loading"
                class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md bg-primary text-on-primary hover:bg-primary-hover"
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
                {{ form.step < 3 ? $t('nav.b.verify') : $t('nav.b.change') }}
            </button>
        </div>
    </a-form>
</template>

<script setup>
import { defineComponent, onMounted, ref } from 'vue'
import ajax from '@/ajax'
import { useRouter } from 'vue-router'
import { iAlert, objectAssignOnlyByKeyHasValue } from '@/mixins'
import { useI18n } from 'vue-i18n'
import { logoAuthUrl, cookie, cmsTitle, projectTitle, COOKIE_KEY_TOKEN } from '@/config'

defineComponent({
    name: 'AuthSignIn',
})

const { t } = useI18n()
/** Empty form to reset form */
const emptyForm = () => {
    return {
        email: null,
        code: null,
        token: null,
        step: 1,
        message: null,
        password: null,
        password_confirmation: null,
        type: 'reset-code',
    }
}
const form = ref(emptyForm())
const formRef = ref(null)
const validatePassword = (rule, value) => {
    if (form.value.step === 3) {
        if (!value) {
            return Promise.reject(t('validation.required', { a: t('validation.attributes.' + rule.field) }))
        }
        if (value.length < 6) {
            return Promise.reject(
                t('validation.min.string', {
                    a: t('validation.attributes.' + rule.field),
                    min: 6,
                })
            )
        }
        if (value.search(/[a-z]/i) < 0 || value.search(/[0-9]/) < 0) {
            return Promise.reject(t('validation.password-complicated'))
        }
        if (
            rule.field === 'password_confirmation' &&
            form.value.password !== form.value.password_confirmation
        ) {
            return Promise.reject(t('validation.confirmed', { a: t('validation.attributes.password') }))
        }
    }
    return Promise.resolve()
}
const loading = ref(false)
const router = useRouter()
/** Process to submit form */
const submitForm = (data = {}) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)
    loading.value = true
    let url
    if (value.step === 1) {
        url = 'request-code'
    } else if (value.step === 2) {
        url = 'check-code'
    } else {
        url = 'set-password'
    }

    //  Submit form
    ajax.post(`auth/${url}`, value)
        .then((res) => {
            if (res.data.status === 'success') {
                form.value = res.data.data

                if (value.step === 3) {
                    //	Reset pass + redirect to login page
                }
            }
            if (res.data.message) {
                iAlert(res.data)
            }
            loading.value = false
        })
        .catch((err) => {
            iAlert(err, true)
            loading.value = false
        })
}

//  go back to login page by click on sign-in by back in history
const goBack = () => {
    router.back()
}

onMounted(() => {
    if (cookie.get(COOKIE_KEY_TOKEN)) {
        router.push({
            name: 'dashboard',
        })
    }
})
</script>
