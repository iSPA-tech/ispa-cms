<template>
    <section class="i-box space-y-3">
        <p class="text-xs text-slate-500">
            {{ $t('text.ai_runtime.tasks.intro', { month: rt.usageMonth.value }) }}
        </p>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div>
                <label class="block text-xs text-slate-500" for="task-search">{{ $t('text.ai_runtime.tasks.search') }}</label>
                <a-input
                    id="task-search"
                    v-model:value="search"
                    :placeholder="$t('text.ai_runtime.tasks.search_placeholder')"
                    allow-clear
                />
            </div>
            <div>
                <label class="block text-xs text-slate-500" for="task-module">{{ $t('text.ai_runtime.tasks.module') }}</label>
                <a-select id="task-module" v-model:value="moduleFilter" :options="moduleOptions" class="w-full" />
            </div>
            <div>
                <label class="block text-xs text-slate-500" for="task-sort">{{ $t('text.ai_runtime.tasks.sort') }}</label>
                <a-select id="task-sort" v-model:value="sortBy" :options="sortOptions" class="w-full" />
            </div>
        </div>

        <div
            v-if="!rt.taskRows.value.length"
            class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
            {{ $t('text.ai_runtime.tasks.empty') }}
        </div>
        <div
            v-else-if="!rows.length"
            class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
        >
            {{ $t('text.ai_runtime.tasks.no_match') }}
        </div>
        <a-table
            v-else
            :columns="columns"
            :data-source="rows"
            :pagination="rows.length > 20 ? { pageSize: 20, size: 'small' } : false"
            :scroll="{ x: 620 }"
            row-key="task"
            size="small"
        >
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'task'">
                    <div class="font-medium text-slate-800">{{ record.label }}</div>
                    <div class="text-[11px] text-slate-500">
                        {{ record.task }}<template v-if="record.module"> · {{ record.module }}</template>
                    </div>
                </template>
                <template v-else-if="column.key === 'effective'">
                    <div v-if="record.effective.model || record.effective.provider">
                        <span v-if="record.effective.provider" class="inline-block whitespace-nowrap">{{ record.effective.provider }}</span>
                        <template v-if="record.effective.provider && record.effective.model"> · </template>
                        <span v-if="record.effective.model" class="inline-block whitespace-nowrap">{{ record.effective.model }}</span>
                    </div>
                    <div v-else class="text-slate-400">—</div>
                    <div class="mt-0.5">
                        <a-tag>{{ sourceText(record.effective.source) }}</a-tag>
                        <a-tag v-if="isUnpriced(record)" color="orange">{{ $t('text.ai_runtime.tasks.unpriced') }}</a-tag>
                        <a-tooltip v-if="fallbackList(record).length" :title="fallbackList(record).join(', ')">
                            <a-tag>
                                {{ $t('text.ai_runtime.tasks.fallback_count', { count: fallbackList(record).length }) }}
                            </a-tag>
                        </a-tooltip>
                    </div>
                </template>
                <template v-else-if="column.key === 'cost'">
                    <div class="font-medium text-slate-800">{{ formatUsd(record.cost) }}</div>
                    <div class="text-[11px] text-slate-500">
                        {{ $t('text.ai_runtime.tasks.calls_count', { count: formatInt(record.calls) }) }}<template v-if="record.calls"> · {{ $t('text.ai_runtime.tasks.error_percent', { percent: percent(record.failed, record.calls) }) }}</template>
                    </div>
                </template>
                <template v-else-if="column.key === 'action'">
                    <a-button
                        v-if="!record.configurable"
                        :aria-label="$t('text.ai_runtime.tasks.view_aria', { label: record.label })"
                        size="small"
                        @click="rt.editingTaskKey.value = record.task"
                    >
                        {{ $t('text.ai_runtime.common.view') }}
                    </a-button>
                    <a-button
                        v-else
                        :aria-label="$t('text.ai_runtime.tasks.edit_aria', { label: record.label })"
                        size="small"
                        @click="rt.editingTaskKey.value = record.task"
                    >
                        {{ $t('text.ai_runtime.common.edit') }}
                    </a-button>
                </template>
            </template>
        </a-table>

        <AiRuntimeTaskDrawer />
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AiRuntimeTaskDrawer from './AiRuntimeTaskDrawer.vue'
import { formatInt, formatUsd, percent, splitModels, useAiRuntime } from './useAiRuntime'

const rt = useAiRuntime()
const { t } = useI18n()

const search = ref('')
const moduleFilter = ref('')
const sortBy = ref('cost')
const sortOptions = computed(() => [
    { value: 'cost', label: t('text.ai_runtime.col.monthly_cost') },
    { value: 'calls', label: t('text.ai_runtime.tasks.sort_calls') },
    { value: 'name', label: t('text.ai_runtime.tasks.sort_name') },
])
const moduleOptions = computed(() => [
    { value: '', label: t('text.ai_runtime.tasks.all_modules') },
    ...[...new Set(rt.taskRows.value.map((r) => r.module).filter(Boolean))]
        .sort()
        .map((m) => ({ value: m, label: m })),
])

const rows = computed(() => {
    const q = search.value.trim().toLowerCase()
    const list = rt.taskRows.value.filter(
        (r) =>
            (!moduleFilter.value || r.module === moduleFilter.value) &&
            (!q || r.label.toLowerCase().includes(q) || r.task.toLowerCase().includes(q))
    )
    const sorters = {
        cost: (a, b) => b.cost - a.cost,
        calls: (a, b) => b.calls - a.calls,
        name: (a, b) => a.label.localeCompare(b.label, 'vi'),
    }
    return list.sort(sorters[sortBy.value])
})

const columns = computed(() => [
    { title: t('text.ai_runtime.col.task'), key: 'task', width: 200 },
    { title: t('text.ai_runtime.col.monthly_cost'), key: 'cost', align: 'right', width: 120 },
    { title: t('text.ai_runtime.col.effective'), key: 'effective', width: 210 },
    // Pinned so the edit button stays reachable; widths sum to the table's scroll.x.
    { title: '', key: 'action', width: 90, fixed: 'right' },
])

const sourceText = (source) =>
    t(
        { task: 'text.ai_runtime.tasks.source_task', observed: 'text.ai_runtime.tasks.source_observed' }[source] ||
            'text.ai_runtime.tasks.source_none'
    )
const fallbackList = (row) => splitModels(row.fallback_models)
const isUnpriced = (row) =>
    Boolean(row.effective.model) &&
    Object.keys(rt.pricing.value).length > 0 &&
    !(row.effective.model in rt.pricing.value)
</script>
