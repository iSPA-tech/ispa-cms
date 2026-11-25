<template>
    <div class="space-y-2" data-testid="rubric-rows">
        <a-select
            v-if="presets.length || loading"
            class="w-full"
            :value="undefined"
            :options="presetOptions"
            :loading="loading"
            :disabled="readOnly"
            :placeholder="t('text.workflow.pick.rubric_set')"
            :aria-label="t('text.workflow.pick.rubric_set')"
            data-testid="rubric-preset"
            @update:value="applyPreset"
        />
        <p v-if="failed" class="mb-0 break-words text-xs text-amber-700" role="alert">{{ t('text.workflow.pick.presets_failed') }}</p>
        <p v-if="!rows.length" class="mb-0 break-words text-xs text-amber-700" data-testid="rubric-empty">{{ t('text.workflow.pick.rubric_empty') }}</p>

        <div v-for="(row, index) in rows" :key="index" class="space-y-1 rounded-md border border-solid border-slate-200 p-2" data-testid="rubric-row">
            <div class="flex items-center gap-1">
                <a-input
                    class="min-w-0 flex-1"
                    :value="row.description"
                    :disabled="readOnly"
                    :placeholder="t('text.workflow.pick.rubric_criterion')"
                    :aria-label="t('text.workflow.pick.rubric_criterion')"
                    data-testid="rubric-description"
                    @update:value="(description) => setRow(index, { description })"
                />
                <a-button
                    v-if="!readOnly"
                    size="small"
                    type="text"
                    danger
                    :aria-label="t('text.workflow.schema_form.remove_row')"
                    :title="t('text.workflow.schema_form.remove_row')"
                    @click="commit(rows.filter((_, i) => i !== index))"
                >
                    <i-icon icon="trash-can-outline" class="h-4 w-4" />
                </a-button>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-700">
                <span>{{ t('text.workflow.pick.rubric_weight') }}</span>
                <a-input-number size="small" :value="row.weight ?? 1" :min="0" :max="100" :disabled="readOnly" data-testid="rubric-weight" @update:value="(weight) => setRow(index, { weight: weight ?? 1 })" />
            </label>
        </div>
        <a-button v-if="!readOnly" size="small" type="dashed" block data-testid="rubric-add" @click="addRow">+ {{ t('text.workflow.pick.rubric_add') }}</a-button>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchWorkflowPresets } from '@/services/workflowApi'

/**
 * Scoring criteria of an "AI score" step: rows of a criterion and its weight, stored as `[{ key, weight, description }]`.
 * A ready-made set fills the rows; each row can still be reworded, weighed or removed.
 */
defineOptions({ name: 'RubricRows' })

const props = defineProps({
    modelValue: { type: null, default: () => [] },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const rows = computed(() => {
    const stored = props.modelValue
    if (Array.isArray(stored)) return stored.filter((row) => row && typeof row === 'object')
    // Older configs store `{ key: description }` or `{ key: { weight, description } }`.
    if (stored && typeof stored === 'object') {
        return Object.entries(stored).map(([key, value]) => (value && typeof value === 'object' ? { key, ...value } : { key, weight: 1, description: String(value ?? '') }))
    }
    return []
})

const presets = ref([])
const loading = ref(false)
const failed = ref(false)
onMounted(async () => {
    loading.value = true
    try {
        presets.value = await fetchWorkflowPresets('score_rubric')
    } catch (error) {
        failed.value = true
    } finally {
        loading.value = false
    }
})
const presetOptions = computed(() => presets.value.map((row) => ({ value: row.key, label: row.label || row.key })))

const commit = (next) => !props.readOnly && emit('update:modelValue', next)
const applyPreset = (key) => {
    const preset = presets.value.find((row) => row.key === key)
    if (!preset) return
    commit((preset.items || []).map((item) => ({ key: item.key, weight: item.weight ?? 1, description: item.text || item.label || item.key })))
}
const setRow = (index, patch) => commit(rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row)))
const addRow = () => {
    let number = rows.value.length + 1
    while (rows.value.some((row) => row.key === `criterion_${number}`)) number++
    commit([...rows.value, { key: `criterion_${number}`, weight: 1, description: '' }])
}
</script>
