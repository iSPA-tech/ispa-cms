<template>
    <section class="space-y-3" data-testid="record-notes">
        <h3 class="m-0 text-base font-semibold text-ink">{{ t('text.notes.title') }}</h3>

        <div v-if="canNote" class="space-y-2" data-testid="note-form">
            <div v-if="chips.length" class="flex flex-wrap gap-1.5" data-testid="mention-chips">
                <span
                    v-for="chip in chips"
                    :key="chip.uuid"
                    class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-sm text-ink"
                    data-testid="mention-chip"
                >
                    @{{ chip.name }}
                    <button
                        type="button"
                        class="border-0 bg-transparent p-0 leading-none text-ink-muted"
                        data-testid="mention-chip-remove"
                        :aria-label="chip.name"
                        @click="removeChip(chip.uuid)"
                    >
                        &times;
                    </button>
                </span>
            </div>

            <a-textarea
                v-model:value="body"
                data-testid="note-input"
                :placeholder="t('text.notes.placeholder')"
                :maxlength="MAX_BODY"
                :rows="3"
                :disabled="loading"
                show-count
            />
            <p v-if="formError" class="m-0 text-sm text-red-700" role="alert" data-testid="note-error">{{ formError }}</p>

            <div v-if="pickerOpen" class="rounded-md border border-line p-2" data-testid="mention-picker">
                <a-input
                    ref="searchInput"
                    v-model:value="query"
                    data-testid="mention-search"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="record-notes-mention-list"
                    :aria-expanded="results.length > 0"
                    :aria-activedescendant="results.length ? optionId(activeIndex) : undefined"
                    :placeholder="t('text.notes.mention')"
                    @keydown="onSearchKey"
                />
                <ul
                    v-if="results.length"
                    id="record-notes-mention-list"
                    role="listbox"
                    class="m-0 mt-1 list-none p-0"
                >
                    <li
                        v-for="(person, index) in results"
                        :id="optionId(index)"
                        :key="person.uuid"
                        role="option"
                        :aria-selected="index === activeIndex"
                        class="cursor-pointer rounded px-2 py-1 text-sm text-ink"
                        :class="{ 'bg-surface-sunken': index === activeIndex }"
                        data-testid="mention-option"
                        @mousedown.prevent
                        @click="choose(person)"
                    >
                        {{ person.name }}
                    </li>
                </ul>
                <p v-else-if="searched" class="m-0 mt-1 px-2 text-sm text-ink-muted" data-testid="mention-empty">
                    {{ t('text.notes.mention_empty') }}
                </p>
            </div>

            <a-alert v-if="ignored > 0" type="warning" show-icon data-testid="notes-ignored" :message="t('text.notes.ignored_mentions', { count: ignored })" />

            <div class="flex flex-wrap items-center gap-2">
                <i-button variant="primary" :loading="loading" :disabled="loading" data-testid="note-submit" @click="submit">
                    {{ t('text.notes.add') }}
                </i-button>
                <i-button variant="default" :disabled="loading" data-testid="mention-button" @click="togglePicker">
                    @ {{ t('text.notes.mention') }}
                </i-button>
            </div>
        </div>

        <a-alert v-if="error" type="error" show-icon :message="t('text.notes.load_failed')">
            <template #action>
                <i-button variant="default" size="sm" data-testid="notes-retry" @click="retry">
                    {{ t('text.profile.retry') }}
                </i-button>
            </template>
        </a-alert>

        <a-skeleton v-if="listLoading && !rows.length" active :paragraph="{ rows: 3 }" />

        <p
            v-else-if="!error && !rows.length"
            class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted"
            data-testid="notes-empty"
        >
            {{ t('text.notes.empty') }}
        </p>

        <ul v-else-if="rows.length" class="m-0 list-none space-y-3 p-0">
            <li v-for="row in rows" :key="row.uuid" class="rounded-md border border-line px-3 py-2" data-testid="note-row">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <span class="font-medium text-ink">{{ row.author }}</span>
                    <span class="whitespace-nowrap text-xs text-ink-muted">
                        {{ row.time }}<template v-if="row.edited"> · {{ t('text.notes.edited') }}</template>
                    </span>
                </div>

                <template v-if="editing === row.uuid">
                    <a-textarea
                        v-model:value="editBody"
                        class="mt-1"
                        data-testid="note-edit-input"
                        :maxlength="MAX_BODY"
                        :rows="3"
                        :disabled="editLoading"
                        show-count
                    />
                    <p v-if="editError" class="m-0 mt-1 text-sm text-red-700" role="alert">{{ editError }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <i-button variant="primary" :loading="editLoading" :disabled="editLoading" data-testid="note-edit-save" @click="saveEdit(row)">
                            {{ t('nav.b.save') }}
                        </i-button>
                        <i-button variant="default" :disabled="editLoading" data-testid="note-edit-cancel" @click="cancelEdit">
                            {{ t('nav.b.cancel') }}
                        </i-button>
                    </div>
                </template>
                <p v-else class="m-0 mt-1 whitespace-pre-wrap break-words text-sm text-ink" data-testid="note-body">{{ row.body }}</p>

                <div v-if="editing !== row.uuid && (row.canEdit || row.canDelete)" class="mt-2 flex flex-wrap gap-2">
                    <i-button v-if="row.canEdit" variant="default" data-testid="note-edit" @click="startEdit(row)">
                        {{ t('text.notes.edit') }}
                    </i-button>
                    <a-popconfirm v-if="row.canDelete" :title="t('text.notes.delete_confirm')" @confirm="remove(row)">
                        <i-button variant="default" data-testid="note-delete">{{ t('nav.b.delete') }}</i-button>
                    </a-popconfirm>
                </div>
            </li>
        </ul>

        <p v-if="limitReached" class="m-0 text-sm text-ink-muted" data-testid="notes-limit">{{ t('text.notes.limit_note', { count: MAX_ROWS }) }}</p>
        <div v-else-if="nextBefore !== null && rows.length">
            <i-button variant="default" :loading="listLoading" data-testid="notes-more" @click="loadMore">
                {{ t('text.history.load_more') }}
            </i-button>
        </div>
    </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { ADMIN_TIMEZONE } from '@/config/constants'
import { addRecordNote, deleteRecordNote, getRecordNotes, searchMentionable, updateRecordNote } from '@/libs/records'

dayjs.extend(utc)
dayjs.extend(timezone)

/**
 * Internal notes of one record: list with "load more" (at most MAX_ROWS rows held), a composer with an @mention picker,
 * in-place edit of own notes and delete with a confirmation. The mention list is the uuids chosen in the picker (chips), never
 * parsed from the text; the text only carries the `@Name` for display. Every request is aborted and the search debounce timer
 * cleared when the widget unmounts or the record changes.
 */
const props = defineProps({
    type: { type: String, required: true },
    uuid: { type: String, required: true },
    canNote: { type: Boolean, default: true },
})

const MAX_BODY = 4000
const MAX_ROWS = 200
const MAX_MENTIONS = 10
const MIN_QUERY = 2
const DEBOUNCE_MS = 250
const { t } = useI18n()

// List state.
const items = ref([])
const nextBefore = ref(null)
const listLoading = ref(false)
const error = ref(false)
let listController = null

// Composer state.
const body = ref('')
const chips = ref([])
const loading = ref(false)
const formError = ref('')
const ignored = ref(0)

// Picker state.
const pickerOpen = ref(false)
const openedByAt = ref(false)
const query = ref('')
const results = ref([])
const searched = ref(false)
const activeIndex = ref(0)
const searchInput = ref(null)
let searchTimer = null
let searchController = null

// Edit state.
const editing = ref(null)
const editBody = ref('')
const editLoading = ref(false)
const editError = ref('')

const limitReached = computed(() => items.value.length >= MAX_ROWS && nextBefore.value !== null)

const rows = computed(() =>
    items.value.map((n) => ({
        uuid: n.uuid,
        body: n.body || '',
        author: n.author?.name || '',
        mentions: Array.isArray(n.mentions) ? n.mentions : [],
        time: n.created_at && dayjs(n.created_at).isValid() ? dayjs(n.created_at).tz(ADMIN_TIMEZONE).format('DD/MM/YYYY HH:mm') : '',
        edited: !!n.edited_at,
        canEdit: !!n.can_edit,
        canDelete: !!n.can_delete,
    }))
)

const optionId = (index) => `record-notes-mention-${index}`

/**
 * Load one page of notes.
 *
 * @param {boolean} reset True for the first page of the current record.
 */
async function load(reset) {
    listController?.abort()
    const mine = new AbortController()
    listController = mine
    listLoading.value = true
    error.value = false
    try {
        const result = await getRecordNotes(props.type, props.uuid, { before: reset ? null : nextBefore.value, signal: mine.signal })
        if (mine.signal.aborted) return
        items.value = reset ? result.items : [...items.value, ...result.items]
        nextBefore.value = result.nextBefore ?? null
    } catch (e) {
        if (!mine.signal.aborted) error.value = true
    } finally {
        if (listController === mine) listLoading.value = false
    }
}

const loadMore = () => load(false)
const retry = () => load(items.value.length === 0)

/** Stop the debounce timer and any search in flight. */
function stopSearch() {
    if (searchTimer !== null) {
        clearTimeout(searchTimer)
        searchTimer = null
    }
    searchController?.abort()
    searchController = null
}

/** Close the picker and forget its search. */
function closePicker() {
    stopSearch()
    pickerOpen.value = false
    openedByAt.value = false
    query.value = ''
    results.value = []
    searched.value = false
    activeIndex.value = 0
}

/**
 * Open the picker and focus its search box.
 *
 * @param {boolean} byAt True when it was opened by typing `@` in the text.
 */
async function openPicker(byAt) {
    pickerOpen.value = true
    openedByAt.value = byAt
    await nextTick()
    searchInput.value?.focus?.()
}

const togglePicker = () => (pickerOpen.value ? closePicker() : openPicker(false))

watch(body, (value, old) => {
    if (!pickerOpen.value && typeof value === 'string' && value.endsWith('@') && !(old || '').endsWith('@') && (value.length === 1 || /\s/.test(value[value.length - 2]))) {
        openPicker(true)
    }
})

watch(query, (value) => {
    stopSearch()
    const q = (value || '').trim()
    if (q.length < MIN_QUERY) {
        results.value = []
        searched.value = false
        return
    }
    searchTimer = setTimeout(async () => {
        searchTimer = null
        const mine = new AbortController()
        searchController = mine
        try {
            const found = await searchMentionable(props.type, props.uuid, q, { signal: mine.signal })
            if (mine.signal.aborted) return
            results.value = found
            searched.value = true
            activeIndex.value = 0
        } catch (e) {
            if (!mine.signal.aborted) {
                results.value = []
                searched.value = true
            }
        }
    }, DEBOUNCE_MS)
})

/**
 * Keyboard handling of the picker search box: arrows move, Enter chooses, Escape closes.
 *
 * @param {KeyboardEvent} event
 */
function onSearchKey(event) {
    if (event.key === 'Escape') {
        event.preventDefault()
        closePicker()
    } else if (event.key === 'ArrowDown' && results.value.length) {
        event.preventDefault()
        activeIndex.value = (activeIndex.value + 1) % results.value.length
    } else if (event.key === 'ArrowUp' && results.value.length) {
        event.preventDefault()
        activeIndex.value = (activeIndex.value - 1 + results.value.length) % results.value.length
    } else if (event.key === 'Enter') {
        event.preventDefault()
        if (results.value[activeIndex.value]) choose(results.value[activeIndex.value])
    }
}

/**
 * Take a person from the picker: insert `@Name` into the text and keep the uuid as a chip.
 *
 * @param {{uuid: string, name: string}} person
 */
function choose(person) {
    if (!chips.value.some((c) => c.uuid === person.uuid)) {
        if (chips.value.length >= MAX_MENTIONS) {
            closePicker()
            return
        }
        chips.value = [...chips.value, { uuid: person.uuid, name: person.name }]
    }
    let text = body.value
    if (openedByAt.value && text.endsWith('@')) {
        text = text.slice(0, -1)
    } else if (text && !/\s$/.test(text)) {
        text += ' '
    }
    body.value = `${text}@${person.name} `
    closePicker()
}

const removeChip = (uuid) => {
    chips.value = chips.value.filter((c) => c.uuid !== uuid)
}

/**
 * The message of a failed write: the server's first validation message under a 422, a generic line otherwise.
 *
 * @param {any} e Axios error.
 * @returns {string}
 */
function errorText(e) {
    const status = e?.response?.status
    const first = Object.values(e?.response?.data?.errors || {})[0]
    if (status === 422) return (Array.isArray(first) ? first[0] : first) || t('text.notes.too_long')
    return e?.response?.data?.message || t('text.notes.load_failed')
}

/** Post the composer's note. A second click while the request is in flight does nothing. */
async function submit() {
    if (loading.value) return
    const text = body.value.trim()
    if (!text) return
    loading.value = true
    formError.value = ''
    ignored.value = 0
    try {
        const result = await addRecordNote(props.type, props.uuid, { body: text, mentions: chips.value.map((c) => c.uuid) })
        if (result.note?.uuid) items.value = [result.note, ...items.value.filter((n) => n.uuid !== result.note.uuid)]
        body.value = ''
        chips.value = []
        ignored.value = result.ignored || 0
        closePicker()
        message.success(t('text.notes.saved'))
    } catch (e) {
        formError.value = errorText(e)
    } finally {
        // The composer stays on screen, so the guard is released on success as well as on error.
        loading.value = false
    }
}

/**
 * Start editing one of the user's own notes.
 *
 * @param {{uuid: string, body: string}} row
 */
function startEdit(row) {
    editing.value = row.uuid
    editBody.value = row.body
    editError.value = ''
}

const cancelEdit = () => {
    editing.value = null
    editError.value = ''
}

/**
 * Save the edit; the note keeps only the mentions whose `@Name` is still in the text.
 *
 * @param {{uuid: string, mentions: Array<{uuid: string, name: string}>}} row
 */
async function saveEdit(row) {
    if (editLoading.value) return
    const text = editBody.value.trim()
    if (!text) return
    editLoading.value = true
    editError.value = ''
    try {
        const mentions = row.mentions.filter((m) => text.includes(`@${m.name}`)).map((m) => m.uuid)
        const result = await updateRecordNote(props.type, props.uuid, row.uuid, { body: text, mentions })
        if (result.note?.uuid) items.value = items.value.map((n) => (n.uuid === row.uuid ? result.note : n))
        ignored.value = result.ignored || 0
        editing.value = null
        message.success(t('text.notes.saved'))
    } catch (e) {
        editError.value = errorText(e)
    } finally {
        editLoading.value = false
    }
}

/**
 * Delete a note after the popconfirm.
 *
 * @param {{uuid: string}} row
 */
async function remove(row) {
    try {
        await deleteRecordNote(props.type, props.uuid, row.uuid)
        items.value = items.value.filter((n) => n.uuid !== row.uuid)
        message.success(t('text.notes.deleted'))
    } catch (e) {
        message.error(errorText(e))
    }
}

watch(
    () => [props.type, props.uuid],
    () => {
        closePicker()
        editing.value = null
        items.value = []
        nextBefore.value = null
        load(true)
    },
    { immediate: true }
)

onBeforeUnmount(() => {
    stopSearch()
    listController?.abort()
    listController = null
})
</script>
