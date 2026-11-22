<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-md w-full space-y-6 mx-auto mt-8 px-4 sm:px-0 pb-8" data-testid="connect-hub">
        <div class="flex flex-col">
            <div class="pb-4">
                <img :alt="cmsTitle" :src="logoAuthUrl" class="mx-auto h-12 w-auto" />
            </div>
            <h1 class="mt-4 text-center text-2xl sm:text-3xl font-extrabold text-ink">
                {{ t('text.site_connect.title') }}
            </h1>
        </div>

        <div v-if="connectedInfo" class="space-y-3" data-testid="connect-hub-done">
            <div class="rounded-md bg-success-soft p-4 text-sm text-success-ink" data-testid="connect-hub-success" role="status">
                {{ t('text.hub_connect.connected') }}
            </div>
            <div
                v-if="connectedInfo.warning"
                class="rounded-md bg-warning-soft p-4 text-sm text-warning-ink"
                data-testid="connect-hub-env-warning"
                role="status"
            >
                {{ connectedInfo.warning }}
            </div>
        </div>

        <template v-else-if="showRetryOnly">
            <div class="rounded-md bg-warning-soft p-4" data-testid="connect-hub-unavailable" role="alert">
                <h2 class="text-sm font-medium text-warning-ink">{{ t('text.site_connect.unavailable_title') }}</h2>
                <p class="mt-1 text-sm text-warning-ink">{{ retryOnlyText }}</p>
            </div>
            <button
                class="w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md bg-primary text-on-primary hover:bg-primary-hover"
                data-testid="connect-hub-retry"
                type="button"
                @click="emit('refresh')"
            >
                {{ t('text.site_connect.retry') }}
            </button>
        </template>

        <template v-else>
            <p class="text-sm text-ink-soft">{{ t('text.site_connect.intro') }}</p>
            <p v-if="signedIn" class="text-sm text-ink-soft" data-testid="connect-hub-hint">
                {{ t('text.hub_connect.hint') }}
                <a
                    :href="portalUrl"
                    class="font-medium text-primary-ink hover:text-primary-hover"
                    data-testid="connect-hub-portal-link"
                    rel="noopener noreferrer"
                    target="_blank"
                >
                    {{ t('text.site_connect.portal_link') }}
                </a>
            </p>

            <div
                v-if="isPending"
                class="rounded-md bg-warning-soft p-4"
                data-testid="connect-hub-pending"
                role="status"
            >
                <h2 class="text-sm font-medium text-warning-ink">{{ t('text.site_connect.pending_title') }}</h2>
                <p class="mt-1 text-sm text-warning-ink">{{ pendingText }}</p>
                <button
                    class="mt-3 text-sm font-medium text-primary-ink hover:text-primary-hover underline"
                    data-testid="connect-hub-recheck"
                    type="button"
                    @click="emit('refresh')"
                >
                    {{ t('text.site_connect.recheck') }}
                </button>
            </div>
            <div
                v-else-if="statusNotice"
                class="rounded-md bg-warning-soft p-4 text-sm text-warning-ink"
                data-testid="connect-hub-notice"
                role="status"
            >
                {{ statusNotice }}
            </div>
            <div
                v-if="errorText"
                class="rounded-md bg-danger-soft p-4 text-sm text-danger-ink"
                data-testid="connect-hub-error"
                role="alert"
            >
                {{ errorText }}
            </div>

            <a-form layout="vertical" :model="form" @finish="submitForm">
                <a-form-item
                    :extra="signedIn ? undefined : t('text.site_connect.site_id_help')"
                    :help="fieldErrors.siteId || undefined"
                    :label="siteIdLabel"
                    :rules="[{ required: true, trigger: 'blur', message: t('text.site_connect.required', { field: siteIdLabel }) }]"
                    :validate-status="fieldErrors.siteId ? 'error' : undefined"
                    name="siteId"
                >
                    <a-input
                        v-model:value="form.siteId"
                        :disabled="locked"
                        autocomplete="off"
                        data-testid="connect-hub-site-id"
                        inputmode="url"
                    />
                </a-form-item>
                <a-form-item
                    :extra="signedIn ? undefined : t('text.site_connect.site_secret_help')"
                    :help="fieldErrors.siteSecret || undefined"
                    :label="secretLabel"
                    :rules="[{ required: true, trigger: 'blur', message: t('text.site_connect.required', { field: secretLabel }) }]"
                    :validate-status="fieldErrors.siteSecret ? 'error' : undefined"
                    name="siteSecret"
                >
                    <a-input
                        v-model:value="form.siteSecret"
                        :disabled="locked"
                        autocomplete="off"
                        data-testid="connect-hub-site-secret"
                        type="password"
                    />
                </a-form-item>
                <a-form-item
                    v-if="!signedIn"
                    :extra="t('text.site_connect.setup_code_help')"
                    :label="t('text.site_connect.setup_code')"
                    :rules="[{ required: true, trigger: 'blur', message: t('text.site_connect.required', { field: t('text.site_connect.setup_code') }) }]"
                    name="setupCode"
                >
                    <a-input
                        v-model:value="form.setupCode"
                        :disabled="loading"
                        autocomplete="off"
                        data-testid="connect-hub-setup-code"
                    />
                </a-form-item>
                <!-- The site already holds a hub connection: ask before the old details are replaced. -->
                <div v-if="confirmReplace" class="rounded-md bg-warning-soft p-4" data-testid="connect-hub-replace" role="alert">
                    <p class="m-0 text-sm text-warning-ink">{{ t('text.hub_connect.replace_text') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            :disabled="loading"
                            class="flex-1 py-2 px-4 border border-transparent text-sm font-medium rounded-md bg-primary text-on-primary hover:bg-primary-hover disabled:opacity-75 disabled:cursor-not-allowed"
                            data-testid="connect-hub-replace-confirm"
                            type="button"
                            @click="send(true)"
                        >
                            {{ loading ? t('text.site_connect.submitting') : t('text.hub_connect.replace_confirm') }}
                        </button>
                        <button
                            :disabled="loading"
                            class="flex-1 py-2 px-4 border border-border text-sm font-medium rounded-md bg-transparent text-ink hover:bg-surface-hover disabled:opacity-75 disabled:cursor-not-allowed"
                            data-testid="connect-hub-replace-cancel"
                            type="button"
                            @click="confirmReplace = false"
                        >
                            {{ t('text.hub_connect.cancel') }}
                        </button>
                    </div>
                </div>
                <button
                    v-else
                    :disabled="loading"
                    class="w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md bg-primary text-on-primary hover:bg-primary-hover disabled:opacity-75 disabled:cursor-not-allowed"
                    data-testid="connect-hub-submit"
                    type="submit"
                >
                    {{ loading ? t('text.site_connect.submitting') : isPending ? t('text.site_connect.submit_again') : t('text.site_connect.submit') }}
                </button>
            </a-form>

            <div v-if="!signedIn" class="border-t border-border pt-4 text-sm text-ink-soft">
                <p class="m-0">{{ t('text.site_connect.portal_hint') }}</p>
                <a
                    :href="portalUrl"
                    class="mt-2 inline-block font-medium text-primary-ink hover:text-primary-hover"
                    data-testid="connect-hub-portal-link"
                    rel="noopener noreferrer"
                    target="_blank"
                >
                    {{ t('text.site_connect.portal_link') }}
                </a>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { apiUrl, cmsTitle, logoAuthUrl } from '@/config'
import { runtimeConfig } from '@/config/runtime'
import { submitSiteConnect } from '@/pages/auth/site-gate'

defineOptions({ name: 'ConnectHub' })

const props = defineProps({
    /** Last answer of `GET /api/v2/public/site-status`; null while unknown. */
    status: { type: Object, default: null },
    /** The status request itself failed: show a retry instead of the form. */
    unavailable: { type: Boolean, default: false },
    /**
     * Signed-in use (Modules → "Kết nối iSPA Hub"): a function `({siteId, secret, replace}) => Promise<result>` that
     * posts to the admin endpoint (see `submitAdminSiteConnect`). When given, the screen asks for the site id and
     * secret only. Without it this is the pre-login screen, which also asks for the install setup code.
     */
    adminSubmit: { type: Function, default: null },
})
const emit = defineEmits(['connected', 'refresh'])

// The wording is served by the admin i18n packs (rows in cms/Core translates-admin-connect-hub.php). The pack
// endpoint is public and does not depend on the site being connected, so it loads before this screen shows.
const { t, te } = useI18n()

const portalUrl = `${String(runtimeConfig().hubUrl).replace(/\/+$/, '')}/portal`

const form = reactive({
    // The site id is the host the operator is looking at; it can still be edited.
    siteId: typeof window !== 'undefined' ? window.location.hostname : '',
    siteSecret: '',
    setupCode: '',
})
const loading = ref(false)
const result = ref(null)

const signedIn = computed(() => typeof props.adminSubmit === 'function')
const siteIdLabel = computed(() => t(signedIn.value ? 'text.hub_connect.site_id' : 'text.site_connect.site_id'))
const secretLabel = computed(() => t(signedIn.value ? 'text.hub_connect.secret' : 'text.site_connect.site_secret'))
/** Signed-in only: the backend said the site is already connected and waits for "Kết nối lại" or "Huỷ". */
const confirmReplace = ref(false)
/** Signed-in only: set once connected, `{warning: string}` (the .env notice, or ''). */
const connectedInfo = ref(null)
/** Signed-in only: what the backend said about one field (422). */
const fieldErrors = reactive({ siteId: '', siteSecret: '' })
const locked = computed(() => loading.value || confirmReplace.value)

/** Status messages worth showing as a notice above the form; the other status keys repeat what the screen already says. */
const NOTICE_KEYS = ['text.site_status.not_connected']
/** The hub could not be reached after the grace period: the site may already hold keys, so no key form. */
const HUB_UNREACHABLE_KEY = 'text.site_status.hub_unreachable'

/** Signed-in connect: the pair was saved, but the `.env` pair on the server keeps winning (row in translate-admin-authored.php). */
const ENV_WINS_KEY = 'text.site_connect.env_wins'

/**
 * Translate a backend `text.*` message key, or '' when the key is empty or has no translation.
 *
 * @param {string} backendKey For example `text.site_connect.reason.wrong_answer`.
 * @returns {string} The translated message, or ''.
 */
const textFor = (backendKey) => {
    const key = String(backendKey || '')

    return key !== '' && te(key) ? t(key) : ''
}

const hubUnreachable = computed(() => props.status?.message_key === HUB_UNREACHABLE_KEY)
// Only a notice and a retry, never the form: the status request failed, or the hub cannot be reached.
const showRetryOnly = computed(() => props.unavailable || hubUnreachable.value)
const retryOnlyText = computed(() => (!props.unavailable && hubUnreachable.value ? textFor(HUB_UNREACHABLE_KEY) : '') || t('text.site_connect.unavailable_text'))
const isPending = computed(() => result.value?.status === 'pending' || (!result.value && props.status?.status === 'pending'))
const pendingText = computed(() => textFor(result.value?.status === 'pending' ? result.value.messageKey : '') || t('text.site_connect.pending_text'))
const errorText = computed(() =>
    result.value && !result.value.ok && !confirmReplace.value ? textFor(result.value.messageKey) || t('text.site_connect.unavailable') : '',
)
// The hub refusing the site or being silent is explained once, until the operator submits the form.
const statusNotice = computed(() => (result.value ? '' : textFor(NOTICE_KEYS.includes(props.status?.message_key) ? props.status.message_key : '')))

/**
 * Send the form; a connected answer hands over to the gate, which asks the site status again.
 */
const submitForm = async () => {
    if (signedIn.value) {
        return send(false)
    }
    if (loading.value) {
        return
    }
    loading.value = true
    result.value = null
    const answer = await submitSiteConnect(axios, apiUrl, {
        siteId: form.siteId,
        siteSecret: form.siteSecret,
        setupCode: form.setupCode,
    })
    result.value = answer
    if (answer.ok && answer.status === 'connected') {
        // Leave `loading` on: the gate replaces this screen as soon as it has the new status.
        form.siteSecret = ''
        form.setupCode = ''
        emit('connected')

        return
    }
    loading.value = false
}

/**
 * Signed-in connect: send the site id and secret to the admin endpoint. A site that is already connected asks first;
 * "Kết nối lại" sends the same values again with `replace`.
 *
 * @param {boolean} replace True once the person confirmed replacing the old connection.
 */
const send = async (replace) => {
    if (loading.value) {
        return
    }
    loading.value = true
    result.value = null
    fieldErrors.siteId = ''
    fieldErrors.siteSecret = ''
    const answer = await props.adminSubmit({ siteId: form.siteId, secret: form.siteSecret, replace })
    result.value = answer
    confirmReplace.value = !answer.ok && answer.code === 'HUB_ALREADY_CONNECTED'
    if (answer.ok && answer.status === 'connected') {
        form.siteSecret = ''
        // The .env details keep winning over the ones just saved: say so next to the success message.
        const warning = answer.source === 'env' || answer.warningKey ? textFor(answer.warningKey) || textFor(ENV_WINS_KEY) : ''
        connectedInfo.value = { warning }
        emit('connected')
    } else if (!answer.ok) {
        fieldErrors.siteId = answer.fieldErrors?.siteId || ''
        fieldErrors.siteSecret = answer.fieldErrors?.siteSecret || ''
    }
    loading.value = false
}
</script>
