<template>
    <section class="i-box space-y-4">
        <div class="rounded-lg border border-cyan-200 bg-cyan-50/40 p-3">
            <label class="text-xs font-medium text-slate-700" for="default-provider">
                {{ $t('text.ai_runtime.providers.default_label') }}
            </label>
            <p id="default-provider-help" class="mb-2 mt-0.5 text-[11px] text-slate-500">
                {{ $t('text.ai_runtime.providers.default_help') }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <a-select
                    id="default-provider"
                    v-model:value="defaultProvider"
                    :options="defaultProviderOptions"
                    aria-describedby="default-provider-help"
                    class="w-full sm:w-72"
                />
                <a-button :disabled="savingDefault" :loading="savingDefault" type="primary" @click="saveDefault">
                    {{ $t('text.ai_runtime.providers.save_default') }}
                </a-button>
            </div>
        </div>

        <a-table
            :columns="columns"
            :data-source="rt.overview.value.providers || []"
            :pagination="false"
            :scroll="{ x: 760 }"
            row-key="key"
            size="small"
        >
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'provider'">
                    <span class="font-medium text-slate-800">{{ record.label }}</span>
                    <a-tag v-if="rt.overview.value.default_provider === record.key" class="ml-1" color="cyan">
                        {{ $t('text.ai_runtime.providers.system_default') }}
                    </a-tag>
                    <div v-if="record.docs_url" class="text-[11px]">
                        <a
                            :href="record.docs_url"
                            class="text-cyan-700 hover:underline"
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            {{ $t('text.ai_runtime.providers.model_list') }}
                        </a>
                    </div>
                </template>
                <template v-else-if="column.key === 'model'">
                    <span v-if="record.model">{{ record.model }}</span>
                    <span v-else class="text-slate-400">{{ $t('text.ai_runtime.providers.env_model', { model: record.env_model || '—' }) }}</span>
                </template>
                <template v-else-if="column.key === 'key'">
                    <a-tag v-if="record.has_api_key" color="green">{{ $t('text.ai_runtime.providers.key_source_admin') }}</a-tag>
                    <a-tag v-else-if="record.env_has_api_key" color="blue">{{ $t('text.ai_runtime.providers.key_source_env') }}</a-tag>
                    <a-tag v-else color="red">{{ $t('text.ai_runtime.providers.key_missing') }}</a-tag>
                </template>
                <template v-else-if="column.key === 'action'">
                    <div class="flex flex-wrap items-center gap-2">
                        <a-button
                            :aria-label="$t('text.ai_runtime.providers.test_aria', { label: record.label })"
                            :loading="rt.testingKey.value === record.key"
                            size="small"
                            @click="rt.testProvider(record)"
                        >
                            {{ $t('text.ai_runtime.common.test') }}
                        </a-button>
                        <a-button
                            :aria-label="$t('text.ai_runtime.providers.edit_aria', { label: record.label })"
                            size="small"
                            @click="rt.editingProviderKey.value = record.key"
                        >
                            {{ $t('text.ai_runtime.common.edit') }}
                        </a-button>
                        <span v-if="rt.testResults[record.key]" class="text-xs" role="status">
                            <template v-if="rt.testResults[record.key].ok">
                                <span class="text-emerald-700">{{ $t('text.ai_runtime.providers.test_ok', { ms: rt.testResults[record.key].latency_ms }) }}</span>
                            </template>
                            <span v-else class="text-red-700">
                                {{ $t('text.ai_runtime.common.error_with', { error: rt.testResults[record.key].error }) }}
                            </span>
                        </span>
                    </div>
                </template>
            </template>
        </a-table>

        <AiRuntimeProviderDrawer />
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AiRuntimeProviderDrawer from './AiRuntimeProviderDrawer.vue'
import { useAiRuntime } from './useAiRuntime'

const rt = useAiRuntime()
const { t } = useI18n()

const columns = computed(() => [
    { title: t('text.ai_runtime.col.provider'), key: 'provider' },
    { title: t('text.ai_runtime.col.default_model'), key: 'model' },
    { title: t('text.ai_runtime.col.api_key'), key: 'key', width: 100 },
    { title: '', key: 'action', width: 260 },
])

const defaultProvider = ref('')
const savingDefault = ref(false)
watch(
    () => rt.overview.value.configured_default_provider,
    (v) => {
        defaultProvider.value = v || ''
    },
    { immediate: true }
)
const defaultProviderOptions = computed(() => [
    {
        value: '',
        label: t('text.ai_runtime.providers.default_from_env', {
            provider: rt.overview.value.env_default_provider || 'agentic_ai',
        }),
    },
    ...rt.drivers.value.map((d) => ({ value: d.key, label: d.label })),
])

const saveDefault = async () => {
    if (savingDefault.value) return
    savingDefault.value = true
    try {
        await rt.saveDefaultProvider(defaultProvider.value)
    } finally {
        savingDefault.value = false
    }
}
</script>
