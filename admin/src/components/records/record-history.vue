<template>
    <section class="space-y-3" data-testid="record-history">
        <h3 class="m-0 text-base font-semibold text-ink">{{ t('text.history.title') }}</h3>

        <a-alert v-if="error" type="error" show-icon :message="t('text.history.load_failed')">
            <template #action>
                <i-button variant="default" size="sm" data-testid="history-retry" @click="retry">
                    {{ t('text.profile.retry') }}
                </i-button>
            </template>
        </a-alert>

        <a-skeleton v-if="loading && !rows.length" active :paragraph="{ rows: 4 }" />

        <p
            v-else-if="!error && !rows.length"
            class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted"
            data-testid="history-empty"
        >
            {{ t('text.history.empty') }}
        </p>

        <ul v-else-if="rows.length" class="m-0 list-none space-y-3 p-0">
            <li v-for="row in rows" :key="row.id" class="rounded-md border border-line px-3 py-2" data-testid="history-row">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <span class="font-medium text-ink">{{ row.label }}</span>
                    <span class="whitespace-nowrap text-xs text-ink-muted">{{ row.time }}</span>
                </div>
                <div v-if="row.actor" class="text-sm text-ink-muted">{{ t('text.history.by', { name: row.actor }) }}</div>
                <ul v-if="row.changes.length" class="m-0 mt-1 list-none space-y-0.5 p-0 text-sm text-ink break-words">
                    <li v-for="(change, index) in row.changes" :key="index">{{ change.text }}</li>
                </ul>
            </li>
        </ul>

        <p v-if="limitReached" class="m-0 text-sm text-ink-muted" data-testid="history-limit">{{ t('text.history.limit_note', { count: MAX_ROWS }) }}</p>
        <div v-else-if="nextBefore !== null && rows.length">
            <i-button variant="default" :loading="loading" data-testid="history-more" @click="loadMore">
                {{ t('text.history.load_more') }}
            </i-button>
        </div>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { ADMIN_TIMEZONE } from '@/config/constants'
import { getRecordHistory } from '@/libs/records'

dayjs.extend(utc)
dayjs.extend(timezone)

/**
 * History of one record (who changed what and when), newest first, from the Core record history endpoint.
 * The first page loads on mount, "Load more" follows the server cursor until there is none, and at most MAX_ROWS rows are
 * held in memory (a note says so). A response for a previous `type`/`uuid` is discarded, and the request is aborted when
 * the widget unmounts or the record changes.
 */
const props = defineProps({
    type: { type: String, required: true },
    uuid: { type: String, required: true },
})

const MAX_ROWS = 200
const { t, te } = useI18n()

const items = ref([])
const nextBefore = ref(null)
const loading = ref(false)
const error = ref(false)
let controller = null

const limitReached = computed(() => items.value.length >= MAX_ROWS && nextBefore.value !== null)

const display = (value) => (value === null || value === undefined || value === '' ? '—' : String(value))

const rows = computed(() =>
    items.value.map((row) => ({
        id: row.id,
        label: row.label && te(row.label) ? t(row.label) : t('text.history.action.unknown'),
        time: row.at && dayjs(row.at).isValid() ? dayjs(row.at).tz(ADMIN_TIMEZONE).format('DD/MM/YYYY HH:mm') : '',
        actor: row.actor?.name || '',
        changes: (Array.isArray(row.changes) ? row.changes : []).map((change) => ({
            text: t('text.history.change_from_to', {
                field: te(`text.history.field.${change.field}`) ? t(`text.history.field.${change.field}`) : change.field,
                from: display(change.from),
                to: display(change.to),
            }),
        })),
    }))
)

/**
 * Load one page and append it (or replace the list for the first page).
 *
 * @param {boolean} reset True for the first page of the current record.
 */
async function load(reset) {
    controller?.abort()
    const mine = new AbortController()
    controller = mine
    loading.value = true
    error.value = false
    try {
        const result = await getRecordHistory(props.type, props.uuid, {
            before: reset ? null : nextBefore.value,
            signal: mine.signal,
        })
        if (mine.signal.aborted) return
        items.value = reset ? result.items : [...items.value, ...result.items]
        nextBefore.value = result.nextBefore ?? null
    } catch (e) {
        if (!mine.signal.aborted) error.value = true
    } finally {
        if (controller === mine) loading.value = false
    }
}

const loadMore = () => load(false)
const retry = () => load(items.value.length === 0)

function reload() {
    items.value = []
    nextBefore.value = null
    load(true)
}

watch(() => [props.type, props.uuid], reload, { immediate: true })

onBeforeUnmount(() => {
    controller?.abort()
    controller = null
})
</script>
