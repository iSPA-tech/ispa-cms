<template>
    <div class="assert-condition-editor space-y-3">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.expression.label') }}</p>
            <ExpressionEditor
                v-model:model-value="config.expression"
                :placeholder="'root.total > 0'"
                :model-class="rootModel"
                :disabled="readOnly"
            />
        </div>

        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.assert_condition.message_on_failure') }}</p>
            <a-input
                v-model:value="config.message"
                :placeholder="t('text.workflow.editor.assert_condition.message_placeholder')"
                @change="emitConfig"
                :disabled="readOnly"
            />
        </div>
    </div>
</template>

<script setup>
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    rootModel: {
        type: String,
        default: '',
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const config = reactive({
    expression: '',
    message: '',
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            expression: val?.expression || '',
            message: val?.message || '',
        })
    },
    { immediate: true, deep: true }
)

const emitConfig = () => {
    emit('update:modelValue', {
        expression: config.expression,
        message: config.message,
    })
}
</script>

<style scoped>
.assert-condition-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
