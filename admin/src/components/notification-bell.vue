<template>
    <a-dropdown v-model:open="open" :trigger="['click']" placement="bottomRight">
        <button
            type="button"
            class="relative mr-3 inline-flex size-6 items-center justify-center rounded-full text-gray-700 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500 dark:text-gray-400 dark:hover:text-gray-200"
            :aria-label="bellLabel"
            :title="bellLabel"
            data-testid="notification-bell"
        >
            <i-icon icon="bell-badge" />
            <span
                v-if="count > 0"
                class="absolute -right-2 -top-2 inline-flex min-w-4 items-center justify-center rounded-full bg-red-700 px-1 text-[10px] font-semibold leading-4 text-white"
                data-testid="notification-count"
            >
                {{ count > 99 ? '99+' : count }}
            </span>
        </button>
        <template #overlay>
            <div
                class="w-80 max-w-[calc(100vw-1.5rem)] rounded-md border border-gray-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900"
                data-testid="notification-panel"
            >
                <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-slate-700">
                    <span class="text-sm font-semibold">{{ t('text.notifications.title') }}</span>
                    <button
                        v-if="count > 0"
                        type="button"
                        class="text-xs text-cyan-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500"
                        data-testid="notification-mark-all"
                        @click="markAll"
                    >
                        {{ t('text.notifications.mark_all_read') }}
                    </button>
                </div>
                <div class="max-h-96 overflow-y-auto">
                    <p v-if="loading" class="m-0 px-3 py-4 text-sm text-gray-500" data-testid="notification-loading">…</p>
                    <div v-else-if="failed" class="px-3 py-4 text-sm" data-testid="notification-error">
                        <p class="m-0 text-gray-700">{{ t('text.notifications.load_failed') }}</p>
                        <button
                            type="button"
                            class="mt-2 text-cyan-700 hover:underline"
                            data-testid="notification-retry"
                            @click="load"
                        >
                            {{ t('text.profile.retry') }}
                        </button>
                    </div>
                    <p v-else-if="rows.length === 0" class="m-0 px-3 py-4 text-sm text-gray-500" data-testid="notification-empty">
                        {{ t('text.notifications.empty') }}
                    </p>
                    <ul v-else class="m-0 list-none p-0">
                        <li v-for="row in rows" :key="row.id" class="border-b border-gray-100 last:border-b-0 dark:border-slate-800">
                            <component
                                :is="row.target ? 'button' : 'div'"
                                :type="row.target ? 'button' : undefined"
                                :class="[
                                    'block w-full px-3 py-2 text-left text-sm',
                                    row.target ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800' : 'cursor-default',
                                    row.read_at ? 'text-gray-500' : 'font-semibold text-gray-900 dark:text-gray-100',
                                ]"
                                data-testid="notification-row"
                                @click="row.target ? openRow(row) : null"
                            >
                                <span class="block">{{ row.label }}</span>
                                <span class="block text-xs font-normal text-gray-500">{{ row.time }}</span>
                            </component>
                        </li>
                    </ul>
                </div>
                <div v-if="canViewAll" class="border-t border-gray-200 px-3 py-2 text-right dark:border-slate-700">
                    <router-link :to="{ name: 'notifications' }" class="text-xs text-cyan-700 hover:underline" @click="open = false">
                        {{ t('text.notifications.view_all') }}
                    </router-link>
                </div>
            </div>
        </template>
    </a-dropdown>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import dayjs from 'dayjs'
import { adminNotificationTypes } from '@/config/admin-modules'
import { getNotifications, getUnreadCount, markAllNotificationsRead, markNotificationRead } from '@/libs/notifications'
import { NOTIFICATIONS_CHANGED_EVENT, resolveNotificationTarget } from '@/utils/notifications'

// Header bell: unread badge polled every 60 s while the tab is visible (and refreshed the moment it becomes visible
// again); the dropdown loads the latest 20 only when opened. A row opens its target through the registry `open(payload)`
// of its type, after being marked read; a row with no target is plain text. Memory: at most 20 rows are kept, and the
// timer, the visibility listener and any in-flight request are released on unmount.
const POLL_MS = 60_000
const MAX_ROWS = 20

const { t, te } = useI18n()
const router = useRouter()
const open = ref(false)
const count = ref(0)
const items = ref([])
const loading = ref(false)
const failed = ref(false)
const bellLabel = computed(() => `${t('text.notifications.bell_label')}: ${count.value}`)
const canViewAll = computed(() => typeof router.hasRoute === 'function' && router.hasRoute('notifications'))

const rows = computed(() =>
    items.value.map((row) => {
        const entry = adminNotificationTypes.find((item) => item.type === row.type)
        return {
            ...row,
            label: entry?.text && te(entry.text) ? t(entry.text) : t('text.notifications.unknown_type'),
            time: row.created_at ? dayjs(row.created_at).format('DD/MM/YYYY HH:mm') : '',
            target: resolveNotificationTarget(row, adminNotificationTypes).location,
        }
    })
)

let timer = null
let countController = null
let listController = null
let running = false
let alive = true

async function refreshCount() {
    if (!alive || running || document.hidden) return
    running = true
    countController = new AbortController()
    try {
        const value = await getUnreadCount({ signal: countController.signal })
        if (alive) count.value = value
    } catch (error) {
        // Keep the last number; the next tick tries again.
    } finally {
        running = false
    }
}

async function load() {
    listController?.abort()
    listController = new AbortController()
    const mine = listController
    loading.value = true
    failed.value = false
    try {
        const result = await getNotifications({ perPage: MAX_ROWS, signal: mine.signal })
        if (!alive || mine.signal.aborted) return
        items.value = result.items.slice(0, MAX_ROWS)
        count.value = result.unreadCount
    } catch (error) {
        if (alive && !mine.signal.aborted) failed.value = true
    } finally {
        if (alive && listController === mine) loading.value = false
    }
}

async function openRow(row) {
    const location = row.target
    try {
        await markNotificationRead(row.id)
        if (!alive) return
        const wasUnread = !row.read_at
        const mark = items.value.find((item) => item.id === row.id)
        if (mark) mark.read_at = new Date().toISOString()
        if (wasUnread) count.value = Math.max(0, count.value - 1)
    } catch (error) {
        // Opening the record matters more than the read mark; the badge corrects itself on the next poll.
    }
    if (!alive) return
    open.value = false
    router.push(location)
}

async function markAll() {
    try {
        await markAllNotificationsRead()
        if (!alive) return
        const now = new Date().toISOString()
        items.value = items.value.map((item) => ({ ...item, read_at: item.read_at || now }))
        count.value = 0
    } catch (error) {
        if (alive) failed.value = true
    }
}

watch(open, (value) => {
    if (value) load()
})

onMounted(() => {
    refreshCount()
    timer = setInterval(refreshCount, POLL_MS)
    document.addEventListener('visibilitychange', refreshCount)
    // Read marks changed on another screen (the notifications page): refresh now instead of at the next poll.
    window.addEventListener(NOTIFICATIONS_CHANGED_EVENT, refreshCount)
})

onBeforeUnmount(() => {
    alive = false
    clearInterval(timer)
    timer = null
    document.removeEventListener('visibilitychange', refreshCount)
    window.removeEventListener(NOTIFICATIONS_CHANGED_EVENT, refreshCount)
    countController?.abort()
    listController?.abort()
})
</script>
