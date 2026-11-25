<template>
    <div class="delete-object-editor space-y-3">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.target_expr') }}</p>
            <ExpressionEditor
                v-model:model-value="config.targetExpr"
                variant="pick"
                :placeholder="'vars.customer'"
                :model-class="rootModel"
                :disabled="readOnly"
            />
            <p class="text-xs text-gray-500">{{ t('text.workflow.editor.delete_object.target_expr_hint') }}</p>
        </div>

        <div class="space-y-1">
            <a-checkbox v-model:checked="config.force" @change="emitConfig" :disabled="readOnly">
                {{ t('text.workflow.editor.delete_object.force_delete') }}
            </a-checkbox>
            <p class="text-xs text-amber-600">
                {{ t('text.workflow.editor.delete_object.force_delete_warning') }}
            </p>
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
    targetExpr: 'vars.',
    force: false,
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            targetExpr: val?.targetExpr || 'vars.',
            force: !!val?.force,
        })
    },
    { immediate: true, deep: true }
)

const emitConfig = () => {
    emit('update:modelValue', {
        targetExpr: config.targetExpr,
        force: config.force,
    })
}
</script>

<style scoped>
.delete-object-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
