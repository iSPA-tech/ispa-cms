<template>
    <a-drawer
        :open="Boolean(provider)"
        :title="provider ? $t('text.ai_runtime.provider_drawer.title', { label: provider.label }) : ''"
        :width="$isMobile() ? '100%' : 720"
        destroy-on-close
        @close="guard.requestClose"
    >
        <div v-if="provider" class="space-y-4">
            <a-alert v-if="error" :message="error" role="alert" show-icon type="error" />

            <div>
                <label class="block text-xs font-medium text-slate-700" for="provider-model">{{ $t('text.ai_runtime.col.default_model') }}</label>
                <a-input
                    id="provider-model"
                    v-model:value="form.model"
                    aria-describedby="provider-model-help"
                    :placeholder="provider.env_model || $t('text.ai_runtime.provider_drawer.model_placeholder')"
                />
                <p id="provider-model-help" class="mt-0.5 text-[11px] text-slate-500">
                    {{ $t('text.ai_runtime.provider_drawer.model_help') }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700" for="provider-base-uri">
                    {{ $t('text.ai_runtime.provider_drawer.base_uri') }}
                </label>
                <a-input id="provider-base-uri" v-model:value="form.base_uri" placeholder="https://…" />
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700" for="provider-api-key">{{ $t('text.ai_runtime.col.api_key') }}</label>
                <div class="flex items-center gap-2">
                    <a-input-password
                        id="provider-api-key"
                        v-model:value="form.api_key"
                        aria-describedby="provider-key-help"
                        autocomplete="new-password"
                        placeholder="••••••••"
                    />
                    <a-button v-if="provider.has_api_key" danger @click="form.clear_api_key = !form.clear_api_key">
                        {{
                            $t(
                                form.clear_api_key
                                    ? 'text.ai_runtime.provider_drawer.undo'
                                    : 'text.ai_runtime.provider_drawer.clear_key'
                            )
                        }}
                    </a-button>
                </div>
                <p id="provider-key-help" class="mt-0.5 text-[11px] text-slate-500">
                    <template v-if="provider.has_api_key">{{ $t('text.ai_runtime.provider_drawer.key_saved') }}</template>
                    <template v-else-if="provider.env_has_api_key">
                        {{ $t('text.ai_runtime.provider_drawer.key_env') }}
                    </template>
                    <template v-else>{{ $t('text.ai_runtime.provider_drawer.key_none') }}</template>
                    {{ $t('text.ai_runtime.provider_drawer.key_write_only') }}
                </p>
                <p v-if="form.clear_api_key" class="mt-1 text-[11px] text-red-600" role="status">
                    {{ $t('text.ai_runtime.provider_drawer.key_clear_notice') }}
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <a-button @click="guard.requestClose">{{ $t('text.ai_runtime.common.cancel') }}</a-button>
                <a-button :disabled="saving" :loading="saving" type="primary" @click="save">
                    {{ $t('text.ai_runtime.provider_drawer.save') }}
                </a-button>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useAiRuntime } from './useAiRuntime'
import { useDrawerGuard } from './useDrawerGuard'

const rt = useAiRuntime()

const provider = computed(
    () => (rt.overview.value.providers || []).find((p) => p.key === rt.editingProviderKey.value) || null
)
const form = reactive({ key: '', model: '', base_uri: '', api_key: '', clear_api_key: false })
const saving = ref(false)
const error = ref('')
const close = () => {
    rt.editingProviderKey.value = ''
}
const guard = useDrawerGuard(form, close)

watch(
    () => rt.editingProviderKey.value,
    () => {
        error.value = ''
        const p = provider.value
        if (!p) return
        Object.assign(form, {
            key: p.key,
            model: p.model || '',
            base_uri: p.base_uri || '',
            api_key: '',
            clear_api_key: false,
        })
        guard.snapshot()
    },
    { immediate: true }
)

const save = async () => {
    if (saving.value) return
    saving.value = true
    error.value = ''
    try {
        const result = await rt.saveProvider(form)
        if (result.ok) close()
        else error.value = result.error
    } finally {
        saving.value = false
    }
}
</script>
