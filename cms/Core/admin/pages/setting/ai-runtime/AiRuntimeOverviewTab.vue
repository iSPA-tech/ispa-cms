<template>
    <div class="space-y-4">
        <section class="i-box space-y-3">
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-slate-500" for="usage-month">{{ $t('text.ai_runtime.usage.month') }}</label>
                    <a-select
                        id="usage-month"
                        v-model:value="rt.usageMonth.value"
                        :options="rt.monthOptions"
                        class="w-40"
                        @change="rt.loadUsage"
                    />
                </div>
                <a-button :loading="rt.usageLoading.value" @click="rt.loadUsage">{{ $t('text.ai_runtime.common.reload') }}</a-button>
            </div>
            <p class="text-[11px] text-slate-500">{{ $t('text.ai_runtime.usage.note') }}</p>

            <div v-if="rt.usageLoading.value" class="text-xs text-slate-500" role="status">
                {{ $t('text.ai_runtime.usage.loading') }}
            </div>
            <div
                v-else-if="rt.usageError.value"
                class="flex flex-wrap items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                role="alert"
            >
                <span>{{ rt.usageError.value }}</span>
                <a-button size="small" @click="rt.loadUsage">{{ $t('text.ai_runtime.common.retry') }}</a-button>
            </div>
            <template v-else-if="usage">
                <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="text-xs text-slate-500">{{ $t('text.ai_runtime.usage.estimated_cost') }}</div>
                        <div class="text-xl font-semibold text-slate-800">{{ formatUsd(usage.estimated_spent_usd) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="text-xs text-slate-500">{{ $t('text.ai_runtime.budget.title') }}</div>
                        <div class="text-xl font-semibold text-slate-800">
                            {{ cap > 0 ? formatUsd(cap) : $t('text.ai_runtime.usage.unlimited') }}
                        </div>
                        <div v-if="cap > 0 && usage.remaining_usd != null" class="text-xs text-slate-500">
                            {{ $t('text.ai_runtime.usage.remaining', { amount: formatUsd(usage.remaining_usd) }) }}
                        </div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="text-xs text-slate-500">{{ $t('text.ai_runtime.usage.calls') }}</div>
                        <div class="text-xl font-semibold text-slate-800">{{ formatInt(usage.request_count) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="text-xs text-slate-500">{{ $t('text.ai_runtime.usage.error_rate') }}</div>
                        <div class="text-xl font-semibold text-slate-800">
                            {{ percent(usage.failed_count, usage.request_count) }}%
                        </div>
                        <div class="text-xs text-slate-500">
                            {{
                                $t('text.ai_runtime.usage.failed_of', {
                                    failed: formatInt(usage.failed_count),
                                    total: formatInt(usage.request_count),
                                })
                            }}
                        </div>
                    </div>
                </div>

                <div v-if="rt.usageSpentPercent.value !== null">
                    <div
                        :aria-valuenow="Math.min(rt.usageSpentPercent.value, 100)"
                        :aria-label="$t('text.ai_runtime.usage.progress_label')"
                        aria-valuemax="100"
                        aria-valuemin="0"
                        class="h-2 w-full overflow-hidden rounded bg-slate-100"
                        role="progressbar"
                    >
                        <div
                            :class="rt.usageSpentPercent.value >= 100 ? 'bg-red-500' : 'bg-cyan-500'"
                            :style="{ width: `${Math.min(rt.usageSpentPercent.value, 100)}%` }"
                            class="h-2"
                        />
                    </div>
                    <div class="mt-0.5 text-xs text-slate-600">
                        {{ $t('text.ai_runtime.usage.spent_percent', { percent: rt.usageSpentPercent.value }) }}
                        <template v-if="rt.usageSpentPercent.value >= 100"> · {{ $t('text.ai_runtime.spend.reached') }}</template>
                    </div>
                </div>

                <ul class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-700">
                    <li>{{ $t('text.ai_runtime.usage.fallback_used') }}: <b>{{ formatInt(usage.fallback_count) }}</b></li>
                    <li>{{ $t('text.ai_runtime.usage.refused') }}: <b>{{ formatInt(usage.refused_count) }}</b></li>
                    <li>{{ $t('text.ai_runtime.col.input_tokens') }}: <b>{{ formatInt(usage.input_tokens) }}</b></li>
                    <li>{{ $t('text.ai_runtime.usage.cached_tokens') }}: <b>{{ formatInt(usage.cached_input_tokens) }}</b></li>
                    <li>{{ $t('text.ai_runtime.col.output_tokens') }}: <b>{{ formatInt(usage.output_tokens) }}</b></li>
                </ul>
            </template>
        </section>

        <div
            v-if="usage && !usage.request_count && !usage.refused_count"
            class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
            {{ $t('text.ai_runtime.usage.empty_month') }}
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <form class="i-box space-y-3 xl:col-span-1" @submit.prevent="submitBudget">
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-slate-800">{{ $t('text.ai_runtime.budget.title') }}</legend>
                    <div>
                        <label class="text-xs text-slate-500" for="budget-monthly">{{ $t('text.ai_runtime.budget.monthly') }}</label>
                        <a-input-number
                            id="budget-monthly"
                            v-model:value="budgetForm.monthly_usd"
                            :min="0"
                            :precision="2"
                            aria-describedby="budget-monthly-help"
                            class="w-full"
                        />
                        <p id="budget-monthly-help" class="mt-0.5 text-[11px] text-slate-500">
                            {{ $t('text.ai_runtime.budget.monthly_help') }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500" for="budget-warning">{{ $t('text.ai_runtime.budget.warning') }}</label>
                        <a-input-number
                            id="budget-warning"
                            v-model:value="budgetForm.warning_percent"
                            :max="100"
                            :min="1"
                            :precision="0"
                            aria-describedby="budget-warning-help"
                            class="w-full"
                        />
                        <p id="budget-warning-help" class="mt-0.5 text-[11px] text-slate-500">
                            {{ $t('text.ai_runtime.budget.warning_help') }}
                        </p>
                    </div>
                </fieldset>
                <fieldset class="space-y-3 border-t border-slate-200 pt-3">
                    <legend class="text-sm font-semibold text-slate-800">{{ $t('text.ai_runtime.spike.title') }}</legend>
                    <div class="flex items-center gap-2">
                        <a-switch id="spike-enabled" v-model:checked="budgetForm.spike_enabled" />
                        <label class="text-xs text-slate-700" for="spike-enabled">
                            {{ $t('text.ai_runtime.spike.enable') }}:
                            <b>{{ $t(budgetForm.spike_enabled ? 'text.ai_runtime.spike.on' : 'text.ai_runtime.spike.off') }}</b>
                        </label>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500" for="spike-multiplier">
                            {{ $t('text.ai_runtime.spike.multiplier') }}
                        </label>
                        <a-input-number
                            id="spike-multiplier"
                            v-model:value="budgetForm.spike_multiplier"
                            :max="20"
                            :min="1"
                            :step="0.5"
                            aria-describedby="spike-help"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <label class="text-xs text-slate-500" for="spike-min">{{ $t('text.ai_runtime.spike.min') }}</label>
                        <a-input-number
                            id="spike-min"
                            v-model:value="budgetForm.spike_min_usd"
                            :min="0"
                            :precision="2"
                            aria-describedby="spike-help"
                            class="w-full"
                        />
                    </div>
                    <p id="spike-help" class="text-[11px] text-slate-500">
                        {{ $t('text.ai_runtime.spike.help', { days: baselineDays }) }}
                    </p>
                </fieldset>
                <a-button :disabled="savingBudget" :loading="savingBudget" html-type="submit" type="primary">
                    {{ $t('text.ai_runtime.budget.save') }}
                </a-button>
            </form>

            <div class="space-y-4 xl:col-span-2">
                <section class="i-box space-y-2">
                    <h3 class="text-sm font-semibold text-slate-800">{{ $t('text.ai_runtime.top.title') }}</h3>
                    <div v-if="!topTasks.length" class="text-xs text-slate-500">{{ $t('text.ai_runtime.top.empty') }}</div>
                    <a-table
                        v-else
                        :columns="topColumns"
                        :data-source="topTasks"
                        :pagination="false"
                        :scroll="{ x: 560 }"
                        row-key="task_key"
                        size="small"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'label'">
                                <button
                                    v-if="record.configurable"
                                    :aria-label="$t('text.ai_runtime.top.open_task', { label: record.label })"
                                    class="text-left font-medium text-cyan-700 hover:underline"
                                    type="button"
                                    @click="rt.openTask(record.task_key)"
                                >
                                    {{ record.label }}
                                </button>
                                <span v-else class="font-medium">{{ record.label }}</span>
                            </template>
                            <template v-else-if="column.key === 'calls'">{{ formatInt(record.request_count) }}</template>
                            <template v-else-if="column.key === 'failed'">{{ formatInt(record.failed_count) }}</template>
                            <template v-else-if="column.key === 'latency'">
                                {{ formatSeconds(record.latency_ms_total, record.request_count) }}
                            </template>
                            <template v-else-if="column.key === 'cost'">{{ formatUsd(record.estimated_cost_usd) }}</template>
                        </template>
                    </a-table>
                </section>

                <section class="i-box space-y-2">
                    <h3 class="text-sm font-semibold text-slate-800">{{ $t('text.ai_runtime.models.title') }}</h3>
                    <div v-if="!byModel.length" class="text-xs text-slate-500">{{ $t('text.ai_runtime.models.empty') }}</div>
                    <a-table
                        v-else
                        :columns="modelColumns"
                        :data-source="byModel"
                        :pagination="false"
                        :row-key="(r) => `${r.provider}/${r.model}`"
                        :scroll="{ x: 700 }"
                        size="small"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'model'">
                                {{ record.model }}
                                <a-tag v-if="record.priced === false" color="orange">{{ $t('text.ai_runtime.models.unpriced') }}</a-tag>
                            </template>
                            <template v-else-if="column.key === 'calls'">{{ formatInt(record.request_count) }}</template>
                            <template v-else-if="column.key === 'in'">{{ formatInt(record.input_tokens) }}</template>
                            <template v-else-if="column.key === 'out'">{{ formatInt(record.output_tokens) }}</template>
                            <template v-else-if="column.key === 'latency'">
                                {{ formatSeconds(record.latency_ms_total, record.request_count) }}
                            </template>
                            <template v-else-if="column.key === 'cost'">{{ formatUsd(record.estimated_cost_usd) }}</template>
                            <template v-else>{{ record[column.dataIndex] }}</template>
                        </template>
                    </a-table>
                </section>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatInt, formatSeconds, formatUsd, percent, useAiRuntime } from './useAiRuntime'

const rt = useAiRuntime()
const { t } = useI18n()

const usage = computed(() => rt.usage.value)
const cap = computed(() => Number(usage.value?.monthly_budget_usd || 0))

const byCost = (a, b) => Number(b.estimated_cost_usd) - Number(a.estimated_cost_usd)
const topTasks = computed(() =>
    [...(usage.value?.by_task || [])]
        .sort(byCost)
        .slice(0, 5)
        .map((r) => ({
            ...r,
            label: rt.taskLabel(r.task_key),
            configurable: rt.taskRows.value.find((t) => t.task === r.task_key)?.configurable === true,
        }))
)
const byModel = computed(() => [...(usage.value?.by_model || [])].sort(byCost))

const right = { align: 'right' }
const topColumns = computed(() => [
    { title: t('text.ai_runtime.col.task'), key: 'label' },
    { title: t('text.ai_runtime.col.calls'), key: 'calls', ...right },
    { title: t('text.ai_runtime.col.failed'), key: 'failed', ...right },
    { title: t('text.ai_runtime.col.avg_latency'), key: 'latency', ...right },
    { title: t('text.ai_runtime.col.cost'), key: 'cost', ...right },
])
const modelColumns = computed(() => [
    { title: t('text.ai_runtime.col.provider'), dataIndex: 'provider', key: 'provider' },
    { title: t('text.ai_runtime.col.model'), key: 'model' },
    { title: t('text.ai_runtime.col.calls'), key: 'calls', ...right },
    { title: t('text.ai_runtime.col.input_tokens'), key: 'in', ...right },
    { title: t('text.ai_runtime.col.output_tokens'), key: 'out', ...right },
    { title: t('text.ai_runtime.col.avg_latency'), key: 'latency', ...right },
    { title: t('text.ai_runtime.col.cost'), key: 'cost', ...right },
])

const budgetForm = reactive({
    monthly_usd: 0,
    warning_percent: 80,
    spike_known: false,
    spike_enabled: false,
    spike_multiplier: 3,
    spike_min_usd: 0,
})
const baselineDays = computed(() => Number(rt.overview.value.budget?.spike?.baseline_days) || 7)
const savingBudget = ref(false)
watch(
    () => rt.overview.value.budget,
    (b) => {
        budgetForm.monthly_usd = Number(b?.monthly_usd) || 0
        budgetForm.warning_percent = Number(b?.warning_percent) || 80
        const spike = b?.spike
        budgetForm.spike_known = Boolean(spike)
        budgetForm.spike_enabled = Boolean(spike?.enabled)
        budgetForm.spike_multiplier = Number(spike?.multiplier) || 3
        budgetForm.spike_min_usd = Number(spike?.min_usd) || 0
    },
    { immediate: true }
)

const submitBudget = async () => {
    if (savingBudget.value) return
    savingBudget.value = true
    try {
        await rt.saveBudget(budgetForm)
    } finally {
        savingBudget.value = false
    }
}
</script>
