<template>
    <div class="space-y-4">
        <AiRuntimeHeader />

        <a-alert
            v-if="rt.paused.value"
            :message="$t('text.ai_runtime.paused_banner')"
            show-icon
            type="error"
        />
        <div
            v-if="rt.overviewError.value"
            class="flex flex-wrap items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
            role="alert"
        >
            <span>{{ rt.overviewError.value }}</span>
            <a-button size="small" @click="rt.loadOverview">{{ $t('text.ai_runtime.common.retry') }}</a-button>
        </div>

        <a-tabs v-model:activeKey="rt.activeTab.value">
            <a-tab-pane key="overview" :tab="$t('text.ai_runtime.tab.overview')">
                <AiRuntimeOverviewTab />
            </a-tab-pane>
            <a-tab-pane key="tasks" :tab="$t('text.ai_runtime.tab.tasks')">
                <AiRuntimeTasksTab />
            </a-tab-pane>
            <a-tab-pane key="providers" :tab="$t('text.ai_runtime.tab.providers')">
                <AiRuntimeProvidersTab />
            </a-tab-pane>
        </a-tabs>
    </div>
</template>

<script setup>
import { defineComponent, onMounted } from 'vue'
import AiRuntimeHeader from './ai-runtime/AiRuntimeHeader.vue'
import AiRuntimeOverviewTab from './ai-runtime/AiRuntimeOverviewTab.vue'
import AiRuntimeProvidersTab from './ai-runtime/AiRuntimeProvidersTab.vue'
import AiRuntimeTasksTab from './ai-runtime/AiRuntimeTasksTab.vue'
import { provideAiRuntime } from './ai-runtime/useAiRuntime'

defineComponent({ name: 'SettingAiRuntime' })

// Shared state (overview, usage, saves) lives in the composable; tabs inject it.
const rt = provideAiRuntime()

onMounted(rt.loadAll)
</script>
