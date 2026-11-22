<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section v-if="!loaded || available" class="flex flex-col gap-4" data-testid="store-community" :aria-busy="loading">
        <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.community.title') }}</h4>

        <div v-if="loading && !loaded" data-testid="community-loading">
            <a-skeleton active :paragraph="{ rows: 3 }" />
        </div>
        <div v-else-if="loadError" role="alert" class="flex flex-col items-start gap-2" data-testid="community-error">
            <p class="m-0 text-sm text-danger">{{ loadError }}</p>
            <i-button size="sm" data-testid="community-retry" @click="load">{{ $t('text.modules.install.retry') }}</i-button>
        </div>
        <template v-else>
            <!-- Average stars -->
            <div class="flex flex-wrap items-center gap-2" data-testid="community-average">
                <template v-if="ratings.count > 0">
                    <a-rate :value="ratings.average" disabled allow-half class="text-base" />
                    <span class="text-sm text-ink-muted">
                        {{ $t('text.modules.store.community.summary', { average: formatAverage(ratings.average), count: ratings.count }, ratings.count) }}
                    </span>
                </template>
                <span v-else class="text-sm text-ink-muted" data-testid="community-ratings-empty">{{ $t('text.modules.store.community.no_ratings') }}</span>
            </div>

            <!-- This website's own rating -->
            <div class="flex flex-col items-start gap-2 rounded-lg border border-border p-3">
                <div v-if="mine.rating" class="flex flex-col gap-1" data-testid="community-mine">
                    <p class="m-0 text-xs font-medium text-ink-muted">{{ $t('text.modules.store.community.your_rating') }}</p>
                    <a-rate :value="mine.rating.stars" disabled class="text-sm" />
                    <p v-if="mine.rating.body" class="m-0 whitespace-pre-line break-words text-sm">{{ mine.rating.body }}</p>
                    <p v-if="mine.rating.hidden" class="m-0 text-xs text-danger" data-testid="community-mine-hidden">{{ $t('text.modules.store.community.mine_hidden') }}</p>
                </div>
                <p v-if="!mine.installed" class="m-0 text-sm text-ink-muted" data-testid="community-not-installed">
                    {{ $t('text.modules.store.community.not_installed') }}
                </p>
                <a-alert
                    v-else-if="!mine.can_rate && mine.reason === REASON_INSTALL_REQUIRED"
                    class="self-stretch"
                    type="info"
                    show-icon
                    :message="$t('text.modules.store.community.reason.install_required')"
                    data-testid="community-rate-reason"
                />
                <i-button v-if="mine.can_rate && form !== 'rate'" size="sm" variant="primary" data-testid="community-rate-open" @click="openForm('rate')">
                    {{ $t(mine.rating ? 'text.modules.store.community.edit_rating' : 'text.modules.store.community.write_rating') }}
                </i-button>

                <form v-if="form === 'rate'" class="flex flex-col gap-3 self-stretch" data-testid="community-rate-form" novalidate @submit.prevent="sendRating">
                    <a-alert v-if="formError" type="error" show-icon :message="formError" data-testid="community-form-error" />
                    <div class="flex flex-col gap-1">
                        <span id="community-stars-label" class="block text-sm font-medium">{{ $t('text.modules.store.community.stars_label') }}</span>
                        <a-rate v-model:value="draft.stars" :allow-clear="false" aria-labelledby="community-stars-label" data-testid="community-rate-stars" />
                    </div>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ $t('text.modules.store.community.rating_text_label') }}</span>
                        <a-textarea v-model:value="draft.body" :rows="3" :maxlength="2000" :placeholder="$t('text.modules.store.community.rating_text_placeholder')" data-testid="community-rate-text" />
                    </label>
                    <name-field />
                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button size="sm" :disabled="sending" @click="closeForm">{{ $t('text.modules.store.community.cancel') }}</i-button>
                        <i-button size="sm" variant="primary" :loading="sending" :disabled="sending || !(draft.stars >= 1)" data-testid="community-rate-save" @click="sendRating">
                            {{ $t('text.modules.store.community.save_rating') }}
                        </i-button>
                    </div>
                </form>
            </div>

            <!-- Ratings of every website -->
            <ul v-if="ratings.items.length" class="m-0 flex list-none flex-col gap-3 p-0">
                <li v-for="(item, index) in ratings.items" :key="index" class="flex min-w-0 flex-col gap-1" data-testid="community-rating">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
                        <a-rate :value="item.stars" disabled class="text-xs" />
                        <span class="font-medium text-ink">{{ item.author_name }}</span>
                        <span v-if="item.version">{{ $t('text.modules.version') }} {{ item.version }}</span>
                        <span>{{ formatDate(item.updated_at) }}</span>
                    </div>
                    <p v-if="item.body" class="m-0 whitespace-pre-line break-words text-sm">{{ item.body }}</p>
                </li>
            </ul>
            <i-button v-if="ratings.items.length < ratings.total" class="self-start" size="sm" :loading="moreLoading === 'ratings'" data-testid="community-ratings-more" @click="more('ratings')">
                {{ $t('text.modules.store.community.more_ratings') }}
            </i-button>

            <!-- Questions and answers -->
            <div class="flex flex-col gap-3 border-0 border-t border-solid border-border pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h5 class="m-0 text-sm font-semibold">{{ $t('text.modules.store.community.questions_title') }}</h5>
                    <i-button v-if="form !== 'ask'" size="sm" data-testid="community-ask-open" @click="openForm('ask')">
                        {{ $t('text.modules.store.community.ask') }}
                    </i-button>
                </div>
                <form v-if="form === 'ask'" class="flex flex-col gap-3 rounded-lg border border-border p-3" data-testid="community-ask-form" novalidate @submit.prevent="sendQuestion">
                    <a-alert v-if="formError" type="error" show-icon :message="formError" data-testid="community-form-error" />
                    <p class="m-0 text-xs text-ink-muted">{{ $t('text.modules.store.community.ask_help') }}</p>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ $t('text.modules.store.community.question_label') }}</span>
                        <a-textarea v-model:value="draft.body" :rows="3" :maxlength="2000" data-testid="community-ask-text" />
                    </label>
                    <name-field />
                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button size="sm" :disabled="sending" @click="closeForm">{{ $t('text.modules.store.community.cancel') }}</i-button>
                        <i-button size="sm" variant="primary" :loading="sending" :disabled="sending || !draft.body.trim()" data-testid="community-ask-send" @click="sendQuestion">
                            {{ $t('text.modules.store.community.ask_send') }}
                        </i-button>
                    </div>
                </form>
                <ul v-if="questions.items.length" class="m-0 flex list-none flex-col gap-4 p-0">
                    <li v-for="(item, index) in questions.items" :key="index" class="flex min-w-0 flex-col gap-2" data-testid="community-question">
                        <div class="text-xs text-ink-muted">
                            <span class="font-medium text-ink">{{ item.author_name }}</span> · {{ formatDate(item.created_at) }}
                        </div>
                        <p class="m-0 whitespace-pre-line break-words text-sm">{{ item.body }}</p>
                        <ul v-if="item.answers.length" class="m-0 flex list-none flex-col gap-2 border-0 border-l-2 border-solid border-border p-0 pl-3">
                            <li v-for="(answer, aIndex) in item.answers" :key="aIndex" class="flex min-w-0 flex-col gap-1" data-testid="community-answer">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
                                    <a-tag v-if="answer.author_kind !== 'site'" :color="answer.author_kind === 'ispa' ? 'processing' : 'default'">
                                        {{ $t('text.modules.store.community.badge.' + answer.author_kind) }}
                                    </a-tag>
                                    <span v-else class="font-medium text-ink">{{ answer.author_name }}</span>
                                    <span>{{ formatDate(answer.created_at) }}</span>
                                </div>
                                <p class="m-0 whitespace-pre-line break-words text-sm">{{ answer.body }}</p>
                            </li>
                        </ul>
                        <p v-else class="m-0 text-xs text-ink-muted">{{ $t('text.modules.store.community.no_answer') }}</p>
                    </li>
                </ul>
                <p v-else class="m-0 text-sm text-ink-muted" data-testid="community-questions-empty">{{ $t('text.modules.store.community.no_questions') }}</p>
                <i-button v-if="questions.items.length < questions.total" class="self-start" size="sm" :loading="moreLoading === 'questions'" data-testid="community-questions-more" @click="more('questions')">
                    {{ $t('text.modules.store.community.more_questions') }}
                </i-button>
            </div>

            <!-- Private feedback to the publisher -->
            <div class="flex flex-col items-start gap-2 border-0 border-t border-solid border-border pt-4">
                <i-button v-if="form !== 'feedback'" size="sm" data-testid="community-feedback-open" @click="openForm('feedback')">
                    {{ $t('text.modules.store.community.feedback') }}
                </i-button>
                <form v-else class="flex flex-col gap-3 self-stretch rounded-lg border border-border p-3" data-testid="community-feedback-form" novalidate @submit.prevent="sendFeedback">
                    <a-alert v-if="formError" type="error" show-icon :message="formError" data-testid="community-form-error" />
                    <p class="m-0 text-xs text-ink-muted">{{ $t('text.modules.store.community.feedback_help') }}</p>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ $t('text.modules.store.community.feedback_kind_label') }}</span>
                        <a-select v-model:value="draft.kind" :options="kindOptions" class="w-full" data-testid="community-feedback-kind" />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ $t('text.modules.store.community.feedback_label') }}</span>
                        <a-textarea v-model:value="draft.body" :rows="4" :maxlength="4000" data-testid="community-feedback-text" />
                    </label>
                    <name-field />
                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button size="sm" :disabled="sending" @click="closeForm">{{ $t('text.modules.store.community.cancel') }}</i-button>
                        <i-button size="sm" variant="primary" :loading="sending" :disabled="sending || !draft.body.trim()" data-testid="community-feedback-send" @click="sendFeedback">
                            {{ $t('text.modules.store.community.feedback_send') }}
                        </i-button>
                    </div>
                </form>
            </div>
        </template>
    </section>
</template>

<script setup>
import { computed, defineComponent, h, reactive, ref, resolveComponent, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'

/**
 * Ratings, questions and private feedback of one module in the store drawer.
 *
 * Everyone who can open the store reads the average, the ratings and the Q&A. A Webmaster of a website that has the
 * module installed rates it (stars + text, editable); the hub also requires the module in the website's latest status
 * report and its refusal is shown in plain words. Asking and private feedback need no install. The hub only learns a
 * pseudonymous reference and the display name typed here (default: the given name), never the e-mail. Hub text is
 * always rendered as text. A hub that predates ratings renders nothing.
 */
const props = defineProps({
    /** The catalog row of the module (name, installed, ...). */
    entry: { type: Object, required: true },
    locale: { type: String, default: 'vi' },
})

const emit = defineEmits(['rated'])
const REASON_INSTALL_REQUIRED = 'MODULE_COMMUNITY_INSTALL_REQUIRED'
const base = 'utilities/modules/catalog/'
const quiet = { silent: true }

const { t } = useI18n()
const loading = ref(false)
const loaded = ref(false)
const loadError = ref('')
const moreLoading = ref('')
const mine = reactive({ available: true, installed: false, rating: null, can_rate: false, reason: null, default_name: '' })
const ratings = reactive({ available: true, average: null, count: 0, items: [], page: 1, total: 0 })
const questions = reactive({ available: true, items: [], page: 1, total: 0 })
const available = computed(() => ratings.available !== false && questions.available !== false)

/** The open form: '' (none), 'rate', 'ask' or 'feedback'; only one at a time. */
const form = ref('')
const draft = reactive({ stars: 0, body: '', kind: 'feature' })
const authorName = ref('')
const sending = ref(false)
const formError = ref('')
let token = 0

const kindOptions = computed(() => ['feature', 'praise', 'other'].map((value) => ({ value, label: t('text.modules.store.community.kind.' + value) })))

/** Display-name input shared by the three forms; prefilled with the saved name or the default. */
const NameField = defineComponent({
    setup() {
        return () =>
            h('label', { class: 'flex flex-col gap-1' }, [
                h('span', { class: 'text-sm font-medium' }, t('text.modules.store.community.name_label')),
                h(resolveComponent('a-input'), {
                    value: authorName.value,
                    maxlength: 120,
                    'data-testid': 'community-name',
                    'onUpdate:value': (value) => (authorName.value = value),
                }),
                h('span', { class: 'block text-xs text-ink-muted' }, t('text.modules.store.community.name_help')),
            ])
    },
})

const nameOf = (entry) => encodeURIComponent(entry?.name || '')
const errorCodeOf = (error) => {
    const body = error?.response?.data || {}
    const code = body.errorCode || body.error_code
    return typeof code === 'string' ? code : ''
}

/** Plain words for a refused write. */
const writeErrorText = (error) => {
    const keys = {
        MODULE_COMMUNITY_INSTALL_REQUIRED: 'text.modules.store.community.reason.install_required',
        MODULE_COMMUNITY_NOT_INSTALLED: 'text.modules.store.community.not_installed',
        MODULE_COMMUNITY_RATE_LIMITED: 'text.modules.store.community.error.rate_limited',
        MODULE_COMMUNITY_NOT_FOUND: 'text.modules.store.community.error.not_found',
        MODULE_COMMUNITY_INVALID: 'text.modules.store.community.error.invalid',
        MODULE_COMMUNITY_NAME_ISPA: 'text.modules.store.community.error.name_ispa',
        MODULE_COMMUNITY_NAME_CONTACT: 'text.modules.store.community.error.name_contact',
    }
    const code = errorCodeOf(error)
    if (keys[code]) return t(keys[code])
    if (error?.response?.status === 422) return t('text.modules.store.community.error.invalid')
    if (error?.response?.status === 429) return t('text.modules.store.community.error.rate_limited')
    return t('text.modules.store.community.error.send_failed')
}

const formatAverage = (value) =>
    Number.isFinite(value) ? new Intl.NumberFormat(props.locale === 'vi' ? 'vi-VN' : 'en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value) : ''

const formatDate = (value) => {
    const date = value ? new Date(value) : null
    return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString(props.locale === 'vi' ? 'vi-VN' : 'en-GB') : ''
}

/** Load this website's rating, the first page of ratings and the first page of questions. */
const load = async () => {
    const name = nameOf(props.entry)
    if (!name) return
    const mineToken = ++token
    loading.value = true
    loadError.value = ''
    closeForm()
    try {
        const [own, list, asked] = await Promise.all([
            ajax.get(`${base}${name}/ratings/mine`, quiet),
            ajax.get(`${base}${name}/ratings`, { ...quiet, params: { page: 1 } }),
            ajax.get(`${base}${name}/questions`, { ...quiet, params: { page: 1 } }),
        ])
        if (mineToken !== token) return
        Object.assign(mine, own.data.data || {})
        Object.assign(ratings, list.data.data || {})
        Object.assign(questions, asked.data.data || {})
        authorName.value = mine.rating?.author_name || mine.default_name || ''
    } catch {
        if (mineToken === token) loadError.value = t('text.modules.store.community.load_failed')
    } finally {
        if (mineToken === token) {
            loading.value = false
            loaded.value = true
        }
    }
}

/** Load the next page of ratings or questions and append it. */
const more = async (kind) => {
    if (moreLoading.value) return
    const target = kind === 'ratings' ? ratings : questions
    moreLoading.value = kind
    try {
        const answer = await ajax.get(`${base}${nameOf(props.entry)}/${kind}`, { ...quiet, params: { page: target.page + 1 } })
        const data = answer.data.data || {}
        target.items = [...target.items, ...(data.items || [])]
        target.page = data.page || target.page + 1
        target.total = data.total ?? target.total
    } catch {
        message.error(t('text.modules.store.community.load_failed'))
    } finally {
        moreLoading.value = ''
    }
}

/** Read the first page of ratings again, so a saved rating shows in the average and the list. */
const reloadRatings = async () => {
    try {
        const answer = await ajax.get(`${base}${nameOf(props.entry)}/ratings`, { ...quiet, params: { page: 1 } })
        Object.assign(ratings, answer.data.data || {})
        // The store card behind the drawer shows the same stars, so it learns the new numbers now.
        emit('rated', { name: props.entry?.name, average: ratings.average, count: ratings.count })
    } catch {
        // The saved rating already shows in the website's own box; the list catches up on the next open.
    }
}

function openForm(kind) {
    form.value = kind
    formError.value = ''
    draft.stars = kind === 'rate' ? mine.rating?.stars || 0 : 0
    draft.body = kind === 'rate' ? mine.rating?.body || '' : ''
    draft.kind = 'feature'
}

function closeForm() {
    form.value = ''
    formError.value = ''
}

/** Send one write; on success run `done` with the answer, otherwise show the reason inside the form. */
const send = async (request, done) => {
    if (sending.value) return
    sending.value = true
    formError.value = ''
    try {
        const answer = await request()
        done(answer.data.data || {})
        sending.value = false
        closeForm()
    } catch (error) {
        formError.value = writeErrorText(error)
        sending.value = false
    }
}

const author = () => authorName.value.trim()

const sendRating = () =>
    send(
        () => ajax.put(`${base}${nameOf(props.entry)}/rating`, { stars: draft.stars, body: draft.body.trim(), author_name: author() }, quiet),
        (data) => {
            mine.rating = data.rating || null
            message.success(t('text.modules.store.community.rating_saved'))
            reloadRatings()
        },
    )

const sendQuestion = () =>
    send(
        () => ajax.post(`${base}${nameOf(props.entry)}/questions`, { body: draft.body.trim(), author_name: author() }, quiet),
        (data) => {
            if (data.question) {
                questions.items = [data.question, ...questions.items]
                questions.total += 1
            }
            message.success(t('text.modules.store.community.question_sent'))
        },
    )

const sendFeedback = () =>
    send(
        () => ajax.post(`${base}${nameOf(props.entry)}/feedback`, { kind: draft.kind, body: draft.body.trim(), author_name: author() }, quiet),
        () => message.success(t('text.modules.store.community.feedback_sent')),
    )

watch(() => props.entry?.name, load, { immediate: true })
</script>
