<template>
    <div class="expression-editor space-y-2">
        <label v-if="label" class="text-sm font-semibold text-gray-700">{{ label }}</label>
        <a-textarea
            ref="textareaRef"
            v-model:value="localValue"
            :auto-size="{ minRows: 3, maxRows: 8 }"
            :disabled="loading || disabled"
            :placeholder="placeholder || 'root.total > 1000'"
            @change="emitChange"
        />
        <div class="flex items-center gap-2">
            <a-button :loading="loading" size="small" type="default" @click="validate">
                {{ t('text.workflow.editor.validate') }}
            </a-button>
            <span v-if="status === 'ok'" class="text-xs text-green-600">{{ t('text.workflow.editor.valid') }}</span>
            <span v-if="status === 'error'" class="text-xs text-red-600">{{ t('text.workflow.editor.invalid') }}</span>
        </div>
        <div v-if="errors.length" class="space-y-1">
            <div
                v-for="(err, idx) in errors"
                :key="idx"
                class="text-xs text-red-600 flex items-start gap-1"
            >
                <span>•</span>
                <span>{{ err.message }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { validateExpression } from '@/services/workflowApi'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    modelClass: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    availableVars: { type: Array, default: () => [] }, // ["vars.postId", "vars.customer", ...]
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const localValue = ref(props.modelValue || '')
const errors = ref([])
const status = ref('idle')
const loading = ref(false)
const textareaRef = ref()

watch(
    () => props.modelValue,
    (val) => {
        localValue.value = val || ''
    }
)

const emitChange = () => {
    emit('update:modelValue', localValue.value)
}

const insertAtCursor = (text) => {
    const el =
        textareaRef.value?.resizableTextArea?.textArea ||
        textareaRef.value?.$el?.querySelector?.('textarea')

    if (!el) {
        localValue.value = `${localValue.value || ''}${text}`
        emitChange()
        return
    }

    const start = el.selectionStart || 0
    const end = el.selectionEnd || 0
    const before = localValue.value.slice(0, start)
    const after = localValue.value.slice(end)
    localValue.value = `${before}${text}${after}`
    emitChange()

    // restore cursor after inserted text
    const pos = start + text.length
    requestAnimationFrame(() => {
        el.setSelectionRange?.(pos, pos)
        el.focus?.()
    })
}

defineExpose({ insertAtCursor })

const validate = async () => {
    if (!localValue.value?.trim()) {
        errors.value = [{ message: t('text.workflow.editor.expression.required') }]
        status.value = 'error'
        return
    }
    loading.value = true
    try {
        const res = await validateExpression(localValue.value, {
            modelClass: props.modelClass,
        })
        const payload = res?.data || res || {}
        const ok = payload.ok ?? false
        const errs = payload.errors || []
        errors.value = errs
        status.value = ok && (!errs || errs.length === 0) ? 'ok' : 'error'
        if (status.value === 'ok') {
            iAlert({ status: 'success', message: t('text.workflow.editor.expression.valid_message') })
        }
    } catch (error) {
        status.value = 'error'
        errors.value = [{ message: error?.message || t('text.workflow.editor.validation_failed') }]
    } finally {
        loading.value = false
    }
}
</script>

<style scoped>
.expression-editor {
    width: 100%;
}
</style>
