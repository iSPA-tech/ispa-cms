<template>
    <div class="space-y-2" data-testid="classify-labels-picker">
        <a-select
            class="w-full"
            :value="source"
            :options="sourceOptions"
            :loading="loading"
            :disabled="readOnly"
            :aria-label="t('text.workflow.pick.labels_from')"
            data-testid="classify-labels-source"
            @update:value="(key) => (picked = key)"
        />
        <p v-if="failed" class="mb-0 break-words text-xs text-amber-700" role="alert">
            {{ t('text.workflow.pick.presets_failed') }}
            <a-button type="link" size="small" class="!h-auto !p-0 !text-xs" @click="load">{{ t('text.workflow.pick.retry') }}</a-button>
        </p>

        <div class="flex flex-wrap gap-1.5" data-testid="classify-labels-chips">
            <a-button
                v-for="row in chips"
                :key="row.key"
                size="small"
                :type="row.on ? 'primary' : 'default'"
                :ghost="row.on"
                :aria-pressed="row.on ? 'true' : 'false'"
                :disabled="readOnly"
                class="!h-auto !whitespace-normal !break-words !text-left"
                :data-label-key="row.key"
                @click="toggle(row)"
            >
                {{ row.on ? '✓ ' : '' }}{{ row.label }}
            </a-button>
        </div>
        <p v-if="!chips.length && !loading" class="mb-0 break-words text-xs text-gray-600" data-testid="classify-labels-none">
            {{ source ? t('text.workflow.pick.labels_source_empty') : t('text.workflow.pick.labels_own_empty') }}
        </p>

        <div v-if="!source && !readOnly" class="flex items-center gap-2">
            <a-input
                v-model:value="ownLabel"
                size="small"
                class="min-w-0 flex-1"
                :placeholder="t('text.workflow.pick.labels_own_placeholder')"
                :aria-label="t('text.workflow.pick.labels_own_placeholder')"
                data-testid="classify-labels-own-input"
                @press-enter="addOwn"
            />
            <a-button size="small" :disabled="!ownLabel.trim()" data-testid="classify-labels-own-add" @click="addOwn">{{ t('text.workflow.pick.add') }}</a-button>
        </div>
        <p class="mb-0 break-words text-xs text-gray-600">{{ t('text.workflow.pick.labels_hint') }}</p>
        <p v-if="!stored.length" class="mb-0 break-words text-xs text-amber-700" data-testid="classify-labels-empty">{{ t('text.workflow.pick.labels_empty') }}</p>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchWorkflowPresets } from '@/services/workflowApi'
import { labelSourceOf, readLabels, toggleLabel } from './widgetRules.js'

/**
 * Labels of an "AI classify" step, picked from something the site already has (its post categories, a ready-made
 * set) or typed as own labels. Every label is one output of the step. Stored as the engine reads it; a label that
 * was stored keeps its exact value.
 */
defineOptions({ name: 'ClassifyLabelsPicker' })

const props = defineProps({
    modelValue: { type: null, default: () => [] },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const presets = ref([])
const loading = ref(false)
const failed = ref(false)
const load = async () => {
    loading.value = true
    failed.value = false
    try {
        presets.value = await fetchWorkflowPresets('classify_labels')
    } catch (error) {
        failed.value = true
    } finally {
        loading.value = false
    }
}
onMounted(load)

const stored = computed(() => readLabels(props.modelValue))
/** The source the operator picked here; until then, the set that covers the stored labels. */
const picked = ref(null)
const source = computed(() => (picked.value !== null ? picked.value : labelSourceOf(props.modelValue, presets.value)))
const sourceOptions = computed(() => [
    ...presets.value.map((row) => ({ value: row.key, label: row.label || row.key })),
    { value: '', label: t('text.workflow.pick.labels_own') },
])

const chips = computed(() => {
    const preset = presets.value.find((row) => row.key === source.value)
    const rows = (preset?.items || []).map((item) => ({ key: String(item.key), label: item.label || String(item.key) }))
    // A stored label the source does not list stays visible, so it can be seen and switched off.
    stored.value.forEach((row) => {
        if (!rows.some((item) => item.key === row.key)) rows.push({ key: row.key, label: row.label || row.key })
    })
    return rows.map((row) => ({ ...row, on: stored.value.some((item) => item.key === row.key) }))
})

const toggle = (row) => !props.readOnly && emit('update:modelValue', toggleLabel(props.modelValue, row.key, row.label))

const ownLabel = ref('')
const addOwn = () => {
    const key = ownLabel.value.trim()
    if (!key) return
    if (!stored.value.some((row) => row.key === key)) emit('update:modelValue', toggleLabel(props.modelValue, key))
    ownLabel.value = ''
}
</script>
