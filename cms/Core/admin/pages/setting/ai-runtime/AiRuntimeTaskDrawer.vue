<template>
    <a-drawer
        :open="Boolean(row)"
        :title="
            row
                ? $t(readOnly ? 'text.ai_runtime.task_drawer.title_view' : 'text.ai_runtime.task_drawer.title_edit', {
                      label: row.label,
                  })
                : ''
        "
        :width="$isMobile() ? '100%' : 720"
        destroy-on-close
        @close="requestClose"
    >
        <div
            v-if="row"
            class="space-y-4"
            @keydown.esc.capture="guard.onEscCapture"
            @keydown.esc="guard.onEscBubble"
        >
            <p class="text-xs text-slate-600" data-testid="task-summary">
                {{
                    $t('text.ai_runtime.task_drawer.summary', {
                        month: rt.usageMonth.value,
                        calls: formatInt(row.calls),
                        failed: formatInt(row.failed),
                        cost: formatUsd(row.cost),
                        running:
                            [row.effective.provider, row.effective.model].filter(Boolean).join(' · ') ||
                            $t('text.ai_runtime.common.unknown'),
                    })
                }}
            </p>

            <p v-if="readOnly" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                {{ $t('text.ai_runtime.task_drawer.read_only') }}
            </p>
            <template v-else>
            <a-alert v-if="error" :message="error" role="alert" show-icon type="error" />

            <div>
                <label class="block text-xs font-medium text-slate-700" for="task-provider">{{ $t('text.ai_runtime.col.provider') }}</label>
                <a-select
                    id="task-provider"
                    v-model:value="form.provider"
                    :options="providerOptions"
                    aria-describedby="task-provider-help"
                    class="w-full"
                    @dropdown-visible-change="guard.onDropdownChange"
                />
                <p id="task-provider-help" class="mt-0.5 text-[11px] text-slate-500">
                    {{ $t('text.ai_runtime.task_drawer.provider_help') }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700" for="task-model">{{ $t('text.ai_runtime.col.model') }}</label>
                <a-auto-complete
                    id="task-model"
                    v-model:value="form.model"
                    :options="modelOptions"
                    aria-describedby="task-model-help"
                    :filter-option="false"
                    class="w-full"
                    :placeholder="$t('text.ai_runtime.task_drawer.model_placeholder')"
                    @dropdown-visible-change="guard.onDropdownChange"
                />
                <p id="task-model-help" class="mt-0.5 text-[11px] text-slate-500">
                    {{ $t('text.ai_runtime.task_drawer.model_help') }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700" for="task-temperature">
                    {{ $t('text.ai_runtime.task_drawer.temperature') }}
                </label>
                <a-input-number
                    id="task-temperature"
                    v-model:value="form.temperature"
                    :max="2"
                    :min="0"
                    :step="0.1"
                    aria-describedby="task-temperature-help"
                    class="w-full"
                    :placeholder="$t('text.ai_runtime.common.default')"
                />
                <p id="task-temperature-help" class="mt-0.5 text-[11px] text-slate-500">
                    {{ $t('text.ai_runtime.task_drawer.temperature_help') }}
                </p>
            </div>

            <div v-if="showReasoning">
                <label class="block text-xs font-medium text-slate-700" for="task-reasoning">
                    {{ $t('text.ai_runtime.task_drawer.reasoning') }}
                </label>
                <a-select
                    id="task-reasoning"
                    v-model:value="form.reasoning_effort"
                    :options="reasoningEffortOptions"
                    aria-describedby="task-reasoning-help"
                    class="w-full"
                    @dropdown-visible-change="guard.onDropdownChange"
                />
                <p id="task-reasoning-help" class="mt-0.5 text-[11px] text-slate-500">
                    {{ $t('text.ai_runtime.task_drawer.reasoning_help') }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700" for="task-fallback">
                    {{ $t('text.ai_runtime.task_drawer.fallback') }}
                </label>
                <a-select
                    id="task-fallback"
                    :value="form.fallback_list"
                    :options="fallbackOptions"
                    aria-describedby="task-fallback-help"
                    class="w-full"
                    mode="tags"
                    :placeholder="$t('text.ai_runtime.task_drawer.fallback_placeholder')"
                    :token-separators="[',']"
                    @dropdown-visible-change="guard.onDropdownChange"
                    @update:value="setFallback"
                />
                <p id="task-fallback-help" class="mt-0.5 text-[11px] text-slate-500">
                    {{ $t('text.ai_runtime.task_drawer.fallback_help') }}
                </p>
                <p v-if="fallbackNotice" class="mt-0.5 text-[11px] text-amber-700" role="status">
                    {{ fallbackNotice }}
                </p>
            </div>
            </template>

            <section aria-labelledby="task-calls-title" class="space-y-2">
                <h4 id="task-calls-title" class="text-sm font-semibold text-slate-800">
                    {{ $t('text.ai_runtime.task_drawer.recent_calls') }}
                </h4>
                <div v-if="callsLoading" class="text-xs text-slate-500" role="status">
                    {{ $t('text.ai_runtime.task_drawer.calls_loading') }}
                </div>
                <div
                    v-else-if="callsError"
                    class="flex flex-wrap items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                    role="alert"
                >
                    <span>{{ callsError }}</span>
                    <a-button size="small" @click="loadCalls">{{ $t('text.ai_runtime.common.retry') }}</a-button>
                </div>
                <div v-else-if="!calls.length" class="text-xs text-slate-500">
                    {{ $t('text.ai_runtime.task_drawer.no_calls') }}
                </div>
                <template v-else>
                    <div v-if="duplicate" data-testid="duplicate-rate">
                        <p class="text-xs text-slate-700">
                            {{ $t('text.ai_runtime.task_drawer.duplicate_label') }}: <b>{{ duplicate.percent }}%</b>
                            {{
                                $t('text.ai_runtime.task_drawer.duplicate_detail', {
                                    duplicates: formatInt(duplicate.duplicate_calls),
                                    total: formatInt(duplicate.calls_with_hash),
                                })
                            }}
                        </p>
                        <p class="mt-0.5 text-[11px] text-slate-500">
                            {{ $t('text.ai_runtime.task_drawer.duplicate_help') }}
                        </p>
                    </div>
                    <a-table
                        :columns="callColumns"
                        :data-source="calls"
                        :pagination="false"
                        :row-key="(r) => r.request_id || `${r.called_at}-${r.attempt}-${r.model}`"
                        :scroll="{ x: 860 }"
                        size="small"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'time'">{{ formatDateTime(record.called_at) }}</template>
                            <template v-else-if="column.key === 'model'">
                                <span class="whitespace-nowrap">{{ record.model || '—' }}</span>
                                <div v-if="record.provider" class="text-[11px] text-slate-500">{{ record.provider }}</div>
                            </template>
                            <template v-else-if="column.key === 'result'">
                                <a-tag :color="resultOf(record).color">{{ resultOf(record).text }}</a-tag>
                            </template>
                            <template v-else-if="column.key === 'latency'">{{ formatCallSeconds(record.latency_ms) }}</template>
                            <template v-else-if="column.key === 'tokens'">
                                {{ formatInt(record.input_tokens) }} / {{ formatInt(record.output_tokens) }}
                            </template>
                            <template v-else-if="column.key === 'cost'">{{ formatUsd(record.cost_usd) }}</template>
                            <template v-else-if="column.key === 'origin'">{{ originText(record.origin) }}</template>
                            <template v-else-if="column.key === 'request'">
                                <template v-if="record.request_id">
                                    <span :title="record.request_id" class="font-mono text-[11px]">
                                        {{ truncateId(record.request_id) }}
                                    </span>
                                    <a-button
                                        :aria-label="$t('text.ai_runtime.task_drawer.copy_aria')"
                                        size="small"
                                        type="text"
                                        @click="copyId(record.request_id)"
                                    >
                                        {{ $t('text.ai_runtime.common.copy') }}
                                    </a-button>
                                </template>
                                <template v-else>—</template>
                            </template>
                        </template>
                    </a-table>
                </template>
            </section>
        </div>

        <template #footer>
            <div v-if="readOnly" class="flex justify-end gap-2">
                <a-button @click="close">{{ $t('text.ai_runtime.common.close') }}</a-button>
            </div>
            <div v-else class="flex justify-end gap-2">
                <a-button @click="guard.requestClose">{{ $t('text.ai_runtime.common.cancel') }}</a-button>
                <a-button :disabled="saving" :loading="saving" type="primary" @click="save">
                    {{ $t('text.ai_runtime.task_drawer.save') }}
                </a-button>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { message } from 'ant-design-vue'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatCallSeconds, formatDateTime, formatInt, formatUsd, percent, splitModels, useAiRuntime } from './useAiRuntime'
import { useDrawerGuard } from './useDrawerGuard'

const rt = useAiRuntime()
const { t } = useI18n()

const MAX_FALLBACK_MODELS = 3
// Only OpenAI GPT-5.x models read reasoning_effort; other providers ignore it.
const reasoningEffortOptions = computed(() =>
    ['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'].map((value) => ({
        value,
        label: t(`text.ai_runtime.reasoning.${value || 'default'}`),
    }))
)

const row = computed(() => rt.taskRows.value.find((r) => r.task === rt.editingTaskKey.value) || null)
const form = reactive({
    task: '',
    provider: '',
    model: '',
    temperature: null,
    reasoning_effort: '',
    fallback_list: [],
})
const saving = ref(false)
const error = ref('')
const fallbackNotice = ref('')
const close = () => {
    rt.editingTaskKey.value = ''
}
const guard = useDrawerGuard(form, close, ['model', 'fallback_list'])
// Direct tasks open the drawer read-only: no form, no dirty guard.
const readOnly = computed(() => Boolean(row.value) && row.value.configurable === false)
const requestClose = () => (readOnly.value ? close() : guard.requestClose())

const calls = ref([])
const callsLoading = ref(false)
const callsError = ref('')
const duplicateRaw = ref(null)
let callsSeq = 0
// Hidden when no call carries a prompt hash.
const duplicate = computed(() => {
    const d = duplicateRaw.value
    if (!d || !Number(d.calls_with_hash)) return null
    return { ...d, percent: percent(d.duplicate_calls, d.calls_with_hash) }
})
// Loads the task's recent calls; stale responses from a previously opened task are dropped.
const loadCalls = async () => {
    const key = rt.editingTaskKey.value
    if (!key) return
    const seq = ++callsSeq
    callsLoading.value = true
    callsError.value = ''
    const res = await rt.fetchTaskCalls(key)
    if (seq !== callsSeq) return
    callsLoading.value = false
    if (res.ok) {
        calls.value = res.data.calls || []
        duplicateRaw.value = res.data.duplicate_prompt_7d || null
    } else {
        calls.value = []
        duplicateRaw.value = null
        callsError.value = res.error
    }
}

const callColumns = computed(() => [
    { title: t('text.ai_runtime.col.time'), key: 'time', width: 110 },
    { title: t('text.ai_runtime.col.model'), key: 'model', width: 140 },
    { title: t('text.ai_runtime.col.result'), key: 'result', width: 150 },
    { title: t('text.ai_runtime.col.latency'), key: 'latency', align: 'right', width: 70 },
    { title: t('text.ai_runtime.col.tokens'), key: 'tokens', align: 'right', width: 100 },
    { title: t('text.ai_runtime.col.cost'), key: 'cost', align: 'right', width: 80 },
    { title: t('text.ai_runtime.col.origin'), key: 'origin', width: 70 },
    { title: t('text.ai_runtime.col.request_id'), key: 'request', width: 130 },
])
// Result is always text; colour only reinforces it.
// A fallback attempt keeps its outcome visible: "Fallback (attempt 2) · Error" / "Fallback (attempt 2) · OK".
const resultOf = (c) => {
    if (c.refused_reason) {
        return { text: t('text.ai_runtime.task_drawer.result_blocked', { reason: c.refused_reason }), color: 'orange' }
    }
    const prefix = c.is_fallback
        ? `${t('text.ai_runtime.task_drawer.result_fallback', { attempt: c.attempt || 2 })} · `
        : ''
    if (c.failed) return { text: `${prefix}${t('text.ai_runtime.task_drawer.result_error')}`, color: 'red' }
    if (c.is_fallback) return { text: `${prefix}${t('text.ai_runtime.task_drawer.result_ok')}`, color: 'gold' }
    return { text: t('text.ai_runtime.task_drawer.result_ok'), color: 'green' }
}
const originText = (o) =>
    ({
        http: t('text.ai_runtime.task_drawer.origin_http'),
        queue: t('text.dashboard.queue'),
        console: t('text.ai_runtime.task_drawer.origin_console'),
    })[o] ||
    o ||
    '—'
const truncateId = (id) => (String(id).length > 12 ? `${String(id).slice(0, 12)}…` : id)
const copyId = async (id) => {
    try {
        await navigator.clipboard.writeText(String(id))
        message.success(t('text.ai_runtime.task_drawer.copied'))
    } catch {
        message.error(t('text.ai_runtime.task_drawer.copy_failed'))
    }
}

// Fill the form once per opened task (not on every overview refresh).
watch(
    () => rt.editingTaskKey.value,
    () => {
        const r = row.value
        error.value = ''
        fallbackNotice.value = ''
        if (!r) return
        loadCalls()
        Object.assign(form, {
            task: r.task,
            provider: r.provider || '',
            model: r.model || '',
            temperature: r.temperature ?? null,
            reasoning_effort: r.reasoning_effort || '',
            fallback_list: splitModels(r.fallback_models),
        })
        guard.snapshot()
    },
    { immediate: true }
)

const providerOptions = computed(() => [
    { value: '', label: t('text.ai_runtime.task_drawer.provider_default') },
    ...rt.drivers.value.map((d) => ({ value: d.key, label: d.label })),
])

const effectiveProvider = computed(
    () => form.provider || row.value?.effective?.provider || rt.overview.value.default_provider || ''
)
const showReasoning = computed(() => effectiveProvider.value === 'openai')

const priceLabel = (name) => {
    const p = rt.pricing.value[name]
    if (!p) return name
    const price = t('text.ai_runtime.task_drawer.price_per_1m', {
        input: `$${p.input_per_1m_usd}`,
        output: `$${p.output_per_1m_usd}`,
    })
    return `${name} · ${price}`
}
// Model families per provider; unknown providers are not filtered.
const PROVIDER_MODEL_PATTERNS = {
    openai: /^(gpt-|o[134]|text-embedding-)/i,
    anthropic: /^claude-/i,
    gemini: /^gemini-/i,
    grok: /^grok-/i,
    deepseek: /^deepseek-/i,
}
// Provider used to narrow suggestions: the selected one, else the task's effective one.
const suggestionProvider = computed(() => effectiveProvider.value)
const providerModels = computed(() => {
    const key = suggestionProvider.value
    const pattern = PROVIDER_MODEL_PATTERNS[key]
    const own = (rt.overview.value.providers || []).find((p) => p.key === key)
    // The provider's own default model (admin, then env) goes first.
    const defaults = [own?.model, own?.env_model].filter(Boolean)
    const names = [...defaults, ...Object.keys(rt.pricing.value)].filter(
        (n) => defaults.includes(n) || !pattern || pattern.test(n)
    )
    return [...new Set(names)]
})
// Suggestions also follow the typed text (case-insensitive); free text stays accepted.
const modelOptions = computed(() => {
    const q = String(form.model || '').trim().toLowerCase()
    return providerModels.value
        .filter((n) => !q || n.toLowerCase().includes(q))
        .map((n) => ({ value: n, label: priceLabel(n) }))
})
const fallbackOptions = computed(() =>
    [...new Set([...providerModels.value, ...form.fallback_list])].map((n) => ({ value: n, label: n }))
)

const setFallback = (values) => {
    const clean = [...new Set((values || []).map((v) => String(v).trim()).filter(Boolean))]
    fallbackNotice.value =
        clean.length > MAX_FALLBACK_MODELS
            ? t('text.ai_runtime.task_drawer.fallback_limit', { max: MAX_FALLBACK_MODELS })
            : ''
    form.fallback_list = clean.slice(0, MAX_FALLBACK_MODELS)
}


const save = async () => {
    if (saving.value) return
    saving.value = true
    error.value = ''
    try {
        const result = await rt.saveTask(form)
        if (result.ok) close()
        else error.value = result.error
    } finally {
        saving.value = false
    }
}
</script>
