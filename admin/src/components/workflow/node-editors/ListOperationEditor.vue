<template>
    <div class="list-operation-editor space-y-3">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.collection_expr') }}</p>
            <ExpressionEditor
                v-model:model-value="config.collectionExpr"
                variant="pick"
                :placeholder="'vars.orders'"
                :model-class="rootModel"
                :disabled="readOnly"
            />
            <p class="text-xs text-gray-500">{{ t('text.workflow.editor.list_operation.collection_hint') }}</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.operation') }}</p>
                <a-select
                    v-model:value="config.operation"
                    :options="operationOptions"
                    :placeholder="t('nav.b.filter')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.store_as') }}</p>
                <a-input
                    v-model:value="config.storeAs"
                    placeholder="vars.result"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
                <p class="text-xs" :class="storeAsValid ? 'text-green-600' : 'text-red-600'">
                    {{ t('text.workflow.editor.store_as_hint_required') }}
                </p>
            </div>
        </div>

        <div v-if="config.operation === 'filter'" class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.list_operation.condition_expr') }}</p>
            <ExpressionEditor
                v-model:model-value="config.conditionExpr"
                :placeholder="'item.total > 1000'"
                :model-class="rootModel"
                :disabled="readOnly"
            />
        </div>

        <div v-if="config.operation === 'map'" class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.list_operation.map_expr') }}</p>
            <ExpressionEditor
                v-model:model-value="config.mapExpr"
                :placeholder="`{'id': item.id }`"
                :model-class="rootModel"
                :disabled="readOnly"
            />
        </div>

        <div v-if="config.operation === 'sort'" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.list_operation.sort_field') }}</p>
                <a-input
                    v-model:value="config.sortField"
                    placeholder="total"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.list_operation.direction') }}</p>
                <a-select
                    v-model:value="config.sortDir"
                    :options="dirOptions"
                    :placeholder="t('text.workflow.editor.retrieve.direction.asc')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
        </div>

        <div v-if="config.operation === 'take'" class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.list_operation.take') }}</p>
            <a-input-number
                v-model:value="config.take"
                :min="1"
                style="width: 160px"
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
    collectionExpr: 'vars.orders',
    operation: 'filter',
    conditionExpr: '',
    mapExpr: '',
    sortField: '',
    sortDir: 'asc',
    take: 10,
    storeAs: 'vars.result',
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            collectionExpr: val?.collectionExpr || 'vars.orders',
            operation: val?.operation || 'filter',
            conditionExpr: val?.conditionExpr || '',
            mapExpr: val?.mapExpr || '',
            sortField: val?.sortField || '',
            sortDir: val?.sortDir || 'asc',
            take: val?.take ?? 10,
            storeAs: val?.storeAs || 'vars.result',
        })
    },
    { immediate: true, deep: true }
)

const operationOptions = computed(() => [
    { label: t('nav.b.filter'), value: 'filter' },
    { label: t('text.workflow.editor.list_operation.op.map'), value: 'map' },
    { label: t('text.workflow.editor.list_operation.op.sort'), value: 'sort' },
    { label: t('text.workflow.editor.list_operation.op.take'), value: 'take' },
])

const dirOptions = computed(() => [
    { label: t('text.workflow.editor.retrieve.direction.asc'), value: 'asc' },
    { label: t('text.workflow.editor.retrieve.direction.desc'), value: 'desc' },
])

const storeAsValid = computed(() => config.storeAs && config.storeAs.startsWith('vars.'))

const emitConfig = () => {
    emit('update:modelValue', {
        collectionExpr: config.collectionExpr,
        operation: config.operation,
        conditionExpr: config.conditionExpr,
        mapExpr: config.mapExpr,
        sortField: config.sortField,
        sortDir: config.sortDir,
        take: config.take,
        storeAs: config.storeAs,
    })
}
</script>

<style scoped>
.list-operation-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
