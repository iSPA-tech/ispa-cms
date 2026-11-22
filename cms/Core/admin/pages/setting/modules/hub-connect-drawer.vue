<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <!-- Connect this site to iSPA Hub from inside the admin.
         One tap: the Modules button calls `start` here and the browser goes to
         ispa.tech; the callback screen finishes. The drawer itself only opens for what needs reading: the question
         before an existing connection is replaced, a refusal, or the site address + secret form behind
         "Kết nối bằng mã thủ công". -->
    <a-drawer :open="open" width="min(480px, 100vw)" destroy-on-close data-testid="hub-connect-drawer" @close="emit('close')">
        <template v-if="showManual">
            <ConnectHub :status="status" :unavailable="failed" :admin-submit="connect" @connected="onConnected" @refresh="load" />
            <div v-if="!failed" class="max-w-md mx-auto px-4 sm:px-0 pb-8 text-center">
                <a-button data-testid="hub-connect-one-tap" type="link" @click="manualMode = false">
                    {{ t('text.hub_connect.one_tap_link') }}
                </a-button>
            </div>
        </template>
        <div v-else class="max-w-md w-full space-y-4 mx-auto mt-8 px-4 sm:px-0 pb-8" data-testid="hub-one-tap">
            <h1 class="text-center text-2xl sm:text-3xl font-extrabold text-ink">{{ t('text.site_connect.title') }}</h1>
            <!-- The site already holds a hub connection: ask before a new attempt replaces it. -->
            <div v-if="askReplace" class="rounded-md bg-warning-soft p-4" data-testid="hub-one-tap-replace" role="alert">
                <p class="m-0 text-sm text-warning-ink">{{ t('text.hub_connect.replace_text') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a-button :loading="starting" data-testid="hub-one-tap-replace-confirm" type="primary" @click="start(true)">
                        {{ t('text.hub_connect.replace_confirm') }}
                    </a-button>
                    <a-button :disabled="starting" data-testid="hub-one-tap-replace-cancel" @click="cancelReplace">
                        {{ t('text.hub_connect.cancel') }}
                    </a-button>
                </div>
            </div>
            <template v-else>
                <p v-if="!problem" class="text-sm text-ink-soft">{{ t('text.hub_connect.one_tap_intro') }}</p>
                <div v-else class="rounded-md bg-danger-soft p-4 text-sm text-danger-ink" data-testid="hub-one-tap-error" role="alert">
                    {{ t(problem.messageKey) }}
                </div>
                <a-button
                    v-if="retryable"
                    :loading="starting"
                    block
                    class="justify-center"
                    data-testid="hub-one-tap-start"
                    size="large"
                    type="primary"
                    @click="start(false)"
                >
                    {{ t('text.hub_connect.start') }}
                </a-button>
            </template>
            <p v-if="waiting" class="text-center text-sm text-ink-soft" data-testid="hub-one-tap-waiting" role="status">
                {{ t('text.hub_connect.waiting') }}
            </p>
            <div v-if="retryable && !askReplace" class="text-center">
                <a-button :disabled="starting" data-testid="hub-connect-manual" type="link" @click="manualMode = true">
                    {{ t('text.hub_connect.manual_link') }}
                </a-button>
            </div>
        </div>
    </a-drawer>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { apiUrl } from '@/config'
import ConnectHub from '@/pages/auth/connect-hub.vue'
import { loadSiteStatus, submitAdminSiteConnect } from '@/pages/auth/site-gate'
import { browser, canRetryHubConnect, HUB_ALREADY_CONNECTED, startHubConnect } from './hub-one-tap'

const props = defineProps({
    /** Whether the drawer is shown. */
    open: { type: Boolean, default: false },
    /** Open on the site address + secret form (the callback screen's "Kết nối bằng mã thủ công" link). */
    manual: { type: Boolean, default: false },
})
// `attention`: a one-tap start needs the person (replace question or refusal), so the page opens the drawer.
const emit = defineEmits(['close', 'connected', 'attention'])

const { t } = useI18n()

/** Last site-status answer of the site's own backend; null while loading. */
const status = ref(null)
/** The status request itself failed: the screen shows a retry instead of the form. */
const failed = ref(false)

/** The person chose the manual form. */
const manualMode = ref(props.manual)
/** A site backend that does not answer shows the connect screen's own retry, never a button that cannot work. */
const showManual = computed(() => manualMode.value || failed.value)
/** A start request is running, or the browser is already leaving for ispa.tech. */
const starting = ref(false)
/** The attempt started: the browser is on its way to the ispa.tech consent screen. */
const waiting = ref(false)
/** Why the last start did not go through: `{code, messageKey}`, or null. */
const problem = ref(null)
/** The site already holds a connection and the person has not answered yet. */
const askReplace = computed(() => problem.value?.code === HUB_ALREADY_CONNECTED)
/** Another attempt (and the manual form) can help; false when the account or the server settings forbid it. */
const retryable = computed(() => canRetryHubConnect(problem.value?.code || ''))

/**
 * One tap: ask the site backend to start an attempt, then send the browser to the ispa.tech consent screen. Called by
 * the Modules button with the drawer closed; a refusal or the replace question asks the page to open the drawer.
 *
 * @param {boolean} replace True once the person confirmed replacing the existing connection.
 */
const start = async (replace = false) => {
    if (starting.value) {
        return
    }
    starting.value = true
    const answer = await startHubConnect(ajax, replace === true)
    if (answer.ok) {
        // Leave `starting` on: the browser is leaving this screen.
        problem.value = null
        waiting.value = true
        browser.go(answer.redirectUrl)

        return
    }
    problem.value = { code: answer.code, messageKey: answer.messageKey }
    manualMode.value = false
    starting.value = false
    emit('attention')
}

/**
 * "Huỷ" on the replace question: keep the existing connection and close.
 */
const cancelReplace = () => {
    problem.value = null
    emit('close')
}

defineExpose({ start, starting, waiting })

/**
 * The browser's back button can restore this screen from its cache after ispa.tech: make the button usable again.
 *
 * @param {PageTransitionEvent} event The `pageshow` event.
 */
const onPageShow = (event) => {
    if (event.persisted) {
        starting.value = false
        waiting.value = false
    }
}
onMounted(() => window.addEventListener('pageshow', onPageShow))
onBeforeUnmount(() => window.removeEventListener('pageshow', onPageShow))

/**
 * Signed-in connect: the site id and secret go to the admin endpoint, no install setup code.
 *
 * @param {{siteId: string, secret: string, replace?: boolean}} form Form values.
 * @returns {Promise<object>} See `submitAdminSiteConnect`.
 */
const connect = (form) => submitAdminSiteConnect(ajax, form)

/**
 * Read the hub connection status from the site's own backend (the browser never calls the hub).
 */
const load = async () => {
    failed.value = false
    status.value = null
    const answer = await loadSiteStatus(axios, apiUrl)
    failed.value = answer === null
    status.value = answer
}

/**
 * The hub accepted the site: read the status once more and let the page reload its module list. The drawer stays
 * open on the success message until the person closes it.
 */
const onConnected = async () => {
    await load()
    emit('connected')
}

watch(
    () => props.open,
    (value) => {
        if (value) {
            manualMode.value = props.manual
            load()
        } else if (!starting.value) {
            // Closed: the next opening starts clean.
            problem.value = null
        }
    },
    { immediate: true },
)
</script>
