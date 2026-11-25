<template>
    <div v-if="fixed" class="space-y-2" data-testid="jev-criteria-fixed">
        <div class="flex items-start gap-2 rounded-lg border border-solid border-gray-200 bg-gray-50 px-3 py-2">
            <i-icon icon="lock-outline" class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
            <span class="min-w-0">
                <span class="block break-words text-sm text-gray-800" data-testid="jev-criteria-fixed-name">
                    {{ preset ? preset.label || preset.key : loading ? t('text.workflow.pick.loading') : t('text.workflow.pick.criteria_fixed_own') }}
                </span>
                <span class="block break-words text-xs text-gray-600">{{ t('text.workflow.pick.criteria_fixed_note') }}</span>
            </span>
        </div>
        <ul v-if="preset" class="m-0 list-none space-y-1 p-0 pl-1" data-testid="jev-criteria-fixed-items">
            <li v-for="item in preset.items" :key="item.key" class="flex items-start gap-2 text-sm text-gray-800">
                <span aria-hidden="true" class="text-green-700">✓</span>
                <span class="min-w-0 break-words">{{ item.label || item.text }}</span>
            </li>
        </ul>
    </div>
    <div v-else class="space-y-3" data-testid="jev-criteria-picker">
        <div class="space-y-1">
            <a-select
                class="w-full"
                :value="state.presetKey"
                :options="presetOptions"
                :loading="loading"
                :disabled="readOnly"
                :aria-label="t('text.workflow.pick.criteria_set')"
                data-testid="jev-criteria-preset"
                @update:value="choosePreset"
            />
            <p v-if="preset?.description" class="mb-0 break-words text-xs text-gray-600">{{ preset.description }}</p>
            <p v-if="failed" class="mb-0 break-words text-xs text-amber-700" role="alert" data-testid="jev-criteria-failed">
                {{ t('text.workflow.pick.presets_failed') }}
                <a-button type="link" size="small" class="!h-auto !p-0 !text-xs" @click="load">{{ t('text.workflow.pick.retry') }}</a-button>
            </p>
            <p v-else-if="unknownPreset" class="mb-0 break-words text-xs text-amber-700" data-testid="jev-criteria-unknown">
                {{ t('text.workflow.pick.criteria_set_missing') }}
            </p>
        </div>

        <ul v-if="preset" class="m-0 list-none space-y-1 p-0" data-testid="jev-criteria-items">
            <li v-for="item in preset.items" :key="item.key" :data-item="item.key">
                <a-checkbox :checked="state.itemKeys.includes(item.key)" :disabled="readOnly" class="!flex items-start" @update:checked="() => toggleItem(item.key)">
                    <span class="break-words text-sm text-gray-900">{{ item.label || item.text }}</span>
                </a-checkbox>
            </li>
        </ul>

        <ul v-if="state.custom.length" class="m-0 list-none space-y-1 p-0" data-testid="jev-criteria-custom">
            <li v-for="(line, index) in state.custom" :key="index" class="flex items-start gap-1">
                <a-textarea
                    :value="line"
                    :disabled="readOnly"
                    :auto-size="{ minRows: 1, maxRows: 4 }"
                    :aria-label="t('text.workflow.pick.own_criterion')"
                    data-testid="jev-criteria-custom-line"
                    @update:value="(text) => setCustom(index, text)"
                />
                <a-button
                    v-if="!readOnly"
                    size="small"
                    type="text"
                    danger
                    :aria-label="t('text.workflow.schema_form.remove_row')"
                    :title="t('text.workflow.schema_form.remove_row')"
                    @click="removeCustom(index)"
                >
                    <i-icon icon="trash-can-outline" class="h-4 w-4" />
                </a-button>
            </li>
        </ul>
        <a-button v-if="!readOnly" type="link" size="small" class="!h-auto !p-0" data-testid="jev-criteria-add" @click="addCustom">
            + {{ t('text.workflow.pick.own_criterion') }}
        </a-button>
        <p v-if="!criteriaText" class="mb-0 break-words text-xs text-amber-700" data-testid="jev-criteria-empty">{{ t('text.workflow.pick.criteria_empty') }}</p>

        <div class="space-y-1">
            <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.pick.strictness') }}</p>
            <div class="flex flex-wrap gap-1" role="group" :aria-label="t('text.workflow.pick.strictness')" data-testid="jev-criteria-strictness">
                <a-button
                    v-for="level in STRICTNESS"
                    :key="level.key"
                    size="small"
                    :type="strictness === level.key ? 'primary' : 'default'"
                    :aria-pressed="strictness === level.key ? 'true' : 'false'"
                    :disabled="readOnly"
                    :data-level="level.key"
                    @click="form?.patch({ min_confidence: level.value })"
                >
                    {{ t(`text.workflow.pick.strictness_${level.key}`) }}
                </a-button>
            </div>
            <p class="mb-0 break-words text-xs text-gray-600" data-testid="jev-criteria-strictness-note">
                {{ strictness ? t(`text.workflow.pick.strictness_${strictness}_hint`) : t('text.workflow.pick.strictness_own', { value: ownStrictness }) }}
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchWorkflowPresets } from '@/services/workflowApi'
import { useStepForm } from '@/components/workflow/inputs/useStepData.js'
import { STRICTNESS, defaultItemKeys, readCriteria, strictnessOf, writeCriteria } from './widgetRules.js'

/**
 * What the JEV judge checks: a ready-made set of criteria, each one on or off, the operator's own lines after them,
 * and how strict the judge is. Writes `criteria` (the text the judge reads), `criteria_preset`, `criteria_items` and
 * `min_confidence`; nothing is written until the operator changes something.
 */
defineOptions({ name: 'JevCriteriaPicker' })

const props = defineProps({
    modelValue: { type: null, default: '' },
    readOnly: { type: Boolean, default: false },
    /** The step always checks the same set: it is shown, and nothing can be changed. */
    fixed: { type: Boolean, default: false },
})

const { t } = useI18n()
const form = useStepForm()

const presets = ref([])
const loading = ref(false)
const failed = ref(false)
const load = async () => {
    loading.value = true
    failed.value = false
    try {
        presets.value = await fetchWorkflowPresets('jev_criteria')
    } catch (error) {
        failed.value = true
    } finally {
        loading.value = false
    }
}
onMounted(load)

const config = computed(() => ({ ...(form?.config.value || {}), criteria: props.modelValue }))
const criteriaText = computed(() => String(props.modelValue ?? '').trim())

// What the form shows. It follows the stored settings, except for own lines being typed (an empty line is not stored).
const state = ref({ presetKey: '', itemKeys: [], custom: [] })
let written = null
let readWith = null
watch(
    [config, presets],
    () => {
        const stored = JSON.stringify([config.value.criteria ?? '', config.value.criteria_preset ?? null, config.value.criteria_items ?? null])
        // Read again when the sets arrive: until then a stored set cannot be shown.
        if (stored === written && presets.value === readWith) return
        written = stored
        readWith = presets.value
        state.value = readCriteria(config.value, presets.value)
    },
    { immediate: true, deep: true }
)

const preset = computed(() => presets.value.find((row) => row.key === state.value.presetKey) || null)
/** The sample names a set this site does not have (its module is off): the criteria text is kept as own lines. */
const unknownPreset = computed(() => !loading.value && !failed.value && !!config.value.criteria_preset && !presets.value.some((row) => row.key === config.value.criteria_preset))

const presetOptions = computed(() => [
    ...presets.value.map((row) => ({ value: row.key, label: row.label || row.key })),
    { value: '', label: t('text.workflow.pick.criteria_set_own') },
])

const write = (next) => {
    if (props.readOnly || props.fixed || !form) return
    state.value = next
    const values = writeCriteria(next, presets.value)
    written = JSON.stringify([values.criteria ?? '', values.criteria_preset ?? null, values.criteria_items ?? null])
    form.patch(values)
}

const choosePreset = (key) => {
    const next = presets.value.find((row) => row.key === key) || null
    write({ presetKey: next ? next.key : '', itemKeys: next ? defaultItemKeys(next) : [], custom: state.value.custom })
}
const toggleItem = (key) => {
    const keys = state.value.itemKeys
    write({ ...state.value, itemKeys: keys.includes(key) ? keys.filter((item) => item !== key) : [...keys, key] })
}
const setCustom = (index, text) => write({ ...state.value, custom: state.value.custom.map((line, i) => (i === index ? String(text ?? '').replace(/\n+/g, ' ') : line)) })
const removeCustom = (index) => write({ ...state.value, custom: state.value.custom.filter((_, i) => i !== index) })
// A new empty line is only shown; it is stored once it has text.
const addCustom = () => (state.value = { ...state.value, custom: [...state.value.custom, ''] })

const strictness = computed(() => strictnessOf(form?.config.value?.min_confidence ?? 0.8))
const ownStrictness = computed(() => String(form?.config.value?.min_confidence ?? ''))
</script>
