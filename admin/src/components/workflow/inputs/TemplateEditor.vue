<template>
    <div class="template-editor space-y-2">
        <label v-if="label" class="text-sm font-semibold text-gray-700">{{ label }}</label>
        <a-textarea
            v-model:value="localValue"
            :placeholder="placeholder || 'Hello {{ root.id }}'"
            :auto-size="{ minRows: 3, maxRows: 8 }"
            :disabled="loading"
            @change="emitChange"
        />
        <div class="flex items-center gap-2">
            <a-button size="small" type="default" :loading="loading" @click="preview">
                {{ t('nav.b.preview') }}
            </a-button>
            <span v-if="status === 'ok'" class="text-xs text-green-600">{{ t('text.workflow.editor.template.rendered_status') }}</span>
            <span v-if="status === 'error'" class="text-xs text-red-600">{{ t('text.agentic.label.failed') }}</span>
        </div>

        <div v-if="errors.length" class="space-y-1">
            <div v-for="(err, idx) in errors" :key="idx" class="text-xs text-red-600 flex gap-1">
                <span>•</span>
                <span>{{ err.message }}</span>
            </div>
        </div>

        <div v-if="previewValue !== null" class="p-2 border rounded bg-gray-50 text-sm text-gray-800">
            {{ previewValue }}
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { previewTemplate } from '@/services/workflowApi'
import { iAlert } from '@/mixins'

const props = defineProps({
    modelValue: {
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
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const localValue = ref(props.modelValue || '')
const loading = ref(false)
const status = ref('idle')
const errors = ref([])
const previewValue = ref(null)

watch(
    () => props.modelValue,
    (val) => {
        localValue.value = val || ''
    }
)

const emitChange = () => {
    emit('update:modelValue', localValue.value)
}

const preview = async () => {
    if (!localValue.value?.trim()) {
        errors.value = [{ message: t('text.workflow.editor.template.required') }]
        status.value = 'error'
        return
    }
    loading.value = true
    try {
        const res = await previewTemplate(localValue.value)
        const ok = res?.ok ?? false
        const rendered = res?.rendered ?? null
        const errs = res?.errors || []
        previewValue.value = rendered
        errors.value = errs
        status.value = ok && (!errs || errs.length === 0) ? 'ok' : 'error'
        if (status.value === 'ok') {
            iAlert({ status: 'success', message: t('text.workflow.editor.template.rendered') })
        }
    } catch (error) {
        status.value = 'error'
        errors.value = [{ message: error?.message || t('text.workflow.editor.preview_failed') }]
    } finally {
        loading.value = false
    }
}
</script>

<style scoped>
.template-editor {
    width: 100%;
}
</style>
