<template>
    <div ref="root" class="relative mr-3 inline-flex items-center" data-testid="background-task-bar" @keydown.esc="open = false">
        <button
            type="button"
            class="relative inline-flex size-8 items-center justify-center rounded-full bg-transparent text-inherit hover:bg-slate-200/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500 dark:hover:bg-slate-700/60"
            :aria-label="label"
            :title="label"
            :aria-expanded="open"
            aria-haspopup="true"
            @click="toggle"
        >
            <i-icon :class="['size-5', runningCount > 0 && 'animate-spin']" :icon="runningCount > 0 ? 'sync' : 'cog-sync'" />
            <span
                v-if="runningCount > 0"
                class="absolute -right-1 -top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-cyan-700 px-1 text-[10px] font-semibold leading-4 text-white"
                aria-hidden="true"
            >
                {{ runningCount > 99 ? '99+' : runningCount }}
            </span>
        </button>
        <div
            v-if="open"
            class="fixed inset-x-4 top-14 z-50 max-h-96 overflow-y-auto sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-80 sm:max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-800"
        >
            <p class="m-0 mb-2 text-sm font-semibold">{{ t('text.background_tasks.title') }}</p>
            <p v-if="!tasks.length" class="m-0 text-sm text-gray-600 dark:text-gray-300">{{ t('text.background_tasks.empty') }}</p>
            <ul v-else class="m-0 list-none space-y-3 p-0">
                <li v-for="item in tasks" :key="item.uuid" class="text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="min-w-0 break-words font-medium">{{ labelFor(item) }}</span>
                        <a-tag :color="statusColor(item.status)" class="m-0 shrink-0">{{ statusText(item.status) }}</a-tag>
                    </div>
                    <a-progress
                        v-if="isActive(item) || item.status === 'succeeded'"
                        :percent="percentOf(item)"
                        :status="isActive(item) ? 'active' : 'success'"
                        size="small"
                    />
                    <p v-if="item.message" class="m-0 break-words text-xs text-gray-600 dark:text-gray-300">{{ messageText(item) }}</p>
                    <router-link
                        v-if="failedLinkFor(item)"
                        :to="{ name: failedLinkFor(item).name }"
                        class="mt-1 block break-words text-xs text-cyan-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500 dark:text-cyan-400"
                        data-testid="background-task-link"
                        @click="open = false"
                    >
                        {{ failedLinkFor(item).text }}
                    </router-link>
                    <p v-if="item.finished_at" class="m-0 text-xs text-gray-500 dark:text-gray-400">{{ formatTime(item.finished_at) }}</p>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { adminBackgroundTaskTypes } from '@/config/admin-modules'
import { getBackgroundTasks, isTaskActive, taskLabelKey } from '@/libs/background-tasks'

// Header indicator of the signed-in person's background tasks. Polls every 5 s while a task is queued or running and every
// 60 s otherwise, pauses while the tab is hidden and catches up when it becomes visible. Requests are silent: a failed poll
// keeps the last list. Labels come from the module registry (`backgroundTasks`), unknown types show the raw type.
// A failed task whose registry entry has `failedLink` ({ name, trail }) shows a breadcrumb-style link to the page where the
// operator can act next; the link is hidden when the route name does not resolve.
const FAST_MS = 5_000
const SLOW_MS = 60_000
const STATUS_COLORS = { queued: 'default', running: 'processing', succeeded: 'success', failed: 'error' }
const STATUS_KEYS = {
    queued: 'text.background_tasks.queued',
    running: 'text.background_tasks.running',
    succeeded: 'text.background_tasks.succeeded',
    failed: 'text.background_tasks.failed',
}

const { t, te } = useI18n()
const router = useRouter()
const tasks = ref([])
const open = ref(false)
const root = ref(null)
const runningCount = computed(() => tasks.value.filter(isTaskActive).length)
const label = computed(() =>
    runningCount.value > 0 ? `${t('text.background_tasks.title')}: ${runningCount.value}` : t('text.background_tasks.title'),
)
let timer = null
let busy = false
let disposed = false

const isActive = isTaskActive

/**
 * Translate a task type through the module registry, falling back to the raw type.
 *
 * @param {{type: string}} item Task record.
 * @returns {string}
 */
function labelFor(item) {
    const key = taskLabelKey(adminBackgroundTaskTypes, item.type)
    return key ? t(key) : item.type
}

/**
 * Where the operator goes when a failed task needs attention: the registry `failedLink`, only for failed rows
 * and only when its route name resolves.
 *
 * @param {{type: string, status: string}} item Task record.
 * @returns {{name: string, text: string}|null} Route name and breadcrumb text, or null for no link.
 */
function failedLinkFor(item) {
    if (item.status !== 'failed') return null
    const link = adminBackgroundTaskTypes.find((entry) => entry.type === item.type)?.failedLink
    if (!link?.name) return null
    try {
        if (!router.resolve({ name: link.name }).matched.length) return null
    } catch (error) {
        return null
    }
    return { name: link.name, text: (link.trail || []).map((key) => t(key)).join(' › ') }
}

/**
 * Translate a task message that is an i18n key, filling its named placeholders from message_params;
 * other (plain text) messages stay raw.
 *
 * @param {{message: string, message_params?: Record<string, unknown>|null}} item Task record.
 * @returns {string}
 */
function messageText(item) {
    const message = item.message
    return typeof message === 'string' && te(message) ? t(message, item.message_params || {}) : message
}

/**
 * @param {string} status Task status.
 * @returns {string} Translated status text (raw status when unknown).
 */
function statusText(status) {
    return STATUS_KEYS[status] ? t(STATUS_KEYS[status]) : status
}

/**
 * @param {string} status Task status.
 * @returns {string} Ant tag colour for the status.
 */
function statusColor(status) {
    return STATUS_COLORS[status] || 'default'
}

/**
 * @param {{progress?: number|null}} item Task record.
 * @returns {number} Progress clamped to 0-100.
 */
function percentOf(item) {
    return Math.min(100, Math.max(0, Number(item.progress) || 0))
}

/**
 * @param {string} value ISO date string.
 * @returns {string} Locale formatted date and time.
 */
function formatTime(value) {
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

/** Plan the next poll: fast while a task is active, slow otherwise. Does nothing after unmount. */
function schedule() {
    clearTimeout(timer)
    timer = null
    if (disposed) return
    timer = setTimeout(tick, runningCount.value > 0 ? FAST_MS : SLOW_MS)
}

/** Fetch the list unless a request is in flight or the tab is hidden. A failed request keeps the last list. */
async function refresh() {
    if (busy || disposed || document.hidden) return
    busy = true
    try {
        const list = await getBackgroundTasks()
        if (!disposed) tasks.value = list
    } catch (error) {
        // Keep the last list; the next tick tries again.
    } finally {
        busy = false
    }
}

/** Timer callback: refresh, then plan the next poll. */
async function tick() {
    await refresh()
    schedule()
}

/** Visibility listener: catch up when the tab comes back, then restart the timer. */
async function onVisibility() {
    await refresh()
    schedule()
}

/** Toggle the panel; opening it refreshes the list and restarts the poll so a just-started task shows at once. */
async function toggle() {
    open.value = !open.value
    if (!open.value) return
    await refresh()
    schedule()
}

/**
 * Close the panel when a click lands outside the component.
 *
 * @param {MouseEvent} event Document click.
 */
function onDocumentClick(event) {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false
}

onMounted(() => {
    tick()
    document.addEventListener('visibilitychange', onVisibility)
    document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => {
    disposed = true
    clearTimeout(timer)
    timer = null
    document.removeEventListener('visibilitychange', onVisibility)
    document.removeEventListener('click', onDocumentClick)
})
</script>
