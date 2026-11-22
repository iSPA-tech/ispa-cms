<template>
    <div class="loop-each-editor space-y-3">
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
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.loop_each.item_var') }}</p>
                <a-input
                    v-model:value="config.itemVar"
                    placeholder="vars.item"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.loop_each.index_var') }}</p>
                <a-input
                    v-model:value="config.indexVar"
                    placeholder="vars.index"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
        </div>

        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.loop_each.max_items') }}</p>
            <a-input-number
                v-model:value="config.maxItems"
                :min="1"
                style="width: 160px"
                @change="emitConfig"
                :disabled="readOnly"
            />
            <p class="text-xs text-gray-500">{{ t('text.workflow.editor.loop_each.max_items_hint') }}</p>
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
    collectionExpr: 'vars.items',
    itemVar: 'vars.item',
    indexVar: 'vars.index',
    maxItems: 100,
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            collectionExpr: val?.collectionExpr || 'vars.items',
            itemVar: val?.itemVar || 'vars.item',
            indexVar: val?.indexVar || 'vars.index',
            maxItems: val?.maxItems ?? 100,
        })
    },
    { immediate: true, deep: true }
)

const emitConfig = () => {
    emit('update:modelValue', {
        collectionExpr: config.collectionExpr,
        itemVar: config.itemVar,
        indexVar: config.indexVar,
        maxItems: config.maxItems,
    })
}
</script>

<style scoped>
.loop-each-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
