<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <!-- Where ispa.tech sends the browser back after "Cho phép" / "Huỷ" (one-tap hub connect, plan 2026-11-22 Task 4).
         One short message at a time: finishing, connected, still being checked, or why it did not work and what to do. -->
    <div class="max-w-md w-full mx-auto mt-8 px-4 sm:px-0 pb-8 space-y-4" data-testid="hub-callback">
        <h1 class="text-xl sm:text-2xl font-bold text-ink">{{ t('text.site_connect.title') }}</h1>

        <div v-if="view === 'working'" class="flex items-center gap-3 text-sm text-ink-soft" data-testid="hub-callback-working" role="status">
            <a-spin size="small" />
            <span>{{ t('text.hub_connect.finishing') }}</span>
        </div>

        <template v-else-if="view === 'done'">
            <div class="rounded-md bg-success-soft p-4 text-sm text-success-ink" data-testid="hub-callback-done" role="status">
                {{ t('text.hub_connect.connected') }}
            </div>
            <div v-if="warning" class="rounded-md bg-warning-soft p-4 text-sm text-warning-ink" data-testid="hub-callback-env-warning" role="status">
                {{ warning }}
            </div>
        </template>

        <div v-else-if="view === 'pending'" class="rounded-md bg-warning-soft p-4" data-testid="hub-callback-pending" role="status">
            <h2 class="m-0 text-sm font-medium text-warning-ink">{{ t('text.site_connect.pending_title') }}</h2>
            <p class="mt-1 mb-0 text-sm text-warning-ink">{{ pendingText }}</p>
        </div>

        <!-- The site already holds a hub connection: ask before the old details are replaced. -->
        <div v-else-if="view === 'replace'" class="rounded-md bg-warning-soft p-4" data-testid="hub-callback-replace" role="alert">
            <p class="m-0 text-sm text-warning-ink">{{ t('text.hub_connect.replace_text') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a-button :loading="loading" data-testid="hub-callback-replace-confirm" type="primary" @click="confirmReplace">
                    {{ t('text.hub_connect.replace_confirm') }}
                </a-button>
                <a-button :disabled="loading" data-testid="hub-callback-replace-cancel" @click="openModules()">
                    {{ t('text.hub_connect.cancel') }}
                </a-button>
            </div>
            <p v-if="waiting" class="mt-3 mb-0 text-sm text-warning-ink" data-testid="hub-callback-waiting" role="status">
                {{ t('text.hub_connect.waiting') }}
            </p>
        </div>

        <template v-else>
            <div class="rounded-md bg-danger-soft p-4 text-sm text-danger-ink" data-testid="hub-callback-error" role="alert">
                {{ errorText }}
            </div>
            <template v-if="canRetry">
                <a-button :loading="loading" block class="justify-center" data-testid="hub-callback-restart" size="large" type="primary" @click="restart(false)">
                    {{ t('text.hub_connect.start') }}
                </a-button>
                <p v-if="waiting" class="text-sm text-ink-soft" data-testid="hub-callback-waiting" role="status">
                    {{ t('text.hub_connect.waiting') }}
                </p>
                <a-button class="px-0" data-testid="hub-callback-manual" type="link" @click="openModules(true)">
                    {{ t('text.hub_connect.manual_link') }}
                </a-button>
            </template>
        </template>

        <a-button v-if="view === 'done' || view === 'pending'" data-testid="hub-callback-modules" type="primary" @click="openModules()">
            {{ t('text.hub_connect.open_modules') }}
        </a-button>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import ajax from '@/ajax'
import { browser, canRetryHubConnect, finishHubConnect, HUB_ALREADY_CONNECTED, startHubConnect, STATE_INVALID_KEY } from './modules/hub-one-tap'

defineOptions({ name: 'HubConnectCallback' })

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()

/** The `.env` pair on the server keeps winning over the one just saved. */
const ENV_WINS_KEY = 'text.site_connect.env_wins'

/** Which message shows: working, done, pending, replace or error. */
const view = ref('working')
const loading = ref(false)
/** The browser is on its way to ispa.tech for a new attempt. */
const waiting = ref(false)
/** Last answer of the backend. */
const result = ref(null)
/** What ispa.tech put in the address; kept in memory so "Kết nối lại" can send the same code and state again. */
const answer = { code: '', state: '', error: '' }

/**
 * Translate a `text.*` key, or '' when it is empty or unknown.
 *
 * @param {string} key Translation key.
 * @returns {string} The wording, or ''.
 */
const textFor = (key) => (key && te(key) ? t(key) : '')

const warning = computed(() => (result.value?.source === 'env' || result.value?.warningKey ? textFor(result.value.warningKey) || textFor(ENV_WINS_KEY) : ''))
const pendingText = computed(() => textFor(result.value?.messageKey) || t('text.site_connect.pending_text'))
const errorText = computed(() => textFor(result.value?.messageKey) || t('text.site_connect.unavailable'))
/** A missing permission, or a connection the server settings own, is not something another attempt can fix. */
const canRetry = computed(() => canRetryHubConnect(result.value?.code || ''))
/** What "Kết nối lại" repeats: the finish request of this attempt, or the start of a new one. */
let replaceStep = 'finish'

/**
 * Send what ispa.tech returned to the site backend and show the outcome.
 *
 * @param {boolean} replace True once the person confirmed replacing the existing connection.
 */
const finish = async (replace = false) => {
    if (loading.value) {
        return
    }
    loading.value = true
    const outcome = await finishHubConnect(ajax, { ...answer, replace })
    result.value = outcome
    if (outcome.ok) {
        view.value = outcome.status === 'pending' ? 'pending' : 'done'
    } else {
        view.value = outcome.code === HUB_ALREADY_CONNECTED ? 'replace' : 'error'
    }
    loading.value = false
}

/**
 * Start a new attempt from the error message: the browser goes to ispa.tech again. A site that already holds a
 * connection asks first.
 *
 * @param {boolean} replace True once the person confirmed replacing the existing connection.
 */
const restart = async (replace = false) => {
    if (loading.value) {
        return
    }
    loading.value = true
    const outcome = await startHubConnect(ajax, replace === true)
    if (outcome.ok) {
        // Leave `loading` on: the browser is leaving this screen.
        waiting.value = true
        browser.go(outcome.redirectUrl)

        return
    }
    result.value = outcome
    replaceStep = 'start'
    view.value = outcome.code === HUB_ALREADY_CONNECTED ? 'replace' : 'error'
    loading.value = false
}

/**
 * "Kết nối lại": repeat the refused step with `replace`.
 */
const confirmReplace = () => (replaceStep === 'start' ? restart(true) : finish(true))

/**
 * Open the Modules screen; with `manual` it opens the connect drawer on the site address + secret form.
 *
 * @param {boolean} manual Open the manual form there.
 */
const openModules = (manual = false) => {
    router.push(manual ? { name: 'setting-modules', query: { connect: 'manual' } } : { name: 'setting-modules' })
}

onMounted(() => {
    const first = (value) => String((Array.isArray(value) ? value[0] : value) || '')
    answer.code = first(route.query?.code)
    answer.state = first(route.query?.state)
    answer.error = first(route.query?.error)
    // The single-use code leaves the address bar and the history entry at once.
    router.replace({ query: {} })

    if (answer.state === '' || (answer.code === '' && answer.error === '')) {
        result.value = { ok: false, code: '', messageKey: STATE_INVALID_KEY }
        view.value = 'error'

        return
    }
    finish(false)
})
</script>
