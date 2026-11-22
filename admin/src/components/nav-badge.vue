<template>
    <span
        v-if="active && count > 0"
        class="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-red-700 px-1.5 text-xs font-semibold leading-5 text-white"
    >
        <span aria-hidden="true">{{ count > 99 ? '99+' : count }}</span>
        <span class="sr-only">{{ title }}: {{ count }}</span>
    </span>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminNavBadges } from '@/config/admin-modules'
import { isModuleActive } from '@/hooks/useActiveModules'
import { rule } from '@/mixins'

// A counter next to a sidebar item. A module registers it through `navBadges` (see admin-modules.js); an item without a
// registered badge renders nothing and asks for nothing. A badge that names its `module` shows and polls only while that module
// is active on this site (the check the sidebar itself uses), and its requests are silent: a failed poll never raises a toast.
// The badge follows `name`: when the sidebar hands this component to another item, the number is dropped and polling restarts.
const props = defineProps({ name: { type: String, required: true } })

const { t } = useI18n()
const badge = computed(() => adminNavBadges.find((item) => item.name === props.name))
const active = computed(() => Boolean(badge.value) && isModuleActive(badge.value.module))
const count = ref(0)
const title = computed(() => (badge.value?.label ? t(badge.value.label) : ''))
let timer = null
let running = false
// Bumped whenever the badge changes, so an answer that was still on its way for the previous badge is thrown away.
let generation = 0

async function refresh() {
    const current = badge.value
    if (!current || running || document.visibilityState === 'hidden' || !isModuleActive(current.module) || !rule(current.rule)) return
    running = true
    const mine = generation
    try {
        const value = Math.max(0, Number(await current.load()) || 0)
        if (mine === generation) count.value = value
    } catch (error) {
        // A failed refresh keeps the last number; the next tick tries again.
    } finally {
        if (mine === generation) running = false
    }
}

function start() {
    clearInterval(timer)
    timer = null
    generation += 1
    running = false
    count.value = 0
    const current = badge.value
    if (!current) return
    refresh()
    timer = setInterval(refresh, (current.intervalSeconds || 60) * 1000)
}

watch(badge, start)

onMounted(() => {
    start()
    // Catch up as soon as the person comes back to the tab.
    document.addEventListener('visibilitychange', refresh)
})

onBeforeUnmount(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', refresh)
})
</script>
