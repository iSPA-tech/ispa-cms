<template>
    <div class="i-box !p-0 !space-y-0 overflow-hidden">
        <!-- Wide pages (route meta settingWide): compact horizontal link row instead of the left nav. -->
        <div v-if="wide" class="flex flex-col min-h-[480px]" data-testid="setting-wide">
            <nav
                :aria-label="$t('nav.a.settings')"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border px-4 py-2"
            >
                <router-link
                    :to="{ name: 'setting-hub' }"
                    class="inline-flex items-center gap-1.5 text-xs text-ink-faint hover:text-ink transition-colors"
                >
                    <i-icon icon="arrow-left" class="size-3.5" />
                    {{ $t('setting.back_to_settings') }}
                </router-link>
                <span v-if="visibleItems.length" class="text-border-strong" aria-hidden="true">|</span>
                <div class="flex gap-0.5 overflow-x-auto">
                    <router-link
                        v-for="item in visibleItems"
                        :key="item.name"
                        :to="{ name: item.name }"
                        :aria-current="route.name === item.name ? 'page' : undefined"
                        class="whitespace-nowrap px-3 py-1.5 rounded-lg text-xs transition-all flex-shrink-0"
                        :class="route.name === item.name ? 'font-medium' : 'text-ink-muted hover:bg-surface-hover hover:text-ink'"
                        :style="route.name === item.name ? { backgroundColor: category.bgColor, color: category.color } : {}"
                    >
                        {{ $t(item.text) }}
                    </router-link>
                </div>
            </nav>
            <div class="flex-1 p-4 md:p-5 overflow-auto">
                <router-view />
            </div>
        </div>
        <div v-else class="flex flex-col md:flex-row min-h-[480px]">
            <!-- Sidebar -->
            <nav class="w-full md:w-[210px] md:border-r border-b md:border-b-0 border-border flex-shrink-0">
                <div class="p-3 md:py-4 md:px-3">
                    <!-- Back link -->
                    <router-link
                        :to="{ name: 'setting-hub' }"
                        class="inline-flex items-center gap-1.5 text-xs text-ink-faint hover:text-ink transition-colors mb-4"
                    >
                        <i-icon icon="arrow-left" class="size-3.5" />
                        {{ $t('setting.back_to_settings') }}
                    </router-link>

                    <!-- Category header -->
                    <div class="flex items-center gap-2 mb-4 px-1">
                        <div
                            class="rounded-lg w-[30px] h-[30px] flex items-center justify-center flex-shrink-0"
                            :style="{ backgroundColor: category.bgColor }"
                        >
                            <i-icon :icon="category.icon" class="size-3.5" :style="{ color: category.color }" />
                        </div>
                        <span class="font-semibold text-sm text-ink">
                            {{ $t(category.label) }}
                        </span>
                    </div>

                    <!-- Sub-page nav list -->
                    <div class="flex md:flex-col gap-0.5 overflow-x-auto md:overflow-x-visible pb-2 md:pb-0">
                        <router-link
                            v-for="item in visibleItems"
                            :key="item.name"
                            :to="{ name: item.name }"
                            class="whitespace-nowrap px-3 py-2 rounded-lg text-xs transition-all flex-shrink-0"
                            :class="[
                                route.name === item.name
                                    ? 'font-medium shadow-xs'
                                    : 'text-ink-muted hover:bg-surface-hover hover:text-ink'
                            ]"
                            :style="route.name === item.name ? {
                                backgroundColor: category.bgColor,
                                color: category.color,
                            } : {}"
                        >
                            {{ $t(item.text) }}
                        </router-link>
                    </div>
                </div>
            </nav>

            <!-- Content area -->
            <div class="flex-1 p-4 md:p-5 overflow-auto">
                <router-view />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { rule } from '@/mixins'
import { isWebmaster } from '@/hooks/useCan'

defineOptions({ name: 'SettingCategoryLayout' })

const route = useRoute()

const wide = computed(() => route.meta?.settingWide === true)

const categoryMap = {
    general: {
        key: 'general',
        label: 'setting.hub.general',
        icon: 'cog',
        color: '#0ea5e9',
        bgColor: '#e0f2fe',
        items: [
            { name: 'setting-basic', text: 'nav.a.settings', rule: 'setting.viewAny' },
            { name: 'setting-contact', text: 'nav.a.setting-contact', rule: 'setting.viewAny' },
            { name: 'setting-social', text: 'nav.a.setting-social', rule: 'setting.viewAny' },
            { name: 'setting-email', text: 'nav.a.setting-email', rule: 'setting.viewAny' },
            { name: 'setting-email-templates', text: 'nav.a.email_templates', rule: 'email_template.viewAny' },
            { name: 'setting-homepage', text: 'nav.a.setting-homepage', rule: 'setting.viewAny' },
            { name: 'setting-ads', text: 'nav.a.setting-ads', rule: 'setting.ads' },
        ],
    },
    content: {
        key: 'content',
        label: 'setting.hub.content',
        icon: 'package',
        color: '#8b5cf6',
        bgColor: '#ede9fe',
        items: [
            { name: 'setting-menu', text: 'nav.a.menus', rule: 'menu.viewAny' },
            { name: 'setting-upload', text: 'nav.a.setting-upload', rule: 'setting.viewAny' },
        ],
    },
    data: {
        key: 'data',
        label: 'setting.hub.data',
        icon: 'database',
        color: '#f59e0b',
        bgColor: '#fef3c7',
        items: [
            { name: 'setting-enumerate', text: 'nav.a.enumerates', rule: 'enumerate.viewAny' },
            { name: 'setting-enumerate-type', text: 'nav.a.enumerates_types', rule: 'enumerate.viewAny' },
            { name: 'setting-meta-class', text: 'nav.a.meta_classes', rule: 'meta_class.viewAny' },
            { name: 'setting-meta-attribute', text: 'nav.a.meta_attributes', rule: 'meta_attribute.viewAny' },
            { name: 'setting-meta-value', text: 'nav.a.meta_values', rule: 'meta_attribute.viewAny' },
            { name: 'setting-custom-fields', text: 'nav.a.custom_fields', rule: 'custom_field.view' },
        ],
    },
    system: {
        key: 'system',
        label: 'setting.hub.system',
        icon: 'tools',
        color: '#10b981',
        bgColor: '#d1fae5',
        items: [
            { name: 'setting-advanced', text: 'nav.a.setting-advanced', rule: 'setting.viewAny' },
            { name: 'setting-modules', text: 'nav.a.setting-modules', rule: 'setting.viewAny' },
            { name: 'setting-backups', text: 'nav.a.setting-backups', webmasterOnly: true },
            { name: 'setting-config-packages', text: 'nav.a.setting-config-packages', webmasterOnly: true },
            { name: 'setting-starter-packs', text: 'nav.a.starter_packs', rule: 'starter_pack.view' },
            { name: 'setting-security-tokens', text: 'nav.a.setting-security-tokens', rule: '' },
            { name: 'setting-cache', text: 'nav.a.setting-cache', rule: 'setting.viewAny' },
            { name: 'setting-api-logs', text: 'nav.a.api_logs', rule: 'api_log.viewAny' },
        ],
    },
    automation: {
        key: 'automation',
        label: 'setting.hub.automation',
        icon: 'robot',
        color: '#ec4899',
        bgColor: '#fce7f3',
        items: [
            { name: 'setting-workflows-manage', text: 'nav.a.workflows', rule: 'workflow.viewAny' },
            { name: 'setting-workflow-credentials', text: 'text.workflow_credential.title', rule: 'workflow_credential.manage' },
            { name: 'setting-agentic', text: 'text.agentic.cms', rule: 'agentic.viewAny' },
            { name: 'setting-ai-runtime', text: 'text.ai_runtime.title', rule: 'setting.viewAny' },
        ],
    },
}

const categoryKey = computed(() => {
    if (route.meta?.settingCategory) return route.meta.settingCategory
    const segments = route.path.split('/')
    const settingIdx = segments.indexOf('setting')
    const key = settingIdx >= 0 ? segments[settingIdx + 1] : null
    return categoryMap[key] ? key : 'general'
})
const category = computed(() => categoryMap[categoryKey.value] || categoryMap.general)
/** Webmaster-only items show for Webmasters; the others follow their permission rule. */
const visibleItems = computed(() =>
    category.value.items.filter((item) => (item.webmasterOnly ? isWebmaster() : rule(item.rule))),
)
</script>
