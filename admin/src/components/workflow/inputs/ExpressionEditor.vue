<template>
    <div class="w-full space-y-1" data-testid="expression-editor" :data-variant="variant">
        <label v-if="label" class="text-sm font-semibold text-gray-700">{{ label }}</label>

        <!-- One piece of data, picked from a list -->
        <template v-if="variant === 'pick' && !editingFormula">
            <div
                class="flex min-h-[2.25rem] flex-wrap items-center gap-1 rounded-lg border border-solid px-2 py-1"
                :class="disabled ? 'border-gray-200 bg-gray-50' : 'border-gray-300 bg-white'"
            >
                <span
                    v-if="view.kind === 'chip'"
                    class="inline-flex max-w-full items-center gap-1 rounded-md border border-solid border-cyan-200 bg-cyan-50 px-2 text-xs leading-5 text-cyan-900"
                    :title="view.raw"
                    data-testid="expression-chip"
                >
                    <span class="truncate">{{ stepData.labelFor(view.path) }}</span>
                    <button
                        v-if="!disabled"
                        type="button"
                        class="-mr-1 inline-flex cursor-pointer items-center border-0 bg-transparent p-0 text-cyan-800 hover:text-cyan-950"
                        :aria-label="t('text.workflow.data.remove')"
                        :title="t('text.workflow.data.remove')"
                        data-testid="expression-clear"
                        @click="setValue('')"
                    >
                        <i-icon icon="close" class="h-3.5 w-3.5" />
                    </button>
                </span>
                <span
                    v-else-if="view.kind === 'formula'"
                    class="min-w-0 break-all rounded-md border border-solid border-slate-300 bg-slate-100 px-2 font-mono text-xs leading-5 text-slate-700"
                    data-testid="expression-formula"
                >
                    {{ view.raw }}
                </span>
                <span v-else class="text-sm text-gray-500">{{ t('text.workflow.data.none_picked') }}</span>
                <a-button
                    v-if="!disabled"
                    type="link"
                    size="small"
                    class="!ml-auto !px-1"
                    :aria-expanded="pickerOpen ? 'true' : 'false'"
                    data-testid="expression-pick"
                    @click="pickerOpen = !pickerOpen"
                >
                    {{ view.kind === 'empty' ? t('text.workflow.data.pick') : t('text.workflow.data.change') }}
                </a-button>
            </div>
            <p v-if="sampleText" class="mb-0 break-words rounded-md bg-gray-50 px-2 py-1 text-xs text-gray-600" data-testid="expression-sample">
                {{ t('text.workflow.data.sample_label') }}: {{ sampleText }}
            </p>
            <p v-if="view.kind === 'formula'" class="mb-0 break-words text-xs text-gray-600">
                {{ t('text.workflow.data.formula_note') }}
                <a-button v-if="canWrite && !disabled" type="link" size="small" class="!h-auto !p-0 !text-xs" data-testid="expression-edit-formula" @click="editingFormula = true">
                    {{ t('text.workflow.data.formula_edit') }}
                </a-button>
            </p>
        </template>

        <!-- A condition or a calculation: typed, with data inserted from the list and checked while typing -->
        <template v-else>
            <a-textarea
                ref="textareaRef"
                v-model:value="localValue"
                :auto-size="{ minRows: 1, maxRows: 6 }"
                :disabled="disabled"
                :placeholder="placeholder"
                :aria-label="label || placeholder"
                class="font-mono"
                data-testid="expression-text"
                @change="onTyped"
            />
            <div v-if="!disabled" class="flex flex-wrap items-center justify-between gap-x-2">
                <a-button type="link" size="small" class="!px-0" :aria-expanded="pickerOpen ? 'true' : 'false'" data-testid="expression-pick" @click="pickerOpen = !pickerOpen">
                    + {{ t('text.workflow.data.insert') }}
                </a-button>
                <span v-if="status === 'checking'" class="text-xs text-gray-600">{{ t('text.workflow.data.checking') }}</span>
                <span v-else-if="status === 'ok'" class="text-xs text-green-700" data-testid="expression-valid">{{ t('text.workflow.editor.valid') }}</span>
            </div>
        </template>

        <DataPicker
            v-if="pickerOpen && !disabled"
            :groups="stepData.groups.value"
            :loading="stepData.loading.value"
            :has-sample="stepData.hasSample.value"
            :unplaced="!stepData.placed.value"
            @pick="onPick"
            @close="pickerOpen = false"
        />

        <ul v-if="errors.length" class="m-0 list-none space-y-1 p-0" role="alert" data-testid="expression-errors">
            <li v-for="(err, idx) in errors" :key="idx" class="break-words text-xs text-red-700">{{ err.message }}</li>
        </ul>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isWebmaster } from '@/hooks/useCan'
import * as workflowApi from '@/services/workflowApi'
import DataPicker from './DataPicker.vue'
import { readPath } from './dataTokens.js'
import { useStepData } from './useStepData.js'

/**
 * A step setting stored as one expression.
 * - `variant="pick"`: the setting names one piece of data (the list to go through, the record to change). The
 *   operator picks it from a list and sees it by name; nothing is typed. A stored value that is more than a plain
 *   path is shown as it is and kept; only a Webmaster may rewrite it by hand.
 * - `variant="formula"` (default): a condition or a calculation. It is typed, data is inserted from the same list,
 *   and it is checked while typing (there is no "Check" button).
 * The stored text is never rewritten: only a pick or an edit changes it.
 */
defineOptions({ name: 'ExpressionEditor' })

const props = defineProps({
    modelValue: { type: null, default: '' },
    modelClass: { type: String, default: '' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    variant: { type: String, default: 'formula' },
    availableVars: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const stepData = useStepData()
const textOf = (value) => (value === null || value === undefined ? '' : String(value))

const localValue = ref(textOf(props.modelValue))
const textareaRef = ref()
const pickerOpen = ref(false)
const editingFormula = ref(false)
const errors = ref([])
const status = ref('idle')

const view = computed(() => readPath(localValue.value))
const canWrite = computed(() => isWebmaster())
/** Sample of the picked data from the last run, when there is one. */
const sampleText = computed(() => {
    if (view.value.kind !== 'chip') return ''
    for (const group of stepData.groups.value) {
        const item = group.items.find((row) => row.path === view.value.path)
        if (item) return item.sample || ''
    }
    return ''
})

watch(
    () => props.modelValue,
    (value) => {
        if (textOf(value) === localValue.value) return
        localValue.value = textOf(value)
        errors.value = []
        status.value = 'idle'
    }
)

// ---------------------------------------------------------------------------------------------------------------------
// Checking while typing
// ---------------------------------------------------------------------------------------------------------------------

let timer = null
let ticket = 0
const check = async (value) => {
    const mine = ++ticket
    if (!value.trim()) {
        errors.value = []
        status.value = 'idle'
        return
    }
    status.value = 'checking'
    try {
        const res = await workflowApi.validateExpression(value, { modelClass: props.modelClass })
        if (mine !== ticket) return
        const payload = res?.data || res || {}
        const found = Array.isArray(payload.errors) ? payload.errors : []
        errors.value = found
        status.value = (payload.ok ?? false) && !found.length ? 'ok' : found.length ? 'error' : 'idle'
    } catch (error) {
        // A check that cannot run says nothing about the expression: the save still checks it.
        if (mine !== ticket) return
        errors.value = []
        status.value = 'idle'
    }
}
const checkSoon = (value) => {
    clearTimeout(timer)
    timer = setTimeout(() => check(value), 600)
}
onBeforeUnmount(() => clearTimeout(timer))

const setValue = (value) => {
    localValue.value = value
    emit('update:modelValue', value)
    // A plain data path needs no check.
    if (readPath(value).kind === 'formula') checkSoon(value)
    else {
        clearTimeout(timer)
        ticket++
        errors.value = []
        status.value = 'idle'
    }
}

const onTyped = () => setValue(localValue.value)

const insertAtCursor = (text) => {
    const el = textareaRef.value?.resizableTextArea?.textArea || textareaRef.value?.$el?.querySelector?.('textarea') || textareaRef.value?.$el
    if (!el || typeof el.selectionStart !== 'number') {
        setValue(`${localValue.value || ''}${text}`)
        return
    }
    const start = el.selectionStart || 0
    const end = el.selectionEnd || 0
    setValue(`${localValue.value.slice(0, start)}${text}${localValue.value.slice(end)}`)
    const pos = start + text.length
    requestAnimationFrame(() => {
        el.setSelectionRange?.(pos, pos)
        el.focus?.()
    })
}

const onPick = (path) => {
    pickerOpen.value = false
    if (props.variant === 'pick' && !editingFormula.value) setValue(path)
    else insertAtCursor(path)
}

defineExpose({ insertAtCursor })
</script>
