<template>
    <div class="decision-editor space-y-3">
        <div class="flex items-center justify-between">
            <label class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.decision.condition') }}</label>
            <div class="flex items-center gap-2">
                <a-button size="small" @click="validateExpression" :loading="validating">{{ t('text.workflow.editor.validate') }}</a-button>
                <span v-if="validationState === 'ok'" class="text-xs text-green-600">{{ t('text.workflow.editor.valid') }} ✓</span>
                <span v-else-if="validationState === 'error'" class="text-xs text-red-600">{{ t('text.workflow.editor.invalid') }}</span>
            </div>
        </div>
        <a-textarea
            ref="textareaEl"
            v-model:value="expression"
            :rows="4"
            placeholder="root.total > 1000"
            @input="onInput"
            :disabled="readOnly"
        />
        <div class="text-xs text-gray-500">
            {{ t('text.workflow.editor.decision.hint', { first: 'root.total > 1000', second: 'vars.customer.email' }) }}
        </div>
        <VariableBrowser
            :root-model="rootModel"
            :steps="steps"
            class="mt-2"
            @insert="insertVar"
        />
        <div v-if="validationMessage" class="text-xs" :class="validationState === 'ok' ? 'text-green-600' : 'text-red-600'">
            {{ validationMessage }}
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { debounce } from 'lodash-es'
import VariableBrowser from '@/components/workflows/variable-browser.vue'
import { validateExpression as validateExpressionApi } from '@/services/workflowApi'

const props = defineProps({
    step: {
        type: Object,
        default: () => ({}),
    },
    stepType: {
        type: String,
        default: '',
    },
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    rootModel: {
        type: String,
        default: '',
    },
    steps: {
        type: Array,
        default: () => [],
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const expression = ref('')
const validationState = ref('idle')
const validationMessage = ref('')
const validating = ref(false)

const normalizeConfig = (cfg) => {
    if (!cfg || typeof cfg !== 'object') return {}
    if (cfg.expression) return { expression: cfg.expression }
    if (cfg.condition) return { expression: cfg.condition }
    return {}
}

watch(
    () => props.modelValue,
    (val) => {
        const normalized = normalizeConfig(val)
        expression.value = normalized.expression || ''
    },
    { immediate: true, deep: true }
)

const emitChange = () => {
    emit('update:modelValue', {
        expression: expression.value,
    })
}

const debouncedValidate = debounce(() => {
    if (!expression.value) {
        validationState.value = 'idle'
        validationMessage.value = ''
        return
    }
    validateExpression()
}, 500)

const onInput = () => {
    if (props.readOnly) return
    emitChange()
    debouncedValidate()
}

const insertVar = (expr) => {
    const cursorPos = (textareaEl.value)?.selectionStart ?? expression.value.length
    const before = expression.value.slice(0, cursorPos)
    const after = expression.value.slice(cursorPos)
    expression.value = `${before}${expr}${after}`
    emitChange()
}

const textareaEl = ref(null)

const validateExpression = async () => {
    if (props.readOnly) return
    if (!expression.value) return
    validating.value = true
    try {
        const res = await validateExpressionApi(expression.value, {
            modelClass: props.rootModel,
        })
        const ok = res?.ok ?? false
        const errors = res?.errors || []
        validationState.value = ok ? 'ok' : 'error'
        validationMessage.value = ok
            ? t('text.workflow.editor.expression.valid_message')
            : errors[0]?.message || t('text.workflow.editor.expression.invalid_message')
    } catch (error) {
        validationState.value = 'error'
        validationMessage.value = error?.message || t('text.workflow.editor.validation_failed')
    } finally {
        validating.value = false
    }
}
</script>

<style scoped>
.decision-editor {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
    background: #fff;
}
</style>
