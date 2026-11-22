<template>
    <router-link
        v-if="total > 0"
        :to="{ name: 'my-tasks' }"
        :aria-label="label"
        :title="label"
        class="mr-3 inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-cyan-700 px-2 text-xs font-semibold leading-6 text-white no-underline hover:bg-cyan-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500"
        data-testid="my-tasks-badge"
    >
        <span aria-hidden="true">{{ total > 99 ? '99+' : total }}</span>
    </router-link>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getMyTasks } from '@/libs/my-tasks'

// Header counter of the work waiting for the signed-in person. Polls every 60 s while the tab is visible and catches up when
// the person returns to it. Requests are silent, a failed poll keeps the last number. Nothing renders while the total is 0.
const POLL_MS = 60_000
const { t } = useI18n()
const total = ref(0)
const label = computed(() => `${t('text.my_tasks.badge_label')}: ${total.value}`)
let timer = null
let running = false

async function refresh() {
    if (running || document.hidden) return
    running = true
    try {
        const result = await getMyTasks()
        total.value = Math.max(0, Number(result?.total) || 0)
    } catch (error) {
        // Keep the last number; the next tick tries again.
    } finally {
        running = false
    }
}

onMounted(() => {
    refresh()
    timer = setInterval(refresh, POLL_MS)
    document.addEventListener('visibilitychange', refresh)
})

onBeforeUnmount(() => {
    clearInterval(timer)
    timer = null
    document.removeEventListener('visibilitychange', refresh)
})
</script>
