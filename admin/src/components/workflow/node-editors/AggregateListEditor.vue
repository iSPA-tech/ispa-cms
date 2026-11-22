<template>
    <div class="aggregate-editor space-y-3">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.collection_expr') }}</p>
            <ExpressionEditor
                v-model:model-value="config.collectionExpr"
                :placeholder="'vars.items'"
                :model-class="rootModel"
                :disabled="readOnly"
            />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.operation') }}</p>
                <a-select
                    v-model:value="config.operation"
                    :options="operationOptions"
                    :placeholder="t('text.workflow.editor.aggregate_list.op.sum')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.store_as') }}</p>
                <a-input
                    v-model:value="config.storeAs"
                    placeholder="vars.totalPrice"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
                <p class="text-xs" :class="storeAsValid ? 'text-green-600' : 'text-red-600'">
                    {{ t('text.workflow.editor.store_as_hint_required') }}
                </p>
            </div>
        </div>

        <div v-if="config.operation !== 'count'" class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('validation.attributes.field') }}</p>
            <a-input
                v-model:value="config.field"
                placeholder="price"
                @change="emitConfig"
                :disabled="readOnly"
            />
        </div>
    </div>
</template>

<script setup>
import { reactive, watch, computed } from 'vue'
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
    collectionExpr: 'vars.items',
    operation: 'sum',
    field: 'price',
    storeAs: 'vars.result',
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            collectionExpr: val?.collectionExpr || 'vars.items',
            operation: val?.operation || 'sum',
            field: val?.field || 'price',
            storeAs: val?.storeAs || 'vars.result',
        })
    },
    { immediate: true, deep: true }
)

const operationOptions = computed(() => [
    { label: t('text.workflow.editor.aggregate_list.op.sum'), value: 'sum' },
    { label: t('text.workflow.editor.aggregate_list.op.count'), value: 'count' },
    { label: t('text.workflow.editor.aggregate_list.op.avg'), value: 'avg' },
    { label: t('text.workflow.editor.aggregate_list.op.min'), value: 'min' },
    { label: t('text.workflow.editor.aggregate_list.op.max'), value: 'max' },
])

const storeAsValid = computed(() => config.storeAs && config.storeAs.startsWith('vars.'))

const emitConfig = () => {
    emit('update:modelValue', {
        collectionExpr: config.collectionExpr,
        operation: config.operation,
        field: config.field,
        storeAs: config.storeAs,
    })
}
</script>

<style scoped>
.aggregate-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
