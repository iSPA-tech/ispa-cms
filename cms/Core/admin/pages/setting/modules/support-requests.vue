<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section class="i-box space-y-3" data-testid="modules-support-requests">
        <!-- Thread of one request -->
        <template v-if="openUuid">
            <div>
                <i-button size="sm" data-testid="support-thread-back" @click="backToList">
                    {{ $t('text.modules.support.requests.back') }}
                </i-button>
            </div>

            <div v-if="thread.loading" aria-busy="true" data-testid="support-thread-loading">
                <a-skeleton active :paragraph="{ rows: 4 }" />
            </div>
            <div v-else-if="thread.error" role="alert" class="space-y-2" data-testid="support-thread-error">
                <p class="m-0 text-sm text-danger">{{ thread.error }}</p>
                <i-button size="sm" data-testid="support-thread-retry" @click="openThread(openUuid)">
                    {{ $t('text.modules.install.retry') }}
                </i-button>
            </div>
            <template v-else-if="thread.ticket">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="m-0 break-words text-base font-semibold">{{ thread.ticket.subject || '-' }}</h3>
                        <p class="m-0 text-xs text-ink-muted">{{ moduleName(thread.ticket.module) }}</p>
                    </div>
                    <a-tag :color="statusColor(thread.ticket.status)" class="m-0" data-testid="support-thread-status">
                        {{ statusText(thread.ticket.status) }}
                    </a-tag>
                </div>

                <ul class="m-0 flex list-none flex-col gap-3 p-0">
                    <li
                        v-for="item in thread.messages"
                        :key="item.uuid"
                        class="min-w-0 rounded-lg border border-border p-3"
                        :class="item.author === 'support' ? 'bg-surface-muted' : ''"
                        data-testid="support-message"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-muted">
                            <span class="font-medium">{{ $t('text.modules.support.requests.author.' + item.author) }}</span>
                            <span>{{ formatDate(item.created_at) }}</span>
                        </div>
                        <!-- Plain text: the body is never rendered as markup. -->
                        <p class="m-0 mt-1 whitespace-pre-wrap break-words text-sm">{{ item.body }}</p>
                    </li>
                </ul>

                <p v-if="thread.ticket.status === 'closed'" class="m-0 text-sm text-ink-muted" data-testid="support-thread-closed">
                    {{ $t('text.modules.support.requests.closed_note') }}
                </p>
                <form v-else class="flex flex-col gap-2" data-testid="support-reply-form" @submit.prevent="sendReply">
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ $t('text.modules.support.requests.reply_label') }}</span>
                        <a-textarea
                            v-model:value="reply"
                            :rows="4"
                            :maxlength="5000"
                            :disabled="sending"
                            :placeholder="$t('text.modules.support.requests.reply_placeholder')"
                            data-testid="support-reply-body"
                        />
                    </label>
                    <a-alert v-if="replyError" type="error" show-icon :message="replyError" data-testid="support-reply-error" />
                    <div class="flex justify-end">
                        <i-button variant="primary" :loading="sending" :disabled="!canReply" data-testid="support-reply-send" @click="sendReply">
                            {{ $t('text.modules.support.requests.reply_send') }}
                        </i-button>
                    </div>
                </form>
            </template>
        </template>

        <!-- List -->
        <template v-else>
            <div v-if="requests.loading && !requests.loaded" aria-busy="true" data-testid="support-requests-loading">
                <a-skeleton active :paragraph="{ rows: 3 }" />
            </div>
            <div v-else-if="requests.error" role="alert" class="space-y-2" data-testid="support-requests-error">
                <p class="m-0 text-sm text-danger">{{ requests.error }}</p>
                <i-button size="sm" data-testid="support-requests-retry" @click="loadList">
                    {{ $t('text.modules.install.retry') }}
                </i-button>
            </div>
            <a-empty v-else-if="!requests.items.length" :description="$t('text.modules.support.requests.empty')" data-testid="support-requests-empty" />
            <ul v-else class="m-0 flex list-none flex-col gap-2 p-0">
                <li
                    v-for="row in requests.items"
                    :key="row.uuid"
                    class="flex min-w-0 flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-3"
                    data-testid="support-request-row"
                >
                    <div class="min-w-0 flex-1 basis-56 space-y-1">
                        <p class="m-0 break-words text-sm font-medium">{{ row.subject || '-' }}</p>
                        <p class="m-0 text-xs text-ink-muted">
                            {{ moduleName(row.module) }} · {{ $t('text.modules.support.requests.last_reply') }}: {{ formatDate(row.last_reply_at) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <a-tag :color="statusColor(row.status)" class="m-0">{{ statusText(row.status) }}</a-tag>
                        <i-button size="sm" data-testid="support-request-open" @click="openThread(row.uuid)">
                            {{ $t('text.modules.support.requests.open') }}
                        </i-button>
                    </div>
                </li>
            </ul>
        </template>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'

/**
 * "Yêu cầu hỗ trợ" of the modules page: this site's support requests on the platform hub and their public
 * thread. The Webmaster opens a request, reads the answers and replies without leaving the site. The hub is reached
 * through the site (never from the browser); an unreachable hub shows a plain message with a retry. A reply cannot be
 * sent twice at the same time, and a closed request offers no reply box.
 */
const props = defineProps({
    /** Admin locale code, used to format dates. */
    locale: { type: String, default: '' },
    /** Readable label of a module name; falls back to the name itself. */
    labelFor: { type: Function, default: (name) => name },
})

const base = 'utilities/modules/support'
const quiet = { silent: true }
const STATUSES = ['new', 'open', 'pending_customer', 'resolved', 'closed']
const STATUS_COLORS = { new: 'processing', open: 'processing', pending_customer: 'warning', resolved: 'success', closed: 'default' }

const { t } = useI18n()
const requests = reactive({ items: [], loading: false, loaded: false, error: '' })
const thread = reactive({ ticket: null, messages: [], loading: false, error: '' })
const openUuid = ref('')
const reply = ref('')
const replyError = ref('')
const sending = ref(false)
let listToken = 0
let threadToken = 0

const canReply = computed(() => !sending.value && reply.value.trim() !== '')

/** Plain words for a hub or site failure, by the fixed error code the site returns. */
const errorText = (error) => {
    const keys = {
        MODULE_SUPPORT_TICKET_NOT_FOUND: 'ticket_not_found',
        MODULE_SUPPORT_RENEWAL_REQUIRED: 'renewal_required',
        MODULE_SUPPORT_CLOSED: 'closed',
        MODULE_SUPPORT_INVALID: 'invalid',
        MODULE_SUPPORT_RATE_LIMITED: 'rate_limited',
        MODULE_SUPPORT_UNAVAILABLE: 'unavailable',
    }
    const body = error?.response?.data || {}
    const code = body.errorCode || body.error_code
    // The hub refused this site's connection (its 401): say so plainly instead of "check the connection".
    if (code === 'MODULE_HUB_NOT_RECOGNISED') return t('text.modules.error.hub_not_recognised')
    if (typeof code === 'string' && keys[code]) return t('text.modules.support.requests.error.' + keys[code])
    if (error?.response?.status === 429) return t('text.modules.support.requests.error.rate_limited')
    if (error?.response?.status === 422) return t('text.modules.support.requests.error.invalid')
    return t('text.modules.support.requests.error.unavailable')
}

const moduleName = (name) => (name ? props.labelFor(name) : t('text.modules.support.requests.module_none'))
const statusText = (status) => (STATUSES.includes(status) ? t('text.modules.support.requests.status.' + status) : '-')
const statusColor = (status) => STATUS_COLORS[status] || 'default'

/** An ISO date in the admin locale, or "-" when missing. */
const formatDate = (iso) => {
    if (!iso) return '-'
    const date = new Date(iso)
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString(props.locale || undefined, { dateStyle: 'short', timeStyle: 'short' })
}

/** Read this site's requests from the hub (through the site). */
const loadList = async () => {
    const mine = ++listToken
    requests.loading = true
    requests.error = ''
    try {
        const answer = await ajax.get(base, quiet)
        if (mine !== listToken) return
        requests.items = Array.isArray(answer.data?.data?.items) ? answer.data.data.items : []
        requests.loaded = true
    } catch (error) {
        if (mine === listToken) requests.error = errorText(error)
    } finally {
        if (mine === listToken) requests.loading = false
    }
}

/** Read one thread; `keepReply` keeps the typed text (used after a refresh). */
const openThread = async (uuid) => {
    const mine = ++threadToken
    openUuid.value = uuid
    thread.loading = true
    thread.error = ''
    try {
        const answer = await ajax.get(`${base}/${encodeURIComponent(uuid)}`, quiet)
        if (mine !== threadToken) return
        thread.ticket = answer.data?.data?.ticket || null
        thread.messages = Array.isArray(answer.data?.data?.messages) ? answer.data.data.messages : []
        if (!thread.ticket) thread.error = t('text.modules.support.requests.error.unavailable')
    } catch (error) {
        if (mine === threadToken) thread.error = errorText(error)
    } finally {
        if (mine === threadToken) thread.loading = false
    }
}

const backToList = () => {
    threadToken++
    openUuid.value = ''
    thread.ticket = null
    thread.messages = []
    thread.error = ''
    reply.value = ''
    replyError.value = ''
    loadList()
}

/** Send the reply; the first press blocks the next ones, the typed text stays when the reply is refused. */
const sendReply = async () => {
    if (sending.value || !canReply.value) return
    sending.value = true
    replyError.value = ''
    try {
        await ajax.post(`${base}/${encodeURIComponent(openUuid.value)}/reply`, { body: reply.value.trim() }, quiet)
        message.success(t('text.modules.support.requests.reply_sent'))
        reply.value = ''
        sending.value = false
        await openThread(openUuid.value)
    } catch (error) {
        replyError.value = errorText(error)
        sending.value = false
    }
}

onMounted(loadList)
</script>
