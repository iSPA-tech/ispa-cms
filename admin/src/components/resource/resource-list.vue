<script setup>
/**
 * Generic resource list screen rendered from a resource schema's `list` part: search, filters, server-paginated table
 * with display widgets, and loading / error / empty states. Emits `open(record)` when the link column is clicked.
 *
 * Schema extras: `list.selectable` adds row checkboxes; `list.rowActions` names `actions` entries shown as buttons on
 * each row (their `visible` condition is evaluated per row); `list.bulkActions` names `bulk` actions offered for the
 * selected rows in a small bar (payload `{uuids}`). Action keys: `danger` and `confirm` (confirm before running),
 * `after` (client hook run once after success) and `refresh` (any target reloads the list here).
 * Search, filters and page live in the route query (`q`, `page`, one key per filter) through `useListQueryState`, so a
 * reload, Back and a shared link restore the list; values a filter does not offer are ignored.
 * The bulk bar is deliberately not `i-bulk-action`: that component brings its own modal, preview and selection API
 * for the legacy pages and would need large changes to take schema actions.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { useListQueryState } from '@/hooks/useListQueryState'
import ajax from '@/ajax'
import { runActionHook, serverMessage } from '@/utils/resource/action-hooks'
import { evaluateCondition } from '@/utils/resource/conditions'
import { resolvePath, toAjaxPath } from '@/utils/resource/endpoints'
import { getListAdapter } from '@/utils/resource/list-adapters'
import { listStateSchema } from '@/utils/resource/list-schema'
import { getPath, safeEntries } from '@/utils/resource/field-path'
import { customLabel, filterParams, filterSlots, resolveOptions } from '@/utils/resource/custom-fields'
import { resolveWidget } from './widgets'

const SEARCH_DEBOUNCE_MS = 300
const SEARCH_MAX_LENGTH = 100

const props = defineProps({
    /** Full resource schema from the schema endpoint. */
    schema: { type: Object, required: true },
})
const emit = defineEmits(['open'])
const { t, locale } = useI18n()
/** Active locale code; specs that mock `useI18n` without a locale fall back to Vietnamese. */
const activeLocale = () => String(locale?.value ?? locale ?? 'vi')

const list = computed(() => props.schema?.list || {})
const { state: queryState, commit: commitQuery } = useListQueryState(listStateSchema(props.schema))
const search = ref(queryState.q || '')
const filters = reactive({})
const rows = ref([])
const loading = ref(false)
const loaded = ref(false)
const failed = ref(false)
const selectedKeys = ref([])
const acting = ref(false)
const pagination = reactive({ current: queryState.page || 1, pageSize: 20, total: 0, showSizeChanger: true })

let sequence = 0
let controller = null
let timer = null

/** Translate a label when it is a key, leaving empty values untouched. */
const label = (value) => (value ? t(value) : '')
/** Label of a schema entry: literal per-locale text for a custom field, else the translated key. */
const entryLabel = (entry) => customLabel(entry, activeLocale()) ?? label(entry.label)
/** Options of an entry with literal option labels resolved and keys translated. */
const entryOptions = (entry) => resolveOptions(entry, activeLocale()).map((o) => ({ ...o, label: o.literal ? o.label : label(o.label) }))
/** Schema filters with unsafe field paths dropped. */
const schemaFilters = computed(() => safeEntries(list.value.filters))

const searchConfig = computed(() => list.value.search || null)
const searchPlaceholder = computed(() => (searchConfig.value?.placeholder ? t(searchConfig.value.placeholder) : t('text.resource.search')))

/** Page text overrides declared by the schema (`list.labels`): `empty` and `load_failed` are used here. */
const labels = computed(() => list.value.labels || {})

// The "all" entry is labelled by the filter's schema `allLabel`; it selects no value and sends no param.
const filterDefs = computed(() =>
    schemaFilters.value.map((filter) => {
        // A boolean custom filter offers no options of its own: yes / no.
        const base = filter.type === 'boolean' && !(filter.options || []).length ? [{ value: '1', label: t('text.yes') }, { value: '0', label: t('text.no') }] : entryOptions(filter)
        return {
            field: filter.field,
            label: entryLabel(filter),
            range: Boolean(filter.range),
            multiple: Boolean(filter.multiple),
            date: filter.type === 'date',
            slots: filterSlots(filter),
            options: filter.multiple ? base : [{ value: '', label: t(filter.allLabel || 'text.resource.all') }, ...base],
        }
    }),
)
/** One slot value from the address: a list slot keeps the offered values, a string slot only an offered value. */
const slotFromQuery = (filter, slot) => {
    const options = filter.options || []
    const offered = (value) => !options.length || options.some((option) => String(option.value) === String(value))
    const raw = queryState[slot.key]
    if (slot.kind === 'list') return (Array.isArray(raw) ? raw : []).filter((value) => value && offered(value))
    const value = raw || ''
    return value && (slot.part !== 'value' || offered(value)) ? value : ''
}
/** All slot values of the filters, from the address. */
const slotValues = () => Object.fromEntries(schemaFilters.value.flatMap((filter) => filterSlots(filter).map((slot) => [slot.key, slotFromQuery(filter, slot)])))
Object.assign(filters, slotValues())
/** Options of a column: its own, else those of the filter on the same field (the schema does not repeat them). */
const columnOptions = (column) => column.options || schemaFilters.value.find((filter) => filter.field === column.field)?.options || []

/** Index of the link column: the one marked `link: 'detail'`, otherwise the first. */
const linkIndex = computed(() => {
    const index = safeEntries(list.value.columns).findIndex((column) => column.link === 'detail')
    return index >= 0 ? index : 0
})

/** Declared actions by name. */
const actionsByName = computed(() => Object.fromEntries((props.schema?.actions || []).map((action) => [action.name, action])))
const pick = (names) => (names || []).map((name) => actionsByName.value[name]).filter(Boolean)
/** A schema that says the user lacks `manage` (`abilities.manage === false`) is read-only: no row or bulk actions. */
const readOnly = computed(() => props.schema?.abilities?.manage === false)
const rowActions = computed(() => (readOnly.value ? [] : pick(list.value.rowActions).filter((action) => !action.bulk)))
const bulkActions = computed(() => (readOnly.value ? [] : pick(list.value.bulkActions)))
const selectable = computed(() => !readOnly.value && (Boolean(list.value.selectable) || bulkActions.value.length > 0))

/** Row actions of one record, filtered by their `visible` condition. */
const actionsFor = (record) => rowActions.value.filter((action) => !action.visible || evaluateCondition(action.visible, record))

const rowSelection = computed(() =>
    selectable.value
        ? { selectedRowKeys: selectedKeys.value, onChange: (keys) => (selectedKeys.value = keys) }
        : undefined,
)

const columns = computed(() => [
    ...safeEntries(list.value.columns).map((column, index) => ({
        key: column.field,
        dataIndex: column.field,
        title: entryLabel(column),
        isLink: index === linkIndex.value,
        width: column.width,
        widget: resolveWidget(column.widget || column.type),
        field: {
            ...column,
            ...(column.custom && column.type === 'date' ? { date_only: true } : {}),
            options: columnOptions(column).length ? entryOptions({ options: columnOptions(column) }) : [],
        },
    })),
    ...(rowActions.value.length ? [{ key: '__actions', title: '', isActions: true, width: 160 }] : []),
])

const rowKey = (record) => record.uuid ?? record.id

/** Abort any in-flight request and invalidate its result. */
function cancelInFlight() {
    sequence += 1
    controller?.abort()
    controller = null
}

/** Request the current page; responses older than the latest request are ignored. */
async function load() {
    cancelInFlight()
    const current = sequence
    controller = new AbortController()
    loading.value = true
    try {
        const adapter = getListAdapter(list.value.endpoint?.adapter || 'ispa-list-v1')
        const params = adapter.toParams({
            page: pagination.current,
            perPage: pagination.pageSize,
            search: search.value,
            searchParam: searchConfig.value?.param || 'q',
            filters: Object.assign({}, ...schemaFilters.value.map((filter) => filterParams(filter, filters))),
        })
        const res = await ajax.get(toAjaxPath(list.value.endpoint?.path ?? list.value.endpoint?.url), { params, signal: controller.signal, silent: true })
        if (current !== sequence) return
        const result = adapter.fromResponse(res.data)
        // A delete may have emptied the last page: step back to the new last page once.
        if (!result.rows.length && result.page > 1 && result.lastPage < result.page) {
            pagination.current = Math.max(1, result.lastPage)
            return load()
        }
        rows.value = result.rows
        pagination.current = result.page
        pagination.pageSize = result.perPage
        pagination.total = result.total
        if (queryState.page !== result.page) commitState()
        failed.value = false
    } catch {
        if (current !== sequence) return
        failed.value = true
    }
    loaded.value = true
    loading.value = false
}

/** Write the current search, filters and page into the route query (`router.replace`, so Back leaves the page). */
function commitState() {
    queryState.q = search.value
    queryState.page = pagination.current
    for (const slot of schemaFilters.value.flatMap(filterSlots)) if (slot.key in queryState) queryState[slot.key] = filters[slot.key]
    return commitQuery()
}

/** Go back to page 1 and reload; the selection belongs to the old result set, so it is cleared. */
function reloadFirstPage() {
    pagination.current = 1
    selectedKeys.value = []
    commitState()
    load()
}

/** Button text of an action: `label_from.on` / `.off` chosen by the record's field (`record` omitted for bulk), else `label`. */
function actionLabel(action, record = null) {
    const source = action.label_from
    if (source && record) return t(record[source.field] ? source.on : source.off)
    return t(action.label)
}

/** Whether an action asks for confirmation: it says so, or it is destructive. */
const needsConfirm = (action) => Boolean(action.confirm || action.danger)

/** Confirmation text of an action; a destructive action without its own text falls back to its label. */
const confirmText = (action, record = null) => (action.confirm ? t(action.confirm) : actionLabel(action, record))

/** Button variant of an action. */
const variantOf = (action) => (action.danger ? 'danger' : undefined)

/**
 * Run a row or bulk action: request, success message, client hook (once, only after success) and a list reload.
 * A bulk action sends `{uuids}` and clears the selection on success; a failure keeps the selection and shows the
 * server message (for example a 409 "has children" guard) or the generic failure text.
 */
async function runAction(action, record = null) {
    if (acting.value) return
    acting.value = true
    const bulk = Boolean(action.bulk)
    const uuids = [...selectedKeys.value]
    try {
        const path = toAjaxPath(bulk ? action.endpoint.path : resolvePath(action.endpoint.path, record || {}))
        const data = bulk ? { ...(action.payload || {}), uuids } : action.payload
        const response = await ajax.request({ url: path, method: (action.endpoint.method || 'POST').toLowerCase(), data, silent: true })
        message.success(t(action.success_message || 'text.resource.saved'))
        if (bulk) selectedKeys.value = []
        await runActionHook(action.after, { action, record, uuids: bulk ? uuids : undefined, response })
        await load()
    } catch (error) {
        message.error(serverMessage(error) || t('text.resource.save_failed'))
    } finally {
        acting.value = false
    }
}

/** Handle table paging events. */
function onTableChange(next) {
    pagination.current = next.current
    pagination.pageSize = next.pageSize
    commitState()
    load()
}

/** Follow an address change made elsewhere (Back, a shared link): take the new state and reload once. */
watch(
    () => JSON.stringify(queryState),
    () => {
        const next = slotValues()
        const same =
            (queryState.q || '') === search.value &&
            (queryState.page || 1) === pagination.current &&
            Object.keys(next).every((key) => JSON.stringify(filters[key]) === JSON.stringify(next[key]))
        if (same) return
        clearTimeout(timer)
        timer = null
        search.value = queryState.q || ''
        pagination.current = queryState.page || 1
        Object.assign(filters, next)
        selectedKeys.value = []
        load()
    },
)

watch(search, (value) => {
    clearTimeout(timer)
    timer = null
    if (value === (queryState.q || '')) return
    timer = setTimeout(() => {
        timer = null
        reloadFirstPage()
    }, SEARCH_DEBOUNCE_MS)
})

/** Below this width the table is replaced by stacked cards so status and actions never need a sideways scroll. */
const COMPACT_WIDTH = 767
const compact = ref(false)
let compactQuery = null
const syncCompact = () => (compact.value = !!compactQuery?.matches)

onMounted(() => {
    if (typeof window !== 'undefined' && window.matchMedia) {
        compactQuery = window.matchMedia(`(max-width: ${COMPACT_WIDTH}px)`)
        syncCompact()
        compactQuery.addEventListener?.('change', syncCompact)
    }
})

onBeforeUnmount(() => {
    compactQuery?.removeEventListener?.('change', syncCompact)
    clearTimeout(timer)
    timer = null
    cancelInFlight()
})

load()
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <a-input-search
                v-if="searchConfig"
                v-model:value="search"
                class="w-full sm:max-w-xs"
                allow-clear
                :maxlength="SEARCH_MAX_LENGTH"
                :placeholder="searchPlaceholder"
            />
            <template v-for="filter in filterDefs" :key="filter.field">
                <span v-if="filter.range" class="flex w-full flex-wrap items-center gap-1 sm:w-auto" :data-filter="filter.field">
                    <a-date-picker
                        v-if="filter.date"
                        v-model:value="filters[filter.slots[0].key]"
                        value-format="YYYY-MM-DD"
                        format="DD/MM/YYYY"
                        class="w-full sm:w-auto"
                        :placeholder="t('text.resource.range_from', { label: filter.label })"
                        @change="reloadFirstPage"
                    />
                    <a-input-number
                        v-else
                        v-model:value="filters[filter.slots[0].key]"
                        class="!w-full sm:!w-40"
                        :placeholder="t('text.resource.range_from', { label: filter.label })"
                        @change="reloadFirstPage"
                    />
                    <span aria-hidden="true">–</span>
                    <a-date-picker
                        v-if="filter.date"
                        v-model:value="filters[filter.slots[1].key]"
                        value-format="YYYY-MM-DD"
                        format="DD/MM/YYYY"
                        class="w-full sm:w-auto"
                        :placeholder="t('text.resource.range_to', { label: filter.label })"
                        @change="reloadFirstPage"
                    />
                    <a-input-number
                        v-else
                        v-model:value="filters[filter.slots[1].key]"
                        class="!w-full sm:!w-40"
                        :placeholder="t('text.resource.range_to', { label: filter.label })"
                        @change="reloadFirstPage"
                    />
                </span>
                <a-select
                    v-else
                    v-model:value="filters[filter.field]"
                    class="w-full sm:w-44"
                    :mode="filter.multiple ? 'multiple' : undefined"
                    :options="filter.options"
                    :placeholder="filter.label"
                    @change="reloadFirstPage"
                />
            </template>
        </div>

        <a-alert v-if="failed" type="error" show-icon :message="t(labels.load_failed || 'text.resource.load_failed')">
            <template #action>
                <i-button size="sm" @click="load">{{ t('text.resource.retry') }}</i-button>
            </template>
        </a-alert>
        <div
            v-if="selectedKeys.length && bulkActions.length"
            class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-gray-200 bg-gray-50 px-3 py-2"
            data-test="bulk-bar"
        >
            <span class="text-xs text-gray-600">{{ t('text.selected') }}: {{ selectedKeys.length }}</span>
            <div class="flex flex-wrap items-center gap-2">
                <template v-for="action in bulkActions" :key="action.name">
                    <a-popconfirm
                        v-if="needsConfirm(action)"
                        :title="confirmText(action)"
                        :ok-text="t(action.label)"
                        :cancel-text="t('text.resource.cancel')"
                        @confirm="runAction(action)"
                    >
                        <i-button size="sm" :data-action="action.name" :variant="variantOf(action)" :disabled="acting">{{ t(action.label) }}</i-button>
                    </a-popconfirm>
                    <i-button v-else size="sm" :data-action="action.name" :variant="variantOf(action)" :disabled="acting" @click="runAction(action)">
                        {{ t(action.label) }}
                    </i-button>
                </template>
            </div>
        </div>
        <a-skeleton v-if="!loaded" active />
        <div v-else-if="compact" class="space-y-2" data-testid="resource-cards">
            <p v-if="!rows.length" class="py-6 text-center text-sm text-gray-500">{{ t(labels.empty || 'text.resource.empty') }}</p>
            <div
                v-for="record in rows"
                :key="rowKey(record)"
                class="space-y-2 rounded-sm border border-gray-200 bg-white p-3"
                :data-testid="`resource-card-${rowKey(record)}`"
            >
                <label v-if="selectable" class="flex items-center gap-2 text-xs text-gray-600">
                    <input
                        type="checkbox"
                        :checked="selectedKeys.includes(rowKey(record))"
                        @change="selectedKeys = selectedKeys.includes(rowKey(record)) ? selectedKeys.filter((k) => k !== rowKey(record)) : [...selectedKeys, rowKey(record)]"
                    />
                    {{ t('text.selected') }}
                </label>
                <template v-for="column in columns.filter((c) => !c.isActions)" :key="column.key">
                    <button v-if="column.isLink" type="button" class="resource-link block break-words text-left text-sm font-medium text-blue-700 underline" @click="emit('open', record)">
                        {{ getPath(record, column.dataIndex) }}
                    </button>
                    <div v-else class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span class="text-gray-500">{{ column.title }}</span>
                        <component :is="column.widget" :value="getPath(record, column.dataIndex)" :field="column.field" :record="record" />
                    </div>
                </template>
                <div v-if="actionsFor(record).length" class="flex flex-wrap gap-1">
                    <template v-for="action in actionsFor(record)" :key="action.name">
                        <a-popconfirm
                            v-if="needsConfirm(action)"
                            :title="confirmText(action, record)"
                            :ok-text="actionLabel(action, record)"
                            :cancel-text="t('text.resource.cancel')"
                            @confirm="runAction(action, record)"
                        >
                            <i-button size="sm" :data-action="action.name" :variant="variantOf(action)" :disabled="acting">{{ actionLabel(action, record) }}</i-button>
                        </a-popconfirm>
                        <i-button v-else size="sm" :data-action="action.name" :variant="variantOf(action)" :disabled="acting" @click="runAction(action, record)">
                            {{ actionLabel(action, record) }}
                        </i-button>
                    </template>
                </div>
            </div>
            <a-pagination
                v-if="pagination.total > pagination.pageSize"
                size="small"
                simple
                :current="pagination.current"
                :page-size="pagination.pageSize"
                :total="pagination.total"
                :show-size-changer="false"
                data-testid="resource-cards-pagination"
                @change="(page) => onTableChange({ current: page, pageSize: pagination.pageSize })"
            />
        </div>
        <a-table
            v-else
            data-testid="resource-table"
            size="small"
            :row-key="rowKey"
            :columns="columns"
            :data-source="rows"
            :loading="loading"
            :pagination="pagination"
            :row-selection="rowSelection"
            :scroll="{ x: 800 }"
            :locale="{ emptyText: t(labels.empty || 'text.resource.empty') }"
            @change="onTableChange"
        >
            <template #bodyCell="{ column, record }">
                <button v-if="column.isLink" type="button" class="resource-link break-words text-left font-medium text-blue-700 underline" @click="emit('open', record)">
                    {{ getPath(record, column.dataIndex) }}
                </button>
                <div v-else-if="column.isActions" class="flex flex-wrap gap-1">
                    <template v-for="action in actionsFor(record)" :key="action.name">
                        <a-popconfirm
                            v-if="needsConfirm(action)"
                            :title="confirmText(action, record)"
                            :ok-text="actionLabel(action, record)"
                            :cancel-text="t('text.resource.cancel')"
                            @confirm="runAction(action, record)"
                        >
                            <i-button size="sm" :data-action="action.name" :variant="variantOf(action)" :disabled="acting">{{ actionLabel(action, record) }}</i-button>
                        </a-popconfirm>
                        <i-button v-else size="sm" :data-action="action.name" :variant="variantOf(action)" :disabled="acting" @click="runAction(action, record)">
                            {{ actionLabel(action, record) }}
                        </i-button>
                    </template>
                </div>
                <component :is="column.widget" v-else :value="getPath(record, column.dataIndex)" :field="column.field" :record="record" />
            </template>
        </a-table>
    </div>
</template>
