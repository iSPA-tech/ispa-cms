<template>
    <div class="terminate-editor space-y-3">
        <a-alert
            type="warning"
            show-icon
            :message="t('text.workflow.editor.terminate.stop_notice')"
            class="mb-2"
        />

        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('validation.attributes.status') }}</p>
            <a-select
                v-model:value="config.status"
                :options="statusOptions"
                :placeholder="t('text.agentic.label.failed')"
                @change="emitConfig"
                :disabled="readOnly"
            />
        </div>

        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.message') }}</p>
            <a-textarea
                v-model:value="config.message"
                :auto-size="{ minRows: 2, maxRows: 6 }"
                :placeholder="t('text.workflow.editor.terminate.reason_placeholder')"
                @change="emitConfig"
                :disabled="readOnly"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const config = reactive({
    status: 'failed',
    message: '',
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            status: val?.status || 'failed',
            message: val?.message || '',
        })
    },
    { immediate: true, deep: true }
)

const statusOptions = computed(() => [
    { label: t('text.agentic.label.success'), value: 'success' },
    { label: t('text.agentic.label.failed'), value: 'failed' },
])

const emitConfig = () => {
    emit('update:modelValue', {
        status: config.status,
        message: config.message,
    })
}
</script>

<style scoped>
.terminate-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
