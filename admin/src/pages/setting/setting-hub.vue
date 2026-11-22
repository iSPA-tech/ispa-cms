<template>
    <div class="w-full max-w-4xl mx-auto">
        <!-- Server Dashboard Card -->
        <router-link
            v-if="rule('setting.viewAny')"
            :to="{ name: 'setting-dashboard' }"
            class="block bg-white border border-gray-200 rounded-lg p-4 mb-4 flex items-center gap-3 hover:shadow-md transition-shadow cursor-pointer"
        >
            <div class="bg-emerald-50 rounded-lg w-[42px] h-[42px] flex items-center justify-center flex-shrink-0">
                <i-icon icon="server" class="size-5 text-emerald-600" />
            </div>
            <div class="flex-1">
                <h3 class="font-semibold text-sm text-gray-800">{{ $t('setting.hub.server_dashboard') }}</h3>
                <p class="text-xs text-gray-400 mt-0.5">{{ $t('setting.hub.server_dashboard_desc') }}</p>
            </div>
            <i-icon icon="chevron-right" class="size-4 text-gray-300" />
        </router-link>

        <!-- Category Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <template v-for="cat in visibleCategories" :key="cat.key">
                <!-- Module-declared settings: a plain card holding one link per entry (links cannot nest). -->
                <div
                    v-if="cat.entries"
                    class="bg-white rounded-lg p-4 flex items-start gap-3"
                    :class="[cat.spanFull ? 'md:col-span-2' : '']"
                    :style="{ borderLeft: '3px solid ' + cat.color, boxShadow: '0 1px 3px rgba(0,0,0,0.06)' }"
                    :data-testid="'setting-hub-' + cat.key"
                >
                    <div
                        class="rounded-lg w-[38px] h-[38px] flex items-center justify-center flex-shrink-0"
                        :style="{ backgroundColor: cat.bgColor }"
                    >
                        <i-icon :icon="cat.icon" class="size-4" :style="{ color: cat.color }" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-sm text-gray-800">{{ $t(cat.label) }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $t(cat.desc) }}</p>
                        <ul class="mt-1 space-y-0.5">
                            <li v-for="entry in cat.entries" :key="entry.name">
                                <router-link
                                    :to="{ name: entry.name }"
                                    class="text-xs font-medium hover:underline"
                                    :style="{ color: cat.color }"
                                >
                                    {{ $t(entry.text) }} →
                                </router-link>
                            </li>
                        </ul>
                    </div>
                </div>
                <router-link
                    v-else
                    :to="{ name: cat.firstChild }"
                    :data-testid="'setting-hub-' + cat.key"
                    class="bg-white rounded-lg p-4 flex items-start gap-3 hover:shadow-md transition-shadow cursor-pointer"
                    :class="[cat.spanFull ? 'md:col-span-2' : '']"
                    :style="{ borderLeft: '3px solid ' + cat.color, boxShadow: '0 1px 3px rgba(0,0,0,0.06)' }"
                >
                    <div
                        class="rounded-lg w-[38px] h-[38px] flex items-center justify-center flex-shrink-0"
                        :style="{ backgroundColor: cat.bgColor }"
                    >
                        <i-icon :icon="cat.icon" class="size-4" :style="{ color: cat.color }" />
                    </div>
                    <div>
                        <h3 class="font-semibold text-sm text-gray-800">{{ $t(cat.label) }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $t(cat.desc) }}</p>
                        <p class="text-xs font-medium mt-1" :style="{ color: cat.color }">
                            {{ cat.count }} {{ $t('setting.hub.items') }} →
                        </p>
                    </div>
                </router-link>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent } from 'vue'
import { rule } from '@/mixins'
import { isWebmaster } from '@/hooks/useCan'
import { isModuleActive } from '@/hooks/useActiveModules'
import { adminModuleSettings } from '@/config/admin-modules'

defineComponent({ name: 'SettingHub' })

const categories = [
    {
        key: 'general',
        label: 'setting.hub.general',
        desc: 'setting.hub.general_desc',
        icon: 'cog',
        color: '#0ea5e9',
        bgColor: '#e0f2fe',
        firstChild: 'setting-basic',
        children: [
            { name: 'setting-basic', rule: 'setting.viewAny' },
            { name: 'setting-social', rule: 'setting.viewAny' },
            { name: 'setting-email', rule: 'setting.viewAny' },
            { name: 'setting-homepage', rule: 'setting.viewAny' },
            { name: 'setting-ads', rule: 'setting.ads' },
        ],
    },
    {
        key: 'content',
        label: 'setting.hub.content',
        desc: 'setting.hub.content_desc',
        icon: 'package',
        color: '#8b5cf6',
        bgColor: '#ede9fe',
        firstChild: 'setting-menu',
        children: [
            { name: 'setting-menu', rule: 'menu.viewAny' },
            { name: 'setting-upload', rule: 'setting.viewAny' },
        ],
    },
    {
        key: 'data',
        label: 'setting.hub.data',
        desc: 'setting.hub.data_desc',
        icon: 'database',
        color: '#f59e0b',
        bgColor: '#fef3c7',
        firstChild: 'setting-enumerate',
        children: [
            { name: 'setting-enumerate', rule: 'enumerate.viewAny' },
            { name: 'setting-enumerate-type', rule: 'enumerate.viewAny' },
            { name: 'setting-meta-class', rule: 'meta_class.viewAny' },
            { name: 'setting-meta-attribute', rule: 'meta_attribute.viewAny' },
            { name: 'setting-meta-value', rule: 'meta_attribute.viewAny' },
            { name: 'setting-custom-fields', rule: 'custom_field.view' },
        ],
    },
    {
        key: 'system',
        label: 'setting.hub.system',
        desc: 'setting.hub.system_desc',
        icon: 'tools',
        color: '#10b981',
        bgColor: '#d1fae5',
        firstChild: 'setting-advanced',
        children: [
            { name: 'setting-advanced', rule: 'setting.viewAny' },
            { name: 'setting-modules', rule: 'setting.viewAny' },
            { name: 'setting-backups', webmasterOnly: true },
            { name: 'setting-config-packages', webmasterOnly: true },
            { name: 'setting-environment', webmasterOnly: true },
            { name: 'setting-starter-packs', rule: 'starter_pack.view' },
            { name: 'setting-security-tokens', rule: '' },
            { name: 'setting-cache', rule: 'setting.viewAny' },
            { name: 'setting-api-logs', rule: 'api_log.viewAny' },
        ],
    },
    {
        key: 'automation',
        label: 'setting.hub.automation',
        desc: 'setting.hub.automation_desc',
        icon: 'robot',
        color: '#ec4899',
        bgColor: '#fce7f3',
        firstChild: 'setting-workflows-manage',
        spanFull: true,
        children: [
            { name: 'setting-workflows-manage', rule: 'workflow.viewAny' },
            { name: 'setting-agentic', rule: 'agentic.viewAny' },
            { name: 'setting-ai-runtime', rule: 'setting.viewAny' },
        ],
    },
]

/**
 * Settings entries declared by modules (`settings` in their admin registry object) for the "Modules" category,
 * limited to entries the user may open and whose module is active on this site.
 */
const visibleModuleEntries = computed(() =>
    adminModuleSettings.filter(
        (entry) => entry.category === 'modules' && rule(entry.rule) && isModuleActive(entry.module)
    )
)

/** A hub entry is visible when its permission rule passes, or (Webmaster-only entries) the account is a Webmaster. */
const childVisible = (child) => (child.webmasterOnly ? isWebmaster() : rule(child.rule))

const visibleCategories = computed(() => {
    const result = categories
        .filter((cat) => cat.children.some(childVisible))
        .map((cat) => {
            const accessibleCount = cat.children.filter(childVisible).length
            return { ...cat, count: accessibleCount }
        })

    const entries = visibleModuleEntries.value
    if (entries.length) {
        result.push({
            key: 'modules',
            label: 'setting.hub.modules',
            desc: 'setting.hub.modules_desc',
            icon: 'puzzle',
            color: '#8b5cf6',
            bgColor: '#ede9fe',
            firstChild: entries[0].name,
            entries,
            count: entries.length,
        })
    }
    return result
})
</script>
