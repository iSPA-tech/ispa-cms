<template>
    <breadcrumbs :items="[{ icon: 'bell-badge', title: t('text.notifications.title'), text: t('text.notifications.title') }]" />

    <div class="w-full pb-6">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <a-radio-group :value="state.filter || 'all'" button-style="solid" @change="changeFilter">
                    <a-radio-button value="all" data-testid="filter-all">{{ t('text.notifications.filter_all') }}</a-radio-button>
                    <a-radio-button value="unread" data-testid="filter-unread">{{ t('text.notifications.filter_unread') }}</a-radio-button>
                </a-radio-group>
                <i-button v-if="unreadCount > 0" variant="default" data-testid="mark-all" @click="markAll">
                    {{ t('text.notifications.mark_all_read') }}
                </i-button>
            </div>

            <a-alert v-if="error" type="error" show-icon :message="t('text.notifications.load_failed')">
                <template #action>
                    <i-button variant="default" size="sm" data-testid="retry" @click="load">
                        {{ t('text.profile.retry') }}
                    </i-button>
                </template>
            </a-alert>

            <a-skeleton v-if="loading && !items.length" active :paragraph="{ rows: 5 }" />

            <p
                v-else-if="!error && !items.length"
                class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted"
                data-testid="notifications-empty"
            >
                {{ t('text.notifications.empty') }}
            </p>

            <div v-else-if="items.length" class="overflow-x-auto">
                <a-table
                    :columns="columns"
                    :data-source="rows"
                    :loading="loading"
                    :pagination="pagination"
                    :custom-row="customRow"
                    row-key="id"
                    size="middle"
                    @change="changePage"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'type'">
                            <span :class="record.read_at ? 'text-ink-muted' : 'font-semibold text-ink'">{{ record.label }}</span>
                            <a-tag v-if="!record.read_at" color="blue" class="ml-2">{{ t('text.notifications.filter_unread') }}</a-tag>
                            <div v-if="record.detail" class="mt-1 text-sm text-ink-muted" data-testid="notification-detail">{{ record.detail }}</div>
                        </template>
                        <template v-else-if="column.key === 'time'">
                            <span class="whitespace-nowrap text-ink-muted">{{ record.time }}</span>
                        </template>
                    </template>
                </a-table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import dayjs from 'dayjs'
import { adminNotificationTypes } from '@/config/admin-modules'
import { useListQueryState } from '@/hooks/useListQueryState'
import { getNotifications, markAllNotificationsRead, markNotificationRead } from '@/libs/notifications'
import { announceNotificationsChanged, resolveNotificationDetail, resolveNotificationTarget } from '@/utils/notifications'

// Every notification of the signed-in person ("View all" from the header bell). Filter (all / unread) and page live
// in the route query, so reload and Back restore them. Server pagination, one request in flight (a newer load aborts
// the older one, and the request is aborted on unmount). A row opens its target like the bell: marked read first.
const PER_PAGE = 20
const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const { state, commit } = useListQueryState({ filter: 'string', page: 'int' }, { filter: '', page: 1 })

const items = ref([])
const total = ref(0)
const unreadCount = ref(0)
const loading = ref(false)
const error = ref(false)
let controller = null
let alive = true

const columns = computed(() => [
    { key: 'type', title: t('text.notifications.column_type') },
    { key: 'time', title: t('text.notifications.column_time'), width: 170 },
])

const rows = computed(() =>
    items.value.map((row) => {
        const entry = adminNotificationTypes.find((item) => item.type === row.type)
        return {
            ...row,
            label: entry?.text && te(entry.text) ? t(entry.text) : t('text.notifications.unknown_type'),
            time: row.created_at ? dayjs(row.created_at).format('DD/MM/YYYY HH:mm') : '',
            target: resolveNotificationTarget(row, adminNotificationTypes).location,
            detail: resolveNotificationDetail(row, adminNotificationTypes, t),
        }
    })
)

const pagination = computed(() => ({
    current: state.page,
    pageSize: PER_PAGE,
    total: total.value,
    showSizeChanger: false,
    hideOnSinglePage: true,
}))

async function load() {
    controller?.abort()
    const mine = new AbortController()
    controller = mine
    loading.value = true
    error.value = false
    try {
        const result = await getNotifications({
            unread: state.filter === 'unread',
            page: state.page,
            perPage: PER_PAGE,
            signal: mine.signal,
        })
        if (!alive || mine.signal.aborted) return
        if (!result.items.length && state.page > 1) {
            // The page no longer exists (rows were read or pruned): fall back to the first page.
            state.page = 1
            commit()
            return
        }
        items.value = result.items
        total.value = result.total
        unreadCount.value = result.unreadCount
    } catch (e) {
        if (alive && !mine.signal.aborted) error.value = true
    } finally {
        if (alive && controller === mine) loading.value = false
    }
}

function changeFilter(event) {
    const value = event?.target?.value === 'unread' ? 'unread' : ''
    if (value === state.filter) return
    state.filter = value
    state.page = 1
    commit()
}

function changePage(paging) {
    const next = Number(paging?.current) || 1
    if (next === state.page) return
    state.page = next
    commit()
}

async function openRow(record) {
    if (!record.target) return
    try {
        await markNotificationRead(record.id)
        announceNotificationsChanged()
    } catch (e) {
        // Opening the record matters more than the read mark; the badge corrects itself on its next poll.
    }
    if (alive) router.push(record.target)
}

const customRow = (record) => ({
    onClick: () => openRow(record),
    onKeydown: (event) => {
        if (event.key === 'Enter') openRow(record)
    },
    tabindex: record.target ? 0 : undefined,
    style: record.target ? { cursor: 'pointer' } : undefined,
})

async function markAll() {
    try {
        await markAllNotificationsRead()
    } catch (e) {
        if (alive) error.value = true
        return
    }
    // The header bell shows the same count: refresh it now, not at its next poll.
    announceNotificationsChanged()
    if (alive) load()
}

watch(
    () => [state.filter, state.page],
    () => load()
)

onMounted(load)
onBeforeUnmount(() => {
    alive = false
    controller?.abort()
})
defineExpose({ load })
</script>
