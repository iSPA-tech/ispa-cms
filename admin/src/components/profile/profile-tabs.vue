<script setup>
import { computed, defineAsyncComponent, h, ref, resolveComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { adminProfileTabs } from '@/config/admin-modules'
import { isModuleActive } from '@/hooks/useActiveModules'
import { rule } from '@/mixins'

/**
 * Tabs a profile page (customer, user, organization ...) shows: its own built-in tabs first, then the tabs modules
 * contribute through `profileTabs` (see admin-modules.js). A contributed tab appears only when it targets this profile,
 * its module is active on this site and the person passes its `rule`. Only the active tab is mounted, so the pollers
 * of the other tabs stop; its body is a lazy component that receives `{ uuid }` and nothing else. The active tab is
 * kept in `query.tab` (`router.replace`, so Back leaves the page).
 */
const props = defineProps({
    /** Profile kind the registry tabs are filtered by (`customer`, `user`, `organization`, ...). */
    target: { type: String, required: true },
    /** Record uuid handed to every tab body. */
    uuid: { type: String, required: true },
    /** Built-in tabs `{ name, text, component }` shown before the contributed ones. */
    extra: { type: Array, default: () => [] },
    /** Active tab name; falls back to `query.tab`, then the first tab. */
    activeKey: { type: String, default: '' },
})
const emit = defineEmits(['update:activeKey'])

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

/** Body component created once per tab, dropped on retry so the import runs again. */
const bodies = new Map()
const version = ref(0)

/** Loading state of a tab body. */
const Loading = { render: () => h(resolveComponent('a-skeleton'), { active: true }) }

/**
 * Build the lazy body of a tab with a skeleton while loading and an error alert with retry when the import fails.
 *
 * @param {{ name: string, load: Function }} entry Registry entry.
 * @returns {object} Async component.
 */
function createBody(entry) {
    const retry = () => {
        bodies.delete(entry.name)
        version.value += 1
    }
    const Failed = {
        render: () =>
            h(
                resolveComponent('a-alert'),
                { type: 'error', showIcon: true, message: t('text.profile.tab_load_failed') },
                {
                    action: () =>
                        h(resolveComponent('i-button'), { size: 'small', onClick: retry }, () =>
                            t('text.profile.retry')
                        ),
                }
            ),
    }
    return defineAsyncComponent({
        loader: entry.load,
        loadingComponent: Loading,
        errorComponent: Failed,
        delay: 0,
    })
}

const tabs = computed(() => {
    const contributed = adminProfileTabs.filter(
        (entry) =>
            entry.target === props.target &&
            isModuleActive(entry.module) &&
            (!entry.rule || rule(entry.rule))
    )
    return [...props.extra, ...contributed]
})

const current = computed(() => {
    const wanted = props.activeKey || route.query?.tab
    return tabs.value.some((tab) => tab.name === wanted) ? wanted : tabs.value[0]?.name
})

const body = computed(() => {
    // Read the version so a retry rebuilds the component.
    void version.value
    const tab = tabs.value.find((item) => item.name === current.value)
    if (!tab) return null
    if (tab.component) return tab.component
    if (!bodies.has(tab.name)) bodies.set(tab.name, createBody(tab))
    return bodies.get(tab.name)
})

/** Switch tab: write it into the address and tell a parent that binds `activeKey`. */
function select(name) {
    if (name === current.value) return
    emit('update:activeKey', name)
    router.replace({ query: { ...(route.query || {}), tab: name } })
}
</script>

<template>
    <div v-if="tabs.length" class="space-y-4">
        <a-tabs :active-key="current" @change="select">
            <a-tab-pane
                v-for="tab in tabs"
                :key="tab.name"
                :data-key="tab.name"
                :tab="t(tab.text)"
            />
        </a-tabs>
        <component :is="body" :key="current" :uuid="uuid" />
    </div>
</template>
